<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\SettingDefinition;
use App\Modules\Shared\Domain\ValueObject\PayrollColumn;
use App\Modules\Shared\Domain\ValueObject\PayrollDateFormat;
use App\Modules\Shared\Domain\ValueObject\PayrollDelimiter;
use App\Modules\Shared\Domain\ValueObject\PayrollEncoding;
use App\Modules\Shared\Domain\ValueObject\PayrollHoursFormat;
use App\Modules\Shared\Domain\ValueObject\PayrollLayout;

/*
 * **La plantilla de la salida a nomina** (RF-IN-07, RF-PD-01, ADR-017, regla
 * dura 13).
 *
 * Dominio puro: ni framework, ni base de datos, ni reloj. Lo que se comprueba
 * aqui es que el formato del fichero de nomina es **dato** y no codigo —que es la
 * promesa entera de RF-IN-07— y que leerlo es tolerante, porque la salida a
 * nomina se pide el dia de cierre y ahi un fallo no admite «lo miramos mañana».
 */

/**
 * La plantilla construida sobre seis escalares, como la entrega el adaptador.
 *
 * @param  list<string>  $columns
 */
function plantilla(
    array $columns = PayrollLayout::DEFAULT_COLUMNS,
    string $delimiter = 'semicolon',
    string $hours = 'hhmm',
    string $dates = 'iso',
    string $encoding = 'utf8_bom',
    string $header = 'enabled',
): PayrollLayout {
    return PayrollLayout::fromSettings($columns, $delimiter, $hours, $dates, $encoding, $header);
}

it('construye la plantilla de serie desde los valores del producto', function (): void {
    // El valor de serie ES el producto: una instalacion sin ninguna fila de
    // `installation_settings` tiene que poder exportar a nomina.
    $layout = PayrollLayout::shipped();

    expect($layout->columns)->toHaveCount(10)
        ->and($layout->columns[0])->toBe(PayrollColumn::EmployeeCode)
        ->and($layout->columns[9])->toBe(PayrollColumn::AbsenceDays)
        ->and($layout->delimiter)->toBe(PayrollDelimiter::Semicolon)
        ->and($layout->hoursFormat)->toBe(PayrollHoursFormat::HoursMinutes)
        ->and($layout->dateFormat)->toBe(PayrollDateFormat::Iso)
        ->and($layout->encoding)->toBe(PayrollEncoding::Utf8Bom)
        ->and($layout->hasHeaderRow)->toBeTrue()
        ->and($layout->rejected)->toBe([]);
})->group('RF-IN-07', 'RF-PD-01');

it('respeta el orden en el que el cliente escribe las columnas', function (): void {
    // El orden de la lista ES el orden del fichero. Es la mitad de lo que un
    // importador de nomina necesita: las mismas columnas en otro orden no se
    // pueden cargar.
    $layout = plantilla(['worked_hours', 'employee_code', 'period_from']);

    expect($layout->columns)->toBe([
        PayrollColumn::WorkedHours,
        PayrollColumn::EmployeeCode,
        PayrollColumn::PeriodFrom,
    ])->and($layout->width())->toBe(3);
})->group('RF-IN-07', 'RF-PD-01');

it('toma el rotulo que el cliente escribe detras del igual', function (): void {
    // `id=Etiqueta`: el identificador es del producto y el rotulo es del cliente,
    // porque tiene que casar con la plantilla de importacion de SU programa.
    $layout = plantilla(['employee_code=COD_EMPL', 'worked_hours']);

    expect($layout->labelFor(PayrollColumn::EmployeeCode))->toBe('COD_EMPL')
        // Sin rotulo configurado, `null`: el dominio no tiene idioma y quien
        // escribe el fichero resuelve la traduccion del producto.
        ->and($layout->labelFor(PayrollColumn::WorkedHours))->toBeNull();
})->group('RF-IN-07', 'RF-PD-01');

it('recorta los espacios alrededor del identificador y del rotulo', function (): void {
    // Un ajuste se teclea a mano en un panel, y `« worked_hours = Horas »` es lo
    // que sale de copiar y pegar de un correo.
    $layout = plantilla([' worked_hours =  Horas trabajadas ']);

    expect($layout->columns)->toBe([PayrollColumn::WorkedHours])
        ->and($layout->labelFor(PayrollColumn::WorkedHours))->toBe('Horas trabajadas');
})->group('RF-IN-07');

it('recorta los espacios tambien cuando la entrada no lleva rotulo', function (): void {
    // El caso SIN el delimitador es otra rama, y sin recorte ahi un
    // `« worked_hours »` copiado de un correo se descartaria como identificador
    // desconocido: la columna desapareceria del fichero de nomina sin decir nada.
    $layout = plantilla(['  worked_hours  ']);

    expect($layout->columns)->toBe([PayrollColumn::WorkedHours])
        ->and($layout->rejected)->toBe([]);
})->group('RF-IN-07');

it('descarta al leer un identificador que este binario no conoce, sin lanzar', function (): void {
    // LECTURA TOLERANTE (regla dura 19). Solo puede venir de una fila escrita por
    // otra version del producto —guardar un identificador desconocido es `422`—,
    // y reventar ahi dejaria a RRHH sin exportacion el dia de cierre de nomina.
    $layout = plantilla(['employee_code', 'horas_del_jefe', 'worked_hours']);

    expect($layout->columns)->toBe([PayrollColumn::EmployeeCode, PayrollColumn::WorkedHours])
        // El descarte NO es silencioso: queda anotado para que alguien lo mire.
        ->and($layout->rejected)->toBe(['horas_del_jefe']);
})->group('RF-IN-07', 'RF-PD-01');

it('descarta la segunda aparicion de una columna repetida', function (): void {
    // Una columna dos veces descuadra la plantilla de importacion sin que se
    // note. Gana la primera, que es donde el cliente la coloco.
    $layout = plantilla(['worked_hours=Horas', 'employee_code', 'worked_hours']);

    expect($layout->columns)->toBe([PayrollColumn::WorkedHours, PayrollColumn::EmployeeCode])
        ->and($layout->labelFor(PayrollColumn::WorkedHours))->toBe('Horas')
        ->and($layout->rejected)->toBe(['worked_hours']);
})->group('RF-IN-07');

it('cae a la plantilla de serie cuando no queda ni una columna reconocible', function (): void {
    // Un CSV sin ninguna celda se parece a «no hay nadie con horas», que es una
    // afirmacion muy distinta de «la configuracion es de otra version».
    $layout = plantilla(['columna_inventada', 'otra_mas']);

    expect($layout->columns)->toBe(PayrollLayout::shipped()->columns);
})->group('RF-IN-07', 'RF-PD-01');

it('traduce cada valor de los cinco ajustes de forma a su enumerado', function (
    string $delimiter,
    string $hours,
    string $dates,
    string $encoding,
    string $header,
    string $expectedCharacter,
    bool $expectedHeader,
): void {
    $layout = plantilla(PayrollLayout::DEFAULT_COLUMNS, $delimiter, $hours, $dates, $encoding, $header);

    expect($layout->delimiter->character())->toBe($expectedCharacter)
        ->and($layout->hoursFormat->value)->toBe($hours)
        ->and($layout->dateFormat->value)->toBe($dates)
        ->and($layout->encoding->value)->toBe($encoding)
        ->and($layout->hasHeaderRow)->toBe($expectedHeader);
})->with([
    'de serie' => ['semicolon', 'hhmm', 'iso', 'utf8_bom', 'enabled', ';', true],
    'coma y decimal con punto' => ['comma', 'decimal_dot', 'dmy', 'utf8', 'enabled', ',', true],
    'tabulador, latin1 y sin cabecera' => ['tab', 'decimal_comma', 'dmy', 'latin1', 'disabled', "\t", false],
])->group('RF-IN-07', 'RF-PD-01');

it('cae al valor de serie de cada ajuste de forma cuando lo guardado no existe', function (): void {
    // Igual que con las columnas: al leer no se lanza. Una fila corrupta se
    // descarta y rige el valor del producto.
    $layout = plantilla(PayrollLayout::DEFAULT_COLUMNS, 'pipe', 'sexagesimal', 'mdy', 'utf16', 'on');

    expect($layout->delimiter)->toBe(PayrollDelimiter::Semicolon)
        ->and($layout->hoursFormat)->toBe(PayrollHoursFormat::HoursMinutes)
        ->and($layout->dateFormat)->toBe(PayrollDateFormat::Iso)
        ->and($layout->encoding)->toBe(PayrollEncoding::Utf8Bom)
        // La cabecera es el unico ajuste cuyo valor desconocido NO cae al de
        // serie sino al lado seguro, que resulta ser el mismo: un fichero con
        // rotulos de mas se arregla mirandolo, y uno sin rotulos obliga a contar
        // columnas a mano.
        ->and($layout->hasHeaderRow)->toBeTrue();
})->group('RF-IN-07', 'RF-PD-01');

it('publica el catalogo de columnas entero, sin repetidos', function (): void {
    // Es la lista que enumera el contrato, la que valida el ajuste y la que el
    // panel ofrece como ayuda. Sale del enumerado y no de un literal: una columna
    // nueva entra sola en los tres sitios.
    $ids = PayrollColumn::ids();

    expect($ids)->toHaveCount(20)
        ->and(array_unique($ids))->toHaveCount(20)
        ->and($ids)->toContain('employee_code', 'unjustified_absence_days', 'time_zone');
})->group('RF-IN-07');

it('clasifica como duracion exactamente las cuatro columnas de tiempo', function (): void {
    // La propiedad esta en el catalogo y no en quien escribe la celda: con la
    // lista repartida, una columna nueva de duracion saldria en `HH:MM` en el CSV
    // y en decimal en el XLSX.
    $durations = array_values(array_filter(
        PayrollColumn::cases(),
        static fn (PayrollColumn $column): bool => $column->isDuration(),
    ));

    expect($durations)->toBe([
        PayrollColumn::WorkedHours,
        PayrollColumn::ContractedHours,
        PayrollColumn::DeviationHours,
        PayrollColumn::OvertimeHours,
    ]);
})->group('RF-IN-07');

it('clasifica como fecha exactamente las dos columnas de periodo', function (): void {
    $dates = array_values(array_filter(
        PayrollColumn::cases(),
        static fn (PayrollColumn $column): bool => $column->isDate(),
    ));

    expect($dates)->toBe([PayrollColumn::PeriodFrom, PayrollColumn::PeriodTo]);
})->group('RF-IN-07');

it('devuelve una clave de traduccion como rotulo por omision, nunca un texto', function (): void {
    // El dominio no tiene idioma. Si `label()` devolviera «Horas trabajadas», un
    // cliente en ingles recibiria una cabecera en castellano y nadie se enteraria
    // hasta la demo.
    foreach (PayrollColumn::cases() as $column) {
        expect($column->label())->toBe('payroll.columns.'.$column->value);
    }
})->group('RF-IN-07');

it('usa el mismo delimitador de rotulo que la validacion del ajuste', function (): void {
    // LAS DOS MITADES TIENEN QUE COINCIDIR: si la validacion partiera por `=` y la
    // plantilla por `:`, el ajuste se guardaria y el fichero saldria con la
    // columna sin rotular. Una convencion que no verifica una herramienta es una
    // sugerencia (doc 02 §3.5).
    expect(PayrollLayout::LABEL_SEPARATOR)->toBe(SettingDefinition::LABEL_SEPARATOR);
})->group('RF-IN-07', 'RF-PD-01');
