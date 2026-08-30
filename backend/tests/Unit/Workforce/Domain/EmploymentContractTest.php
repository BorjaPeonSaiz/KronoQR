<?php

declare(strict_types=1);

use App\Modules\Workforce\Domain\Exception\InvalidEmploymentContract;
use App\Modules\Workforce\Domain\Model\EmploymentContract;
use App\Modules\Workforce\Domain\ValueObject\ScheduleType;

/*
 * `EmploymentContract` — las invariantes del contrato y la formula del prorrateo
 * (**RF-GP-02**, RF-IN-03, tarea 2.8).
 *
 * Suite unitaria: sin framework, sin base de datos y sin reloj. Es lo que permite
 * comprobar la aritmetica del prorrateo —la cifra contra la que se mide la
 * jornada de una persona— en milisegundos y sin montar un informe entero.
 *
 * **Ninguna prueba calcula su resultado esperado con la misma formula que el
 * codigo**: los minutos se escriben como numero. Si se dedujeran, las dos
 * podrian estar mal de la misma manera.
 */

function contrato(
    float $weeklyHours = 40.0,
    string $validFrom = '2026-01-01',
    ?string $validTo = null,
): EmploymentContract {
    return new EmploymentContract(
        employeeUuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
        weeklyHours: $weeklyHours,
        annualHours: null,
        scheduleType: ScheduleType::Shifts,
        validFrom: new DateTimeImmutable($validFrom.' 00:00:00', new DateTimeZone('UTC')),
        validTo: $validTo === null ? null : new DateTimeImmutable($validTo.' 00:00:00', new DateTimeZone('UTC')),
    );
}

it('reparte las horas semanales por dia natural y redondea una sola vez', function (float $horas, int $dias, int $minutos): void {
    // La formula de RF-IN-03: dias x horas semanales / 7, en minutos. Una semana
    // completa da exactamente las horas del contrato, que es la comprobacion que
    // hace RRHH mentalmente antes de creerse el informe.
    expect(contrato($horas)->contractedMinutesForDays($dias))->toBe($minutos);
})->with([
    'una semana a 40 h son 40 h exactas' => [40.0, 7, 2400],
    'una semana a 37,5 h son 37 h 30' => [37.5, 7, 2250],
    'cuatro semanas a 20 h' => [20.0, 28, 4800],
    // 31 x 40 x 60 / 7 = 10.628,571... El redondeo va al final y una sola vez:
    // redondear por dia y sumar acumularia media hora de error en un mes.
    'un mes de 31 dias a 40 h' => [40.0, 31, 10629],
    'un mes de 30 dias a 30 h' => [30.0, 30, 7714],
    'ningun dia de vigencia no son horas' => [40.0, 0, 0],
])->group('RF-IN-03', 'RF-GP-02');

it('cuenta solo los dias de vigencia que caen dentro del periodo', function (): void {
    // La primera mitad del prorrateo. Un contrato del 1 al 15 dentro de un
    // informe de todo marzo aporta quince dias, no treinta y uno.
    $marzo = [new DateTimeImmutable('2026-03-01'), new DateTimeImmutable('2026-03-31')];

    expect(contrato(40.0, '2026-01-01', '2026-03-15')->coveredDaysWithin(...$marzo))->toBe(15)
        ->and(contrato(40.0, '2026-03-16')->coveredDaysWithin(...$marzo))->toBe(16)
        // Los dos extremos entran: un contrato de un solo dia cubre ese dia.
        ->and(contrato(40.0, '2026-03-10', '2026-03-10')->coveredDaysWithin(...$marzo))->toBe(1)
        // Y uno que no toca el periodo aporta cero, no un negativo.
        ->and(contrato(40.0, '2025-01-01', '2025-12-31')->coveredDaysWithin(...$marzo))->toBe(0);
})->group('RF-IN-03', 'RF-GP-02');

it('los dos tramos de un cambio de contrato suman exactamente el periodo', function (): void {
    // La invariante que hace que la serie historica sirva para algo: sin huecos
    // y sin solapes, los dias de todos los contratos vigentes suman los del
    // periodo. Si no fuera asi, el informe compararia contra menos —o mas— dias
    // de los que tiene el mes.
    $marzo = [new DateTimeImmutable('2026-03-01'), new DateTimeImmutable('2026-03-31')];

    $anterior = contrato(20.0, '2026-01-01', '2026-03-15');
    $nuevo = contrato(40.0, '2026-03-16');

    expect($anterior->coveredDaysWithin(...$marzo) + $nuevo->coveredDaysWithin(...$marzo))->toBe(31)
        ->and($anterior->overlaps($nuevo))->toBeFalse();
})->group('RF-IN-03', 'RF-GP-02');

it('cierra el contrato anterior el dia antes de que empiece el siguiente', function (): void {
    // Es lo que mantiene la serie sin hueco —el nuevo empieza justo al dia
    // siguiente— y sin solape, que es lo que la restriccion de exclusion no
    // admitiria.
    $cerrado = contrato(20.0, '2026-01-01')->closedBefore(new DateTimeImmutable('2026-03-16'));

    expect($cerrado->isoValidTo())->toBe('2026-03-15')
        ->and($cerrado->isOpenEnded())->toBeFalse()
        // Lo pactado no se toca: solo se declara hasta cuando estuvo vigente.
        ->and($cerrado->weeklyHours)->toBe(20.0)
        ->and($cerrado->isoValidFrom())->toBe('2026-01-01');
})->group('RF-GP-02');

it('sabe que dias cubre, con el ultimo incluido', function (): void {
    $contrato = contrato(40.0, '2026-03-01', '2026-03-31');

    expect($contrato->covers(new DateTimeImmutable('2026-03-01')))->toBeTrue()
        ->and($contrato->covers(new DateTimeImmutable('2026-03-31')))->toBeTrue()
        ->and($contrato->covers(new DateTimeImmutable('2026-02-28')))->toBeFalse()
        ->and($contrato->covers(new DateTimeImmutable('2026-04-01')))->toBeFalse()
        // Un contrato abierto cubre cualquier dia posterior a su inicio.
        ->and(contrato(40.0, '2026-03-01')->covers(new DateTimeImmutable('2030-01-01')))->toBeTrue();
})->group('RF-GP-02');

it('detecta el solape de dos vigencias', function (): void {
    // La comprobacion de verdad la hace PostgreSQL, porque una consulta previa
    // desde PHP seria una carrera. Esto existe para que el dominio sea probable
    // sin base de datos y para que el caso de uso pueda explicar el choque.
    $enero = contrato(40.0, '2026-01-01', '2026-01-31');

    expect($enero->overlaps(contrato(20.0, '2026-01-15', '2026-02-15')))->toBeTrue()
        ->and($enero->overlaps(contrato(20.0, '2026-01-31')))->toBeTrue()
        ->and($enero->overlaps(contrato(20.0, '2026-02-01')))->toBeFalse()
        // Dos abiertos siempre solapan: los dos llegan hasta el infinito.
        ->and(contrato(40.0, '2026-01-01')->overlaps(contrato(20.0, '2030-01-01')))->toBeTrue();
})->group('RF-GP-02');

it('rechaza un contrato que no describe ninguna jornada', function (float $semanales): void {
    // Las mismas afirmaciones estan declaradas en la migracion con `CHECK`. La
    // del esquema protege de las escrituras que no pasan por el dominio —una
    // importacion de nomina—; esta da un mensaje con significado a quien esta
    // dando de alta el contrato.
    expect(fn (): EmploymentContract => contrato($semanales))
        ->toThrow(InvalidEmploymentContract::class);
})->with([
    'cero horas' => [0.0],
    'horas negativas' => [-10.0],
    'mas horas que la semana' => [169.0],
])->group('RF-GP-02');

it('rechaza una vigencia invertida', function (): void {
    expect(fn (): EmploymentContract => contrato(40.0, '2026-03-31', '2026-03-01'))
        ->toThrow(InvalidEmploymentContract::class);
})->group('RF-GP-02');

it('rechaza un computo anual que no es una cantidad de horas', function (): void {
    expect(fn (): EmploymentContract => new EmploymentContract(
        employeeUuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
        weeklyHours: 40.0,
        annualHours: 0.0,
        scheduleType: ScheduleType::Shifts,
        validFrom: new DateTimeImmutable('2026-01-01'),
        validTo: null,
    ))->toThrow(InvalidEmploymentContract::class);
})->group('RF-GP-02');
