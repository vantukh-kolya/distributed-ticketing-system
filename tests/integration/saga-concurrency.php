<?php

declare(strict_types=1);

use App\Entity\Saga;
use App\Enum\SagaState;
use App\Kernel;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Uid\Uuid;
use Ticketing\Contracts\Event\{PaymentFailed, PaymentSucceeded, SeatHoldRejected, SeatsConfirmed, SeatsHeld, SeatsReleased};

require '/app/services/orchestrator-service/vendor/autoload.php';

// Expose the real inbound bus only in this test kernel; production DI stays private.
final class ConcurrencyKernel extends Kernel
{
    public function getProjectDir(): string
    {
        return '/app/services/orchestrator-service';
    }

    public function getCacheDir(): string
    {
        return '/tmp/saga-concurrency-cache';
    }

    protected function build(\Symfony\Component\DependencyInjection\ContainerBuilder $container): void
    {
        parent::build($container);
        $container->setAlias('test.event.bus', 'event.bus')->setPublic(true);
        $container->setAlias('test.saga_state_machine', 'state_machine.reservation_saga')->setPublic(true);
    }
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function receive($stream): string
{
    $read = [$stream];
    $write = $except = [];
    check(stream_select($read, $write, $except, 15) === 1, 'Timed out waiting for worker barrier');
    $line = fgets($stream);
    check($line !== false, 'Worker exited before reaching its barrier');

    return trim($line);
}

function signal($stream, string $message): void
{
    fwrite($stream, $message . "\n");
    fflush($stream);
}

function eventFor(string $name, string $id): object
{
    $now = new DateTimeImmutable();

    return match ($name) {
        'held' => new SeatsHeld($id, 'test-show', ['test-seat'], $id, $now->modify('+15 minutes')),
        'rejected' => new SeatHoldRejected($id, 'test-show', $id, 'SEATS_UNAVAILABLE'),
        'paid' => new PaymentSucceeded($id, $id, $id, 5000, 'UAH', $now),
        'failed' => new PaymentFailed($id, $id, $id, 'PAYMENT_DECLINED', $now),
        'released' => new SeatsReleased($id, 'test-show', ['test-seat'], $id),
        'confirmed' => new SeatsConfirmed($id, 'test-show', ['test-seat'], $id, $now),
    };
}

$kernel = new ConcurrencyKernel('dev', false);
$kernel->boot();
$container = $kernel->getContainer();
$em = $container->get('doctrine')->getManager();
$db = $em->getConnection();
$db->executeStatement("SET statement_timeout = '12s'");
$db->executeStatement("SET idle_in_transaction_session_timeout = '25s'");

if (($argv[1] ?? '') === '--worker') {
    [$unused, $mode, $schema, $id, $eventName] = $argv;
    check(preg_match('/^saga_test_[a-f0-9]+$/D', $schema) === 1, 'Invalid test schema');
    $db->executeStatement('SET search_path TO ' . $schema);
    // Both workers deliberately cache the initial state before either transition.
    // The locked query must refresh it after waiting for the first transaction.
    check($em->getRepository(Saga::class)->findByReservationId($id) !== null, 'Missing fixture');
    signal(STDOUT, (string) $db->fetchOne('SELECT pg_backend_pid()'));
    check(receive(STDIN) === 'go', 'Expected go barrier');
    $db->beginTransaction();
    try {
        $container->get('test.event.bus')->dispatch(new Envelope(eventFor($eventName, $id), [
            new ReceivedStamp('inbound'),
            // Different IDs are essential: inbox must not serialize this test.
            new TransportMessageIdStamp(Uuid::v7()->toRfc4122()),
        ]));
        signal(STDOUT, 'processed');
        $decision = receive(STDIN);
        check(in_array($decision, ['commit', 'rollback'], true), 'Invalid transaction decision');
        $decision === 'commit' ? $db->commit() : $db->rollBack();
        signal(STDOUT, 'done');
    } finally {
        if ($db->isTransactionActive()) {
            $db->rollBack();
        }
        $kernel->shutdown();
    }
    exit(0);
}

function startWorker(string $schema, string $id, string $event): array
{
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', __FILE__, '--worker', $schema, $id, $event], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR,
    ], $pipes);
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
            return;
        }
        usleep(20_000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Second handler did not wait on the first transaction; row lock missing');
}

// Verify the configured graph, including terminal and legacy states, before races.
$machine = $container->get('test.saga_state_machine');
$transitions = [
    'seats_held' => [SagaState::AwaitingSeats, SagaState::AwaitingPayment],
    'seat_hold_rejected' => [SagaState::AwaitingSeats, SagaState::Cancelled],
    'payment_succeeded' => [SagaState::AwaitingPayment, SagaState::PaymentPaid],
    'payment_failed' => [SagaState::AwaitingPayment, SagaState::Compensating],
    'seats_released' => [SagaState::Compensating, SagaState::Cancelled],
    'seats_confirmed' => [SagaState::PaymentPaid, SagaState::Confirmed],
];
foreach (SagaState::cases() as $state) {
    foreach ($transitions as $transition => [$from, $to]) {
        $subject = new Saga('test', 'test', 'test', 'show', ['seat'], $state);
        $updatedAt = $subject->getUpdatedAt();
        check($machine->can($subject, $transition) === ($state === $from), 'Unexpected allowed transition');
        check($subject->getUpdatedAt() === $updatedAt, 'Checking a transition changed the entity');
        if ($state === $from) {
            $machine->apply($subject, $transition);
            check($subject->getState() === $to, 'Wrong transition target');
        } else {
            try {
                $machine->apply($subject, $transition);
                throw new RuntimeException('Invalid transition was accepted');
            } catch (\Symfony\Component\Workflow\Exception\NotEnabledTransitionException) {
                check($subject->getState() === $state && $subject->getUpdatedAt() === $updatedAt, 'Rejected transition mutated saga');
            }
        }
    }
}
echo "PASS: configured state machine accepts only the six valid transitions across all saga states.\n";

$schema = 'saga_test_' . bin2hex(random_bytes(8));
$db->executeStatement('CREATE SCHEMA ' . $schema);
$db->executeStatement('SET search_path TO ' . $schema);
try {
    foreach (glob('/app/services/orchestrator-service/migrations/Version*.php') as $file) {
        require_once $file;
        $class = 'DoctrineMigrations\\' . basename($file, '.php');
        $migration = new $class($db, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    // initial state, first event, competing event, expected state/route/reason, rollback first
    $cases = [
        [SagaState::AwaitingSeats, 'held', 'held', 'AWAITING_PAYMENT', 'payment.process', null, false],
        [SagaState::AwaitingSeats, 'held', 'rejected', 'AWAITING_PAYMENT', 'payment.process', null, false],
        [SagaState::AwaitingSeats, 'rejected', 'held', 'CANCELLED', 'reservation.cancelled', 'SEATS_UNAVAILABLE', false],
        [SagaState::AwaitingPayment, 'paid', 'paid', 'PAYMENT_PAID', 'seats.confirm', null, false],
        [SagaState::AwaitingPayment, 'failed', 'failed', 'COMPENSATING', 'seats.release', 'PAYMENT_DECLINED', false],
        [SagaState::AwaitingPayment, 'paid', 'failed', 'PAYMENT_PAID', 'seats.confirm', null, false],
        [SagaState::AwaitingPayment, 'failed', 'paid', 'COMPENSATING', 'seats.release', 'PAYMENT_DECLINED', false],
        [SagaState::Compensating, 'released', 'released', 'CANCELLED', 'reservation.cancelled', null, false],
        [SagaState::PaymentPaid, 'confirmed', 'confirmed', 'CONFIRMED', 'reservation.confirmed', null, false],
        [SagaState::AwaitingPayment, 'paid', 'failed', 'COMPENSATING', 'seats.release', 'PAYMENT_DECLINED', true],
    ];
    foreach ($cases as [$initial, $firstEvent, $secondEvent, $expectedState, $route, $reason, $rollback]) {
        $id = Uuid::v7()->toRfc4122();
        $em->persist(new Saga(Uuid::v7()->toRfc4122(), $id, $id, 'test-show', ['test-seat'], $initial));
        $em->flush();
        $em->clear();
        $claimsBefore = (int) $db->fetchOne('SELECT COUNT(*) FROM inbox_messages');
        $workers = [];
        try {
            $workers[] = startWorker($schema, $id, $firstEvent);
            $workers[] = startWorker($schema, $id, $secondEvent);
            $firstPid = (int) receive($workers[0]['pipes'][1]);
            $secondPid = (int) receive($workers[1]['pipes'][1]);
            check($firstPid > 0 && $secondPid > 0 && $firstPid !== $secondPid, 'Expected independent PostgreSQL sessions');
            signal($workers[0]['pipes'][0], 'go');
            check(receive($workers[0]['pipes'][1]) === 'processed', 'First handler did not finish');
            signal($workers[1]['pipes'][0], 'go');
            waitForBlocked($db, $secondPid, $firstPid);
            // Neither state nor outbox is visible before the outer transaction commits.
            check($db->fetchOne('SELECT state FROM sagas WHERE reservation_id = ?', [$id]) === $initial->value, 'Uncommitted state leaked');
            check((int) $db->fetchOne('SELECT COUNT(*) FROM outbox_messages WHERE reservation_id = ?', [$id]) === 0, 'Uncommitted outbox leaked');
            signal($workers[0]['pipes'][0], $rollback ? 'rollback' : 'commit');
            check(receive($workers[0]['pipes'][1]) === 'done', 'First transaction did not finish');
            check(receive($workers[1]['pipes'][1]) === 'processed', 'Second handler did not resume');
            signal($workers[1]['pipes'][0], 'commit');
            check(receive($workers[1]['pipes'][1]) === 'done', 'Second transaction did not finish');
            foreach ($workers as &$worker) {
                foreach ($worker['pipes'] as $pipe) {
                    fclose($pipe);
                }
                $worker['pipes'] = [];
                $exitCode = proc_close($worker['process']);
                check($exitCode === 0, 'Worker failed');
            }
            unset($worker);
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
        $saga = $db->fetchAssociative('SELECT state, failure_reason FROM sagas WHERE reservation_id = ?', [$id]);
        check($saga['state'] === $expectedState && $saga['failure_reason'] === $reason, 'Unexpected final saga state/reason');
        check($db->fetchFirstColumn('SELECT routing_key FROM outbox_messages WHERE reservation_id = ?', [$id]) === [$route], 'Expected exactly one matching outbox command/event');
        $claimsAfter = (int) $db->fetchOne('SELECT COUNT(*) FROM inbox_messages');
        check($claimsAfter - $claimsBefore === ($rollback ? 1 : 2), 'Incorrect inbox commit/rollback');
        echo sprintf("PASS: %s / %s%s → %s, one %s\n", $firstEvent, $secondEvent, $rollback ? ' (first rolls back)' : '', $expectedState, $route);
    }
} finally {
    // Only this test's unique schema is removed; normal workers use public.
    $db->executeStatement('SET search_path TO public');
    $db->executeStatement('DROP SCHEMA ' . $schema . ' CASCADE');
    $kernel->shutdown();
}
