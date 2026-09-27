<?php

declare(strict_types=1);

use App\Entity\Seat;
use App\Entity\Show;
use App\Kernel;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Uid\Uuid;
use Ticketing\Contracts\Command\HoldSeats;
use Ticketing\Contracts\Event\SeatHoldRejected;
use Ticketing\Contracts\Event\SeatsHeld;

require '/app/services/inventory-service/vendor/autoload.php';

set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
    exit(1);
});

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function signal($stream, array $message): void
{
    fwrite($stream, json_encode($message, JSON_THROW_ON_ERROR) . "\n");
    fflush($stream);
}

function receive($stream, string $expected): array
{
    $read = [$stream];
    $write = $except = [];
    check(stream_select($read, $write, $except, 15) === 1, "Timed out waiting for '$expected'");
    $line = fgets($stream);
    check($line !== false, "Worker exited before '$expected'");
    $message = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    check(($message['event'] ?? null) === $expected, "Expected '$expected', received: " . trim($line));

    return $message;
}

final class InventoryConcurrencyKernel extends Kernel
{
    public function getProjectDir(): string
    {
        return '/app/services/inventory-service';
    }

    public function getCacheDir(): string
    {
        return '/tmp/inventory-concurrency-cache';
    }

    protected function build(ContainerBuilder $container): void
    {
        $container->setAlias('test.command.bus', 'command.bus')->setPublic(true);
    }
}

$kernel = new InventoryConcurrencyKernel('dev', false);
$kernel->boot();
$container = $kernel->getContainer();
$em = $container->get('doctrine')->getManager();
$db = $em->getConnection();
$db->executeStatement("SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL READ COMMITTED");
$db->executeStatement("SET statement_timeout = '12s'");
$db->executeStatement("SET idle_in_transaction_session_timeout = '25s'");

if (($argv[1] ?? '') === '--worker') {
    [$script, $mode, $schema, $showId, $seatId, $reservationId] = $argv;
    check(preg_match('/^inventory_test_[a-f0-9]+$/D', $schema) === 1, 'Invalid test schema');
    $db->executeStatement('SET search_path TO ' . $schema);
    // Keep this exact PHP object across the competing transaction's commit.
    $seat = $em->find(Seat::class, $seatId);
    check($seat !== null && $seat->isAvailable(), 'Expected an AVAILABLE seat to preload');
    signal(STDOUT, ['event' => 'ready', 'pid' => (int) $db->fetchOne('SELECT pg_backend_pid()')]);
    receive(STDIN, 'go');
    $db->beginTransaction();
    try {
        signal(STDOUT, ['event' => 'started']);
        $container->get('test.command.bus')->dispatch(new Envelope(
            new HoldSeats($reservationId, $showId, [$seatId], $reservationId),
            [new ReceivedStamp('inbound'), new TransportMessageIdStamp(Uuid::v7()->toRfc4122())],
        ));
        check($em->contains($seat), 'Preloaded seat was detached during processing');
        signal(STDOUT, [
            'event' => 'processed',
            'state' => $seat->getState()->value,
            'owner' => $seat->getHeldByReservationId(),
        ]);
        $decision = receive(STDIN, 'finish')['decision'];
        check(in_array($decision, ['commit', 'rollback'], true), 'Invalid transaction decision');
        $decision === 'commit' ? $db->commit() : $db->rollBack();
        signal(STDOUT, ['event' => 'done']);
    } finally {
        if ($db->isTransactionActive()) {
            $db->rollBack();
        }
        $kernel->shutdown();
    }
    exit(0);
}

function startWorker(string $schema, string $showId, string $seatId, string $reservationId): array
{
    $process = proc_open([
        PHP_BINARY, '-d', 'display_errors=stderr', __FILE__, '--worker', $schema, $showId, $seatId, $reservationId,
    ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
    check(is_resource($process), 'Could not start worker');

    return ['process' => $process, 'pipes' => $pipes];
}

function waitForBlocked(Connection $db, int $waitingPid, int $blockingPid): void
{
    $deadline = microtime(true) + 5;
    do {
        $blocked = (int) $db->fetchOne(
            'SELECT COUNT(*) FROM unnest(pg_blocking_pids(?)) AS blocker(pid) WHERE pid = ?',
            [$waitingPid, $blockingPid],
        );
        if ($blocked === 1) {
            $query = (string) $db->fetchOne('SELECT query FROM pg_stat_activity WHERE pid = ?', [$waitingPid]);
            check(str_contains($query, 'FOR UPDATE') && str_contains($query, 'seats'),
                'T2 is blocked on something other than the seat SELECT FOR UPDATE');

            return;
        }
        usleep(20_000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('T2 did not wait on T1 within 5 seconds; seat lock missing');
}

function assertSeat(array $snapshot, string $state, ?string $owner): void
{
    check($snapshot['state'] === $state && $snapshot['owner'] === $owner,
        'Expected seat ' . json_encode(['state' => $state, 'owner' => $owner], JSON_THROW_ON_ERROR)
        . ', received ' . json_encode($snapshot, JSON_THROW_ON_ERROR));
}

$schema = 'inventory_test_' . bin2hex(random_bytes(8));
$db->executeStatement('CREATE SCHEMA ' . $schema);
try {
    $db->executeStatement('SET search_path TO ' . $schema);
    foreach (glob('/app/services/inventory-service/migrations/Version*.php') as $file) {
        require_once $file;
        $class = 'DoctrineMigrations\\' . basename($file, '.php');
        $migration = new $class($db, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
    echo "Inventory concurrency — PostgreSQL READ COMMITTED\n";
    echo "Two PHP workers, distinct reservations/message IDs, real inbound Messenger bus.\n";
    foreach (['commit', 'rollback'] as $decision) {
        echo "\nScenario: T1 " . strtoupper($decision) . "\n";
        $showId = Uuid::v7()->toRfc4122();
        $seatId = Uuid::v7()->toRfc4122();
        $r1 = Uuid::v7()->toRfc4122();
        $r2 = Uuid::v7()->toRfc4122();
        $show = new Show($showId, 'Inventory concurrency fixture');
        $em->persist($show);
        $em->persist(new Seat($seatId, $show, 'A1'));
        $em->flush();
        $em->clear();
        $claimsBefore = (int) $db->fetchOne('SELECT COUNT(*) FROM inbox_messages');
        $workers = [];
        try {
            $workers[] = startWorker($schema, $showId, $seatId, $r1);
            $workers[] = startWorker($schema, $showId, $seatId, $r2);
            $pid1 = receive($workers[0]['pipes'][1], 'ready')['pid'];
            $pid2 = receive($workers[1]['pipes'][1], 'ready')['pid'];
            check($pid1 > 0 && $pid2 > 0 && $pid1 !== $pid2, 'Expected independent PostgreSQL sessions');
            echo "T1/T2: preloaded AVAILABLE into separate EntityManagers.\n";
            signal($workers[0]['pipes'][0], ['event' => 'go']);
            receive($workers[0]['pipes'][1], 'started');
            assertSeat(receive($workers[0]['pipes'][1], 'processed'), 'HELD', $r1);
            echo "T1: flushed HELD/R1, SeatHold and SeatsHeld outbox; transaction still open.\n";
            signal($workers[1]['pipes'][0], ['event' => 'go']);
            receive($workers[1]['pipes'][1], 'started');
            echo "T2: started HoldSeats for R2.\n";
            waitForBlocked($db, $pid2, $pid1);
            echo "DB: pg_blocking_pids($pid2) contains $pid1; T2 waits on seat SELECT FOR UPDATE.\n";

            assertSeat($db->fetchAssociative('SELECT state, held_by_reservation_id AS owner FROM seats WHERE id = ?', [$seatId]), 'AVAILABLE', null);
            check((int) $db->fetchOne('SELECT COUNT(*) FROM seat_holds WHERE show_id = ?', [$showId]) === 0, 'Uncommitted hold leaked');
            check((int) $db->fetchOne('SELECT COUNT(*) FROM outbox_messages WHERE reservation_id IN (?, ?)', [$r1, $r2]) === 0, 'Uncommitted outbox leaked');
            echo "DB: uncommitted seat change, hold and outbox are invisible to observer.\n";

            signal($workers[0]['pipes'][0], ['event' => 'finish', 'decision' => $decision]);
            receive($workers[0]['pipes'][1], 'done');
            echo 'T1: ' . strtoupper($decision) . " completed.\n";
            $committed = $decision === 'commit';
            $winner = $committed ? $r1 : $r2;
            assertSeat(receive($workers[1]['pipes'][1], 'processed'), 'HELD', $winner);
            echo $committed
                ? "T2: handler resumed; preloaded ORM object now reads HELD/R1 (was AVAILABLE).\n"
                : "T2: handler resumed after rollback; preloaded ORM object now reads HELD/R2.\n";
            signal($workers[1]['pipes'][0], ['event' => 'finish', 'decision' => 'commit']);
            receive($workers[1]['pipes'][1], 'done');
            echo "T2: COMMIT completed.\n";
            foreach ($workers as $index => $worker) {
                foreach ($worker['pipes'] as $pipe) {
                    fclose($pipe);
                }
                $workers[$index]['pipes'] = [];
                check(proc_close($worker['process']) === 0, 'Worker failed');
            }
        } finally {
            foreach ($workers as $worker) {
                if (is_resource($worker['process'])) {
                    proc_terminate($worker['process']);
                    foreach ($worker['pipes'] as $pipe) {
                        fclose($pipe);
                    }
                    proc_close($worker['process']);
                }
            }
        }

        assertSeat($db->fetchAssociative('SELECT state, held_by_reservation_id AS owner FROM seats WHERE id = ?', [$seatId]), 'HELD', $winner);
        $holds = $db->fetchAllAssociative('SELECT reservation_id, status, seat_ids FROM seat_holds WHERE show_id = ?', [$showId]);
        check(count($holds) === 1 && $holds[0]['reservation_id'] === $winner && $holds[0]['status'] === 'ACTIVE', 'Expected exactly one active hold belonging to the winner');
        check(json_decode($holds[0]['seat_ids'], true, 512, JSON_THROW_ON_ERROR) === [$seatId], 'Hold has incorrect seats');
        $events = $db->fetchAllAssociative('SELECT reservation_id, event_type, payload FROM outbox_messages WHERE reservation_id IN (?, ?)', [$r1, $r2]);
        check(count($events) === ($committed ? 2 : 1), 'Unexpected outbox count');
        $expectedEvents = $committed ? [$r1 => SeatsHeld::class, $r2 => SeatHoldRejected::class] : [$r2 => SeatsHeld::class];
        foreach ($events as $event) {
            check(($expectedEvents[$event['reservation_id']] ?? null) === $event['event_type'], 'Unexpected or duplicate outbox event');
            unset($expectedEvents[$event['reservation_id']]);
            $payload = json_decode($event['payload'], true, 512, JSON_THROW_ON_ERROR);
            check($payload['reservationId'] === $event['reservation_id'] && $payload['showId'] === $showId, 'Outbox payload does not match reservation/show');
            if ($event['event_type'] === SeatHoldRejected::class) {
                check($payload['reason'] === 'One or more seats are not AVAILABLE', 'Unexpected rejection reason');
            } else {
                check($payload['seatIds'] === [$seatId], 'SeatsHeld payload has incorrect seats');
            }
        }
        check($expectedEvents === [], 'Missing expected outbox event');
        check((int) $db->fetchOne('SELECT COUNT(*) FROM inbox_messages') - $claimsBefore === ($committed ? 2 : 1), 'Incorrect inbox commit/rollback');
        echo $committed
            ? "PASS: one active hold (R1); SeatsHeld for R1; SeatHoldRejected for R2; no oversell.\n"
            : "PASS: one active hold (R2); only R2 SeatsHeld; R1 hold/outbox/inbox rolled back.\n";
    }
} finally {
    $em->clear();
    $db->executeStatement('SET search_path TO public');
    $db->executeStatement('DROP SCHEMA ' . $schema . ' CASCADE');
    $kernel->shutdown();
}
echo "\nPASS: both inventory concurrency scenarios; isolated test schema removed.\n";
