<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\Exception\IncidentAlreadyClosed;
use App\Modules\Compliance\Domain\Exception\InvalidIncident;
use App\Modules\Compliance\Domain\Model\Incident;
use App\Modules\Compliance\Domain\ValueObject\IncidentSeverity;
use App\Modules\Compliance\Domain\ValueObject\IncidentStatus;
use App\Modules\Compliance\Domain\ValueObject\IncidentType;
use Tests\Support\Time\Instants;

/*
 * La transicion de estado de una incidencia (**RF-PA-05**, RN-13, tarea 2.5).
 *
 * Unitarias y sin base de datos: lo que aqui se comprueba son las invariantes del
 * agregado, que es donde tienen que estar para que no dependan de que alguien se
 * acuerde de repetirlas en cada camino. Que ademas el esquema las afirme —los dos
 * `CHECK` de `incidents`— y que el `UPDATE` resuelva la carrera se prueba en la
 * suite de integracion, que es donde eso se puede ejercitar de verdad.
 *
 * **El reloj entra por parametro** (regla dura 2). Sin eso, «cuanto tardo en
 * resolverse» seria una cifra que cambia segun el momento en que corra la suite,
 * y esa cifra es la que observa `incident_resolution_seconds`.
 */

/** Una incidencia abierta, detectada el 15 de marzo a las 03:30 UTC. */
function incidenciaAbierta(IncidentType $type = IncidentType::InsufficientRest): Incident
{
    return Incident::open(
        type: $type,
        employeeUuid: 'employee-1',
        siteId: 1,
        workDate: '2026-03-14',
        shiftEntryUuid: 'shift-entry-1',
        detectedAt: Instants::utc('2026-03-15 03:30'),
        assignedToUserId: 7,
        context: ['rest_minutes' => 420, 'threshold_minutes' => 720],
    );
}

it('la cierra una persona, con su momento y su nota', function (): void {
    // RN-13: quien, cuando y por que. Es la traza que convierte «la bandeja esta
    // vacia» en algo que se puede explicar seis meses despues.
    $resuelta = incidenciaAbierta()->resolvedBy(
        outcome: IncidentStatus::Resolved,
        userId: 7,
        note: 'Corregida la salida olvidada del dia 14 con el parte de turno.',
        at: Instants::utc('2026-03-15 08:12'),
    );

    expect($resuelta->status)->toBe(IncidentStatus::Resolved)
        ->and($resuelta->resolvedByUserId)->toBe(7)
        ->and($resuelta->resolvedAt)->toEqual(Instants::utc('2026-03-15 08:12'))
        ->and($resuelta->resolutionNote)->toBe('Corregida la salida olvidada del dia 14 con el parte de turno.');
})->group('RF-PA-05', 'RN-13');

it('distingue resolver de descartar, que no significan lo mismo', function (): void {
    // «Habia algo y se ha arreglado» frente a «se ha mirado y no habia nada». Con
    // un solo desenlace, el «tiempo medio hasta resolver» del doc 01 §9.2
    // mezclaria trabajo real con falsos positivos y no serviria para ajustar los
    // umbrales.
    $descartada = incidenciaAbierta()->resolvedBy(
        outcome: IncidentStatus::Dismissed,
        userId: 7,
        note: 'Doblo turno con autorizacion escrita del jefe de departamento.',
        at: Instants::utc('2026-03-15 08:12'),
    );

    expect($descartada->status)->toBe(IncidentStatus::Dismissed)
        ->and($descartada->status->isOpen())->toBeFalse()
        // Y la nota es obligatoria tambien aqui: descartar sin explicar por que
        // es lo mismo que borrar.
        ->and($descartada->resolutionNote)->not->toBeNull();
})->group('RF-PA-05');

it('no deja resolver dos veces la misma incidencia', function (): void {
    // La invariante que impide que dos pestañas abiertas sobre la misma bandeja
    // dejen dos notas encadenadas sobre el mismo hecho, la segunda tapando a la
    // primera en una tabla donde nada se sobrescribe (regla dura 5).
    $resuelta = incidenciaAbierta()->resolvedBy(
        outcome: IncidentStatus::Resolved,
        userId: 7,
        note: 'La primera nota, que es la que vale.',
        at: Instants::utc('2026-03-15 08:12'),
    );

    expect(fn (): Incident => $resuelta->resolvedBy(
        outcome: IncidentStatus::Dismissed,
        userId: 9,
        note: 'La segunda, que no deberia poder escribirse.',
        at: Instants::utc('2026-03-15 09:00'),
    ))->toThrow(IncidentAlreadyClosed::class);
})->group('RF-PA-05', 'RN-13');

it('tampoco deja descartar una ya descartada', function (): void {
    // Los dos desenlaces son finales, no solo `resolved`.
    $descartada = incidenciaAbierta()->resolvedBy(
        outcome: IncidentStatus::Dismissed,
        userId: 7,
        note: 'Se ha mirado y no habia nada.',
        at: Instants::utc('2026-03-15 08:12'),
    );

    expect(fn (): Incident => $descartada->resolvedBy(
        outcome: IncidentStatus::Resolved,
        userId: 7,
        note: 'Cambio de opinion, que no es un camino.',
        at: Instants::utc('2026-03-15 09:00'),
    ))->toThrow(IncidentAlreadyClosed::class);
})->group('RF-PA-05');

it('rechaza lo que dejaria una fila que no explica nada', function (callable $close, string $expected): void {
    expect($close)->toThrow(InvalidIncident::class, $expected);
})->with([
    'reabrir no es un desenlace' => [
        fn (): Incident => incidenciaAbierta()->resolvedBy(
            IncidentStatus::Open,
            7,
            'Vuelvo a dejarla abierta.',
            Instants::utc('2026-03-15 08:12'),
        ),
        'resuelta o como descartada',
    ],
    'sin nota' => [
        fn (): Incident => incidenciaAbierta()->resolvedBy(
            IncidentStatus::Resolved,
            7,
            '',
            Instants::utc('2026-03-15 08:12'),
        ),
        'exige una nota',
    ],
    'una nota de espacios en blanco no es una nota' => [
        fn (): Incident => incidenciaAbierta()->resolvedBy(
            IncidentStatus::Resolved,
            7,
            "   \t  ",
            Instants::utc('2026-03-15 08:12'),
        ),
        'exige una nota',
    ],
    'sin autor' => [
        fn (): Incident => incidenciaAbierta()->resolvedBy(
            IncidentStatus::Resolved,
            0,
            'Firmada por nadie.',
            Instants::utc('2026-03-15 08:12'),
        ),
        'identificador positivo',
    ],
    'resuelta antes de detectarse' => [
        fn (): Incident => incidenciaAbierta()->resolvedBy(
            IncidentStatus::Resolved,
            7,
            'Viaje en el tiempo.',
            Instants::utc('2026-03-15 03:29'),
        ),
        'no se resuelve antes de detectarse',
    ],
])->group('RF-PA-05', 'RN-13');

it('recorta la nota antes de guardarla', function (): void {
    // Lo que llega a `audit_log` y a la tabla es la nota, no los espacios con los
    // que alguien la pego desde otra ventana.
    $resuelta = incidenciaAbierta()->resolvedBy(
        outcome: IncidentStatus::Resolved,
        userId: 7,
        note: "  Revisado con el parte de turno.\n",
        at: Instants::utc('2026-03-15 08:12'),
    );

    expect($resuelta->resolutionNote)->toBe('Revisado con el parte de turno.');
})->group('RF-PA-05');

it('mide lo que tardo en trabajarse, y nada mientras siga abierta', function (): void {
    // Es lo que observa `incident_resolution_seconds{type}` (doc 02 §8.2) y lo
    // que alimenta el objetivo «< 24 h» del doc 01 §1.3. Abierta devuelve `null`
    // y no cero: no ha tardado nada es distinto de todavia no ha terminado, y un
    // cero en el histograma diria lo contrario.
    $abierta = incidenciaAbierta();

    $resuelta = $abierta->resolvedBy(
        outcome: IncidentStatus::Resolved,
        userId: 7,
        note: 'Revisado.',
        at: Instants::utc('2026-03-16 03:30'),
    );

    expect($abierta->resolutionSeconds())->toBeNull()
        // Exactamente 24 h.
        ->and($resuelta->resolutionSeconds())->toBe(86400);
})->group('RF-PA-05');

it('no toca nada mas de la incidencia al cerrarla', function (): void {
    // Resolver no reclasifica: la incidencia sigue siendo la que se detecto, con
    // su tipo, su severidad, su jornada, su responsable y los numeros con los que
    // se abrio. Lo unico que ha pasado es que alguien la ha mirado (RN-08).
    $abierta = incidenciaAbierta(IncidentType::LongShift);

    $resuelta = $abierta->resolvedBy(
        outcome: IncidentStatus::Resolved,
        userId: 9,
        note: 'Revisado.',
        at: Instants::utc('2026-03-15 08:12'),
    );

    expect($resuelta->type)->toBe(IncidentType::LongShift)
        ->and($resuelta->severity)->toBe(IncidentSeverity::Medium)
        ->and($resuelta->employeeUuid)->toBe($abierta->employeeUuid)
        ->and($resuelta->workDate)->toBe($abierta->workDate)
        ->and($resuelta->shiftEntryUuid)->toBe($abierta->shiftEntryUuid)
        ->and($resuelta->detectedAt)->toEqual($abierta->detectedAt)
        // El responsable sigue siendo el del departamento aunque la cierre otra
        // persona: son dos preguntas distintas.
        ->and($resuelta->assignedToUserId)->toBe(7)
        ->and($resuelta->context)->toBe($abierta->context)
        // Y la de partida no cambia: la clase es `readonly` y devuelve otra.
        ->and($abierta->status)->toBe(IncidentStatus::Open);
})->group('RF-PA-05', 'RN-08');

it('reconstruye una fila cerrada tal y como esta escrita', function (): void {
    // `restore()` y no `open()`: aquella decide la severidad otra vez y devuelve
    // el estado inicial, asi que una fila resuelta volveria a la bandeja como
    // abierta y una severidad que el catalogo cambiara mañana reescribiria en
    // silencio la que se aplico al detectarla.
    $reconstruida = Incident::restore(
        type: IncidentType::ShortShift,
        // Una severidad que hoy NO es la que el tipo decidiria: es justo lo que
        // esta prueba fija.
        severity: IncidentSeverity::High,
        status: IncidentStatus::Dismissed,
        employeeUuid: 'employee-1',
        siteId: 1,
        workDate: '2026-03-14',
        shiftEntryUuid: null,
        detectedAt: Instants::utc('2026-03-15 03:30'),
        assignedToUserId: 7,
        context: ['worked_minutes' => 12],
        resolvedAt: Instants::utc('2026-03-15 08:12'),
        resolvedByUserId: 9,
        resolutionNote: 'Se ha mirado y no habia nada.',
    );

    expect($reconstruida->severity)->toBe(IncidentSeverity::High)
        ->and($reconstruida->status)->toBe(IncidentStatus::Dismissed)
        ->and($reconstruida->resolvedByUserId)->toBe(9)
        ->and($reconstruida->isAboutASingleShiftEntry())->toBeFalse();
})->group('RF-PA-05');
