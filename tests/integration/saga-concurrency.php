<?php

declare(strict_types=1);

use App\Entity\Saga;
use App\Enum\SagaState;
use App\Enum\SagaTransition;
use App\Kernel;
use App\Saga\SagaCoordinator;
use App\Saga\SagaExecutor;
use App\Saga\Strategy\SagaTransitionStrategyInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Uid\Uuid;
use Ticketing\Contracts\Command\ProcessPayment;
use Ticketing\Contracts\Event\{PaymentFailed, PaymentSucceeded, ReservationCancelled, ReservationEventInterface, ReservationRequested, SeatHoldRejected, SeatsConfirmed, SeatsHeld, SeatsReleased};

require '/app/services/orchestrator-service/vendor/autoload.php';

final readonly class StrategyProbeEvent implements ReservationEventInterface
{
    public function __construct(
        public string $reservationId,
        public string $correlationId,
    ) {
    }
}

/** @implements SagaTransitionStrategyInterface<StrategyProbeEvent> */
final class StrategyProbe implements SagaTransitionStrategyInterface
{
    public bool $handled = false;

    public static function eventClass(): string
    {
        return StrategyProbeEvent::class;
    }

    public function transition(): SagaTransition
    {
        return SagaTransition::SeatsHeld;
    }

    public function handle(Saga $saga, ReservationEventInterface $event): ProcessPayment
    {
        $this->handled = true;

        return new ProcessPayment(
            reservationId: $event->reservationId,
            showId: $saga->getShowId(),
            correlationId: $event->correlationId,
            amountMinor: 5000,
            currency: 'UAH',
            paymentMethodToken: 'probe-token',
        );
    }
}

// Expose services only in this test kernel; production DI stays private.
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
        $container->setAlias('test.saga_coordinator', SagaCoordinator::class)->setPublic(true);
        $container->setAlias('test.saga_executor', SagaExecutor::class)->setPublic(true);
        $container->register(StrategyProbe::class, StrategyProbe::class)->setAutoconfigured(true)->setPublic(true);
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

function eventFor(string $name, string $id): ReservationEventInterface
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

    // Adding an implementation needs no change to the coordinator or its wiring.
    $coordinator = $container->get('test.saga_coordinator');
    $probe = $container->get(StrategyProbe::class);
    $probeId = Uuid::v7()->toRfc4122();
    $em->persist(new Saga(Uuid::v7()->toRfc4122(), $probeId, $probeId, 'probe-show', ['probe-seat'], SagaState::AwaitingSeats));
    $em->flush();
    $coordinator->handle(new StrategyProbeEvent($probeId, $probeId));
    check($probe->handled, 'New strategy was not discovered through autoconfiguration');
    check($db->fetchOne('SELECT state FROM sagas WHERE reservation_id = ?', [$probeId]) === 'AWAITING_PAYMENT', 'Discovered strategy bypassed shared transition execution');
    check($db->fetchFirstColumn('SELECT routing_key FROM outbox_messages WHERE reservation_id = ?', [$probeId]) === ['payment.process'], 'Discovered strategy bypassed shared recording');
    try {
        $coordinator->handle(new ReservationCancelled('unsupported', 'correlation', new DateTimeImmutable()));
        throw new RuntimeException('Unsupported event was silently accepted');
    } catch (InvalidArgumentException) {
    }
    try {
        new SagaCoordinator($container->get('test.saga_executor'), [$probe, $probe]);
        throw new RuntimeException('Ambiguous strategy registration was accepted');
    } catch (LogicException) {
    }
    echo "PASS: automatic strategy discovery, unsupported events and duplicate registration.\n";

    $bus = $container->get('test.event.bus');
    $id = Uuid::v7()->toRfc4122();
    $correlationId = Uuid::v7()->toRfc4122();
    $requested = new ReservationRequested($id, 'creation-show', ['seat-b', 'seat-a'], $correlationId);
    $envelope = new Envelope($requested, [
        new ReceivedStamp('inbound'),
        new TransportMessageIdStamp(Uuid::v7()->toRfc4122()),
    ]);
    $claimsBefore = (int) $db->fetchOne('SELECT COUNT(*) FROM inbox_messages');
    $db->beginTransaction();
    try {
        $bus->dispatch($envelope);
        check((int) $db->fetchOne('SELECT COUNT(*) FROM sagas WHERE reservation_id = ?', [$id]) === 1, 'Creation did not persist saga');
        check((int) $db->fetchOne('SELECT COUNT(*) FROM outbox_messages WHERE reservation_id = ?', [$id]) === 1, 'Creation did not record hold');
    } finally {
        $db->rollBack();
        $em->clear();
    }
    check((int) $db->fetchOne('SELECT COUNT(*) FROM sagas WHERE reservation_id = ?', [$id]) === 0, 'Creation rollback retained saga');
    check((int) $db->fetchOne('SELECT COUNT(*) FROM outbox_messages WHERE reservation_id = ?', [$id]) === 0, 'Creation rollback retained outbox');
    check((int) $db->fetchOne('SELECT COUNT(*) FROM inbox_messages') === $claimsBefore, 'Creation rollback retained inbox claim');
    $bus->dispatch($envelope);
    $bus->dispatch($envelope); // Same message ID is suppressed by the inbox.
    $bus->dispatch(new Envelope($requested, [
        new ReceivedStamp('inbound'),
        new TransportMessageIdStamp(Uuid::v7()->toRfc4122()),
    ])); // Different message ID is suppressed by existing saga lookup.
    $created = $db->fetchAllAssociative('SELECT state, correlation_id, show_id, seat_ids FROM sagas WHERE reservation_id = ?', [$id]);
    check(count($created) === 1 && $created[0]['state'] === 'AWAITING_SEATS', 'Duplicate creation changed saga');
    check($created[0]['correlation_id'] === $correlationId && $created[0]['show_id'] === 'creation-show', 'Creation lost saga metadata');
    check(json_decode($created[0]['seat_ids'], true, flags: JSON_THROW_ON_ERROR) === ['seat-b', 'seat-a'], 'Creation changed seat IDs');
    $outgoing = $db->fetchAllAssociative('SELECT routing_key, correlation_id, payload FROM outbox_messages WHERE reservation_id = ?', [$id]);
    check(count($outgoing) === 1 && $outgoing[0]['routing_key'] === 'seats.hold', 'Duplicate creation recorded extra work');
    check($outgoing[0]['correlation_id'] === $correlationId, 'Creation lost outbox correlation');
    check(json_decode($outgoing[0]['payload'], true, flags: JSON_THROW_ON_ERROR) === [
        'reservationId' => $id,
        'showId' => 'creation-show',
        'seatIds' => ['seat-b', 'seat-a'],
        'correlationId' => $correlationId,
    ], 'Creation changed HoldSeats payload');
    check((int) $db->fetchOne('SELECT COUNT(*) FROM inbox_messages') - $claimsBefore === 2, 'Incorrect creation replay claims');
    echo "PASS: creation rollback and replay preserve one saga, one HoldSeats and message metadata.\n";

    // Direct calls exercise the executor-owned transaction, without middleware.
    $id = Uuid::v7()->toRfc4122();
    $coordinator->handle(new ReservationRequested($id, 'direct-show', ['direct-seat'], $correlationId));
    $coordinator->handle(new ReservationRequested($id, 'direct-show', ['direct-seat'], $correlationId));
    $now = new DateTimeImmutable('2026-09-30T12:00:00+00:00');
    $coordinator->handle(new SeatsHeld($id, 'direct-show', ['direct-seat'], $correlationId, $now->modify('+15 minutes')));
    $coordinator->handle(new PaymentSucceeded('payment', $id, $correlationId, 5000, 'UAH', $now));
    $confirmationCorrelationId = Uuid::v7()->toRfc4122();
    $coordinator->handle(new SeatsConfirmed($id, 'direct-show', ['direct-seat'], $confirmationCorrelationId, $now));
    check(!$db->isTransactionActive(), 'Direct call left a transaction open');
    check($db->fetchOne('SELECT state FROM sagas WHERE reservation_id = ?', [$id]) === 'CONFIRMED', 'Direct calls failed to commit');
    $rows = $db->fetchAllAssociative('SELECT routing_key, reservation_id, correlation_id, payload FROM outbox_messages WHERE reservation_id = ?', [$id]);
    check(count($rows) === 4, 'Direct calls recorded unexpected work');
    $payloads = [];
    foreach ($rows as $row) {
        $payload = json_decode($row['payload'], true, flags: JSON_THROW_ON_ERROR);
        $expectedCorrelationId = $row['routing_key'] === 'reservation.confirmed' ? $confirmationCorrelationId : $correlationId;
        check($payload['reservationId'] === $id && $payload['correlationId'] === $expectedCorrelationId, 'Reaction lost message metadata');
        check($row['reservation_id'] === $payload['reservationId'] && $row['correlation_id'] === $payload['correlationId'], 'Outbox metadata differs from outgoing payload');
        $payloads[$row['routing_key']] = $payload;
    }
    check($payloads['payment.process']['amountMinor'] === 5000 && $payloads['payment.process']['currency'] === 'UAH', 'Payment defaults changed');
    check($payloads['payment.process']['paymentMethodToken'] === getenv('PAYMENT_METHOD_TOKEN'), 'Payment token wiring changed');
    check($payloads['seats.confirm']['showId'] === 'direct-show' && $payloads['seats.confirm']['seatIds'] === ['direct-seat'], 'Confirmation lost stored seats');
    check(new DateTimeImmutable($payloads['reservation.confirmed']['confirmedAt']) == $now, 'Confirmation timestamp changed');
    echo "PASS: direct creation and success flow commit with unchanged outgoing payloads.\n";

    // Ignored outcomes must not run strategy side effects.
    foreach ([null, SagaState::SeatsHeld, SagaState::Confirmed, SagaState::Cancelled] as $state) {
        $id = Uuid::v7()->toRfc4122();
        if ($state !== null) {
            $em->persist(new Saga(Uuid::v7()->toRfc4122(), $id, $id, 'show', ['seat'], $state));
            $em->flush();
        }
        $before = $db->fetchAssociative('SELECT state, failure_reason, updated_at FROM sagas WHERE reservation_id = ?', [$id]);
        foreach (['held', 'rejected', 'paid', 'failed', 'released', 'confirmed'] as $eventName) {
            $coordinator->handle(eventFor($eventName, $id));
        }
        check($db->fetchAssociative('SELECT state, failure_reason, updated_at FROM sagas WHERE reservation_id = ?', [$id]) === $before, 'Ignored outcome mutated saga');
        check((int) $db->fetchOne('SELECT COUNT(*) FROM outbox_messages WHERE reservation_id = ?', [$id]) === 0, 'Ignored outcome recorded work');
    }
    echo "PASS: missing, legacy and terminal sagas ignore outcomes without side effects.\n";

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

    // A failure in shared outbox recording must roll back the accepted transition.
    // Run last because Doctrine closes the EntityManager on transaction failure.
    $id = Uuid::v7()->toRfc4122();
    $em->persist(new Saga(Uuid::v7()->toRfc4122(), $id, $id, 'show', ['seat'], SagaState::AwaitingPayment));
    $em->flush();
    $before = $db->fetchAssociative('SELECT state, failure_reason, updated_at FROM sagas WHERE reservation_id = ?', [$id]);
    try {
        $container->get('test.saga_executor')->execute(
            event: eventFor('failed', $id),
            strategy: new class implements SagaTransitionStrategyInterface {
                public static function eventClass(): string
                {
                    return PaymentFailed::class;
                }

                public function transition(): SagaTransition
                {
                    return SagaTransition::PaymentFailed;
                }

                public function handle(Saga $saga, ReservationEventInterface $event): object
                {
                    $saga->recordFailure('TEST_FAILURE');

                    return new stdClass(); // No outbox route exists for this message.
                }
            },
        );
        throw new RuntimeException('Invalid outgoing message was accepted');
    } catch (InvalidArgumentException $error) {
        check(str_contains($error->getMessage(), 'No outbox routing key'), 'Unexpected recording failure');
    }
    check(!$db->isTransactionActive(), 'Recording failure left a transaction open');
    check($db->fetchAssociative('SELECT state, failure_reason, updated_at FROM sagas WHERE reservation_id = ?', [$id]) === $before, 'Recording failure committed saga changes');
    check((int) $db->fetchOne('SELECT COUNT(*) FROM outbox_messages WHERE reservation_id = ?', [$id]) === 0, 'Recording failure committed outgoing work');
    echo "PASS: outbox recording failure rolls back transition and failure details.\n";
} finally {
    // Only this test's unique schema is removed; normal workers use public.
    $db->executeStatement('SET search_path TO public');
    $db->executeStatement('DROP SCHEMA ' . $schema . ' CASCADE');
    $kernel->shutdown();
}
