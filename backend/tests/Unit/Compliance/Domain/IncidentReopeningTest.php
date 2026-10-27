<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\Exception\InvalidIncident;
use App\Modules\Compliance\Domain\Model\Incident;
use App\Modules\Compliance\Domain\ValueObject\IncidentSeverity;
use App\Modules\Compliance\Domain\ValueObject\IncidentStatus;
use App\Modules\Compliance\Domain\ValueObject\IncidentType;
use Tests\Support\Time\Instants;

/*
 * El sistema reabre lo que el sistema cerro, y nada mas (RN-19, enmienda del
 * 27-10-2026 de ADR-057 §2).
 *
 * Una `rejected_pin_scan` que la revision cerro porque la jornada quedo
 * subsanada vuelve a `open` si despues aparece otro intento sin subsanar de esa
 * jornada, con el contexto del hallazgo nuevo. Lo que trabajo una persona no se
 * reabre, ni ningun otro tipo. Unitarias: la misma regla la afirma el esquema
 * (`IncidentReopeningGuardTest`).
 */

/** @var array<string, int|string> */
const INCIDENT_REOPENING_NEW_CONTEXT = [
    'scan_id' => '019a0000-0000-7000-8000-0000000000a2',
    'occurred_at' => '2026-03-14T21:00:00Z',
    'attempts' => 1,
    'lockout_attempts' => 0,
    'max_sync_delay_seconds' => 172800,
];

/** Una `rejected_pin_scan` de la jornada del 14, detectada el 15 a las 03:30 UTC. */
function reaperturaAbierta(IncidentType $type = IncidentType::RejectedPinScan): Incident
{
    return Incident::open(
        type: $type,
        employeeUuid: 'employee-1',
        siteId: 1,
        workDate: '2026-03-14',
        shiftEntryUuid: null,
        detectedAt: Instants::utc('2026-03-15 03:30'),
        assignedToUserId: 7,
        context: ['scan_id' => '019a0000-0000-7000-8000-0000000000a1', 'attempts' => 1, 'lockout_attempts' => 0],
    );
}

/** Cerrada como la cierra la revision: sin autor ni nota. */
function reaperturaCerradaPorElSistema(): Incident
{
    return reaperturaAbierta()->closedAsRecovered(Instants::utc('2026-03-16 03:30'));
}

it('reabre la rejected_pin_scan que cerro el sistema, sin resolucion y con el contexto nuevo', function (): void {
    $cerrada = reaperturaCerradaPorElSistema();
    $reabierta = $cerrada->reopenedBySystem(INCIDENT_REOPENING_NEW_CONTEXT);

    expect($cerrada->wasClosedBySystem())->toBeTrue()
        ->and($reabierta->status)->toBe(IncidentStatus::Open)
        ->and($reabierta->resolvedAt)->toBeNull()
        ->and($reabierta->resolvedByUserId)->toBeNull()
        ->and($reabierta->resolutionNote)->toBeNull()
        ->and($reabierta->resolutionSeconds())->toBeNull()
        ->and($reabierta->context)->toBe(INCIDENT_REOPENING_NEW_CONTEXT)
        ->and($reabierta->wasClosedBySystem())->toBeFalse()
        // Lo demas es la misma incidencia: se detecto cuando se detecto.
        ->and($reabierta->detectedAt)->toEqual($cerrada->detectedAt)
        ->and($reabierta->type)->toBe($cerrada->type)
        ->and($reabierta->severity)->toBe($cerrada->severity)
        ->and($reabierta->employeeUuid)->toBe($cerrada->employeeUuid)
        ->and($reabierta->siteId)->toBe($cerrada->siteId)
        ->and($reabierta->workDate)->toBe($cerrada->workDate)
        ->and($reabierta->shiftEntryUuid)->toBe($cerrada->shiftEntryUuid)
        ->and($reabierta->assignedToUserId)->toBe($cerrada->assignedToUserId)
        ->and($reabierta->deviceUuid)->toBe($cerrada->deviceUuid)
        // Y la que se reabrio se puede volver a cerrar sola.
        ->and($reabierta->closedAsRecovered(Instants::utc('2026-03-18 03:30'))->wasClosedBySystem())->toBeTrue();
})->group('RN-19');

it('no reabre lo que resolvio o descarto una persona', function (IncidentStatus $outcome): void {
    $trabajada = reaperturaAbierta()->resolvedBy($outcome, 7, 'Revisado con la persona.', Instants::utc('2026-03-15 09:00'));

    expect($trabajada->wasClosedBySystem())->toBeFalse()
        ->and(fn (): Incident => $trabajada->reopenedBySystem(INCIDENT_REOPENING_NEW_CONTEXT))
        ->toThrow(InvalidIncident::class, 'nada resuelta por una persona se puede reabrir');
})->with([
    'resuelta' => [IncidentStatus::Resolved],
    'descartada' => [IncidentStatus::Dismissed],
])->group('RN-19', 'RN-13');

it('no reabre una resuelta a la que solo le falta la nota o solo el autor', function (?int $userId, ?string $note): void {
    // Ninguna de las dos existe en el producto —una persona deja las dos, el
    // sistema ninguna—, pero restaurada de la tabla tiene que fallar del lado
    // seguro: solo «ni autor ni nota» es un cierre del sistema.
    $fila = Incident::restore(
        type: IncidentType::RejectedPinScan,
        severity: IncidentSeverity::Medium,
        status: IncidentStatus::Resolved,
        employeeUuid: 'employee-1',
        siteId: 1,
        workDate: '2026-03-14',
        shiftEntryUuid: null,
        detectedAt: Instants::utc('2026-03-15 03:30'),
        assignedToUserId: 7,
        context: ['attempts' => 1],
        resolvedAt: Instants::utc('2026-03-16 03:30'),
        resolvedByUserId: $userId,
        resolutionNote: $note,
    );

    expect($fila->wasClosedBySystem())->toBeFalse()
        ->and(fn (): Incident => $fila->reopenedBySystem(INCIDENT_REOPENING_NEW_CONTEXT))
        ->toThrow(InvalidIncident::class);
})->with([
    'con autor y sin nota' => [7, null],
    'sin autor y con nota' => [null, 'Revisado.'],
])->group('RN-19');

it('no reabre una abierta', function (): void {
    expect(fn (): Incident => reaperturaAbierta()->reopenedBySystem(INCIDENT_REOPENING_NEW_CONTEXT))
        ->toThrow(InvalidIncident::class, 'esta «open»');
})->group('RN-19');

it('no reabre ningun otro tipo, aunque este resuelto sin autor ni nota', function (): void {
    // No se puede cerrar asi por el dominio; restaurada de la tabla, si.
    $fila = Incident::restore(
        type: IncidentType::OutOfOrderScan,
        severity: IncidentSeverity::Medium,
        status: IncidentStatus::Resolved,
        employeeUuid: 'employee-1',
        siteId: 1,
        workDate: '2026-03-14',
        shiftEntryUuid: null,
        detectedAt: Instants::utc('2026-03-15 03:30'),
        assignedToUserId: 7,
        context: ['scans' => 1],
        resolvedAt: Instants::utc('2026-03-16 03:30'),
        resolvedByUserId: null,
        resolutionNote: null,
    );

    expect($fila->wasClosedBySystem())->toBeTrue()
        ->and(fn (): Incident => $fila->reopenedBySystem(INCIDENT_REOPENING_NEW_CONTEXT))
        ->toThrow(InvalidIncident::class, 'out_of_order_scan');
})->group('RN-19');

it('no reabre sin el contexto del hallazgo nuevo', function (): void {
    expect(fn (): Incident => reaperturaCerradaPorElSistema()->reopenedBySystem([]))
        ->toThrow(InvalidIncident::class, 'datos del hallazgo nuevo');
})->group('RN-19');
