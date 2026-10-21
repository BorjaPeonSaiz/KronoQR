<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Event\EmployeeClockedIn;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Reporting\Application\Port\LivePresenceReader;
use App\Modules\Reporting\Domain\ValueObject\PresenceBoard;
use App\Modules\Reporting\Domain\ValueObject\PresenceEntry;
use App\Modules\Reporting\Domain\ValueObject\PresenceStatus;
use App\Modules\Reporting\Infrastructure\Broadcasting\BroadcastPresenceChange;
use App\Modules\Shared\Domain\ValueObject\AccessScope;
use App\Support\Database\DatabaseUnavailable;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Events\Dispatcher;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Psr\Log\LoggerInterface;
use Tests\Support\Observability\RecordingLogger;
use Tests\Support\Queue\InMemoryQueue;
use Tests\Support\Time\FrozenTime;

/*
 * LA DIFUSION DE PRESENCIA, EN UN WORKER DE VERDAD, NUNCA SE DA POR FALLIDA SIN
 * HABERSE INTENTADO (ADR-059 §5).
 *
 * Con `retryUntil()`, `Worker::markJobAsFailedIfAlreadyExceedsMaxAttempts`
 * fallaba el trabajo con `MaxAttemptsExceededException` antes de ejecutarlo en
 * cuanto vencia el plazo: `failed_jobs`, una fila en `error_events` y ningun
 * `warning` `reporting.presence_broadcast_expired`. Y bastaba con que Horizon
 * fuera 60 s atrasado, sin averia. La prueba pasa por el payload que construye
 * el despachador de eventos (con las opciones que copia del listener) y por
 * `Worker::process`, con el `tries` de Horizon (1).
 */

const BROADCAST_PRESENCE_WORKER_EMPLOYEE = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b92';

/**
 * Despacha el evento como lo hace la aplicacion, pero con solo este listener,
 * a las 07:00:00 UTC: eso es el `createdAt` del payload.
 */
function broadcastPresenceWorkerDispatch(): void
{
    // Los dos relojes: `createdAt` lo pone Carbon al encolar. El `TestCase`
    // devuelve Carbon al reloj real al terminar la prueba.
    FrozenTime::at('2026-03-14 07:00:00');

    $eventos = new Dispatcher(app());
    // @phpstan-ignore argument.type (el docblock de Laravel pide una `Queue`, pero `Dispatcher::queueHandler()` llama a `->connection()` sobre lo que devuelve: es el `QueueManager`, como en `EventServiceProvider`)
    $eventos->setQueueResolver(static fn (): QueueManager => app('queue'));
    $eventos->listen(EmployeeClockedIn::class, [BroadcastPresenceChange::class, 'clockedIn']);
    $eventos->dispatch(new EmployeeClockedIn(
        BROADCAST_PRESENCE_WORKER_EMPLOYEE,
        1,
        '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b93',
        WorkDate::fromIsoDate('2026-03-14', new DateTimeZone('Europe/Madrid')),
        new DateTimeImmutable('2026-03-14 06:59:58', new DateTimeZone('UTC')),
        ScanOrigin::QR_KIOSK,
    ));
}

/**
 * Saca el siguiente trabajo y lo pasa por el worker a la hora dada: el `Clock`
 * del listener y el `Carbon::now()` del worker, los dos en ese instante.
 */
function broadcastPresenceWorkerRun(InMemoryQueue $cola, string $hora): Job
{
    FrozenTime::at($hora);
    $job = $cola->pop();
    expect($job)->not->toBeNull();
    assert($job instanceof Job);

    /** @var Worker $worker */
    $worker = app('queue.worker');
    $worker->process('resilient', $job, new WorkerOptions(maxTries: 1));

    return $job;
}

/**
 * Monta la cola en memoria como conexion `resilient`, una lectura de presencia
 * sin PostgreSQL y un logger que apunta.
 *
 * @return array{0: InMemoryQueue, 1: RecordingLogger}
 */
function broadcastPresenceWorkerSetUp(): array
{
    $cola = new InMemoryQueue;
    $logger = new RecordingLogger;

    /** @var QueueManager $colas */
    $colas = app('queue');
    $colas->addConnector('kq-memory', static fn (): ConnectorInterface => new readonly class($cola) implements ConnectorInterface
    {
        public function __construct(private InMemoryQueue $cola) {}

        /** @param  array<string, mixed>  $config */
        public function connect(array $config): InMemoryQueue
        {
            return $this->cola;
        }
    });
    config()->set('queue.connections.resilient', ['driver' => 'kq-memory']);

    app()->instance(LivePresenceReader::class, new readonly class implements LivePresenceReader
    {
        public function board(AccessScope $scope, ?int $departmentId, ?string $search, PresenceStatus $status, DateTimeImmutable $generatedAt, string $timeZone): PresenceBoard
        {
            throw DatabaseUnavailable::circuitOpen(7);
        }

        public function stateOf(string $employeeUuid): ?PresenceEntry
        {
            throw DatabaseUnavailable::circuitOpen(7);
        }

        public function openShiftsByDepartment(): array
        {
            return [];
        }
    });
    app()->when(BroadcastPresenceChange::class)->needs(LoggerInterface::class)->give(static fn (): LoggerInterface => $logger);

    return [$cola, $logger];
}

it('dentro del plazo vuelve a la cola, y pasado sale el warning sin fallar el trabajo', function (): void {
    [$cola, $logger] = broadcastPresenceWorkerSetUp();
    broadcastPresenceWorkerDispatch();

    $primero = broadcastPresenceWorkerRun($cola, '2026-03-14 07:00:05');

    expect($primero->isReleased())->toBeTrue()
        ->and($primero->hasFailed())->toBeFalse()
        ->and($cola->releases)->toBe([7])
        ->and($logger->lines)->toBe([]);

    // Segundo intento, ya pasado el `tries` de Horizon (1) y el plazo de 60 s.
    $segundo = broadcastPresenceWorkerRun($cola, '2026-03-14 07:01:01');

    expect($segundo->attempts())->toBe(2)
        ->and($segundo->hasFailed())->toBeFalse()
        ->and($segundo->isDeleted())->toBeTrue()
        ->and($cola->size())->toBe(0)
        ->and(array_column($logger->lines, 'message'))->toBe(['reporting.presence_broadcast_expired']);
})->group('RF-PA-01', 'RNF-D-03');

it('con Horizon dos minutos atrasado se ejecuta y avisa, no falla sin haberse intentado', function (): void {
    [$cola, $logger] = broadcastPresenceWorkerSetUp();
    broadcastPresenceWorkerDispatch();

    // `Carbon::now()` del worker tambien va atrasado: con `retryUntil()` el
    // worker lo habria fallado aqui con `MaxAttemptsExceededException`.
    // `broadcastPresenceWorkerRun()` congela los dos relojes en esa hora.
    $job = broadcastPresenceWorkerRun($cola, '2026-03-14 07:02:00');

    expect($job->hasFailed())->toBeFalse()
        ->and($job->isDeleted())->toBeTrue()
        ->and($cola->releases)->toBe([])
        ->and(array_column($logger->lines, 'message'))->toBe(['reporting.presence_broadcast_expired']);
})->group('RF-PA-01', 'RNF-D-03');
