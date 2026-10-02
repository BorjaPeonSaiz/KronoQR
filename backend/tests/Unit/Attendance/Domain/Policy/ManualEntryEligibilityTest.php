<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Exception\WorkDateOutsideEmployment;
use App\Modules\Attendance\Domain\Policy\ManualEntryEligibility;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Shared\Domain\ValueObject\EmployeeSnapshot;
use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;

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
])->group('RN-14', 'RF-PA-04');

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

it('decide por el estado y no por que la instantanea traiga fecha de cese', function (EmploymentStatus $status): void {
    // Solo la baja acota. Una instantanea en alta con las dos fechas no deberia
    // existir, pero si existiera, su jornada no se rechazaria por ellas.
    $persona = personaParaElAltaManual($status, '2026-03-01', '2026-09-30');

    expect(sinRechazoDelAltaManual(fn () => $persona->assertWorkDateAllowed(jornadaParaElAltaManual('2026-10-15'))))->toBeTrue();
})->with([
    'en alta' => [EmploymentStatus::ACTIVE],
    'suspendida' => [EmploymentStatus::SUSPENDED],
])->group('RN-14', 'RF-PA-04');

it('no compara contra una fecha que falta: a esa baja ya no la admite admitsEntries', function (?string $hiredOn, ?string $terminatedOn, string $jornada): void {
    $baja = personaParaElAltaManual(EmploymentStatus::TERMINATED, $hiredOn, $terminatedOn);

    expect(sinRechazoDelAltaManual(fn () => $baja->assertWorkDateAllowed(jornadaParaElAltaManual($jornada))))->toBeTrue()
        ->and($baja->admitsEntries())->toBeFalse();
})->with([
    'sin cese' => ['2026-03-01', null, '2026-06-15'],
    'sin alta' => [null, '2026-09-30', '2026-10-15'],
])->group('RN-14', 'RF-PA-04');
