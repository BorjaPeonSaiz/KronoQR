<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\ValueObject\AnomalyType;
use App\Modules\Compliance\Domain\Exception\InvalidIncident;
use App\Modules\Compliance\Domain\Exception\UnknownIncidentType;
use App\Modules\Compliance\Domain\Model\Incident;
use App\Modules\Compliance\Domain\ValueObject\IncidentSeverity;
use App\Modules\Compliance\Domain\ValueObject\IncidentStatus;
use App\Modules\Compliance\Domain\ValueObject\IncidentType;
use Tests\Support\Time\Instants;

/*
 * La incidencia como objeto de dominio (RF-PR-01, doc 01 §5.1).
 *
 * `Attendance` dice QUE ha pasado con las horas de alguien; `Compliance` dice
 * QUIEN responde de ello y con que urgencia. Estas pruebas van a por la segunda
 * mitad, que es la que vive aqui.
 */

it('nace abierta y con la severidad que decide su tipo', function (): void {
    // La severidad NO la elige quien abre la incidencia: si cada detector
    // pudiera ponerla, el mismo hecho entraria en la bandeja con dos prioridades
    // distintas segun quien lo viera.
    $incident = Incident::open(
        type: IncidentType::OpenShiftExpired,
        employeeUuid: 'employee-1',
        siteId: 1,
        workDate: '2026-03-14',
        shiftEntryUuid: 'shift-entry-1',
        detectedAt: Instants::utc('2026-03-14 19:00'),
    );

    expect($incident->status)->toBe(IncidentStatus::Open)
        ->and($incident->severity)->toBe(IncidentSeverity::Medium)
        ->and($incident->assignedToUserId)->toBeNull()
        ->and($incident->isAboutASingleShiftEntry())->toBeTrue();
})->group('RF-PR-01');

it('reparte la severidad por lo que se rompe si nadie lo mira', function (IncidentType $type, IncidentSeverity $expected): void {
    // El reparto esta escrito en `IncidentType::defaultSeverity()` y esta prueba
    // lo fija: `insufficient_rest` es alta porque es un incumplimiento del art.
    // 34.3 ET con consecuencia sancionadora, y `short_shift` es baja porque el
    // registro es valido y el dato solo es raro.
    expect($type->defaultSeverity())->toBe($expected);
})->with([
    'descanso insuficiente' => [IncidentType::InsufficientRest, IncidentSeverity::High],
    'patron anomalo' => [IncidentType::AnomalousPattern, IncidentSeverity::High],
    'turno abierto' => [IncidentType::OpenShiftExpired, IncidentSeverity::Medium],
    'jornada larga' => [IncidentType::LongShift, IncidentSeverity::Medium],
    'sin pausa' => [IncidentType::MissingBreak, IncidentSeverity::Medium],
    'falta la salida' => [IncidentType::MissingClockOut, IncidentSeverity::Medium],
    'tramo corto' => [IncidentType::ShortShift, IncidentSeverity::Low],
    'reloj desviado' => [IncidentType::ClockSkew, IncidentSeverity::Low],
])->group('RF-PR-01');

it('traduce cada tipo que la deteccion puede emitir', function (AnomalyType $detected): void {
    // Los dos catalogos viven en modulos que no pueden importarse entre si
    // (doc 02 §1.6), asi que la unica forma de que no diverjan es esta prueba.
    // Si alguien anade un tipo en `Attendance` y no aqui, falla al traducirlo.
    expect(IncidentType::fromDetected($detected->value))->toBeInstanceOf(IncidentType::class);
})->with(array_map(
    static fn (AnomalyType $type): array => [$type],
    AnomalyType::cases(),
))->group('RF-PR-01');

it('se niega a traducir un tipo que no esta en el catalogo', function (): void {
    // Falla cerrado: descartar el hallazgo en silencio dejaria una situacion ya
    // detectada sin nadie que la revise.
    expect(fn (): IncidentType => IncidentType::fromDetected('turno_raro'))
        ->toThrow(UnknownIncidentType::class);
})->group('RF-PR-01');

it('acepta quedarse sin responsable, que es un estado legitimo', function (): void {
    // Un departamento sin responsable asignado NO hace que la incidencia se
    // descarte: queda sin asignar y visible en la bandeja (doc 01 §5.5).
    $incident = Incident::open(
        type: IncidentType::LongShift,
        employeeUuid: 'employee-1',
        siteId: 1,
        workDate: '2026-03-14',
        shiftEntryUuid: null,
        detectedAt: Instants::utc('2026-03-14 19:00'),
        assignedToUserId: null,
    );

    expect($incident->assignedToUserId)->toBeNull()
        ->and($incident->assignedTo(7)->assignedToUserId)->toBe(7)
        // La reasignacion no muta: la clase es `readonly` y devuelve otra.
        ->and($incident->assignedToUserId)->toBeNull()
        ->and($incident->isAboutASingleShiftEntry())->toBeFalse();
})->group('RF-PR-01');

it('rechaza lo que nadie podria trabajar', function (callable $build, string $expected): void {
    // Las cuatro guardas responden a la misma pregunta: sin empleado no hay a
    // quien mirar, sin centro no se sabe en que zona ocurrio la jornada (RN-05),
    // con una fecha imposible la bandeja no puede ordenarla, y con un instante
    // que no es UTC el «tiempo hasta resolver» sale desplazado (regla dura 3).
    expect($build)->toThrow($expected);
})->with([
    'sin empleado' => [
        fn (): Incident => Incident::open(
            IncidentType::ShortShift, '  ', 1, '2026-03-14', null, Instants::utc('2026-03-14 19:00')
        ),
        InvalidIncident::class,
    ],
    'sin centro' => [
        fn (): Incident => Incident::open(
            IncidentType::ShortShift, 'employee-1', 0, '2026-03-14', null, Instants::utc('2026-03-14 19:00')
        ),
        InvalidIncident::class,
    ],
    'fecha que no existe' => [
        fn (): Incident => Incident::open(
            IncidentType::ShortShift, 'employee-1', 1, '2026-02-30', null, Instants::utc('2026-03-14 19:00')
        ),
        InvalidIncident::class,
    ],
    'fecha con otro formato' => [
        fn (): Incident => Incident::open(
            IncidentType::ShortShift, 'employee-1', 1, '14/03/2026', null, Instants::utc('2026-03-14 19:00')
        ),
        InvalidIncident::class,
    ],
    'instante que no es UTC' => [
        fn (): Incident => Incident::open(
            IncidentType::ShortShift, 'employee-1', 1, '2026-03-14', null, new DateTimeImmutable('2026-03-14 19:00', new DateTimeZone('Europe/Madrid'))
        ),
        InvalidIncident::class,
    ],
    'responsable imposible' => [
        fn (): Incident => Incident::open(
            IncidentType::ShortShift, 'employee-1', 1, '2026-03-14', null, Instants::utc('2026-03-14 19:00'), 0
        ),
        InvalidIncident::class,
    ],
])->group('RF-PR-01');
