<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Exception\ShiftEntryMarkAfterTermination;
use App\Modules\Attendance\Domain\Exception\WorkDateOutsideEmployment;
use App\Modules\Attendance\Domain\Policy\ManualEntryEligibility;
use App\Modules\Attendance\Domain\ValueObject\FutureMark;
use App\Modules\Attendance\Domain\ValueObject\ShiftEntryTimes;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Shared\Domain\ValueObject\EmployeeSnapshot;
use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;
use Tests\Support\Factory\ContinuationCeilingFactory;
use Tests\Support\Time\Instants;

/*
 * A quien se le puede anotar un tramo a mano, y de que jornadas (RN-14,
 * RF-PA-04, 2.2.0).
 *
 * En alta, como siempre. Dada de baja, solo jornadas entre su alta y su cese,
 * ambas incluidas: es como se completan los dias trabajados que no constaron
 * cuando la baja ya corto el fichaje. Suspendida, no.
 */

function personaParaElAltaManual(
    EmploymentStatus $status,
    ?string $hiredOn = '2026-03-01',
    ?string $terminatedOn = null,
): ManualEntryEligibility {
    return ManualEntryEligibility::of(
        new EmployeeSnapshot('uuid-1', 'EMP-0001', 'Lucia', $status, 1, null, $hiredOn, $terminatedOn),
    );
}

function jornadaParaElAltaManual(string $isoDate): WorkDate
{
    return WorkDate::fromIsoDate($isoDate, new DateTimeZone('Europe/Madrid'));
}

/** La excepcion que lanza `$accion`; si no lanza ninguna, la prueba falla. */
function rechazoDelAltaManual(Closure $accion): WorkDateOutsideEmployment
{
    try {
        $accion();
    } catch (WorkDateOutsideEmployment $exception) {
        return $exception;
    }

    throw new RuntimeException('Se esperaba WorkDateOutsideEmployment y no se lanzo nada.');
}

/**
 * `true` si `$accion` termina sin lanzar; si lanza, la excepcion sale y la
 * prueba falla. No se usa `->not->toThrow(Throwable::class)`: con una interfaz,
 * Pest no la reconoce como clase, la toma por el texto del mensaje y la
 * afirmacion pasa aunque se lance algo.
 */
function sinRechazoDelAltaManual(Closure $accion): bool
{
    $accion();

    return true;
}

it('admite tramos de una persona en alta o de baja, y no de una suspendida', function (EmploymentStatus $status, ?string $terminatedOn, bool $admite): void {
    expect(personaParaElAltaManual($status, terminatedOn: $terminatedOn)->admitsEntries())->toBe($admite);
})->with([
    'en alta' => [EmploymentStatus::ACTIVE, null, true],
    'de baja' => [EmploymentStatus::TERMINATED, '2026-09-30', true],
    'suspendida' => [EmploymentStatus::SUSPENDED, null, false],
])->group('RN-25', 'RN-14', 'RF-PA-04');

it('no admite a una persona de baja sin las dos fechas de su periodo de empleo', function (?string $hiredOn, ?string $terminatedOn): void {
    expect(personaParaElAltaManual(EmploymentStatus::TERMINATED, $hiredOn, $terminatedOn)->admitsEntries())->toBeFalse();
})->with([
    'sin cese' => ['2026-03-01', null],
    'sin alta' => [null, '2026-09-30'],
    'sin ninguna' => [null, null],
])->group('RN-14', 'RF-PA-04');

it('admite a una persona de baja las jornadas de su alta a su cese, ambas incluidas', function (string $jornada): void {
    $baja = personaParaElAltaManual(EmploymentStatus::TERMINATED, '2026-03-01', '2026-09-30');

    expect(sinRechazoDelAltaManual(fn () => $baja->assertWorkDateAllowed(jornadaParaElAltaManual($jornada))))->toBeTrue();
})->with([
    'el dia del alta' => ['2026-03-01'],
    'un dia intermedio' => ['2026-06-15'],
    'el dia del cese' => ['2026-09-30'],
])->group('RN-14', 'RF-PA-04');

it('rechaza a una persona de baja una jornada fuera de su periodo de empleo con su clave y sus fechas', function (string $jornada): void {
    $baja = personaParaElAltaManual(EmploymentStatus::TERMINATED, '2026-03-01', '2026-09-30');

    $rechazo = rechazoDelAltaManual(fn () => $baja->assertWorkDateAllowed(jornadaParaElAltaManual($jornada)));

    expect($rechazo->translationKey)->toBe('attendance.errors.work_date_outside_employment')
        ->and($rechazo->parameters)->toBe(['hired_on' => '2026-03-01', 'terminated_on' => '2026-09-30'])
        ->and($rechazo->getMessage())->toBe(
            'The work date '.$jornada.' is outside the employment period 2026-03-01..2026-09-30 of an offboarded employee (RN-14).',
        );
})->with([
    'el dia siguiente al cese' => ['2026-10-01'],
    'el dia anterior al alta' => ['2026-02-28'],
])->group('RN-14', 'RF-PA-04');

it('no acota las jornadas de quien no esta de baja', function (EmploymentStatus $status): void {
    // Hoy se puede anotar a una persona en alta un dia anterior a su alta: en la
    // 2.2.0 se deja como esta (decision del propietario).
    $persona = personaParaElAltaManual($status, '2026-03-01', null);

    expect(sinRechazoDelAltaManual(fn () => $persona->assertWorkDateAllowed(jornadaParaElAltaManual('2026-02-01'))))->toBeTrue();
})->with([
    'en alta' => [EmploymentStatus::ACTIVE],
    'suspendida' => [EmploymentStatus::SUSPENDED],
])->group('RN-14', 'RF-PA-04');

it('con la baja programada rechaza una jornada posterior al cese, aunque la tarea no la haya aplicado', function (EmploymentStatus $status): void {
    // RN-14 (2.3.0): desde que hay fecha de cese ningun tramo nuevo cae
    // despues, este la baja aplicada o no.
    $programada = personaParaElAltaManual($status, '2026-03-01', '2026-09-30');

    $rechazo = rechazoDelAltaManual(fn () => $programada->assertWorkDateAllowed(jornadaParaElAltaManual('2026-10-01')));

    expect($rechazo->translationKey)->toBe('attendance.errors.work_date_after_termination')
        ->and($rechazo->parameters)->toBe(['terminated_on' => '2026-09-30'])
        ->and($rechazo->getMessage())->toBe('The work date 2026-10-01 is after the termination date 2026-09-30 of a scheduled offboarding (RN-14).');
})->with([
    'en alta' => [EmploymentStatus::ACTIVE],
    'suspendida' => [EmploymentStatus::SUSPENDED],
])->group('RN-14', 'RF-PA-04');

it('con la baja programada admite el dia del cese y los anteriores, tambien los previos al alta', function (string $jornada): void {
    $programada = personaParaElAltaManual(EmploymentStatus::ACTIVE, '2026-03-01', '2026-09-30');

    expect(sinRechazoDelAltaManual(fn () => $programada->assertWorkDateAllowed(jornadaParaElAltaManual($jornada))))->toBeTrue();
})->with([
    'el dia del cese' => ['2026-09-30'],
    'el dia anterior' => ['2026-09-29'],
    // A una persona en alta no se le aplica el limite por la fecha de alta.
    'antes del alta' => ['2026-02-01'],
])->group('RN-14', 'RF-PA-04');

it('no compara contra una fecha que falta: a esa baja ya no la admite admitsEntries', function (?string $hiredOn, ?string $terminatedOn, string $jornada): void {
    $baja = personaParaElAltaManual(EmploymentStatus::TERMINATED, $hiredOn, $terminatedOn);

    expect(sinRechazoDelAltaManual(fn () => $baja->assertWorkDateAllowed(jornadaParaElAltaManual($jornada))))->toBeTrue()
        ->and($baja->admitsEntries())->toBeFalse();
})->with([
    'sin cese' => ['2026-03-01', null, '2026-06-15'],
    'sin alta' => [null, '2026-09-30', '2026-10-15'],
])->group('RN-14', 'RF-PA-04');

// --- RN-05 (2.3.0): las marcas no pasan de la cota del dia del cese ----------

/** La excepcion del cese; si `$accion` no la lanza, la prueba falla. */
function rechazoPorElCese(Closure $accion): ShiftEntryMarkAfterTermination
{
    try {
        $accion();
    } catch (ShiftEntryMarkAfterTermination $exception) {
        return $exception;
    }

    throw new RuntimeException('Se esperaba ShiftEntryMarkAfterTermination y no se lanzo nada.');
}

it('admite marcas de una persona de baja hasta un segundo antes de la cota de su dia de cese', function (string $cese, string $entrada, ?string $salida): void {
    // Cese el 10: el dia acaba a la medianoche del 11 en Madrid (22:00 UTC del
    // 10) y la cota de 12 h llega a las 10:00 UTC del 11, las 12:00 locales.
    // Con el cese el 24 de octubre la medianoche es aun CEST y la cota cae
    // despues del cambio de hora: 12 h reales, las 11:00 CET.
    $baja = personaParaElAltaManual(EmploymentStatus::TERMINATED, '2026-03-01', $cese);

    expect(sinRechazoDelAltaManual(fn () => $baja->assertTimesAllowed(
        $salida === null
            ? ShiftEntryTimes::open(Instants::utc($entrada))
            : ShiftEntryTimes::closed(Instants::utc($entrada), Instants::utc($salida)),
        ContinuationCeilingFactory::standard(),
        jornadaParaElAltaManual('2026-10-10'),
    )))->toBeTrue();
})->with([
    'cese el 10' => ['2026-10-10', '2026-10-11 08:00', '2026-10-11 09:59:59'],
    'un tramo abierto antes de la cota' => ['2026-10-10', '2026-10-11 08:00', null],
    'cese el 24, con cambio de hora' => ['2026-10-24', '2026-10-25 08:00', '2026-10-25 09:59:59'],
    'el turno de noche del dia del cese' => ['2026-10-10', '2026-10-10 20:00', '2026-10-11 04:00'],
])->group('RN-05', 'RN-14', 'RF-PA-04');

it('rechaza a una persona de baja la marca que llega a la cota de su dia de cese', function (string $cese, string $entrada, ?string $salida, FutureMark $marca): void {
    $baja = personaParaElAltaManual(EmploymentStatus::TERMINATED, '2026-03-01', $cese);

    $rechazo = rechazoPorElCese(fn () => $baja->assertTimesAllowed(
        $salida === null
            ? ShiftEntryTimes::open(Instants::utc($entrada))
            : ShiftEntryTimes::closed(Instants::utc($entrada), Instants::utc($salida)),
        ContinuationCeilingFactory::standard(),
        jornadaParaElAltaManual('2026-10-10'),
    ));

    expect($rechazo->mark)->toBe($marca)
        ->and($rechazo->translationKey)->toBe('attendance.errors.mark_after_termination')
        ->and($rechazo->parameters)->toBe(['terminated_on' => $cese]);
})->with([
    'la salida en la cota' => ['2026-10-10', '2026-10-11 08:00', '2026-10-11 10:00', FutureMark::ClockOut],
    'la entrada en la cota' => ['2026-10-10', '2026-10-11 10:00', '2026-10-11 11:00', FutureMark::ClockIn],
    'un tramo abierto en la cota' => ['2026-10-10', '2026-10-11 10:00', null, FutureMark::ClockIn],
    'la entrada del 13' => ['2026-10-10', '2026-10-13 06:00', '2026-10-13 13:00', FutureMark::ClockIn],
    'la salida en la cota, con cambio de hora' => ['2026-10-24', '2026-10-25 08:00', '2026-10-25 10:00', FutureMark::ClockOut],
])->group('RN-05', 'RN-14', 'RF-PA-04');

it('dice en el mensaje tecnico cual es el limite, sin datos de la persona', function (): void {
    $baja = personaParaElAltaManual(EmploymentStatus::TERMINATED, '2026-03-01', '2026-10-10');

    $rechazo = rechazoPorElCese(fn () => $baja->assertTimesAllowed(
        ShiftEntryTimes::open(Instants::utc('2026-10-11 10:00')),
        ContinuationCeilingFactory::standard(),
        jornadaParaElAltaManual('2026-10-10'),
    ));

    expect($rechazo->getMessage())->toBe(
        'The clocked_in_at mark 2026-10-11T10:00:00+00:00 is at or after 2026-10-11T10:00:00+00:00, '
        .'the continuation limit of the termination day 2026-10-10 (RN-05, RN-14).',
    );
})->group('RN-05', 'RN-14');

it('no acota las marcas de quien no tiene fecha de cese', function (EmploymentStatus $status): void {
    $persona = personaParaElAltaManual($status, '2026-03-01', null);

    expect(sinRechazoDelAltaManual(fn () => $persona->assertTimesAllowed(
        ShiftEntryTimes::closed(Instants::utc('2026-10-20 08:00'), Instants::utc('2026-10-20 15:00')),
        ContinuationCeilingFactory::standard(),
        jornadaParaElAltaManual('2026-10-10'),
    )))->toBeTrue();
})->with([
    'en alta' => [EmploymentStatus::ACTIVE],
    'suspendida' => [EmploymentStatus::SUSPENDED],
])->group('RN-05', 'RN-14', 'RF-PA-04');

it('con la baja programada acota las marcas igual que con la baja efectiva', function (EmploymentStatus $status): void {
    // Cese el 10: el turno de noche del ultimo dia cabe, la entrada del 13 no.
    $programada = personaParaElAltaManual($status, '2026-03-01', '2026-10-10');

    expect(sinRechazoDelAltaManual(fn () => $programada->assertTimesAllowed(
        ShiftEntryTimes::closed(Instants::utc('2026-10-10 20:00'), Instants::utc('2026-10-11 04:00')),
        ContinuationCeilingFactory::standard(),
        jornadaParaElAltaManual('2026-10-10'),
    )))->toBeTrue()
        ->and(rechazoPorElCese(fn () => $programada->assertTimesAllowed(
            ShiftEntryTimes::closed(Instants::utc('2026-10-13 06:00'), Instants::utc('2026-10-13 13:00')),
            ContinuationCeilingFactory::standard(),
            jornadaParaElAltaManual('2026-10-10'),
        ))->mark)->toBe(FutureMark::ClockIn);
})->with([
    'en alta' => [EmploymentStatus::ACTIVE],
    'suspendida' => [EmploymentStatus::SUSPENDED],
])->group('RN-05', 'RN-14', 'RF-PA-04');

it('en una correccion solo mira las marcas que cambian', function (): void {
    // La salida antigua ya estaba mas alla de la cota (un dato anterior a la
    // 2.3.0); corregir la entrada no tropieza con ella. Cambiarla, si.
    $baja = personaParaElAltaManual(EmploymentStatus::TERMINATED, '2026-03-01', '2026-10-10');
    $antes = ShiftEntryTimes::closed(Instants::utc('2026-10-10 06:00'), Instants::utc('2026-10-11 11:00'));

    expect(sinRechazoDelAltaManual(fn () => $baja->assertTimesAllowed(
        ShiftEntryTimes::closed(Instants::utc('2026-10-10 07:00'), Instants::utc('2026-10-11 11:00')),
        ContinuationCeilingFactory::standard(),
        jornadaParaElAltaManual('2026-10-10'),
        $antes,
    )))->toBeTrue();

    $rechazo = rechazoPorElCese(fn () => $baja->assertTimesAllowed(
        ShiftEntryTimes::closed(Instants::utc('2026-10-10 06:00'), Instants::utc('2026-10-11 12:00')),
        ContinuationCeilingFactory::standard(),
        jornadaParaElAltaManual('2026-10-10'),
        $antes,
    ));

    expect($rechazo->mark)->toBe(FutureMark::ClockOut);
})->group('RN-05', 'RN-14', 'RF-PA-04');
