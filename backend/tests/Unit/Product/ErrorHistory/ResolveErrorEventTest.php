<?php

declare(strict_types=1);

use App\Modules\Product\Application\Port\ErrorEventQuery;
use App\Modules\Product\Application\Port\ErrorEventRepository;
use App\Modules\Product\Application\UseCase\ResolveErrorEvent;
use App\Modules\Product\Domain\ValueObject\ErrorEvent;
use App\Modules\Product\Domain\ValueObject\ErrorEventPage;
use App\Modules\Product\Domain\ValueObject\ErrorEventSummary;
use App\Modules\Product\Domain\ValueObject\ErrorFingerprint;
use App\Modules\Product\Domain\ValueObject\ErrorWriteOutcome;
use App\Modules\Shared\Domain\ValueObject\ErrorLevel;
use App\Modules\Shared\Domain\ValueObject\ErrorReport;
use App\Modules\Shared\Domain\ValueObject\ErrorSource;
use Tests\Support\Time\FixedClock;

/*
 * Resolver es idempotente y no escribe auditoria (RF-PD-15, decision 8 de la
 * ficha 5.12).
 *
 * Unitaria y con un doble del puerto: lo que se comprueba aqui es que **el caso
 * de uso pide el instante al reloj inyectado** (regla dura 2) y que no toca nada
 * mas. Que la segunda resolucion no reescriba el autor lo garantiza el `WHERE`
 * de la sentencia y lo prueba `ErrorEventEndpointTest` contra PostgreSQL, que es
 * donde vive esa garantia.
 */

/**
 * Un historico en memoria con una sola fila.
 *
 * @return ErrorEventRepository&object{resoluciones: list<array{id: int, userId: int, at: DateTimeImmutable}>}
 */
function historicoConUnGrupo(): ErrorEventRepository
{
    return new class implements ErrorEventRepository
    {
        /** @var list<array{id: int, userId: int, at: DateTimeImmutable}> */
        public array $resoluciones = [];

        private ?DateTimeImmutable $resueltoEn = null;

        private ?int $resueltoPor = null;

        public function upsert(
            ErrorReport $report,
            ErrorFingerprint $fingerprint,
            string $message,
            array $context,
            DateTimeImmutable $seenAt,
            DateTimeImmutable $recordedAt,
        ): ErrorWriteOutcome {
            return ErrorWriteOutcome::Recurred;
        }

        public function countOpenGroups(ErrorSource $source): int
        {
            return 0;
        }

        public function exists(ErrorFingerprint $fingerprint): bool
        {
            return false;
        }

        public function page(ErrorEventQuery $query): ErrorEventPage
        {
            return new ErrorEventPage([], 0, 1, 25, 0, 0);
        }

        public function resolve(int $id, int $userId, DateTimeImmutable $at): ?ErrorEvent
        {
            if ($id !== 7) {
                return null;
            }

            $this->resoluciones[] = ['id' => $id, 'userId' => $userId, 'at' => $at];

            // Primera resolucion: se queda. Segunda: devuelve la primera, que es
            // lo que hace el `WHERE resolved_at IS NULL` en PostgreSQL.
            $this->resueltoEn ??= $at;
            $this->resueltoPor ??= $userId;

            return new ErrorEvent(
                id: 7,
                fingerprint: str_repeat('a', 64),
                level: ErrorLevel::Error,
                source: ErrorSource::Api,
                module: 'product',
                code: null,
                message: 'algo fallo',
                exceptionClass: 'RuntimeException',
                file: 'app/Foo.php',
                line: 10,
                context: [],
                traceId: null,
                deviceId: null,
                employeeUuid: null,
                appVersion: '2.2.0',
                occurrences: 3,
                firstSeenAt: new DateTimeImmutable('2026-09-01T00:00:00Z'),
                lastSeenAt: new DateTimeImmutable('2026-09-02T00:00:00Z'),
                resolvedAt: $this->resueltoEn,
                // La cuenta que resolvio la PRIMERA vez: una segunda pulsacion
                // no reescribe el autor.
                resolvedByUuid: 'aaaaaaaa-0000-7000-8000-'.str_pad((string) $this->resueltoPor, 12, '0', STR_PAD_LEFT),
                resolvedByName: 'Cuenta '.$this->resueltoPor,
            );
        }

        public function pruneOlderThan(DateTimeImmutable $cutoff, int $batchSize): int
        {
            return 0;
        }

        public function summary(DateTimeImmutable $since): ErrorEventSummary
        {
            return new ErrorEventSummary([], [], 0, 0);
        }
    };
}

it('resuelve con el instante del reloj inyectado y nunca con now()', function (): void {
    // Regla dura 2: sin esto no se puede probar nada que dependa del tiempo.
    $ahora = new DateTimeImmutable('2026-09-09T08:00:00Z');
    $historico = historicoConUnGrupo();

    $evento = (new ResolveErrorEvent($historico, FixedClock::at('2026-09-09T08:00:00Z')))->handle(7, 42);

    expect($evento)->toBeInstanceOf(ErrorEvent::class)
        ->and($evento?->resolvedAt?->format(DATE_ATOM))->toBe($ahora->format(DATE_ATOM))
        ->and($historico->resoluciones[0]['userId'])->toBe(42);
})->group('RF-PD-15');

it('resolver dos veces devuelve la misma fila y no cambia autor ni instante', function (): void {
    // La accion no tiene segundo efecto y no merece un `409`.
    $historico = historicoConUnGrupo();
    $primero = new ResolveErrorEvent($historico, FixedClock::at('2026-09-09T08:00:00Z'));
    $segundo = new ResolveErrorEvent($historico, FixedClock::at('2026-09-09T09:30:00Z'));

    $uno = $primero->handle(7, 42);
    $dos = $segundo->handle(7, 99);

    expect($dos?->resolvedAt?->format(DATE_ATOM))->toBe($uno?->resolvedAt?->format(DATE_ATOM))
        ->and($dos?->resolvedByName)->toBe($uno?->resolvedByName);
})->group('RF-PD-15');

it('devuelve nulo cuando el grupo no existe, para que el controlador responda 404', function (): void {
    $resolver = new ResolveErrorEvent(
        historicoConUnGrupo(),
        FixedClock::at('2026-09-09T08:00:00Z'),
    );

    expect($resolver->handle(404, 42))->toBeNull();
})->group('RF-PD-15');
