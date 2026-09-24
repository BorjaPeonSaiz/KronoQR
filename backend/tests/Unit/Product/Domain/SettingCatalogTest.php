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

it('conserva los dos umbrales de la deteccion de patrones, con su valor del Anexo B', function (string $key, int $expected): void {
    // RF-PR-06 (tarea 3.11). Los dos valores estan en el Anexo B del doc 02 y en
    // `.env.example` desde la Fase 0; lo que la 3.11 anade es que alguien los
    // lea. Si esta prueba cambia, hay que cambiar tambien el Anexo B.
    expect(SettingKey::fromString($key)->definition()->default)->toBe($expected);
})->with([
    'ventana de coincidencia en el mismo quiosco' => ['ATTENDANCE_PATTERN_WINDOW_SECONDS', 10],
    'dias con coincidencia antes de abrir incidencia' => ['ATTENDANCE_PATTERN_MIN_REPEATS', 3],
])->group('RF-PD-01', 'RF-PR-06');

it('admite el cero solo en la ventana de coincidencia, no en los dias', function (): void {
    // El cero de la ventana APAGA el hallazgo, que es una decision legitima de un
    // centro. El cero en los dias no significa nada: «sistematico» con cero
    // repeticiones abriria incidencia sin haber observado nada.
    $window = SettingKey::ATTENDANCE_PATTERN_WINDOW_SECONDS;
    $repeats = SettingKey::ATTENDANCE_PATTERN_MIN_REPEATS;

    expect($window->definition()->validate($window, 0))->toBe(0)
        ->and(fn (): mixed => $repeats->definition()->validate($repeats, 0))
        ->toThrow(InvalidSettingValue::class);
})->group('RF-PD-01', 'RF-PR-06');

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

it('la salida de datos personales no cuenta como calculo de horas', function (): void {
    // `DATA_DISCLOSURE` describe **a donde van los datos**, no cuanto suman: un
    // `affectsWorkedHours()` verdadero aqui llenaria de ruido la unica señal que
    // sirve para explicar una discrepancia de nomina dos años despues.
    $disclosure = array_values(array_filter(
        SettingKey::cases(),
        static fn (SettingKey $key): bool => $key->definition()->impact === SettingImpact::DATA_DISCLOSURE,
    ));

    expect($disclosure)->toBe([SettingKey::WEEKLY_SUMMARY_EMAIL])
        ->and(SettingImpact::DATA_DISCLOSURE->affectsWorkedHours())->toBeFalse();
})->group('RF-PD-01', 'RF-PR-05');

it('clasifica el impacto de cada clave', function (SettingKey $key, SettingImpact $impact): void {
    expect($key->definition()->impact)->toBe($impact);
})->with([
    'el maximo de tramo abre incidencia, no cambia minutos' => [SettingKey::ATTENDANCE_MAX_SHIFT_HOURS, SettingImpact::COMPLIANCE_REVIEW],
    'el desfase de reloj nunca rechaza el fichaje' => [SettingKey::ATTENDANCE_MAX_CLOCK_SKEW_MINUTES, SettingImpact::COMPLIANCE_REVIEW],
    'el transito minimo abre incidencia (RN-16)' => [SettingKey::ATTENDANCE_MIN_TRANSIT_SECONDS, SettingImpact::COMPLIANCE_REVIEW],
    // RF-PR-06. Cambian que se pone en la bandeja para revision humana y no
    // mueven ni un minuto: la deteccion de patrones nunca anula ni marca un
    // fichaje (reglas duras 5 y 19).
    'la ventana de coincidencia abre incidencia (RF-PR-06)' => [SettingKey::ATTENDANCE_PATTERN_WINDOW_SECONDS, SettingImpact::COMPLIANCE_REVIEW],
    'los dias con coincidencia abren incidencia (RF-PR-06)' => [SettingKey::ATTENDANCE_PATTERN_MIN_REPEATS, SettingImpact::COMPLIANCE_REVIEW],
    'el nombre de la aplicacion solo se ve' => [SettingKey::BRANDING_APP_NAME, SettingImpact::PRESENTATION],
    'el logotipo solo se ve' => [SettingKey::BRANDING_LOGO_PATH, SettingImpact::PRESENTATION],
    'el color de acento solo se ve' => [SettingKey::BRANDING_ACCENT_COLOR, SettingImpact::PRESENTATION],
    'el idioma por defecto solo se ve' => [SettingKey::LOCALE_DEFAULT, SettingImpact::PRESENTATION],
    'los idiomas disponibles solo se ven' => [SettingKey::LOCALE_AVAILABLE, SettingImpact::PRESENTATION],
    'el codigo de servicio no mueve ni un minuto' => [SettingKey::KIOSK_SERVICE_CODE, SettingImpact::PRESENTATION],
    // Las seis de la salida a nomina (RF-IN-07, tarea 3.9). Ninguna mueve un
    // minuto ni abre una incidencia: cambian como se ESCRIBE el fichero, nunca
    // lo que el informe calcula.
    'las columnas del fichero de nomina solo cambian el fichero' => [SettingKey::PAYROLL_EXPORT_COLUMNS, SettingImpact::PRESENTATION],
    'el separador de nomina solo cambia el fichero' => [SettingKey::PAYROLL_EXPORT_DELIMITER, SettingImpact::PRESENTATION],
    'el formato de horas de nomina solo cambia el fichero' => [SettingKey::PAYROLL_EXPORT_HOURS_FORMAT, SettingImpact::PRESENTATION],
    'el formato de fechas de nomina solo cambia el fichero' => [SettingKey::PAYROLL_EXPORT_DATE_FORMAT, SettingImpact::PRESENTATION],
    'la codificacion de nomina solo cambia el fichero' => [SettingKey::PAYROLL_EXPORT_ENCODING, SettingImpact::PRESENTATION],
    'la fila de cabecera de nomina solo cambia el fichero' => [SettingKey::PAYROLL_EXPORT_HEADER_ROW, SettingImpact::PRESENTATION],
    // Las tres de la tarea 3.12. Ninguna mueve un minuto ni abre una incidencia:
    // la primera decide si sale un correo y las dos ultimas, cuando puede
    // recargarse una tablet (RF-PR-05, RF-KI-07).
    // Y la unica `DATA_DISCLOSURE` del catalogo: no mueve minutos, pero
    // enciende una salida de datos personales de la instalacion.
    'el resumen semanal enciende una salida de datos' => [SettingKey::WEEKLY_SUMMARY_EMAIL, SettingImpact::DATA_DISCLOSURE],
    'la ventana de actualizacion no toca el registro' => [SettingKey::KIOSK_UPDATE_WINDOW, SettingImpact::PRESENTATION],
    'los minutos de silencio no tocan el registro' => [SettingKey::KIOSK_UPDATE_QUIET_MINUTES, SettingImpact::PRESENTATION],
    // La linea base del cuadro de impacto (RF-IN-08, tarea 3.13). No mueve un
    // minuto, no abre incidencia y no enciende ninguna salida de datos: solo
    // decide si una tarjeta del cuadro ensena una referencia o sale vacia.
    'la linea base de horas manuales solo se ve' => [SettingKey::BASELINE_MANUAL_HOURS_PER_MONTH, SettingImpact::PRESENTATION],
])->group('RF-PD-01');

// --- El resumen semanal y la ventana del quiosco (RF-PR-05, RF-KI-07) -------

it('entrega el resumen semanal por correo desactivado de serie', function (): void {
    // Doc 05 §5.7: «correo opcional». Lo que sale por SMTP son nombres de la
    // plantilla, asi que una instalacion recien puesta en marcha no manda datos
    // personales a ninguna parte hasta que alguien lo decide en el panel.
    $definition = SettingKey::WEEKLY_SUMMARY_EMAIL->definition();

    expect($definition->default)->toBe('disabled')
        ->and($definition->allowed)->toBe(['enabled', 'disabled']);
})->group('RF-PR-05', 'RF-PD-01');

it('acepta como ventana de actualizacion una franja HH:MM-HH:MM y nada mas', function (string $value, bool $valid): void {
    // La forma la presta el objeto de valor del dominio y se copia aqui para que
    // un valor mal escrito de un `422` con una persona delante, en vez de un
    // fallo mas adentro — donde ya no hay a quien decirselo.
    $definition = SettingKey::KIOSK_UPDATE_WINDOW->definition();
    $validate = fn (): int|string|array => $definition->validate(SettingKey::KIOSK_UPDATE_WINDOW, $value);

    $valid
        ? expect($validate())->toBe($value)
        : expect($validate)->toThrow(InvalidSettingValue::class);
})->with([
    'la de serie' => ['03:00-05:00', true],
    'cruzando la medianoche' => ['23:00-02:00', true],
    'los dos extremos iguales, que es «nunca sola»' => ['04:00-04:00', true],
    'una hora que no existe' => ['24:00-05:00', false],
    'sin ceros a la izquierda' => ['3:00-5:00', false],
    'un solo extremo' => ['03:00', false],
    'vacia' => ['', false],
])->group('RF-KI-07', 'RF-PD-01');

it('entrega la ventana de actualizacion de madrugada y diez minutos de silencio', function (): void {
    // De serie, de tres a cinco: lejos de los tres cambios de turno de un hotel
    // y dentro de la franja en la que ya corren las tareas nocturnas. Los diez
    // minutos de silencio cubren el turno que entra antes de lo previsto sin que
    // el producto tenga que saber cuando empieza (RF-KI-07).
    $quiet = SettingKey::KIOSK_UPDATE_QUIET_MINUTES->definition();

    expect(SettingKey::KIOSK_UPDATE_WINDOW->definition()->default)->toBe('03:00-05:00')
        ->and($quiet->default)->toBe(10)
        // Cero es legitimo y la desactiva; el maximo son dos horas, porque una
        // guarda mayor que la ventana de serie la dejaria cerrada para siempre
        // en un hotel con actividad de madrugada.
        ->and($quiet->minimum)->toBe(0)
        ->and($quiet->maximum)->toBe(120);
})->group('RF-KI-07', 'RF-PD-01');

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

// --- La linea base del cuadro de impacto (RF-IN-08, tarea 3.13) --------------

it('entrega la linea base de horas manuales sin declarar', function (): void {
    // CERO SIGNIFICA «NO DECLARADO», no «cero horas»: una instalacion recien puesta
    // en marcha no ha contestado todavia, y el cuadro de impacto traduce ese cero a
    // «vacio». Un valor de serie distinto de cero seria peor de todas las formas
    // posibles: el cuadro ensenaria una linea base inventada como si el cliente la
    // hubiera declarado.
    expect(SettingKey::BASELINE_MANUAL_HOURS_PER_MONTH->definition()->default)->toBe(0);
})->group('RF-PD-01', 'RF-IN-08');

it('acota la linea base entre cero y diez mil horas al mes', function (int $hours, bool $valid): void {
    // El techo no es un limite de negocio: son unas catorce personas a jornada
    // completa dedicadas solo a consolidar hojas de horas, asi que es la frontera
    // entre un dato y un error de tecleo. Un negativo no significa nada.
    $key = SettingKey::BASELINE_MANUAL_HOURS_PER_MONTH;

    $accepted = rescue(
        static fn (): bool => $key->definition()->validate($key, $hours) === $hours,
        false,
        report: false,
    );

    expect($accepted)->toBe($valid);
})->with([
    'sin declarar' => [0, true],
    'una hora al mes' => [1, true],
    'cuarenta horas al mes' => [40, true],
    'el techo exacto' => [10000, true],
    'por encima del techo' => [10001, false],
    'negativo' => [-1, false],
])->group('RF-PD-01', 'RF-IN-08');
