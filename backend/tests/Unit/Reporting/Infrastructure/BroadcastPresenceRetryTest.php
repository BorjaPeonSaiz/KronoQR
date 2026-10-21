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
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Events\Dispatcher;
use Illuminate\Queue\Jobs\SyncJob;
use Mockery\MockInterface;
use Tests\Support\Observability\RecordingLogger;

/*
 * LA DIFUSION DE PRESENCIA VUELVE A LA COLA SI FALTA POSTGRESQL, DURANTE 60 S
 * DESDE QUE SE ENCOLO (ADR-059 §5, ADR-011).
 *
 * - en un worker, con `DatabaseUnavailable`, se devuelve a la cola con la espera
 *   del cortacircuitos;
 * - pasado el plazo de `retryUntil()`, `warning` y fin;
 * - en `deferred` (un `SyncJob`), `warning` sin excepcion;
 * - otro fallo no se reintenta.
 */

const BROADCAST_PRESENCE_RETRY_EMPLOYEE = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90';

function broadcastPresenceRetryReader(Throwable $fallo): LivePresenceReader
{
    return new readonly class($fallo) implements LivePresenceReader
    {
        public function __construct(private Throwable $fallo) {}

        public function board(AccessScope $scope, ?int $departmentId, ?string $search, PresenceStatus $status, DateTimeImmutable $generatedAt, string $timeZone): PresenceBoard
        {
            throw $this->fallo;
        }

        public function stateOf(string $employeeUuid): ?PresenceEntry
        {
            throw $this->fallo;
        }

        public function openShiftsByDepartment(): array
        {
            return [];
        }
    };
}

function broadcastPresenceRetryEvent(): EmployeeClockedIn
{
    return new EmployeeClockedIn(
        BROADCAST_PRESENCE_RETRY_EMPLOYEE,
        1,
        '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b91',
        WorkDate::fromIsoDate('2026-03-14', new DateTimeZone('Europe/Madrid')),
        new DateTimeImmutable('2026-03-14 07:00:00', new DateTimeZone('UTC')),
        ScanOrigin::QR_KIOSK,
    );
}

/**
 * Un trabajo de Horizon que apunta a cuanto se devuelve.
 *
 * @param  ArrayObject<int, int>  $liberado
 */
function broadcastPresenceRetryJob(?int $retryUntil, ArrayObject $liberado): Job
{
    /** @var Job&MockInterface $job */
    $job = Mockery::mock(Job::class);
    $job->allows('retryUntil')->andReturn($retryUntil);
    $job->allows('release')->andReturnUsing(static function (int $delay) use ($liberado): void {
        $liberado->append($delay);
    });

    return $job;
}

it('vuelve a la cola con la espera del cortacircuitos si falta PostgreSQL y queda plazo', function (): void {
    $logger = new RecordingLogger;
    $listener = new BroadcastPresenceChange(broadcastPresenceRetryReader(DatabaseUnavailable::circuitOpen(7)), new Dispatcher, $logger);
    /** @var ArrayObject<int, int> $liberado */
    $liberado = new ArrayObject;
    $listener->setJob(broadcastPresenceRetryJob(time() + 30, $liberado));

    $listener->clockedIn(broadcastPresenceRetryEvent());

    expect($liberado->getArrayCopy())->toBe([7])
        ->and($logger->lines)->toBe([]);
})->group('RF-PA-01', 'RNF-D-03');

it('se abandona con un warning pasado el plazo de 60 s desde que se encolo', function (): void {
    $logger = new RecordingLogger;
    $listener = new BroadcastPresenceChange(broadcastPresenceRetryReader(DatabaseUnavailable::circuitOpen(7)), new Dispatcher, $logger);
    /** @var ArrayObject<int, int> $liberado */
    $liberado = new ArrayObject;
    $listener->setJob(broadcastPresenceRetryJob(time() - 1, $liberado));

    $listener->clockedIn(broadcastPresenceRetryEvent());

    expect($liberado->getArrayCopy())->toBe([])
        ->and(array_column($logger->lines, 'message'))->toBe(['reporting.presence_broadcast_expired']);
})->group('RF-PA-01', 'RNF-D-03');

it('en deferred no hay cola a la que volver: warning sin excepcion', function (): void {
    $logger = new RecordingLogger;
    $listener = new BroadcastPresenceChange(broadcastPresenceRetryReader(DatabaseUnavailable::circuitOpen(7)), new Dispatcher, $logger);
    $listener->setJob(new SyncJob(new Container, '{"job":"x","data":[]}', 'deferred', 'default'));

    $listener->clockedIn(broadcastPresenceRetryEvent());

    expect(array_column($logger->lines, 'message'))->toBe(['reporting.presence_broadcast_failed']);
})->group('RF-PA-01', 'RNF-D-03');

it('no reintenta otro fallo, y no escribe el mensaje de la excepcion', function (): void {
    $logger = new RecordingLogger;
    $listener = new BroadcastPresenceChange(broadcastPresenceRetryReader(new RuntimeException('reverb no responde en 10.20.30.40')), new Dispatcher, $logger);
    /** @var ArrayObject<int, int> $liberado */
    $liberado = new ArrayObject;
    $listener->setJob(broadcastPresenceRetryJob(time() + 30, $liberado));

    $listener->clockedIn(broadcastPresenceRetryEvent());

    expect($liberado->getArrayCopy())->toBe([])
        ->and(array_column($logger->lines, 'message'))->toBe(['reporting.presence_broadcast_failed'])
        ->and(json_encode($logger->lines))->not->toContain('10.20.30.40');
})->group('RF-PA-01', 'RS-05');

it('fija el plazo al despachar, 60 s despues de ahora y no de occurred_at', function (): void {
    $listener = new ReflectionClass(BroadcastPresenceChange::class)->newInstanceWithoutConstructor();
    $hasta = $listener->retryUntil(broadcastPresenceRetryEvent());

    expect($hasta->getTimestamp() - time())->toBeGreaterThanOrEqual(59)->toBeLessThanOrEqual(60);
})->group('RF-PA-01', 'RNF-D-03');
