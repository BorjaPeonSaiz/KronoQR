<?php

declare(strict_types=1);

use App\Modules\Reporting\Domain\ValueObject\PayrollCell;
use App\Modules\Reporting\Domain\ValueObject\PeriodReportRow;
use App\Modules\Reporting\Domain\ValueObject\ReportSubject;
use App\Modules\Shared\Domain\ValueObject\PayrollColumn;
use App\Modules\Shared\Domain\ValueObject\PayrollDateFormat;
use App\Modules\Shared\Domain\ValueObject\PayrollHoursFormat;
use App\Modules\Shared\Domain\ValueObject\PayrollLayout;

/*
 * **Que dice cada celda del fichero de nomina** (RF-IN-07, RF-IN-04, RF-PD-01).
 *
 * Dominio puro, sin base de datos y sin framework. Se prueba aqui —sobre el
 * objeto de valor— y no abriendo un CSV, por lo mismo que `ReportedDurationTest`:
 * una prueba que comprobara el formato de las horas leyendo un fichero fallaria
 * por veinte motivos distintos y solo uno seria este.
 *
 * Lo que aqui se decide es donde se pierde el dinero de alguien: un redondeo de
 * mas, un separador decimal heredado de la configuracion regional o un signo
 * perdido convierten un fichero de nomina en una nomina mal pagada.
 */

/** Una fila conocida: 22 h 30 trabajadas frente a 24 h contratadas. */
function filaDeNomina(int $worked = 1350, int $contracted = 1440): PeriodReportRow
{
    return new PeriodReportRow(
        subject: ReportSubject::employee(
            uuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
            employeeCode: 'EMP-0007',
            fullName: 'Lucia Fernandez de la Vega',
            departmentId: 3,
            departmentName: 'Cocina',
            firstName: 'Lucia',
            lastName: 'Fernandez de la Vega',
        ),
        periodStart: new DateTimeImmutable('2026-03-01 00:00:00', new DateTimeZone('UTC')),
        periodEnd: new DateTimeImmutable('2026-03-31 00:00:00', new DateTimeZone('UTC')),
        workedMinutes: $worked,
        shiftCount: 3,
        daysInPeriod: 31,
        daysWithActivity: 3,
        openShiftDays: 0,
        incidentDays: 1,
        contractedMinutes: $contracted,
        daysWithoutContract: 2,
        absenceDays: 4,
        holidayDays: 1,
        unjustifiedAbsenceDays: 5,
    );
}

/**
 * @param  list<string>  $columns
 */
function plantillaDeCeldas(array $columns, string $hours = 'hhmm', string $dates = 'iso'): PayrollLayout
{
    return PayrollLayout::fromSettings($columns, 'semicolon', $hours, $dates, 'utf8_bom', 'enabled');
}

// --- Las horas: `HH:MM` de serie, decimal si el programa lo exige ------------

it('escribe las duraciones en HH:MM por omision, con signo y por encima de 24 h', function (
    int $minutes,
    string $expected,
): void {
    // Es el valor de serie y la unica forma que no se interpreta: `168:00` no
    // depende de la configuracion regional de nadie.
    expect(PayrollCell::duration($minutes, PayrollHoursFormat::HoursMinutes))->toBe($expected);
})->with([
    'cero' => [0, '00:00'],
    'siete y tres cuartos' => [465, '07:45'],
    'mas de un dia, que no es un error' => [10080, '168:00'],
    'negativa: se trabajo por debajo de lo contratado' => [-750, '-12:30'],
    'negativa de menos de una hora' => [-30, '-00:30'],
])->group('RF-IN-04', 'RF-IN-07');

it('escribe las duraciones en decimal con el separador que pide la plantilla', function (
    int $minutes,
    string $format,
    string $expected,
): void {
    // LA EXCEPCION RAZONADA al «nunca decimal» del paso 6 de `/informe-nuevo`:
    // este fichero lo importa un programa que multiplica por un precio hora.
    //
    // **El separador se elige, no se hereda.** `7.75` leido con separador de
    // miles español es setecientos setenta y cinco, y esa ambigüedad es lo que
    // hunde a quien confia en la configuracion regional del proceso.
    expect(PayrollCell::duration($minutes, PayrollHoursFormat::from($format)))->toBe($expected);
})->with([
    'siete y tres cuartos con punto' => [465, 'decimal_dot', '7.75'],
    'siete y tres cuartos con coma' => [465, 'decimal_comma', '7,75'],
    'cero con dos decimales' => [0, 'decimal_dot', '0.00'],
    'mas de un dia' => [10080, 'decimal_dot', '168.00'],
    'negativa con punto' => [-750, 'decimal_dot', '-12.50'],
    'negativa con coma' => [-750, 'decimal_comma', '-12,50'],
    'media hora negativa: el signo va delante del cero' => [-30, 'decimal_dot', '-0.50'],
    // UN MINUTO en contra. El caso borde del signo: con `< -1` en vez de `< 0`
    // saldria «0.02» y una desviacion negativa se leeria como positiva.
    'un minuto negativo' => [-1, 'decimal_dot', '-0.02'],
])->group('RF-IN-04', 'RF-IN-07');

it('redondea a dos decimales una sola vez y al final', function (): void {
    // 1 minuto son 0,01666… horas. Redondear por dia o por tramo acumularia el
    // error a lo largo del mes; aqui se divide el total de minutos una vez.
    expect(PayrollCell::duration(1, PayrollHoursFormat::DecimalDot))->toBe('0.02')
        ->and(PayrollCell::duration(29, PayrollHoursFormat::DecimalDot))->toBe('0.48')
        // 9.997 h -> 10.00, no «9.99»: el redondeo es del numero completo.
        ->and(PayrollCell::duration(599, PayrollHoursFormat::DecimalDot))->toBe('9.98')
        ->and(PayrollCell::duration(600, PayrollHoursFormat::DecimalDot))->toBe('10.00');
})->group('RF-IN-04', 'RF-IN-07');

it('nunca escribe el decimal con separador de miles', function (): void {
    // `number_format` con sus valores por omision metería «10,080.00» y un
    // importador leeria diez. El tercer argumento va vacio a proposito.
    expect(PayrollCell::duration(604800, PayrollHoursFormat::DecimalDot))->toBe('10080.00')
        ->and(PayrollCell::duration(604800, PayrollHoursFormat::DecimalComma))->toBe('10080,00');
})->group('RF-IN-07');

// --- Las fechas -------------------------------------------------------------

it('escribe las fechas en la forma que pide la plantilla', function (string $format, string $expected): void {
    $day = new DateTimeImmutable('2026-03-01 00:00:00', new DateTimeZone('UTC'));

    expect(PayrollCell::formatDate($day, PayrollDateFormat::from($format)))->toBe($expected);
})->with([
    'ISO 8601, de serie' => ['iso', '2026-03-01'],
    'dia/mes/año, la que piden muchos importadores españoles' => ['dmy', '01/03/2026'],
])->group('RF-IN-07');

// --- La fila entera ---------------------------------------------------------

it('escribe la fila en el orden de la plantilla y con los rotulos del cliente', function (): void {
    // El orden de la lista ES el orden del fichero (RF-PD-01): cambiarlo en el
    // panel produce otro fichero sin desplegar codigo, que es la promesa entera
    // de RF-IN-07.
    $layout = plantillaDeCeldas(['worked_hours', 'employee_code=COD', 'period_from']);

    expect(PayrollCell::row(filaDeNomina(), $layout, 'Europe/Madrid'))
        ->toBe(['22:30', 'EMP-0007', '2026-03-01']);
})->group('RF-IN-07', 'RF-PD-01');

it('cambia el fichero entero al cambiar dos ajustes, sin tocar el codigo', function (): void {
    // La misma fila, dos plantillas: es literalmente el resultado esperado de la
    // ficha —«cambiar el formato de nomina por configuracion produce otro fichero
    // sin desplegar codigo»— comprobado en el objeto que lo decide.
    $row = filaDeNomina();
    $columns = ['employee_code', 'period_from', 'worked_hours'];

    expect(PayrollCell::row($row, plantillaDeCeldas($columns), 'Europe/Madrid'))
        ->toBe(['EMP-0007', '2026-03-01', '22:30'])
        ->and(PayrollCell::row($row, plantillaDeCeldas($columns, 'decimal_comma', 'dmy'), 'Europe/Madrid'))
        ->toBe(['EMP-0007', '01/03/2026', '22,50']);
})->group('RF-IN-07', 'RF-PD-01');

it('escribe una celda para cada columna del catalogo, sin dejarse ninguna', function (): void {
    // EL GUARDA DEL `default` QUE LANZA. `PayrollCell::of()` esta partido en
    // cuatro familias por el limite de complejidad del §3.5, y una columna nueva
    // que nadie enganche a su familia cae al `default`. Aqui se recorre el
    // catalogo entero: el fallo aparece en la suite y no en la nomina de
    // quinientas personas.
    $layout = PayrollLayout::fromSettings(
        PayrollColumn::ids(), 'semicolon', 'hhmm', 'iso', 'utf8_bom', 'enabled',
    );

    $cells = PayrollCell::row(filaDeNomina(), $layout, 'Europe/Madrid');

    expect($cells)->toHaveCount(\count(PayrollColumn::cases()));
})->group('RF-IN-07');

it('escribe cada columna del catalogo con el valor que le corresponde', function (
    string $id,
    string $expected,
): void {
    $layout = plantillaDeCeldas([$id]);

    expect(PayrollCell::row(filaDeNomina(), $layout, 'Europe/Madrid'))->toBe([$expected]);
})->with([
    'codigo de empleado' => ['employee_code', 'EMP-0007'],
    'identificador publico' => ['employee_uuid', '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90'],
    // Apellidos y nombre POR SEPARADO: partir el nombre completo por el primer
    // espacio habria dado «Lucia Fernandez» y «de la Vega».
    'apellidos' => ['last_name', 'Fernandez de la Vega'],
    'nombre' => ['first_name', 'Lucia'],
    'nombre completo' => ['full_name', 'Lucia Fernandez de la Vega'],
    'departamento' => ['department', 'Cocina'],
    'desde' => ['period_from', '2026-03-01'],
    'hasta' => ['period_to', '2026-03-31'],
    'dias del periodo' => ['days_in_period', '31'],
    'dias con actividad' => ['days_with_activity', '3'],
    'tramos' => ['shift_count', '3'],
    'trabajado' => ['worked_hours', '22:30'],
    'contratado' => ['contracted_hours', '24:00'],
    'desviacion, con signo' => ['deviation_hours', '-01:30'],
    // Trabajar de menos no es exceso negativo: es desviacion. El exceso es cero.
    'exceso, solo la parte positiva' => ['overtime_hours', '00:00'],
    'dias de ausencia' => ['absence_days', '4'],
    'festivos' => ['holiday_days', '1'],
    'absentismo no justificado' => ['unjustified_absence_days', '5'],
    'dias sin contrato' => ['days_without_contract', '2'],
    // La zona del centro, dentro del fichero: sin ella quien lo importa tiene
    // que suponerla (regla dura 3).
    'zona horaria' => ['time_zone', 'Europe/Madrid'],
])->group('RF-IN-07', 'RF-IN-04');

it('deja en blanco lo que un agregado no tiene, en vez de inventarlo', function (): void {
    // El informe de nomina va siempre por empleado, pero la fila admite los tres
    // sujetos. Un agregado de departamento no tiene codigo ni apellidos, y una
    // celda vacia es la verdad; rellenarla con el nombre del departamento haria
    // que un importador diera de alta a un empleado llamado «Cocina».
    $row = new PeriodReportRow(
        subject: ReportSubject::department(3, 'Cocina'),
        periodStart: new DateTimeImmutable('2026-03-01 00:00:00', new DateTimeZone('UTC')),
        periodEnd: new DateTimeImmutable('2026-03-31 00:00:00', new DateTimeZone('UTC')),
        workedMinutes: 0,
        shiftCount: 0,
        daysInPeriod: 31,
        daysWithActivity: 0,
        openShiftDays: 0,
        incidentDays: 0,
        contractedMinutes: 0,
        daysWithoutContract: 0,
        absenceDays: 0,
        holidayDays: 0,
        unjustifiedAbsenceDays: 0,
    );

    $layout = plantillaDeCeldas(['employee_code', 'employee_uuid', 'last_name', 'first_name', 'full_name', 'department']);

    expect(PayrollCell::row($row, $layout, 'Europe/Madrid'))
        // `full_name` cae a la etiqueta del agregado, que es de quien habla la
        // fila; los cuatro de persona quedan vacios.
        ->toBe(['', '', '', '', 'Cocina', 'Cocina']);
})->group('RF-IN-07');
