<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Exception\CorrectionWouldChangeWorkDate;
use App\Modules\Attendance\Domain\Exception\EntryDoesNotContinueWorkDay;
use App\Modules\Attendance\Domain\Exception\OverlappingShiftEntry;
use App\Modules\Attendance\Domain\Exception\ShiftAlreadyOpen;
use App\Modules\Attendance\Domain\Model\ShiftEntry;
use App\Modules\Attendance\Domain\Model\WorkDay;
use App\Modules\Attendance\Domain\ValueObject\EntryClosure;
use App\Modules\Attendance\Domain\ValueObject\FutureMark;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Domain\ValueObject\ShiftTimes;
use Tests\Support\Factory\ClockingPolicyFactory;
use Tests\Support\Factory\ContinuationCeilingFactory;
use Tests\Support\Factory\CorrectionFactory;
use Tests\Support\Factory\ShiftEntryFactory;
use Tests\Support\Factory\WorkDayFactory;
use Tests\Support\Time\Instants;

/*
 * RN-05 en el alta y la correccion manuales: la cota de continuacion (2.3.0,
 * V21-3-AR-1, V21-2-QA-1, R2-QB-04).
 *
 * Un tramo que no abre la jornada puede empezar en otro dia civil —la vuelta de
 * una pausa a las 02:30 es de la jornada que empezo ayer a las 22:00—, pero solo
 * si la continua: su entrada queda a menos del descanso minimo entre jornadas
 * (12 h en `ES-hosteleria`) de la salida del tramo anterior. Es la misma cota
 * que el quiosco aplica a la vuelta de una pausa. Sin ella, un responsable podia
 * colgar de la jornada del 10 un tramo del 13.
 *
 * Todas las horas son de Madrid salvo que se diga otra cosa: la jornada es una
 * fecha civil del centro (RN-05) y es ahi donde se ve la medianoche.
 */

/** Da de alta un tramo cerrado en la jornada, con la cota de 12 h. */
function continuacionAlta(WorkDay $workDay, string $desdeMadrid, ?string $hastaMadrid, bool $terminaEnPausa = false): ShiftEntry
{
    return $workDay->addEntry(
        'shift-entry-nuevo-'.$desdeMadrid,
        $hastaMadrid === null
            ? ShiftTimes::open(Instants::inMadrid($desdeMadrid))
            : ShiftTimes::closed(
                Instants::inMadrid($desdeMadrid),
                Instants::inMadrid($hastaMadrid),
                $terminaEnPausa ? EntryClosure::BREAK_START : EntryClosure::CLOCK_OUT,
            ),
        ScanOrigin::MANUAL_ADMIN,
        CorrectionFactory::standard(),
        ClockingPolicyFactory::standard(),
        ContinuationCeilingFactory::standard(),
    );
}

/** Corrige un tramo de la jornada con la cota de 12 h. */
function continuacionCorreccion(WorkDay $workDay, string $entryUuid, ShiftTimes $times): ShiftEntry
{
    return $workDay->correctEntry(
        $entryUuid,
        $entryUuid.'-v2',
        $times,
        ScanOrigin::MANUAL_ADMIN,
        CorrectionFactory::standard(),
        ClockingPolicyFactory::standard(),
        ContinuationCeilingFactory::standard(),
    );
}

/** La excepcion de la cota; si `$accion` no la lanza, la prueba falla. */
function continuacionRechazo(Closure $accion): EntryDoesNotContinueWorkDay
{
    try {
        $accion();
    } catch (EntryDoesNotContinueWorkDay $exception) {
        return $exception;
    }

    throw new RuntimeException('Se esperaba EntryDoesNotContinueWorkDay y no se lanzo nada.');
}

/**
 * La jornada del 10 de octubre con un tramo de 08:00 a 15:00 que termino en
 * pausa: la cota es lo unico que decide si el tramo del dia siguiente continua.
 */
function continuacionJornadaDelDiez(): WorkDay
{
    return WorkDayFactory::new()
        ->onWorkDate('2026-10-10')
        ->withShift(ShiftEntryFactory::new()->workedBetween(
            Instants::inMadrid('2026-10-10 08:00'),
            Instants::inMadrid('2026-10-10 15:00'),
        )->endingInBreak())
        ->build();
}

it('rechaza dar de alta en una jornada vacia un tramo que empieza otro dia', function (): void {
    $jornada = WorkDayFactory::new()->onWorkDate('2026-03-14')->build();

    // Sin tramo anterior no hay nada que continuar: lo decide la guarda de la
    // apertura, porque ese tramo abriria la jornada del 16.
    expect(fn (): ShiftEntry => continuacionAlta($jornada, '2026-03-16 08:00', '2026-03-16 15:00'))
        ->toThrow(CorrectionWouldChangeWorkDate::class);

    expect($jornada->shiftCount())->toBe(0)
        ->and($jornada->releaseEvents())->toBe([]);
})->group('RN-05', 'RF-PA-04');

it('rechaza dar de alta un tramo que pisa a otro y un abierto sobre otro abierto', function (): void {
    $pisada = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withShift(ShiftEntryFactory::new()->workedBetween(
            Instants::inMadrid('2026-03-14 08:00'),
            Instants::inMadrid('2026-03-14 15:00'),
        ))
        ->build();

    expect(fn (): ShiftEntry => continuacionAlta($pisada, '2026-03-14 14:00', '2026-03-14 18:00'))
        ->toThrow(OverlappingShiftEntry::class);

    $abierta = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withShift(ShiftEntryFactory::new()->openSince('2026-03-14 07:00'))
        ->build();

    expect(fn (): ShiftEntry => continuacionAlta($abierta, '2026-03-14 06:00', null))
        ->toThrow(ShiftAlreadyOpen::class);
})->group('RN-01', 'RN-02', 'RF-PA-04');

it('admite la entrada del dia siguiente un segundo antes de la cota y la rechaza en la cota', function (): void {
    // Salida a las 15:00 del 10 y descanso minimo de 12 h: la jornada se puede
    // continuar hasta las 02:59:59 del 11; a las 03:00:00 ya es otra.
    $admitida = continuacionJornadaDelDiez();
    $entry = continuacionAlta($admitida, '2026-10-11 02:59:59', '2026-10-11 04:00');

    expect($entry->workDate()->isoDate)->toBe('2026-10-10')
        ->and($admitida->shiftCount())->toBe(2);

    $rechazada = continuacionJornadaDelDiez();
    $rechazo = continuacionRechazo(fn (): ShiftEntry => continuacionAlta($rechazada, '2026-10-11 03:00', '2026-10-11 04:00'));

    expect($rechazo->mark)->toBe(FutureMark::ClockIn)
        ->and($rechazo->workDate)->toBe('2026-10-10')
        ->and($rechazo->minimumRestMinutes)->toBe(720)
        ->and($rechazo->lastMark)->toEqual(Instants::inMadrid('2026-10-10 15:00'))
        ->and($rechazo->translationKey)->toBe('attendance.errors.entry_does_not_continue_work_day')
        ->and($rechazo->parameters)->toBe(['work_date' => '2026-10-10', 'hours' => '12'])
        // Tecnico, en ingles y sin datos de la persona (regla dura 21).
        ->and($rechazo->getMessage())->toBe(
            'A shift entry starting at 2026-10-11T01:00:00+00:00 does not continue work day 2026-10-10: the '
            .'previous mark 2026-10-10T13:00:00+00:00 is 720 minutes or more before it (RN-05).',
        )
        ->and($rechazada->shiftCount())->toBe(1)
        ->and($rechazada->releaseEvents())->toBe([]);
})->group('RN-05', 'RF-PA-04');

it('rechaza colgar de la jornada del 10 un tramo del 13, en el alta y en la correccion', function (): void {
    $alta = continuacionJornadaDelDiez();

    expect(continuacionRechazo(fn (): ShiftEntry => continuacionAlta($alta, '2026-10-13 08:00', '2026-10-13 15:00'))->mark)
        ->toBe(FutureMark::ClockIn);

    // Con un solo tramo, moverlo al 13 es mover la apertura de la jornada.
    $unico = continuacionJornadaDelDiez();

    expect(fn (): ShiftEntry => continuacionCorreccion($unico, 'shift-entry-1', ShiftTimes::closed(
        Instants::inMadrid('2026-10-13 08:00'),
        Instants::inMadrid('2026-10-13 15:00'),
    )))->toThrow(CorrectionWouldChangeWorkDate::class);

    // Con dos, el segundo no abre la jornada: lo para la cota.
    $dos = WorkDayFactory::new()
        ->onWorkDate('2026-10-10')
        ->withShift(ShiftEntryFactory::new()->workedBetween(
            Instants::inMadrid('2026-10-10 08:00'),
            Instants::inMadrid('2026-10-10 12:00'),
        ))
        ->withShift(ShiftEntryFactory::new()->workedBetween(
            Instants::inMadrid('2026-10-10 13:00'),
            Instants::inMadrid('2026-10-10 15:00'),
        ))
        ->build();

    $rechazo = continuacionRechazo(fn (): ShiftEntry => continuacionCorreccion($dos, 'shift-entry-2', ShiftTimes::closed(
        Instants::inMadrid('2026-10-13 08:00'),
        Instants::inMadrid('2026-10-13 15:00'),
    )));

    expect($rechazo->mark)->toBe(FutureMark::ClockIn)
        ->and($dos->retiredEntries())->toBe([])
        ->and($dos->entry('shift-entry-2')->version())->toBe(1)
        ->and($dos->releaseEvents())->toBe([]);
})->group('RN-05', 'RF-PA-04');

it('admite el turno de noche y la vuelta de su pausa de madrugada', function (): void {
    // Regla dura 4: un tramo que empieza el dia de la jornada no tiene cota.
    $noche = WorkDayFactory::new()->onWorkDate('2026-03-14')->build();
    $turno = continuacionAlta($noche, '2026-03-14 22:00', '2026-03-15 02:00', terminaEnPausa: true);

    // Y la vuelta a las 02:30 continua la jornada del 14 (ADR-024).
    $vuelta = continuacionAlta($noche, '2026-03-15 02:30', '2026-03-15 06:00');

    expect($turno->workDate()->isoDate)->toBe('2026-03-14')
        ->and($vuelta->workDate()->isoDate)->toBe('2026-03-14')
        ->and($noche->totalWorked()->minutes)->toBe(450);
})->group('RN-05', 'RF-PA-04');

it('rechaza acortar una salida si la vuelta del dia siguiente queda en la cota o mas alla', function (string $salida, bool $admite): void {
    // La vuelta de las 02:30 del 15 continua el tramo de 08:00 a 15:00 del 14
    // (11 h 30 min). Adelantar la salida estira ese hueco: a las 14:30 son
    // 12 h justas y la vuelta deja de continuar la jornada. El tramo termina
    // en pausa antes y despues: lo que se prueba es la cota.
    $jornada = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withShift(ShiftEntryFactory::new()->workedBetween(
            Instants::inMadrid('2026-03-14 08:00'),
            Instants::inMadrid('2026-03-14 15:00'),
        )->endingInBreak())
        ->withShift(ShiftEntryFactory::new()->workedBetween(
            Instants::inMadrid('2026-03-15 02:30'),
            Instants::inMadrid('2026-03-15 04:00'),
        ))
        ->build();

    $correccion = fn (): ShiftEntry => continuacionCorreccion($jornada, 'shift-entry-1', ShiftTimes::closed(
        Instants::inMadrid('2026-03-14 08:00'),
        Instants::inMadrid($salida),
        EntryClosure::BREAK_START,
    ));

    if ($admite) {
        expect($correccion()->clockedOutAt())->toEqual(Instants::inMadrid($salida));

        return;
    }

    $rechazo = continuacionRechazo($correccion);

    // El que deja de continuar es el siguiente, y el mensaje lo dice.
    expect($rechazo->mark)->toBe(FutureMark::ClockOut)
        ->and($rechazo->translationKey)->toBe('attendance.errors.next_entry_would_not_continue_work_day')
        ->and($jornada->entry('shift-entry-1')->version())->toBe(1);
})->with([
    'un minuto antes de la cota' => ['2026-03-14 14:31', true],
    'en la cota' => ['2026-03-14 14:30', false],
    'mas alla de la cota' => ['2026-03-14 14:00', false],
])->group('RN-05', 'RF-PA-04');

it('rechaza mover un tramo si el que venia detras deja de continuar la jornada', function (): void {
    // El tramo de 02:00 del 15 continua el de 18:00 a 23:00 del 14 (3 h). Si
    // ese tramo se mueve detras de el, su anterior pasa a ser el de 06:00 a
    // 08:00 del 14, a 18 h: la vuelta ya no es de esta jornada. Todos terminan
    // en pausa: lo que se prueba es la cota.
    $jornada = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withShift(ShiftEntryFactory::new()->workedBetween(
            Instants::inMadrid('2026-03-14 06:00'),
            Instants::inMadrid('2026-03-14 08:00'),
        )->endingInBreak())
        ->withShift(ShiftEntryFactory::new()->workedBetween(
            Instants::inMadrid('2026-03-14 18:00'),
            Instants::inMadrid('2026-03-14 23:00'),
        )->endingInBreak())
        ->withShift(ShiftEntryFactory::new()->workedBetween(
            Instants::inMadrid('2026-03-15 02:00'),
            Instants::inMadrid('2026-03-15 04:00'),
        )->endingInBreak())
        ->build();

    $rechazo = continuacionRechazo(fn (): ShiftEntry => continuacionCorreccion($jornada, 'shift-entry-2', ShiftTimes::closed(
        Instants::inMadrid('2026-03-15 05:00'),
        Instants::inMadrid('2026-03-15 06:00'),
    )));

    expect($rechazo->mark)->toBe(FutureMark::ClockIn)
        ->and($rechazo->lastMark)->toEqual(Instants::inMadrid('2026-03-14 08:00'))
        ->and($rechazo->translationKey)->toBe('attendance.errors.next_entry_would_not_continue_work_day')
        ->and($jornada->shiftCount())->toBe(3);
})->group('RN-05', 'RF-PA-04');

it('no tropieza con un dato antiguo que ya violaba la cota mas adelante en la jornada', function (): void {
    // La comprobacion es local: mira el tramo corregido, el que le sigue y el
    // que seguia a su version anterior, no la jornada entera. Un tramo antiguo
    // del 16, escrito antes de la 2.3.0 y que ya no continuaba nada, no impide
    // corregir la salida del primer tramo del dia (doc 01, RN-05).
    $jornada = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withShift(ShiftEntryFactory::new()->workedBetween(
            Instants::inMadrid('2026-03-14 08:00'),
            Instants::inMadrid('2026-03-14 12:00'),
        ))
        ->withShift(ShiftEntryFactory::new()->workedBetween(
            Instants::inMadrid('2026-03-14 18:00'),
            Instants::inMadrid('2026-03-14 23:00'),
        ))
        ->withShift(ShiftEntryFactory::new()->workedBetween(
            Instants::inMadrid('2026-03-15 01:00'),
            Instants::inMadrid('2026-03-15 03:00'),
        ))
        ->withShift(ShiftEntryFactory::new()->workedBetween(
            Instants::inMadrid('2026-03-16 08:00'),
            Instants::inMadrid('2026-03-16 10:00'),
        ))
        ->build();

    // Acortar el primero no cambia el anterior de nadie, asi que el tramo del
    // 16 no se vuelve a juzgar.
    $corregido = continuacionCorreccion($jornada, 'shift-entry-1', ShiftTimes::closed(
        Instants::inMadrid('2026-03-14 08:00'),
        Instants::inMadrid('2026-03-14 11:00'),
    ));

    expect($corregido->version())->toBe(2);
})->group('RN-05', 'RF-PA-04');

it('admite dos tramos del mismo dia civil aunque entre ellos medien 13 horas', function (): void {
    // La cota es solo para lo que empieza otro dia. Dentro del dia de la
    // jornada, un hueco largo no la parte en dos (doc 01, nota sobre RN-10).
    $jornada = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withShift(ShiftEntryFactory::new()->workedBetween(
            Instants::inMadrid('2026-03-14 06:00'),
            Instants::inMadrid('2026-03-14 08:00'),
        ))
        ->build();

    $entry = continuacionAlta($jornada, '2026-03-14 21:00', '2026-03-14 23:00');

    expect($entry->workDate()->isoDate)->toBe('2026-03-14')
        ->and($jornada->shiftCount())->toBe(2);
})->group('RN-05', 'RF-PA-04');

it('mide la cota en horas reales la noche del cambio de hora de octubre', function (string $vueltaUtc, bool $admite): void {
    // Jornada del 24-10-2026. Salida a la 01:30 CEST del 25 (23:30 UTC del 24).
    // Esa noche el reloj repite una hora, pero el descanso no: 12 h reales
    // acaban a las 11:30 UTC, las 12:30 CET. Las horas en UTC no son ambiguas.
    $jornada = WorkDayFactory::new()
        ->onWorkDate('2026-10-24')
        ->withShift(ShiftEntryFactory::new()->workedBetween(
            Instants::utc('2026-10-24 16:00'),
            Instants::utc('2026-10-24 23:30'),
        )->endingInBreak())
        ->build();

    $alta = fn (): ShiftEntry => $jornada->addEntry(
        'shift-entry-nuevo',
        ShiftTimes::closed(Instants::utc($vueltaUtc), Instants::utc('2026-10-25 14:00')),
        ScanOrigin::MANUAL_ADMIN,
        CorrectionFactory::standard(),
        ClockingPolicyFactory::standard(),
        ContinuationCeilingFactory::standard(),
    );

    if ($admite) {
        expect($alta()->workDate()->isoDate)->toBe('2026-10-24');

        return;
    }

    expect(continuacionRechazo($alta)->mark)->toBe(FutureMark::ClockIn);
})->with([
    'a las 12:29 CET' => ['2026-10-25 11:29', true],
    'a las 12:30 CET' => ['2026-10-25 11:30', false],
])->group('RN-05', 'RN-09', 'RF-PA-04');

it('aplica la cota del perfil, no una constante', function (): void {
    // Con un convenio de 10 h, la vuelta a las 01:30 del 11 tras salir a las
    // 15:00 del 10 ya no continua: 10 h 30 min (regla dura 14).
    $jornada = continuacionJornadaDelDiez();

    $rechazo = continuacionRechazo(fn (): ShiftEntry => $jornada->addEntry(
        'shift-entry-nuevo',
        ShiftTimes::closed(Instants::inMadrid('2026-10-11 01:30'), Instants::inMadrid('2026-10-11 03:00')),
        ScanOrigin::MANUAL_ADMIN,
        CorrectionFactory::standard(),
        ClockingPolicyFactory::standard(),
        ContinuationCeilingFactory::ofHours(10),
    ));

    expect($rechazo->minimumRestMinutes)->toBe(600);
})->group('RN-05', 'RF-PA-04');

// --- Anular tambien respeta la cota (2.3.0) ----------------------------------

/**
 * Jornada del 14 con un tramo de 08:00 a 15:00, otro de 18:00 a 23:00 y una
 * vuelta el 15 a la hora que se pida. Hora de Madrid (CET).
 */
function continuacionJornadaConVuelta(string $vueltaMadrid): WorkDay
{
    return WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withShift(ShiftEntryFactory::new()->workedBetween(
            Instants::inMadrid('2026-03-14 08:00'),
            Instants::inMadrid('2026-03-14 15:00'),
        )->endingInBreak())
        ->withShift(ShiftEntryFactory::new()->workedBetween(
            Instants::inMadrid('2026-03-14 18:00'),
            Instants::inMadrid('2026-03-14 23:00'),
        )->endingInBreak())
        ->withShift(ShiftEntryFactory::new()->workedBetween(
            Instants::inMadrid($vueltaMadrid),
            Instants::inMadrid('2026-03-15 06:00'),
        ))
        ->build();
}

function continuacionAnulacion(WorkDay $workDay, string $entryUuid): ShiftEntry
{
    return $workDay->voidEntry($entryUuid, CorrectionFactory::standard(), ContinuationCeilingFactory::standard());
}

it('rechaza anular el tramo intermedio si la vuelta de despues deja de continuar la jornada', function (string $vuelta, bool $admite): void {
    // Sin el tramo de 18:00 a 23:00, la vuelta del 15 se mide contra la salida
    // de las 15:00 del 14: 12 h justas a las 03:00 ya son otra jornada.
    $jornada = continuacionJornadaConVuelta($vuelta);

    if ($admite) {
        expect(continuacionAnulacion($jornada, 'shift-entry-2')->status()->value)->toBe('voided')
            ->and($jornada->shiftCount())->toBe(2);

        return;
    }

    $rechazo = continuacionRechazo(fn (): ShiftEntry => continuacionAnulacion($jornada, 'shift-entry-2'));

    expect($rechazo->mark)->toBe(FutureMark::ClockIn)
        ->and($rechazo->lastMark)->toEqual(Instants::inMadrid('2026-03-14 15:00'))
        // La guarda va antes de tocar nada.
        ->and($jornada->shiftCount())->toBe(3)
        ->and($jornada->retiredEntries())->toBe([])
        ->and($jornada->releaseEvents())->toBe([]);
})->with([
    'un segundo antes de la cota' => ['2026-03-15 02:59:59', true],
    'en la cota' => ['2026-03-15 03:00', false],
    'mas alla de la cota' => ['2026-03-15 04:00', false],
])->group('RN-05', 'RF-PA-04', 'RN-13');

it('admite anular el ultimo tramo aunque la jornada ya no cumpla la cota', function (): void {
    // Es como se arreglan los datos malos: de atras hacia delante.
    $jornada = continuacionJornadaConVuelta('2026-03-15 04:00');

    expect(continuacionAnulacion($jornada, 'shift-entry-3')->status()->value)->toBe('voided')
        ->and(continuacionAnulacion($jornada, 'shift-entry-2')->status()->value)->toBe('voided');
})->group('RN-05', 'RF-PA-04', 'RN-13');

it('admite anular un tramo cuyo siguiente es del dia de la jornada', function (): void {
    // El siguiente empieza el 14: no tiene cota, por lejos que quede del
    // anterior (regla dura 4).
    $jornada = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withShift(ShiftEntryFactory::new()->workedBetween(
            Instants::inMadrid('2026-03-14 06:00'),
            Instants::inMadrid('2026-03-14 07:00'),
        ))
        ->withShift(ShiftEntryFactory::new()->workedBetween(
            Instants::inMadrid('2026-03-14 12:00'),
            Instants::inMadrid('2026-03-14 14:00'),
        ))
        ->withShift(ShiftEntryFactory::new()->workedBetween(
            Instants::inMadrid('2026-03-14 21:00'),
            Instants::inMadrid('2026-03-14 23:00'),
        ))
        ->build();

    expect(continuacionAnulacion($jornada, 'shift-entry-2')->status()->value)->toBe('voided')
        ->and($jornada->shiftCount())->toBe(2);
})->group('RN-05', 'RF-PA-04', 'RN-13');

it('rechaza anular el tramo que abre la jornada si el siguiente empieza otro dia, sin tocar nada', function (): void {
    // Turno de noche del 14 con su vuelta a las 02:30 del 15. Sin el turno, la
    // jornada del 14 quedaria abierta por un tramo del 15 (RN-05).
    $jornada = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withShift(ShiftEntryFactory::new()->workedBetween(
            Instants::inMadrid('2026-03-14 22:00'),
            Instants::inMadrid('2026-03-15 02:00'),
        ))
        ->withShift(ShiftEntryFactory::new()->workedBetween(
            Instants::inMadrid('2026-03-15 02:30'),
            Instants::inMadrid('2026-03-15 06:00'),
        ))
        ->build();

    try {
        continuacionAnulacion($jornada, 'shift-entry-1');
        throw new RuntimeException('Se esperaba CorrectionWouldChangeWorkDate.');
    } catch (CorrectionWouldChangeWorkDate $rechazo) {
        expect($rechazo->byVoiding)->toBeTrue()
            ->and($rechazo->workDate)->toBe('2026-03-14');
    }

    expect($jornada->shiftCount())->toBe(2)
        ->and($jornada->retiredEntries())->toBe([])
        ->and($jornada->releaseEvents())->toBe([]);

    // De atras hacia delante si se puede.
    continuacionAnulacion($jornada, 'shift-entry-2');

    expect(continuacionAnulacion($jornada, 'shift-entry-1')->status()->value)->toBe('voided')
        ->and($jornada->shiftCount())->toBe(0);
})->group('RN-05', 'RF-PA-04', 'RN-13');

it('admite anular el tramo que abre la jornada si el siguiente tambien empieza ese dia', function (): void {
    $jornada = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withShift(ShiftEntryFactory::new()->workedBetween(
            Instants::inMadrid('2026-03-14 06:00'),
            Instants::inMadrid('2026-03-14 07:00'),
        ))
        ->withShift(ShiftEntryFactory::new()->workedBetween(
            Instants::inMadrid('2026-03-14 23:00'),
            Instants::inMadrid('2026-03-15 02:00'),
        ))
        ->build();

    expect(continuacionAnulacion($jornada, 'shift-entry-1')->status()->value)->toBe('voided');
})->group('RN-05', 'RF-PA-04', 'RN-13');

it('da el hueco que un tramo de otro dia dice continuar, y ninguno si no continua nada', function (): void {
    $jornada = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withShift(ShiftEntryFactory::new()->workedBetween(
            Instants::inMadrid('2026-03-14 22:00'),
            Instants::inMadrid('2026-03-15 00:00'),
        ))
        ->withShift(ShiftEntryFactory::new()->workedBetween(
            Instants::inMadrid('2026-03-15 01:00'),
            Instants::inMadrid('2026-03-15 03:00'),
        ))
        ->build();

    $hueco = $jornada->continuationGapFor(ShiftTimes::closed(
        Instants::inMadrid('2026-03-15 05:00'),
        Instants::inMadrid('2026-03-15 07:00'),
    ));

    expect($hueco?->start)->toEqual(Instants::inMadrid('2026-03-15 03:00'))
        ->and($hueco?->end)->toEqual(Instants::inMadrid('2026-03-15 05:00'))
        // Un tramo del dia de la jornada no continua nada: no tiene hueco.
        ->and($jornada->continuationGapFor(ShiftTimes::closed(
            Instants::inMadrid('2026-03-14 18:00'),
            Instants::inMadrid('2026-03-14 20:00'),
        )))->toBeNull()
        // Al anular el segundo, la vuelta... no hay siguiente: ninguno.
        ->and($jornada->continuationGapAfterVoiding('shift-entry-2'))->toBeNull();

    $anulandoElPrimero = $jornada->continuationGapAfterVoiding('shift-entry-1');

    // Sin anterior, el segundo no continua nada que medir.
    expect($anulandoElPrimero)->toBeNull();

    // Corregir el segundo: el hueco se mide contra el primero.
    $corrigiendo = $jornada->continuationGapFor(ShiftTimes::closed(
        Instants::inMadrid('2026-03-15 01:30'),
        Instants::inMadrid('2026-03-15 03:00'),
    ), 'shift-entry-2');

    expect($corrigiendo?->start)->toEqual(Instants::inMadrid('2026-03-15 00:00'))
        ->and($corrigiendo?->end)->toEqual(Instants::inMadrid('2026-03-15 01:30'));
})->group('RN-05', 'RF-PA-04');

// --- Solo una pausa continua la jornada, y como mucho hasta el dia siguiente (2.3.0)

it('no deja encadenar dos turnos de dias seguidos sin pausa', function (): void {
    // 10 de 08:00 a 20:00 y 11 de 07:00 a 19:00: 11 h entre medias, menos que
    // la cota, pero el primero termino en salida. Si se admitiera, las dos
    // jornadas serian una y el descanso insuficiente no se veria (RN-10).
    $jornada = WorkDayFactory::new()
        ->onWorkDate('2026-10-10')
        ->withShift(ShiftEntryFactory::new()->workedBetween(
            Instants::inMadrid('2026-10-10 08:00'),
            Instants::inMadrid('2026-10-10 20:00'),
        ))
        ->build();

    $rechazo = continuacionRechazo(fn (): ShiftEntry => continuacionAlta($jornada, '2026-10-11 07:00', '2026-10-11 19:00'));

    expect($rechazo->mark)->toBe(FutureMark::ClockIn)
        ->and($rechazo->translationKey)->toBe('attendance.errors.entry_not_after_break')
        ->and($rechazo->parameters)->toBe(['work_date' => '2026-10-10'])
        ->and($rechazo->getMessage())->toBe('A shift entry starting at 2026-10-11T05:00:00+00:00 does not continue '
            .'work day 2026-10-10: the previous entry did not end in a break (RN-05).')
        ->and($jornada->shiftCount())->toBe(1);
})->group('RN-05', 'RF-PA-04', 'RN-10');

it('no deja encadenar con pausas mas alla del dia siguiente', function (): void {
    // Pausa en todos: el 10 de 20:00 a 23:00, el 11 de 08:00 a 20:00 (9 h
    // despues) y el 12 a las 07:00 (11 h despues). Cada hueco cabe en la cota,
    // pero la jornada del 10 solo llega hasta el 11.
    $jornada = WorkDayFactory::new()->onWorkDate('2026-10-10')->build();
    continuacionAlta($jornada, '2026-10-10 20:00', '2026-10-10 23:00', terminaEnPausa: true);
    continuacionAlta($jornada, '2026-10-11 08:00', '2026-10-11 20:00', terminaEnPausa: true);

    $rechazo = continuacionRechazo(fn (): ShiftEntry => continuacionAlta($jornada, '2026-10-12 07:00', '2026-10-12 09:00', terminaEnPausa: true));

    expect($rechazo->translationKey)->toBe('attendance.errors.entry_beyond_next_day')
        ->and($rechazo->parameters)->toBe(['work_date' => '2026-10-10', 'next_day' => '2026-10-11'])
        ->and($rechazo->getMessage())->toBe('A shift entry starting at 2026-10-12T05:00:00+00:00 does not continue '
            .'work day 2026-10-10: a work day only continues until 2026-10-11 (RN-05).')
        ->and($jornada->shiftCount())->toBe(2);
})->group('RN-05', 'RF-PA-04', 'RN-10');

it('admite la vuelta de una pausa que cruza la medianoche', function (): void {
    // Pausa a las 23:30 y vuelta a las 00:30: el quiosco lo continua, y el
    // panel tambien.
    $jornada = WorkDayFactory::new()->onWorkDate('2026-10-10')->build();
    continuacionAlta($jornada, '2026-10-10 18:00', '2026-10-10 23:30', terminaEnPausa: true);
    $vuelta = continuacionAlta($jornada, '2026-10-11 00:30', '2026-10-11 02:00');

    expect($vuelta->workDate()->isoDate)->toBe('2026-10-10')
        ->and($jornada->shiftCount())->toBe(2);
})->group('RN-05', 'RF-PA-04');

it('admite continuar un tramo anterior escrito a mano que se declaro terminado en pausa', function (): void {
    $jornada = WorkDayFactory::new()
        ->onWorkDate('2026-10-10')
        ->withShift(ShiftEntryFactory::new()
            ->workedBetween(Instants::inMadrid('2026-10-10 22:00'), Instants::inMadrid('2026-10-11 01:00'))
            ->endingInBreak())
        ->build();

    $vuelta = continuacionAlta($jornada, '2026-10-11 01:30', '2026-10-11 05:00');

    expect($vuelta->workDate()->isoDate)->toBe('2026-10-10');
})->group('RN-05', 'RF-PA-04');

it('rechaza anular la pausa intermedia si la vuelta de despues deja de continuar la jornada', function (): void {
    // De 18:00 a 22:00 (salida), de 23:00 a 23:30 (pausa) y vuelta a las 00:30.
    // Sin el tramo de la pausa, la vuelta seguiria a una salida.
    $jornada = WorkDayFactory::new()
        ->onWorkDate('2026-10-10')
        ->withShift(ShiftEntryFactory::new()->workedBetween(
            Instants::inMadrid('2026-10-10 18:00'),
            Instants::inMadrid('2026-10-10 22:00'),
        ))
        ->withShift(ShiftEntryFactory::new()->workedBetween(
            Instants::inMadrid('2026-10-10 23:00'),
            Instants::inMadrid('2026-10-10 23:30'),
        )->endingInBreak())
        ->withShift(ShiftEntryFactory::new()->workedBetween(
            Instants::inMadrid('2026-10-11 00:30'),
            Instants::inMadrid('2026-10-11 02:00'),
        ))
        ->build();

    $rechazo = continuacionRechazo(fn (): ShiftEntry => continuacionAnulacion($jornada, 'shift-entry-2'));

    expect($rechazo->translationKey)->toBe('attendance.errors.entry_not_after_break')
        ->and($rechazo->mark)->toBe(FutureMark::ClockIn)
        ->and($jornada->shiftCount())->toBe(3)
        ->and($jornada->releaseEvents())->toBe([]);
})->group('RN-05', 'RF-PA-04', 'RN-13');

it('declarar la pausa en una correccion es una version nueva, aunque las horas no cambien', function (): void {
    $jornada = continuacionJornadaDelDiez();
    $antes = $jornada->entry('shift-entry-1')->times();

    $corregido = continuacionCorreccion($jornada, 'shift-entry-1', $antes->withClosure(EntryClosure::CLOCK_OUT));

    expect($corregido->version())->toBe(2)
        ->and($corregido->closure())->toBe(EntryClosure::CLOCK_OUT)
        ->and($corregido->clockOutSource())->toBe(ScanOrigin::MANUAL_ADMIN);
})->group('RN-05', 'RF-PA-04', 'RN-13');
