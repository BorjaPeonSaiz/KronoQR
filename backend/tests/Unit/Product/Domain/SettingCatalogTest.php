<?php

declare(strict_types=1);

use App\Modules\Product\Domain\Exception\InvalidSettingValue;
use App\Modules\Product\Domain\Exception\UnknownSettingKey;
use App\Modules\Product\Domain\ValueObject\SettingImpact;
use App\Modules\Product\Domain\ValueObject\SettingKey;
use App\Modules\Product\Domain\ValueObject\SettingValue;

/*
 * El catalogo de claves de configuracion (RF-PD-01, ADR-017).
 *
 * Dominio puro: ni framework ni base de datos. Lo que se comprueba aqui es que
 * el catalogo es completo, que sus valores de serie son validos segun sus
 * propias reglas —si no lo fueran, una instalacion sin filas no arrancaria— y
 * que marca correctamente que claves afectan al calculo de horas, que es lo que
 * el asiento de auditoria del `PATCH` va a escribir.
 */

it('declara una definicion para cada clave del catalogo', function (): void {
    // El catalogo es un literal, no un match exhaustivo: esta prueba es lo que
    // impide que una clave nueva se quede sin definicion y falle en produccion.
    foreach (SettingKey::cases() as $key) {
        expect($key->definition())->not->toBeNull();
    }
})->group('RF-PD-01');

it('acepta el valor de serie de cada clave contra su propia definicion', function (): void {
    // Si un valor por defecto no cumpliera su definicion, una instalacion sin
    // ninguna fila en installation_settings no arrancaria — que es justo el
    // caso que el paso 3 de la tarea exige que funcione.
    foreach (SettingKey::cases() as $key) {
        $definition = $key->definition();

        expect($definition->validate($key, $definition->default))->toBe($definition->default);
    }
})->group('RF-PD-01');

it('conserva las cuatro claves operativas que sembro la migracion, con su valor del Anexo B', function (string $key, int $expected): void {
    // Renombrarlas seria una migracion de datos a cambio de nada, y ademas son
    // identificadores tecnicos internos (doc 02 §5.8). Si esta prueba cambia,
    // hay que cambiar tambien la migracion 1.3 y el Anexo B.
    $setting = SettingKey::fromString($key);

    expect($setting->definition()->default)->toBe($expected);
})->with([
    'duracion anomala de tramo (RN-08)' => ['ATTENDANCE_MAX_SHIFT_HOURS', 12],
    'ventana anti-rebote (RF-AT-06)' => ['ATTENDANCE_DEBOUNCE_SECONDS', 60],
    'desfase de reloj tolerado (RF-AT-10)' => ['ATTENDANCE_MAX_CLOCK_SKEW_MINUTES', 15],
    'transito minimo entre quioscos (RN-16)' => ['ATTENDANCE_MIN_TRANSIT_SECONDS', 120],
])->group('RF-PD-01');

it('marca como clave que afecta al calculo de horas exactamente la ventana anti-rebote', function (): void {
    // Es la unica que cambia los minutos registrados: un escaneo que la ventana
    // se traga no cierra el tramo. Las otras tres abren o dejan de abrir
    // incidencias, y ninguna cierra, corrige ni descarta nada (doc 01 §4).
    $affecting = array_values(array_filter(
        SettingKey::cases(),
        static fn (SettingKey $key): bool => $key->definition()->impact->affectsWorkedHours(),
    ));

    expect($affecting)->toBe([SettingKey::ATTENDANCE_DEBOUNCE_SECONDS]);
})->group('RF-PD-01');

it('clasifica el impacto de cada clave', function (SettingKey $key, SettingImpact $impact): void {
    expect($key->definition()->impact)->toBe($impact);
})->with([
    'el maximo de tramo abre incidencia, no cambia minutos' => [SettingKey::ATTENDANCE_MAX_SHIFT_HOURS, SettingImpact::COMPLIANCE_REVIEW],
    'el desfase de reloj nunca rechaza el fichaje' => [SettingKey::ATTENDANCE_MAX_CLOCK_SKEW_MINUTES, SettingImpact::COMPLIANCE_REVIEW],
    'el transito minimo abre incidencia (RN-16)' => [SettingKey::ATTENDANCE_MIN_TRANSIT_SECONDS, SettingImpact::COMPLIANCE_REVIEW],
    'el nombre de la aplicacion solo se ve' => [SettingKey::BRANDING_APP_NAME, SettingImpact::PRESENTATION],
    'el logotipo solo se ve' => [SettingKey::BRANDING_LOGO_PATH, SettingImpact::PRESENTATION],
    'el color de acento solo se ve' => [SettingKey::BRANDING_ACCENT_COLOR, SettingImpact::PRESENTATION],
    'el idioma por defecto solo se ve' => [SettingKey::LOCALE_DEFAULT, SettingImpact::PRESENTATION],
    'los idiomas disponibles solo se ven' => [SettingKey::LOCALE_AVAILABLE, SettingImpact::PRESENTATION],
    'el codigo de servicio no mueve ni un minuto' => [SettingKey::KIOSK_SERVICE_CODE, SettingImpact::PRESENTATION],
])->group('RF-PD-01');

// --- El codigo de servicio del quiosco (RF-KI-08, tarea 3.3) ----------------

it('acepta como codigo de servicio de 8 a 12 cifras, y nada mas', function (string $code, bool $valid): void {
    // NUMERICO Y NO ALFANUMERICO porque la tablet solo tiene el teclado en
    // pantalla de `PinNumericKeypad`: un codigo con letras seria un codigo que
    // nadie puede teclear donde hay que teclearlo (decision 6 de la ficha 3.3).
    $definition = SettingKey::KIOSK_SERVICE_CODE->definition();
    $validate = fn (): int|string|array => $definition->validate(SettingKey::KIOSK_SERVICE_CODE, $code);

    $valid
        ? expect($validate())->toBe($code)
        : expect($validate)->toThrow(InvalidSettingValue::class);
})->with([
    'ocho cifras, el minimo' => ['12345678', true],
    'doce cifras, el maximo' => ['123456789012', true],
    'diez cifras' => ['1234567890', true],
    'siete cifras, corto' => ['1234567', false],
    'trece cifras, largo' => ['1234567890123', false],
    'con letras' => ['1234abcd', false],
    'con espacios' => ['1234 5678', false],
    'con guion' => ['1234-5678', false],
])->group('RF-PD-01', 'RF-KI-08');

it('deja quitar el codigo de servicio con la cadena vacia', function (): void {
    // El vacio es el valor de serie y significa «la pantalla se abre sin
    // codigo». Si el patron se comprobara tambien sobre el vacio, un codigo ya
    // configurado no se podria RETIRAR nunca desde el panel.
    $definition = SettingKey::KIOSK_SERVICE_CODE->definition();

    expect($definition->default)->toBe('')
        ->and($definition->validate(SettingKey::KIOSK_SERVICE_CODE, ''))->toBe('');
})->group('RF-PD-01', 'RF-KI-08');

it('marca como confidencial el codigo de servicio y solo el codigo de servicio', function (): void {
    // LA MARCA QUE IMPIDE QUE EL VALOR ACABE EN `audit_log` Y EN EL PAQUETE DE
    // DIAGNOSTICO. Vive en la definicion y no en el listener a proposito: si la
    // decision estuviera en quien escribe el asiento, la clave siguiente que
    // hubiera que proteger se olvidaria. Y se afirma ademas que **solo** esta lo
    // esta, porque marcar de mas convertiria el trail de los umbrales en un
    // «alguien cambio algo» inservible para RL-04.
    $confidential = array_values(array_filter(
        SettingKey::cases(),
        static fn (SettingKey $key): bool => $key->definition()->confidential,
    ));

    expect($confidential)->toBe([SettingKey::KIOSK_SERVICE_CODE]);
})->group('RF-PD-01', 'RF-KI-08', 'RL-04');

it('rechaza una clave que no esta en el catalogo', function (): void {
    // Aceptarla produciria una fila que no lee nadie: el cliente creeria haber
    // configurado un umbral y el sistema seguiria aplicando el de serie.
    expect(fn (): SettingKey => SettingKey::fromString('ATTENDANC_MAX_SHIFT_HOURS'))
        ->toThrow(UnknownSettingKey::class);
})->group('RF-PD-01');

it('entrega el valor de serie marcado como tal', function (): void {
    // La procedencia viaja con el valor: el panel enseña cual esta configurado
    // y cual sigue siendo el del producto.
    $value = SettingValue::productDefault(SettingKey::BRANDING_APP_NAME);

    expect($value->asText())->toBe('KronoQR')
        ->and($value->isProductDefault)->toBeTrue();
})->group('RF-PD-01');

// --- El fichaje de pausa (RF-AT-12, ADR-024, tarea 3.5) ----------------------

it('entrega el fichaje de pausa desactivado de serie', function (): void {
    // **`disabled` y no `enabled`.** El doc 05 lo vende como opcional, y arrancar
    // activado reactivaria RN-12 (`ComplianceRuleSuspension`) en una plantilla
    // que descansa sin fichar: incidencias `missing_break` contra gente que no
    // hizo nada mal, el primer dia de uso.
    $definition = SettingKey::ATTENDANCE_BREAK_CLOCKING->definition();

    expect($definition->default)->toBe('disabled')
        ->and($definition->validate(SettingKey::ATTENDANCE_BREAK_CLOCKING, 'enabled'))->toBe('enabled')
        ->and($definition->validate(SettingKey::ATTENDANCE_BREAK_CLOCKING, 'disabled'))->toBe('disabled');
})->group('RF-PD-01', 'RF-AT-12');

it('admite solo los dos valores del fichaje de pausa', function (string $value): void {
    // `choice` de dos valores y no un tipo booleano nuevo (decision 7 de la ficha
    // 3.5): anadir `SettingType::BOOLEAN` obligaria a ampliar el enum, el
    // contrato, la validacion y el panel para una sola clave, y un enumerado
    // admite una tercera opcion el dia que la haya sin migrar lo guardado.
    expect(fn (): int|string|array => SettingKey::ATTENDANCE_BREAK_CLOCKING->definition()
        ->validate(SettingKey::ATTENDANCE_BREAK_CLOCKING, $value))
        ->toThrow(InvalidSettingValue::class);
})->with([
    'booleano de PHP serializado' => ['1'],
    'booleano en texto' => ['true'],
    'vacio' => [''],
    'mayusculas' => ['ENABLED'],
])->group('RF-PD-01', 'RF-AT-12');

it('clasifica el fichaje de pausa como revision de cumplimiento y no como calculo de horas', function (): void {
    // No mueve ni un minuto: la pausa son dos tramos y su tiempo simplemente no
    // esta en ninguno (ADR-024), asi que no hay ninguna resta que hacer. Lo que
    // si cambia es **que jornadas se marcan**, porque reactiva RN-12, y el
    // asiento de `installation_setting.changed` tiene que decirlo.
    $definition = SettingKey::ATTENDANCE_BREAK_CLOCKING->definition();

    expect($definition->impact)->toBe(SettingImpact::COMPLIANCE_REVIEW)
        ->and($definition->impact->affectsWorkedHours())->toBeFalse()
        ->and($definition->confidential)->toBeFalse();
})->group('RF-PD-01', 'RF-AT-12', 'RL-04');
