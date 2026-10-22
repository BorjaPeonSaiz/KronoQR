<?php

declare(strict_types=1);

use App\Modules\Reporting\Domain\Event\ReportExportCancelled;
use App\Modules\Reporting\Domain\Exception\InvalidReportExportTransition;
use App\Modules\Reporting\Domain\Exception\ReportExportNotCancellable;
use App\Modules\Reporting\Domain\Exception\ReportExportWasCancelled;
use App\Modules\Reporting\Domain\Model\ReportExport;
use App\Modules\Reporting\Domain\ValueObject\ReportExportFailure;
use App\Modules\Reporting\Domain\ValueObject\ReportExportKind;
use App\Modules\Reporting\Domain\ValueObject\ReportExportNotificationChannel;
use App\Modules\Reporting\Domain\ValueObject\ReportExportParameters;
use App\Modules\Reporting\Domain\ValueObject\ReportExportStatus;
use App\Modules\Shared\Domain\Exception\BusinessRejection;
use App\Modules\Shared\Domain\ValueObject\AccessScope;
use Tests\Support\Reporting\ReportExportFixtures;

/*
 * Cancelar una exportacion en curso y las dos clases nuevas del diferido
 * (**V5-PA-4**, RC2, RF-IN-05, RF-IN-08), sin base de datos y sin reloj.
 *
 * Lo que se fija aqui es la maquina de estados: que solo se cancela lo que
 * sigue en curso, que cancelar no resucita ni tira un fichero ya generado, y
 * que `legal` y `adoption` no se pueden convertir por error en una consulta del
 * informe por periodo.
 */

it('cancela una exportacion pendiente y sella el momento', function (): void {
    $cancelada = ReportExportFixtures::pending()->cancel(ReportExportFixtures::at('2026-03-08T09:00:30+00:00'));

    expect($cancelada->status)->toBe(ReportExportStatus::Cancelled)
        ->and($cancelada->wasCancelled())->toBeTrue()
        // Libera el turno de esa persona: el indice unico parcial solo cuenta
        // `pending` y `running`.
        ->and($cancelada->isInProgress())->toBeFalse()
        ->and($cancelada->cancelledAt?->format(DATE_ATOM))->toBe('2026-03-08T09:00:30+00:00')
        // No es un fallo: ni fecha ni motivo de fallo.
        ->and($cancelada->failedAt)->toBeNull()
        ->and($cancelada->failureReason)->toBeNull()
        ->and($cancelada->isDownloadable())->toBeFalse();
})->group('RF-IN-06');

it('cancela una exportacion que ya se estaba generando, conservando cuando arranco', function (): void {
    $cancelada = ReportExportFixtures::pending()
        ->start(ReportExportFixtures::at('2026-03-08T09:00:05+00:00'))
        ->cancel(ReportExportFixtures::at('2026-03-08T09:01:00+00:00'));

    expect($cancelada->status)->toBe(ReportExportStatus::Cancelled)
        ->and($cancelada->startedAt?->format(DATE_ATOM))->toBe('2026-03-08T09:00:05+00:00')
        ->and($cancelada->cancelledAt?->format(DATE_ATOM))->toBe('2026-03-08T09:01:00+00:00');
})->group('RF-IN-06');

it('no cancela una exportacion que ya termino, fallo o se purgo', function (ReportExport $cerrada): void {
    // Cancelar una `completed` seria tirar un fichero ya generado y anunciado en
    // `audit_log` (regla dura 5). El caso idempotente —ya `cancelled`— lo
    // distingue quien llama con `wasCancelled()`, antes de llegar aqui.
    expect(static fn () => $cerrada->cancel(ReportExportFixtures::at('2026-03-08T10:00:00+00:00')))
        ->toThrow(InvalidReportExportTransition::class);
})->with([
    'terminada' => fn (): ReportExport => ReportExportFixtures::completed(),
    'fallida' => fn (): ReportExport => ReportExportFixtures::pending()
        ->fail(ReportExportFixtures::at('2026-03-08T09:00:10+00:00'), ReportExportFailure::Stale),
    'purgada' => fn (): ReportExport => ReportExportFixtures::completed()
        ->purge(ReportExportFixtures::at('2026-03-15T04:00:00+00:00')),
    'ya cancelada' => fn (): ReportExport => ReportExportFixtures::pending()
        ->cancel(ReportExportFixtures::at('2026-03-08T09:00:30+00:00')),
])->group('RF-IN-06');

it('una exportacion cancelada no se puede arrancar, terminar ni dar por fallida', function (): void {
    // Es lo que hace que el trabajador, al releer la fila bloqueada, no pueda
    // cerrar como `completed` una exportacion que alguien cancelo mientras se
    // escribia: la transicion lanza y el fichero a medias se borra.
    $cancelada = ReportExportFixtures::pending()->cancel(ReportExportFixtures::at('2026-03-08T09:00:30+00:00'));
    $instante = ReportExportFixtures::at('2026-03-08T09:01:00+00:00');

    expect(static fn () => $cancelada->start($instante))->toThrow(InvalidReportExportTransition::class)
        ->and(static fn () => $cancelada->fail($instante, ReportExportFailure::Unexpected))
        ->toThrow(InvalidReportExportTransition::class)
        ->and(static fn () => $cancelada->complete(
            completedAt: $instante,
            filePath: '/tmp/x.csv',
            fileName: 'x.csv',
            sizeBytes: 1,
            sha256: str_repeat('a', 64),
            rowCount: 0,
            criteria: [],
            expiresAt: $instante,
        ))->toThrow(InvalidReportExportTransition::class);
})->group('RF-IN-06');

it('los copiadores que no tocan el ciclo de vida conservan la cancelacion', function (): void {
    $cancelada = ReportExportFixtures::pending()->cancel(ReportExportFixtures::at('2026-03-08T09:00:30+00:00'));

    $avisada = $cancelada->markNotified(ReportExportFixtures::at('2026-03-08T09:00:31+00:00'), ReportExportNotificationChannel::Panel);
    $consumida = $cancelada->consumeDownloadToken(ReportExportFixtures::at('2026-03-08T09:00:32+00:00'));

    expect($avisada->cancelledAt?->format(DATE_ATOM))->toBe('2026-03-08T09:00:30+00:00')
        ->and($consumida->cancelledAt?->format(DATE_ATOM))->toBe('2026-03-08T09:00:30+00:00')
        ->and($avisada->status)->toBe(ReportExportStatus::Cancelled);
})->group('RF-IN-06');

it('una exportacion terminada y purgada nunca lleva fecha de cancelacion', function (): void {
    $purgada = ReportExportFixtures::completed()->purge(ReportExportFixtures::at('2026-03-15T04:00:00+00:00'));

    expect(ReportExportFixtures::completed()->cancelledAt)->toBeNull()
        ->and($purgada->cancelledAt)->toBeNull();
})->group('RF-IN-06');

it('el catalogo de estados incluye cancelled y no lo cuenta como en curso', function (): void {
    expect(ReportExportStatus::names())->toBe(['pending', 'running', 'completed', 'failed', 'cancelled', 'purged'])
        ->and(ReportExportStatus::Cancelled->isInProgress())->toBeFalse()
        ->and(ReportExportStatus::Cancelled->isDownloadable())->toBeFalse()
        ->and(ReportExportStatus::inProgress())->toBe([ReportExportStatus::Pending, ReportExportStatus::Running]);
})->group('RF-IN-06');

it('la exportacion para la Inspeccion solo sale en CSV y el cuadro de impacto en los tres formatos', function (): void {
    // RL-06: formato tabular, legible y no propietario.
    expect(ReportExportKind::Legal->allows())->toBe(['csv'])
        ->and(ReportExportKind::Adoption->allows())->toBe(['csv', 'xlsx', 'pdf'])
        ->and(ReportExportKind::formats())->toBe(['csv', 'xlsx', 'pdf'])
        ->and(ReportExportKind::names())->toBe(['period', 'payroll', 'legal', 'adoption']);
})->group('RF-IN-05', 'RF-IN-08', 'RL-06');

it('solo period y payroll salen de la consulta del informe por periodo', function (ReportExportKind $kind, bool $esInforme): void {
    expect($kind->isPeriodReport())->toBe($esInforme);
})->with([
    'period' => [ReportExportKind::Period, true],
    'payroll' => [ReportExportKind::Payroll, true],
    'legal' => [ReportExportKind::Legal, false],
    'adoption' => [ReportExportKind::Adoption, false],
])->group('RF-IN-06');

it('los parametros de legal y adoption no llevan granularidad, agrupacion ni filtros de equipo', function (): void {
    $parametros = ReportExportParameters::span('2026-01-01', '2026-12-31', '019a0000-0000-7000-8000-000000000002');

    expect($parametros->granularity)->toBeNull()
        ->and($parametros->grouping)->toBeNull()
        ->and($parametros->includeOpenShifts)->toBeFalse()
        ->and($parametros->departmentId)->toBeNull()
        ->and($parametros->toArray())->toBe([
            'from' => '2026-01-01',
            'to' => '2026-12-31',
            'granularity' => null,
            'group_by' => null,
            'include_open_shifts' => false,
            'department_id' => null,
            'employee_uuid' => '019a0000-0000-7000-8000-000000000002',
        ])
        ->and(ReportExportParameters::span('2026-01-01', '2026-01-31')->employeeUuid)->toBeNull();
})->group('RF-IN-05', 'RF-IN-08');

it('se niega a convertir unos parametros sin granularidad en el informe por periodo', function (): void {
    // Generar el informe de horas con una granularidad inventada seria entregar
    // un fichero que nadie pidio.
    expect(static fn () => ReportExportParameters::span('2026-01-01', '2026-01-31')->toQuery(AccessScope::unrestricted()))
        ->toThrow(LogicException::class);
})->group('RF-IN-06');

it('reconstruye desde la fila los parametros sin granularidad, y sigue rompiendo con una desconocida', function (): void {
    $guardados = ReportExportParameters::span('2026-01-01', '2026-01-31', '019a0000-0000-7000-8000-000000000002')->toArray();
    $releidos = ReportExportParameters::fromArray($guardados);

    expect($releidos->toArray())->toBe($guardados)
        ->and(static fn () => ReportExportParameters::fromArray([...$guardados, 'granularity' => 'fortnight']))
        ->toThrow(ValueError::class)
        ->and(static fn () => ReportExportParameters::fromArray([...$guardados, 'group_by' => 'floor']))
        ->toThrow(ValueError::class);
})->group('RF-IN-06');

it('el hecho de la cancelacion lleva quien la pidio y el estado en que estaba, sin parametros', function (): void {
    $evento = new ReportExportCancelled(
        uuid: '019a12b4-5c6d-7e8f-9012-3456789abcde',
        kind: 'legal',
        format: 'csv',
        previousStatus: 'running',
        requestedByUserId: 7,
        occurredAt: ReportExportFixtures::at('2026-03-08T09:01:00+00:00'),
    );

    expect($evento->eventName())->toBe('reporting.report_export_cancelled')
        ->and($evento->occurredAt()->format(DATE_ATOM))->toBe('2026-03-08T09:01:00+00:00')
        ->and($evento->previousStatus)->toBe('running')
        ->and($evento->requestedByUserId)->toBe(7);
})->group('RF-IN-06', 'RS-05');

it('las dos negativas de la cancelacion son rechazos de negocio, no averias', function (): void {
    $terminada = ReportExportFixtures::completed();
    $noCancelable = new ReportExportNotCancellable($terminada);

    expect($noCancelable)->toBeInstanceOf(BusinessRejection::class)
        ->and($noCancelable->current)->toBe($terminada)
        ->and(ReportExportWasCancelled::during('019a12b4-5c6d-7e8f-9012-3456789abcde'))->toBeInstanceOf(BusinessRejection::class)
        ->and(ReportExportWasCancelled::during('019a12b4-5c6d-7e8f-9012-3456789abcde')->getMessage())
        ->toContain('019a12b4-5c6d-7e8f-9012-3456789abcde');
})->group('RF-IN-06');
