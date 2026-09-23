<?php

declare(strict_types=1);

use App\Modules\Product\Domain\Exception\InvalidSettingValue;
use App\Modules\Product\Domain\ValueObject\SettingKey;
use App\Modules\Product\Domain\ValueObject\SettingType;
use App\Modules\Shared\Domain\ValueObject\PayrollColumn;
use App\Modules\Shared\Domain\ValueObject\PayrollLayout;

/*
 * **Las seis claves de la salida a nomina** (RF-IN-07, RF-PD-01, ADR-017, regla
 * dura 13).
 *
 * Fichero propio y no dentro de `SettingCatalogTest`, por lo mismo que
 * `SettingsSurfaceTest` se separo de `ClientDocumentationTest`: aquel afirma que
 * el catalogo es completo y coherente, y esto afirma que **el formato de un
 * fichero con consecuencias de nomina es dato y no codigo**. Son dos preguntas
 * distintas y la segunda tiene bastantes bordes propios.
 *
 * Dominio puro: ni framework, ni base de datos.
 */

it('entrega de serie las diez columnas de nomina que casi todo programa pide', function (): void {
    // EL VALOR DE SERIE ES EL PRODUCTO. Una instalacion sin ninguna fila de
    // `installation_settings` tiene que poder exportar a nomina, y lo que salga
    // tiene que ser util sin configurar nada: quien, de cuando a cuando, cuanto
    // trabajo, cuanto tenia contratado, cuanto de mas y cuantos dias falto
    // justificadamente.
    //
    // La lista vive en `Shared\Domain\ValueObject\PayrollLayout` y no en el
    // catalogo: escribirla dos veces seria tener dos plantillas de serie, y la
    // que ganaria seria la que nadie mira.
    $definition = SettingKey::PAYROLL_EXPORT_COLUMNS->definition();

    expect($definition->default)->toBe(PayrollLayout::DEFAULT_COLUMNS)
        ->and($definition->type)->toBe(SettingType::TEXT_LIST)
        ->and($definition->allowsLabels)->toBeTrue()
        // El catalogo de identificadores viaja en `constraints.allowed` para que
        // el panel lo ofrezca sin llevarlo copiado en TypeScript.
        ->and($definition->allowed)->toBe(PayrollColumn::ids());
})->group('RF-PD-01', 'RF-IN-07');

it('acepta una columna de nomina con rotulo y sin rotulo', function (): void {
    // `id` o `id=Etiqueta`: el identificador es del producto —el catalogo es
    // cerrado porque el SIGNIFICADO de la columna lo fija el producto— y el
    // rotulo es del cliente, porque tiene que casar con la plantilla de
    // importacion de SU programa de nomina (ADR-017, regla dura 13).
    $definition = SettingKey::PAYROLL_EXPORT_COLUMNS->definition();
    $value = ['employee_code', 'worked_hours=Horas trabajadas', 'period_from=Desde'];

    expect($definition->validate(SettingKey::PAYROLL_EXPORT_COLUMNS, $value))->toBe($value);
})->group('RF-PD-01', 'RF-IN-07');

it('rechaza al guardar una columna de nomina que el catalogo no admite', function (array $value): void {
    // ESCRITURA ESTRICTA: aqui hay una persona delante del panel a la que se le
    // puede decir cual es el identificador malo. Aceptarlo produciria una columna
    // vacia que nadie ve hasta que el gestor cuadra la nomina.
    expect(fn (): int|string|array => SettingKey::PAYROLL_EXPORT_COLUMNS->definition()
        ->validate(SettingKey::PAYROLL_EXPORT_COLUMNS, $value))
        ->toThrow(InvalidSettingValue::class);
})->with([
    'identificador inventado' => [['horas_del_jefe']],
    'identificador inventado con rotulo' => [['horas_del_jefe=Horas']],
    'columna repetida' => [['worked_hours', 'worked_hours']],
    // La misma columna dos veces, una con rotulo: la comparacion es por
    // IDENTIFICADOR y no por la entrada entera. Aceptarla descuadraria la
    // plantilla de importacion sin que se note.
    'columna repetida con rotulo distinto' => [['worked_hours', 'worked_hours=Horas']],
    'rotulo vacio' => [['worked_hours=']],
    'rotulo con un segundo igual, que tendria dos lecturas' => [['worked_hours=Horas=netas']],
    'rotulo de mas de 60 caracteres' => [['worked_hours=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']],
    'lista vacia' => [[]],
    'una entrada que no es texto' => [[7]],
])->group('RF-PD-01', 'RF-IN-07');

it('admite un rotulo de nomina de exactamente 60 caracteres', function (): void {
    // El limite es inclusivo. Se comprueba el borde porque un `>=` donde va un
    // `>` rechazaria un rotulo legitimo y nadie sabria por que.
    $value = ['worked_hours='.str_repeat('x', PayrollLayout::MAXIMUM_LABEL_LENGTH)];

    expect(SettingKey::PAYROLL_EXPORT_COLUMNS->definition()
        ->validate(SettingKey::PAYROLL_EXPORT_COLUMNS, $value))->toBe($value);
})->group('RF-PD-01', 'RF-IN-07');

it('deriva del catalogo la forma que admite una entrada de columna de nomina', function (): void {
    // La regla del `FormRequest` sale de AQUI y no de una segunda copia del
    // catalogo escrita a mano: con dos listas, el `422` del borde acabaria
    // diciendo algo distinto del que lanza el dominio.
    $pattern = SettingKey::PAYROLL_EXPORT_COLUMNS->definition()->labelledItemPattern();

    expect($pattern)->not->toBeNull();
    expect(preg_match((string) $pattern, 'worked_hours'))->toBe(1);
    expect(preg_match((string) $pattern, 'worked_hours=Horas trabajadas'))->toBe(1);
    expect(preg_match((string) $pattern, 'horas_del_jefe'))->toBe(0);
    expect(preg_match((string) $pattern, 'worked_hours=Horas=netas'))->toBe(0);
    expect(preg_match((string) $pattern, 'worked_hours='))->toBe(0);

    // Y una lista SIN rotulo no tiene forma: la valida `in:` de toda la vida.
    expect(SettingKey::LOCALE_AVAILABLE->definition()->labelledItemPattern())->toBeNull();
})->group('RF-PD-01', 'RF-IN-07');

it('entrega los cinco ajustes de forma del fichero de nomina con su valor de serie', function (
    SettingKey $key,
    string $default,
    array $allowed,
): void {
    // Los valores de serie de la decision 5 de la ficha 3.9. `hhmm` entre ellos:
    // el decimal existe porque lo exigen muchos programas de nomina, pero el
    // producto no lo entrega de serie (`/informe-nuevo`, paso 6).
    $definition = $key->definition();

    expect($definition->default)->toBe($default)
        ->and($definition->allowed)->toBe($allowed);
})->with([
    'separador' => [SettingKey::PAYROLL_EXPORT_DELIMITER, 'semicolon', ['semicolon', 'comma', 'tab']],
    'horas' => [SettingKey::PAYROLL_EXPORT_HOURS_FORMAT, 'hhmm', ['hhmm', 'decimal_dot', 'decimal_comma']],
    'fechas' => [SettingKey::PAYROLL_EXPORT_DATE_FORMAT, 'iso', ['iso', 'dmy']],
    'codificacion' => [SettingKey::PAYROLL_EXPORT_ENCODING, 'utf8_bom', ['utf8_bom', 'utf8', 'latin1']],
    'fila de cabecera' => [SettingKey::PAYROLL_EXPORT_HEADER_ROW, 'enabled', ['enabled', 'disabled']],
])->group('RF-PD-01', 'RF-IN-07');

it('rechaza un valor fuera del conjunto cerrado en los cinco ajustes de forma', function (SettingKey $key, string $value): void {
    expect(fn (): int|string|array => $key->definition()->validate($key, $value))
        ->toThrow(InvalidSettingValue::class);
})->with([
    'un separador que no esta' => [SettingKey::PAYROLL_EXPORT_DELIMITER, 'pipe'],
    // El NOMBRE y no el caracter: guardar `;` dejaria la puerta abierta a guardar
    // `"` o un retorno de carro, y cualquiera de los dos produce un fichero que
    // no se puede volver a leer.
    'el caracter en vez del nombre' => [SettingKey::PAYROLL_EXPORT_DELIMITER, ';'],
    'un formato de horas inventado' => [SettingKey::PAYROLL_EXPORT_HOURS_FORMAT, 'sexagesimal'],
    // `mdy` no esta, y la ausencia es deliberada: `03/04/2026` significa cosas
    // distintas en los dos lados del Atlantico.
    'el formato de fecha ambiguo' => [SettingKey::PAYROLL_EXPORT_DATE_FORMAT, 'mdy'],
    'una codificacion inventada' => [SettingKey::PAYROLL_EXPORT_ENCODING, 'utf16'],
    'un booleano en la cabecera' => [SettingKey::PAYROLL_EXPORT_HEADER_ROW, 'true'],
])->group('RF-PD-01', 'RF-IN-07');

it('no marca confidencial ninguna clave de nomina', function (SettingKey $key): void {
    // El formato del fichero no es un secreto del cliente: el panel lo enseña, el
    // asiento de auditoria lo copia y el paquete de diagnostico lo puede llevar.
    // Marcarlo dejaria a quien atiende una incidencia sin poder ver por que sale
    // un fichero con otras columnas.
    expect($key->definition()->confidential)->toBeFalse();
})->with([
    'columnas' => [SettingKey::PAYROLL_EXPORT_COLUMNS],
    'separador' => [SettingKey::PAYROLL_EXPORT_DELIMITER],
    'horas' => [SettingKey::PAYROLL_EXPORT_HOURS_FORMAT],
    'fechas' => [SettingKey::PAYROLL_EXPORT_DATE_FORMAT],
    'codificacion' => [SettingKey::PAYROLL_EXPORT_ENCODING],
    'cabecera' => [SettingKey::PAYROLL_EXPORT_HEADER_ROW],
])->group('RF-PD-01', 'RF-IN-07', 'RL-04');
