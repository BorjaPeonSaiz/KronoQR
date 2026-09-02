<?php

declare(strict_types=1);

use App\Modules\Product\Domain\Exception\InvalidSettingValue;
use App\Modules\Product\Domain\ValueObject\ResolvedSettings;
use App\Modules\Product\Domain\ValueObject\SettingKey;
use App\Modules\Product\Domain\ValueObject\SettingValue;

/*
 * La resolucion en cascada (RF-PD-01, paso 4 de la tarea 5.1).
 *
 * Dominio puro: entra lo que hay guardado, sale la configuracion con tipo. Sin
 * base de datos, sin cache y sin reloj — todo eso es del adaptador.
 *
 * La cascada tiene dos escalones y no tres: el ambito `site` desaparece porque
 * hay un centro por instalacion (ADR-040), y la variable de entorno no es un
 * escalon sino el valor de arranque con el que el instalador siembra la fila.
 */

it('deja ganar la fila de la instalacion sobre el valor de serie', function (): void {
    $settings = ResolvedSettings::resolve(['ATTENDANCE_MAX_SHIFT_HOURS' => 10]);

    expect($settings->integer(SettingKey::ATTENDANCE_MAX_SHIFT_HOURS))->toBe(10)
        ->and($settings->get(SettingKey::ATTENDANCE_MAX_SHIFT_HOURS)->isProductDefault)->toBeFalse();
})->group('RF-PD-01');

it('devuelve el valor de serie de cada clave cuando no hay ninguna fila', function (): void {
    // El requisito literal del paso 3: una instalacion sin ninguna fila en
    // installation_settings arranca y responde con los valores por defecto.
    $settings = ResolvedSettings::resolve([]);

    expect($settings->all())->toHaveCount(count(SettingKey::cases()))
        ->and($settings->integer(SettingKey::ATTENDANCE_DEBOUNCE_SECONDS))->toBe(60)
        ->and($settings->text(SettingKey::BRANDING_APP_NAME))->toBe('KronoQR')
        ->and($settings->text(SettingKey::BRANDING_LOGO_PATH))->toBe('')
        ->and($settings->textList(SettingKey::LOCALE_AVAILABLE))->toBe(['es', 'en'])
        ->and($settings->get(SettingKey::LOCALE_DEFAULT)->isProductDefault)->toBeTrue();
})->group('RF-PD-01');

it('resuelve cada clave por separado, sin que una fila arrastre a las demas', function (): void {
    $settings = ResolvedSettings::resolve(['BRANDING_APP_NAME' => 'Hotel de prueba']);

    expect($settings->text(SettingKey::BRANDING_APP_NAME))->toBe('Hotel de prueba')
        ->and($settings->text(SettingKey::BRANDING_ACCENT_COLOR))->toBe('#111827')
        ->and($settings->get(SettingKey::BRANDING_ACCENT_COLOR)->isProductDefault)->toBeTrue();
})->group('RF-PD-01');

it('ignora una fila con una clave que el catalogo no conoce, y la anota', function (): void {
    // Solo puede venir de una version posterior o de una edicion a mano, y no la
    // lee nadie. Hacerla fatal dejaria a un centro sin fichar por una fila
    // sobrante; callarla del todo esconderia una actualizacion a medias.
    $settings = ResolvedSettings::resolve([
        'ATTENDANCE_DEBOUNCE_SECONDS' => 30,
        'ATTENDANCE_LEGACY_TOLERANCE' => 5,
    ]);

    expect($settings->unknownKeys)->toBe(['ATTENDANCE_LEGACY_TOLERANCE'])
        ->and($settings->integer(SettingKey::ATTENDANCE_DEBOUNCE_SECONDS))->toBe(30);
})->group('RF-PD-01');

it('descarta al LEER una fila cuyo valor no es del tipo que declara su clave, y la anota', function (string $key, mixed $stored): void {
    // **Lectura tolerante.** Esta resolucion ocurre en el camino de fichaje: si
    // lanzara, una fila corrupta editada a mano —un color de marca escrito como
    // rgb(17,24,39)— haria que POST /scan respondiera un error y nadie pudiera
    // fichar (regla dura 19). Se descarta, rige el valor de serie, y la clave
    // queda anotada para que el descarte no sea silencioso: viaja en
    // meta.invalid_keys y deja un warning en el log.
    $settings = ResolvedSettings::resolve([$key => $stored]);

    $rejected = $settings->invalidKeys;

    expect($rejected)->toHaveCount(1)
        ->and($rejected[0]->key->value)->toBe($key)
        ->and($rejected[0]->translationKey)->toStartWith('settings.errors.')
        // Rige el valor de serie del catalogo, no el guardado.
        ->and($settings->get(SettingKey::fromString($key))->isProductDefault)->toBeTrue();
})->with([
    'un umbral escrito como cadena' => ['ATTENDANCE_MAX_SHIFT_HOURS', '12'],
    'un umbral con decimales' => ['ATTENDANCE_DEBOUNCE_SECONDS', 60.5],
    'un nombre de aplicacion numerico' => ['BRANDING_APP_NAME', 7],
    'un nombre de aplicacion vacio' => ['BRANDING_APP_NAME', ''],
    'un color que no es #rrggbb' => ['BRANDING_ACCENT_COLOR', 'azul'],
    'unos idiomas que no son lista' => ['LOCALE_AVAILABLE', 'es'],
    'unos idiomas con un elemento que no es cadena' => ['LOCALE_AVAILABLE', [1]],
    'unos idiomas vacios' => ['LOCALE_AVAILABLE', []],
    'unos idiomas repetidos' => ['LOCALE_AVAILABLE', ['es', 'es']],
    'un idioma que el producto no trae traducido' => ['LOCALE_DEFAULT', 'fr'],
])->group('RF-PD-01');

it('descarta al LEER un umbral fuera del rango que admite su clave', function (string $key, int $stored): void {
    $settings = ResolvedSettings::resolve([$key => $stored]);

    expect($settings->invalidKeys)->toHaveCount(1)
        ->and($settings->get(SettingKey::fromString($key))->isProductDefault)->toBeTrue();
})->with([
    'un tramo anomalo de mas de un dia' => ['ATTENDANCE_MAX_SHIFT_HOURS', 25],
    'un tramo anomalo de cero horas' => ['ATTENDANCE_MAX_SHIFT_HOURS', 0],
    'un anti-rebote negativo' => ['ATTENDANCE_DEBOUNCE_SECONDS', -1],
    // Cero apagaria la tolerancia de reloj y marcaria incidencia ante un segundo
    // de deriva; OperationalSettings tampoco lo admite.
    'un desfase de reloj tolerado de cero' => ['ATTENDANCE_MAX_CLOCK_SKEW_MINUTES', 0],
])->group('RF-PD-01');

it('acepta el cero en las dos claves donde apagar la comprobacion es legitimo', function (string $key): void {
    // Un centro puede querer el anti-rebote apagado, o tener dos quioscos
    // contiguos donde el transito real es de segundos (doc 01 §4, nota RN-16).
    $settings = ResolvedSettings::resolve([$key => 0]);

    expect($settings->integer(SettingKey::fromString($key)))->toBe(0);
})->with([
    'anti-rebote' => ['ATTENDANCE_DEBOUNCE_SECONDS'],
    'transito minimo' => ['ATTENDANCE_MIN_TRANSIT_SECONDS'],
])->group('RF-PD-01');

it('resuelve al LEER un idioma por defecto que no esta entre los disponibles', function (): void {
    // Invariante entre claves. Al leer no puede ser fatal —es el camino de
    // fichaje— y caer al valor de serie tampoco sirve, porque el de serie
    // tampoco tiene por que estar disponible. Se aplica el PRIMER idioma
    // disponible: es la unica salida determinista y deja la instalacion
    // sirviendo un idioma que existe de verdad.
    $settings = ResolvedSettings::resolve([
        'LOCALE_DEFAULT' => 'en',
        'LOCALE_AVAILABLE' => ['es'],
    ]);

    expect($settings->text(SettingKey::LOCALE_DEFAULT))->toBe('es')
        ->and($settings->invalidKeys)->toHaveCount(1)
        ->and($settings->invalidKeys[0]->key)->toBe(SettingKey::LOCALE_DEFAULT)
        ->and($settings->invalidKeys[0]->translationKey)->toBe('settings.errors.default_locale_not_available');
})->group('RF-PD-01');

it('rechaza al ESCRIBIR un idioma por defecto que no esta entre los disponibles', function (): void {
    // La otra mitad de la regla: escritura estricta. Aqui hay una persona
    // delante que puede corregir el formulario, asi que se le dice que no.
    $settings = ResolvedSettings::resolve([]);

    expect(fn (): ResolvedSettings => $settings->with(
        SettingValue::of(SettingKey::LOCALE_DEFAULT, 'en'),
        SettingValue::of(SettingKey::LOCALE_AVAILABLE, ['es']),
    ))->toThrow(InvalidSettingValue::class);
})->group('RF-PD-01');

it('rechaza al ESCRIBIR un valor que su clave no admite', function (): void {
    // Lo que al leer se descarta, al escribir se rechaza: guardar un valor que
    // no se va a aplicar es peor que un 422, porque quien lo envia se va
    // convencido de haber configurado algo.
    $settings = ResolvedSettings::resolve([]);

    expect(fn (): ResolvedSettings => $settings->with(
        SettingValue::of(SettingKey::ATTENDANCE_MAX_SHIFT_HOURS, 25),
    ))->toThrow(InvalidSettingValue::class);
})->group('RF-PD-01');

it('no arrastra a la escritura una fila que ya estaba corrupta', function (): void {
    // Un PATCH de una clave ajena no puede quedar bloqueado por una corrupcion
    // anterior: al proyectar se parte de lo que ESTA VIGENTE —donde esa clave ya
    // rige con su valor de serie—, no de lo que hay en la tabla. Y tampoco la
    // repara: la fila sigue donde estaba, porque solo se escribe lo que se pide.
    $settings = ResolvedSettings::resolve(['BRANDING_ACCENT_COLOR' => 'rgb(17,24,39)']);

    $projected = $settings->with(SettingValue::of(SettingKey::ATTENDANCE_DEBOUNCE_SECONDS, 90));

    expect($projected->integer(SettingKey::ATTENDANCE_DEBOUNCE_SECONDS))->toBe(90)
        ->and($projected->text(SettingKey::BRANDING_ACCENT_COLOR))->toBe('#111827');
})->group('RF-PD-01');

it('distingue una clave desconocida de una invalida', function (): void {
    // Son dos problemas distintos y por eso van en dos listas: la desconocida no
    // la lee nadie; la invalida SI cambia lo que se aplica.
    $settings = ResolvedSettings::resolve([
        'ATTENDANCE_LEGACY_TOLERANCE' => 5,
        'ATTENDANCE_MAX_SHIFT_HOURS' => 99,
    ]);

    expect($settings->unknownKeys)->toBe(['ATTENDANCE_LEGACY_TOLERANCE'])
        ->and($settings->invalidKeys)->toHaveCount(1)
        ->and($settings->invalidKeys[0]->key)->toBe(SettingKey::ATTENDANCE_MAX_SHIFT_HOURS)
        // El umbral de tramo anomalo no mueve minutos: solo cambia que
        // incidencias se abren.
        ->and($settings->invalidKeys[0]->affectsWorkedHours)->toBeFalse()
        ->and($settings->hasAnomalies())->toBeTrue();
})->group('RF-PD-01');

it('marca como afectante al calculo el descarte de una clave que mueve minutos', function (): void {
    // Es la señal que separa «arreglalo cuando puedas» de «arreglalo hoy y revisa
    // lo calculado desde que se rompio». Hoy solo la ventana anti-rebote
    // (RF-AT-06) la produce.
    $settings = ResolvedSettings::resolve(['ATTENDANCE_DEBOUNCE_SECONDS' => 'sesenta']);

    expect($settings->invalidKeys[0]->affectsWorkedHours)->toBeTrue()
        ->and($settings->integer(SettingKey::ATTENDANCE_DEBOUNCE_SECONDS))->toBe(60);
})->group('RF-PD-01');

it('no anota nada cuando todo lo guardado es valido', function (): void {
    $settings = ResolvedSettings::resolve(['ATTENDANCE_MAX_SHIFT_HOURS' => 10]);

    expect($settings->invalidKeys)->toBe([])
        ->and($settings->unknownKeys)->toBe([])
        ->and($settings->hasAnomalies())->toBeFalse();
})->group('RF-PD-01');

it('proyecta el conjunto que quedaria tras escribir, antes de tocar la base de datos', function (): void {
    $settings = ResolvedSettings::resolve(['BRANDING_APP_NAME' => 'Hotel de prueba']);

    $projected = $settings->with(
        SettingValue::of(SettingKey::ATTENDANCE_DEBOUNCE_SECONDS, 90),
    );

    expect($projected->integer(SettingKey::ATTENDANCE_DEBOUNCE_SECONDS))->toBe(90)
        ->and($projected->text(SettingKey::BRANDING_APP_NAME))->toBe('Hotel de prueba')
        ->and($settings->integer(SettingKey::ATTENDANCE_DEBOUNCE_SECONDS))->toBe(60);
})->group('RF-PD-01');

it('rechaza la proyeccion de un cambio que rompe una invariante entre claves', function (): void {
    // Es lo que impide que un PATCH de dos claves deje la instalacion en un
    // estado imposible segun el orden en que se escriban.
    $settings = ResolvedSettings::resolve([]);

    expect(fn (): ResolvedSettings => $settings->with(
        SettingValue::of(SettingKey::LOCALE_AVAILABLE, ['en']),
    ))->toThrow(InvalidSettingValue::class);
})->group('RF-PD-01');
