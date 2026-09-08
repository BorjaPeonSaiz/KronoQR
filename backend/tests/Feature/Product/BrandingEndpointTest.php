<?php

declare(strict_types=1);

use App\Modules\Product\Application\Command\ActivateLicenseCommand;
use App\Modules\Product\Application\Port\SettingsRepository;
use App\Modules\Product\Application\UseCase\ActivateLicenseHandler;
use App\Modules\Product\Infrastructure\Adapter\DbBrandingProvider;
use App\Modules\Product\Infrastructure\Adapter\DbLocalePolicyProvider;
use App\Modules\Shared\Application\Port\BrandingLogoReader;
use App\Modules\Shared\Application\Port\BrandingProvider;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\FeatureGate;
use App\Modules\Shared\Application\Port\LocalePolicyProvider;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Identity\PortalLogins;
use Tests\Support\Product\FixedLogo;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `GET /api/v1/branding` y `GET /api/v1/branding/logo` — la marca que pintan las
 * tres aplicaciones (RF-PD-08, tarea 5.8, regla dura 13).
 *
 * ES LO QUE HACE QUE VENDER A UN CLIENTE NUEVO NO EXIJA TOCAR EL REPOSITORIO. El
 * nombre, el color y el logotipo son filas de `installation_settings`, y estas
 * pruebas fijan que lleguen de verdad a quien pinta — y, sobre todo, que NADA MAS
 * llegue con ellos: son las dos unicas rutas publicas del producto ademas de las
 * del asistente.
 *
 * Las respuestas se validan contra `openapi.yaml` con Spectator: el contrato es
 * la fuente de verdad (ADR-013) y una respuesta que no lo cumple rompe el cliente
 * TypeScript generado de el.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    WorkforceFixtures::site();
    LicenseKeys::install();

    // La marca blanca es funcionalidad del plan (ADR-023): sin licencia que la
    // cubra se sirve la del producto, y eso tiene su propio bloque de pruebas al
    // final. Aqui se parte de una instalacion con el plan completo.
    conMarcaBlancaContratada();
});

/** Un directorio de marca propio de la prueba, ya enlazado a la configuracion. */
function raizDeMarcaConfigurada(): string
{
    $root = sys_get_temp_dir().'/kronoqr-branding-'.bin2hex(random_bytes(6));

    mkdir($root, 0o755, true);
    config(['branding.logo_root' => $root]);

    return $root;
}

function marcaToken(): string
{
    return ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));
}

/**
 * Guarda claves de marca por la via que las guarda el panel.
 *
 * @param  array<string, mixed>  $settings
 */
function guardarMarca(array $settings): void
{
    Api::as(marcaToken())
        ->patch('/api/v1/settings', ['settings' => $settings])
        ->assertValidResponse(200);

    olvidarMarcaMemorizada();
}

/**
 * Descarta la memoria por peticion de los adaptadores de marca.
 *
 * En produccion cada peticion arranca la suya; en la suite, el contenedor
 * sobrevive de una llamada a la siguiente dentro de la misma prueba.
 */
function olvidarMarcaMemorizada(): void
{
    foreach ([
        BrandingProvider::class,
        BrandingLogoReader::class,
        LocalePolicyProvider::class,
        DbBrandingProvider::class,
        DbLocalePolicyProvider::class,
        FeatureGate::class,
    ] as $abstract) {
        app()->forgetInstance($abstract);
    }
}

// --- La marca del producto y la del cliente ---------------------------------

it('sirve la marca del producto, sin autenticar, cuando no hay ninguna fila', function (): void {
    // El valor por defecto ES el producto (paso 8 de la tarea): una instalacion
    // recien montada enseña `KronoQR`, nunca la marca de otro cliente.
    DB::table('installation_settings')->delete();
    olvidarMarcaMemorizada();

    $response = Api::guest()->get('/api/v1/branding')
        ->assertValidRequest()
        ->assertValidResponse(200);

    expect($response->json('application_name'))->toBe('KronoQR')
        // `null` y no el terracota de serie: con `null` las SPA no tocan ningun
        // token y el sistema visual del doc 06 queda intacto.
        ->and($response->json('accent_color'))->toBeNull()
        ->and($response->json('logo_url'))->toBeNull()
        ->and($response->json('locales.default'))->toBe('es')
        ->and($response->json('locales.available'))->toBe(['es', 'en']);
})->group('RF-PD-08');

it('sirve el nombre y el color que el cliente ha configurado', function (): void {
    guardarMarca(['BRANDING_APP_NAME' => 'Hotel Marina', 'BRANDING_ACCENT_COLOR' => '#0f5c8c']);

    $response = Api::guest()->get('/api/v1/branding')->assertValidResponse(200);

    expect($response->json('application_name'))->toBe('Hotel Marina')
        ->and($response->json('accent_color'))->toBe('#0f5c8c');
})->group('RF-PD-08');

it('sigue publicando null en el color mientras el cliente no elija uno propio', function (): void {
    // Cambiar solo el nombre no puede meter a la instalacion por el camino de la
    // marca personalizada: el color sigue siendo el de serie y las SPA no derivan
    // ningun tono.
    guardarMarca(['BRANDING_APP_NAME' => 'Hotel Marina']);

    $response = Api::guest()->get('/api/v1/branding')->assertValidResponse(200);

    expect($response->json('application_name'))->toBe('Hotel Marina')
        ->and($response->json('accent_color'))->toBeNull();
})->group('RF-PD-08');

it('publica los idiomas que la instalacion ofrece, y no los que trae el producto', function (): void {
    guardarMarca(['LOCALE_DEFAULT' => 'es', 'LOCALE_AVAILABLE' => ['es']]);

    $response = Api::guest()->get('/api/v1/branding')->assertValidResponse(200);

    // El selector del quiosco se filtra con esto: un hotel que solo trabaja en
    // castellano no enseña una bandera inglesa que nadie va a usar.
    expect($response->json('locales.available'))->toBe(['es'])
        ->and($response->json('locales.default'))->toBe('es');
})->group('RF-PD-08', 'RF-PD-01');

// --- No filtra nada mas ------------------------------------------------------

it('no filtra ninguna clave de configuracion que no sea marca ni idioma', function (): void {
    // La respuesta es PUBLICA. De las nueve claves del catalogo salen cinco
    // nombradas una a una; si algun dia se recorriera el catalogo, un umbral
    // operativo empezaria a publicarse sin que nadie lo decidiera.
    guardarMarca(['ATTENDANCE_MAX_SHIFT_HOURS' => 9, 'BRANDING_APP_NAME' => 'Hotel Marina']);

    $response = Api::guest()->get('/api/v1/branding')->assertValidResponse(200);

    $cuerpo = $response->getContent();

    expect($cuerpo)->toBeString()
        ->and($cuerpo)->not->toContain('ATTENDANCE')
        ->and($cuerpo)->not->toContain('LICENSE')
        ->and(array_keys((array) $response->json()))
        ->toBe(['application_name', 'accent_color', 'logo_url', 'locales']);
})->group('RF-PD-08', 'RS-03');

it('no revela la ruta del logotipo en el servidor del cliente', function (): void {
    // Es una ruta del sistema de ficheros de otra persona y la respuesta es
    // publica: lo que viaja es una URL del propio producto.
    $root = raizDeMarcaConfigurada();
    file_put_contents($root.'/logo.png', FixedLogo::onePixelPng());

    guardarMarca(['BRANDING_LOGO_PATH' => $root.'/logo.png']);

    $response = Api::guest()->get('/api/v1/branding')->assertValidResponse(200);

    // Sobre el cuerpo crudo para la ausencia —asi tambien se cubre que la ruta
    // no se cuele en ningun otro campo— y sobre el JSON decodificado para la
    // presencia, porque en el crudo las barras van escapadas.
    expect((string) $response->getContent())->not->toContain($root)
        ->and($response->json('logo_url'))->toStartWith('/api/v1/branding/logo');
})->group('RF-PD-08', 'RS-03');

// --- El logotipo -------------------------------------------------------------

it('publica la URL del logotipo con la huella del contenido y lo sirve como PNG', function (): void {
    $root = raizDeMarcaConfigurada();
    $bytes = FixedLogo::onePixelPng();
    file_put_contents($root.'/logo.png', $bytes);

    guardarMarca(['BRANDING_LOGO_PATH' => $root.'/logo.png']);

    $url = Api::guest()->get('/api/v1/branding')->assertValidResponse(200)->json('logo_url');

    expect($url)->toBeString()
        ->and($url)->toBe('/api/v1/branding/logo?v='.substr(hash('sha256', $bytes), 0, 12));

    $logo = Api::guest()->get('/api/v1/branding/logo', ['v' => substr(hash('sha256', $bytes), 0, 12)])
        ->assertValidRequest()
        ->assertValidResponse(200);

    expect($logo->headers->get('Content-Type'))->toBe('image/png')
        // `immutable` es seguro porque la URL lleva la huella: cambiar el fichero
        // cambia la URL y la copia vieja nunca se vuelve a ver.
        ->and($logo->headers->get('Cache-Control'))->toContain('immutable')
        ->and($logo->headers->get('Cache-Control'))->toContain('max-age=31536000')
        ->and($logo->headers->get('ETag'))->toBe('"'.hash('sha256', $bytes).'"')
        ->and($logo->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($logo->getContent())->toBe($bytes);
})->group('RF-PD-08');

it('sirve un SVG con su tipo y con la politica que impide que ejecute nada', function (): void {
    // Un SVG es un documento, no una imagen: abierto en una pestaña puede
    // ejecutar lo que lleve dentro. El inspector ya rechaza los que traen un
    // guion; esto es la segunda linea, en la puerta.
    $root = raizDeMarcaConfigurada();
    file_put_contents($root.'/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');

    guardarMarca(['BRANDING_LOGO_PATH' => $root.'/logo.svg']);

    $logo = Api::guest()->get('/api/v1/branding/logo')->assertValidResponse(200);

    expect($logo->headers->get('Content-Type'))->toBe('image/svg+xml')
        ->and($logo->headers->get('Content-Security-Policy'))->toContain('sandbox')
        ->and($logo->headers->get('Content-Security-Policy'))->toContain("default-src 'none'")
        ->and($logo->headers->get('X-Content-Type-Options'))->toBe('nosniff');
})->group('RF-PD-08', 'RS-03');

it('responde 404 cuando no hay ningun logotipo configurado', function (): void {
    Api::guest()->get('/api/v1/branding/logo')->assertValidResponse(404);
})->group('RF-PD-08');

it('deja de servir el logotipo si el fichero desaparece, sin romper la marca', function (): void {
    // ESTE ES EL CASO QUE NO PUEDE TUMBAR NADA. El fichero se borra un martes:
    // `logo_url` pasa a null, el logotipo da 404 y el nombre y el color siguen.
    // Nadie se queda sin fichar ni sin pintar la pantalla por una imagen.
    $root = raizDeMarcaConfigurada();
    file_put_contents($root.'/logo.png', FixedLogo::onePixelPng());

    guardarMarca(['BRANDING_APP_NAME' => 'Hotel Marina', 'BRANDING_LOGO_PATH' => $root.'/logo.png']);

    unlink($root.'/logo.png');
    olvidarMarcaMemorizada();

    $response = Api::guest()->get('/api/v1/branding')->assertValidResponse(200);

    expect($response->json('logo_url'))->toBeNull()
        ->and($response->json('application_name'))->toBe('Hotel Marina');

    Api::guest()->get('/api/v1/branding/logo')->assertValidResponse(404);
})->group('RF-PD-08');

it('no sirve un fichero que quedo fuera del directorio de marca al moverse el montaje', function (): void {
    // La ruta se guardo cuando era valida y despues cambio `BRANDING_LOGO_ROOT`.
    // La comprobacion se repite AL LEER, asi que el endpoint publico no se
    // convierte en una lectura de cualquier fichero del servidor.
    $root = raizDeMarcaConfigurada();
    file_put_contents($root.'/logo.png', FixedLogo::onePixelPng());

    guardarMarca(['BRANDING_LOGO_PATH' => $root.'/logo.png']);

    raizDeMarcaConfigurada();
    olvidarMarcaMemorizada();

    expect(Api::guest()->get('/api/v1/branding')->assertValidResponse(200)->json('logo_url'))->toBeNull();

    Api::guest()->get('/api/v1/branding/logo')->assertValidResponse(404);
})->group('RF-PD-08', 'RS-03');

// --- Quien puede leerla y quien puede cambiarla ------------------------------

it('responde igual con token y sin el, porque la marca no es de nadie', function (): void {
    // El quiosco la pide antes de que nadie escanee y el portal antes de que
    // nadie se identifique: si dependiera de la sesion, las dos pantallas
    // arrancarian con la marca del fabricante y repintarian despues.
    guardarMarca(['BRANDING_APP_NAME' => 'Hotel Marina']);

    $sinToken = Api::guest()->get('/api/v1/branding')->assertValidResponse(200)->json();
    $conAdmin = Api::as(marcaToken())->get('/api/v1/branding')->assertValidResponse(200)->json();
    $conQuiosco = Api::as(ManagementUsers::kioskToken())->get('/api/v1/branding')->assertValidResponse(200)->json();

    expect($sinToken)->toBe($conAdmin)->and($sinToken)->toBe($conQuiosco);
})->group('RF-PD-08');

it('el quiosco puede leer la marca pero no cambiarla', function (): void {
    // Regla dura 18: el poder no esta en leer la marca, esta en escribirla. El
    // ambito `settings:*` no lo tiene un token de quiosco, asi que el guard corta
    // antes que la policy — y por eso es 403 de ambito y no de rol.
    Api::as(ManagementUsers::kioskToken())->get('/api/v1/branding')->assertValidResponse(200);

    Api::as(ManagementUsers::kioskToken())
        ->patch('/api/v1/settings', ['settings' => ['BRANDING_APP_NAME' => 'Hotel Intruso']])
        ->assertValidResponse(403);
})->group('RF-PD-08', 'RS-03');

it('el portal del empleado puede leer la marca pero no cambiarla', function (): void {
    $token = PortalLogins::open(WorkforceFixtures::employee(WorkforceFixtures::onlySiteId()));

    Api::as($token)->get('/api/v1/branding')->assertValidResponse(200);

    Api::as($token)
        ->patch('/api/v1/settings', ['settings' => ['BRANDING_APP_NAME' => 'Hotel Intruso']])
        ->assertValidResponse(403);
})->group('RF-PD-08', 'RS-03');

it('corta el ruido con un techo por origen, sin llegar a ser un control de acceso', function (): void {
    // La piden navegadores al arrancar, no personas. El techo protege del bucle,
    // no del contenido: el nombre del hotel y su color van impresos en cada
    // tarjeta que la plantilla lleva en el bolsillo.
    config(['product.branding_rate_limit_per_minute' => 3]);

    for ($i = 0; $i < 3; $i++) {
        Api::guest()->fromIp('203.0.113.7')->get('/api/v1/branding')->assertStatus(200);
    }

    Api::guest()->fromIp('203.0.113.7')->get('/api/v1/branding')->assertValidResponse(429);

    // Y el techo es POR ORIGEN: otra tablet del mismo hotel no se queda fuera
    // porque la primera haya gastado su cupo.
    Api::guest()->fromIp('203.0.113.8')->get('/api/v1/branding')->assertStatus(200);
})->group('RF-PD-08');

// --- La marca blanca es funcionalidad del plan (ADR-023) ---------------------

/*
 * ADR-023 lista la marca blanca entre las funcionalidades **accesorias**: «Marca
 * blanca (RF-PD-08) → vuelve a la marca por defecto del producto».
 *
 * SE DEGRADA EL ASPECTO, NUNCA EL NOMBRE. Sin `white_label` en el plan se pierden
 * el color de acento y el logotipo; el `application_name` del cliente **se sirve
 * siempre**. Ese nombre encabeza la exportacion para la Inspeccion y el informe
 * sellado: dice DE QUIEN es el registro horario. Degradarlo haria que un
 * documento con valor probatorio identificara peor al obligado por un motivo
 * comercial —o por un Redis caido, que es lo que produce `LicenseUnverifiable`—,
 * y eso choca con la regla dura 15 y con RL-03/RL-06. Un color no identifica a
 * nadie; un nombre si.
 *
 * Y LAS FILAS NO SE TOCAN. `GET` y `PATCH /api/v1/settings` siguen abiertos —la
 * licencia jamas cierra la configuracion, regla dura 15— y el aspecto vuelve solo
 * en cuanto la licencia lo cubra. El cliente que renueva no reconfigura nada, y
 * el que esta evaluando el producto puede dejar su marca lista antes de
 * comprarla.
 */

/** Licencia vigente con el plan completo, marca blanca incluida. */
function conMarcaBlancaContratada(): void
{
    LicenseKeys::grantAll();
    olvidarMarcaMemorizada();
}

/**
 * Licencia vigente cuyo plan NO incluye la marca blanca.
 *
 * @param  list<string>  $features
 */
function sinMarcaBlancaEnElPlan(array $features = ['advanced_reports']): void
{
    app()->instance(Clock::class, FixedClock::at('2026-06-15 09:00:00'));

    app(ActivateLicenseHandler::class)->handle(
        new ActivateLicenseCommand(
            LicenseKeys::current()->issue(['features' => $features]),
        ),
    );

    olvidarMarcaMemorizada();
}

/** Licencia que incluia la marca blanca, pero que ya vencio. */
function conLicenciaVencida(): void
{
    app()->instance(Clock::class, FixedClock::at('2026-06-15 09:00:00'));

    app(ActivateLicenseHandler::class)->handle(
        new ActivateLicenseCommand(
            LicenseKeys::current()->issue([
                'features' => ['white_label'],
                'valid_from' => '2025-01-01T00:00:00Z',
                'valid_until' => '2025-12-31T23:59:59Z',
            ]),
        ),
    );

    olvidarMarcaMemorizada();
}

/** Deja guardada la marca de un cliente, con logotipo incluido. */
function marcaDeCliente(): void
{
    $root = raizDeMarcaConfigurada();
    file_put_contents($root.'/logo.png', FixedLogo::onePixelPng());

    guardarMarca([
        'BRANDING_APP_NAME' => 'Hotel Marina',
        'BRANDING_ACCENT_COLOR' => '#0f5c8c',
        'BRANDING_LOGO_PATH' => $root.'/logo.png',
    ]);
}

it('con la marca blanca en el plan, se sirve la marca del cliente', function (): void {
    marcaDeCliente();

    $response = Api::guest()->get('/api/v1/branding')->assertValidResponse(200);

    expect($response->json('application_name'))->toBe('Hotel Marina')
        ->and($response->json('accent_color'))->toBe('#0f5c8c')
        ->and($response->json('logo_url'))->toBeString();

    Api::guest()->get('/api/v1/branding/logo')->assertValidResponse(200);
})->group('RF-PD-08', 'RF-PD-05');

it('sin la marca blanca en el plan, se pierden el color y el logotipo pero NO el nombre', function (): void {
    marcaDeCliente();
    sinMarcaBlancaEnElPlan();

    $response = Api::guest()->get('/api/v1/branding')->assertValidResponse(200);

    // El nombre identifica la instalacion y encabeza los documentos legales: no
    // es aspecto y no se degrada jamas.
    expect($response->json('application_name'))->toBe('Hotel Marina')
        // El aspecto si: `null`, igual que una instalacion que no ha elegido
        // color. «Sin licencia» y «sin configurar» se ven exactamente igual.
        ->and($response->json('accent_color'))->toBeNull()
        ->and($response->json('logo_url'))->toBeNull();

    Api::guest()->get('/api/v1/branding/logo')->assertValidResponse(404);
})->group('RF-PD-08', 'RF-PD-05');

it('con la licencia caducada, tampoco se pierde el nombre', function (): void {
    marcaDeCliente();
    conLicenciaVencida();

    $response = Api::guest()->get('/api/v1/branding')->assertValidResponse(200);

    expect($response->json('application_name'))->toBe('Hotel Marina')
        ->and($response->json('accent_color'))->toBeNull()
        ->and($response->json('logo_url'))->toBeNull();
})->group('RF-PD-08', 'RF-PD-05');

it('la degradacion nunca es un error: la marca responde 200 siempre', function (): void {
    // ADR-019 y regla dura 15. Un 402 o un 403 aqui dejaria al quiosco sin
    // pantalla de espera por una fecha de vencimiento, que es exactamente la
    // clase de castigo que el producto no aplica.
    marcaDeCliente();
    conLicenciaVencida();

    Api::guest()->get('/api/v1/branding')->assertValidResponse(200);
})->group('RF-PD-08', 'RF-PD-05');

it('lo guardado se conserva y se sigue pudiendo editar sin la marca blanca en el plan', function (): void {
    // LA MITAD QUE IMPORTA. Las filas no se tocan (regla dura 5) y la
    // configuracion no se cierra (regla dura 15): el cliente sigue viendo lo que
    // configuro, con `source: installation`, y puede cambiarlo.
    marcaDeCliente();
    sinMarcaBlancaEnElPlan();

    $token = marcaToken();

    $ajustes = Api::as($token)->get('/api/v1/settings')->assertValidResponse(200)->json('data');

    $porClave = [];

    foreach ((array) $ajustes as $fila) {
        $porClave[$fila['key']] = $fila;
    }

    expect($porClave['BRANDING_APP_NAME']['value'])->toBe('Hotel Marina')
        ->and($porClave['BRANDING_APP_NAME']['source'])->toBe('installation')
        ->and($porClave['BRANDING_ACCENT_COLOR']['value'])->toBe('#0f5c8c')
        ->and($porClave['BRANDING_ACCENT_COLOR']['source'])->toBe('installation');

    // Y se puede seguir editando: nadie queda atrapado con la marca a medias.
    Api::as($token)
        ->patch('/api/v1/settings', ['settings' => ['BRANDING_APP_NAME' => 'Hotel Marina Resort']])
        ->assertValidResponse(200);
})->group('RF-PD-08', 'RF-PD-05');

it('el aspecto del cliente vuelve solo al renovar, sin reconfigurar nada', function (): void {
    // Es la promesa entera de ADR-023 en una prueba: se degrada lo que se ve y no
    // lo que se guarda, asi que renovar basta.
    marcaDeCliente();
    sinMarcaBlancaEnElPlan();

    expect(Api::guest()->get('/api/v1/branding')->assertValidResponse(200)->json('accent_color'))
        ->toBeNull();

    conMarcaBlancaContratada();

    $response = Api::guest()->get('/api/v1/branding')->assertValidResponse(200);

    expect($response->json('application_name'))->toBe('Hotel Marina')
        ->and($response->json('accent_color'))->toBe('#0f5c8c')
        ->and($response->json('logo_url'))->toBeString();
})->group('RF-PD-08', 'RF-PD-05');

it('los idiomas no son marca y no se degradan con el plan', function (): void {
    // Una instalacion cuya plantilla trabaja en ingles no puede quedarse sin su
    // idioma porque venza un plan: `LOCALE_*` no pasa por el decorador.
    guardarMarca(['LOCALE_DEFAULT' => 'en', 'LOCALE_AVAILABLE' => ['en']]);
    sinMarcaBlancaEnElPlan();

    $response = Api::guest()->get('/api/v1/branding')->assertValidResponse(200);

    expect($response->json('locales.default'))->toBe('en')
        ->and($response->json('locales.available'))->toBe(['en']);
})->group('RF-PD-08', 'RF-PD-05');

it('el nombre del cliente sigue encabezando los documentos sin la marca blanca', function (): void {
    // LA RAZON DE QUE EL NOMBRE NO SE DEGRADE, comprobada donde importa: la
    // exportacion para la Inspeccion (RL-06) y el informe sellado (RL-03) dicen
    // de quien es el registro. Se comprueba sobre el PUERTO porque es lo que
    // consumen los tres documentos.
    marcaDeCliente();
    sinMarcaBlancaEnElPlan();

    $marca = app(BrandingProvider::class)->current();

    expect($marca->applicationName)->toBe('Hotel Marina')
        // Y sin aspecto: acento de serie y ninguna ruta de logotipo, de modo que
        // los PDF salen sin imagen sin enterarse de que existe una licencia.
        ->and($marca->accentColor)->toBe('#b8542a')
        ->and($marca->logoPath)->toBeNull();
})->group('RF-PD-08', 'RF-PD-05', 'RL-06');

// --- Nada de esto puede fallar ------------------------------------------------

it('devuelve 304 sin cuerpo a quien ya tiene esa version del logotipo', function (): void {
    // El quiosco revalida al recuperar la red, y suele hacerlo por la conexion de
    // un hotel: bajar medio megabyte para descubrir que no ha cambiado nada es
    // justo lo que el `ETag` existe para evitar.
    $root = raizDeMarcaConfigurada();
    $bytes = FixedLogo::onePixelPng();
    file_put_contents($root.'/logo.png', $bytes);

    guardarMarca(['BRANDING_LOGO_PATH' => $root.'/logo.png']);

    $etag = Api::guest()->get('/api/v1/branding/logo')->assertValidResponse(200)->headers->get('ETag');

    expect($etag)->toBe('"'.hash('sha256', $bytes).'"');

    $revalidacion = Api::guest()
        ->withHeaders(['If-None-Match' => (string) $etag])
        ->get('/api/v1/branding/logo')
        ->assertStatus(304);

    expect($revalidacion->getContent())->toBe('');
})->group('RF-PD-08');

it('vuelve a servir el fichero entero si el logotipo ha cambiado', function (): void {
    // La otra mitad: una huella vieja NO puede dar 304, o la tablet se quedaria
    // con el logotipo anterior para siempre (`Cache-Control: immutable`).
    $root = raizDeMarcaConfigurada();
    file_put_contents($root.'/logo.png', FixedLogo::onePixelPng());

    guardarMarca(['BRANDING_LOGO_PATH' => $root.'/logo.png']);

    Api::guest()
        ->withHeaders(['If-None-Match' => '"'.str_repeat('0', 64).'"'])
        ->get('/api/v1/branding/logo')
        ->assertValidResponse(200);
})->group('RF-PD-08');

it('responde 200 con la marca del producto aunque la configuracion sea ilegible', function (): void {
    // ESTO NO PUEDE DAR 500. Lo piden el quiosco y el portal antes de identificar
    // a nadie, y es lo primero que pasa al abrir la tablet por la mañana: un error
    // aqui es una pantalla de espera rota justo cuando entra el turno.
    //
    // SE ROMPE LA CONSULTA, NO EL CONSTRUCTOR, que es como se rompe de verdad: el
    // objeto se construye sin problema y lo que falla es hablar con PostgreSQL o
    // con Redis. Romper el enlace del contenedor daria un 500 en la resolucion de
    // dependencias y no llegaria a ejercitar el `catch` que aqui interesa.
    app()->bind(SettingsRepository::class, fn (): SettingsRepository => new class implements SettingsRepository
    {
        public function storedValues(): array
        {
            throw new RuntimeException('installation_settings ilegible');
        }

        public function storedValuesForWrite(): array
        {
            throw new RuntimeException('installation_settings ilegible');
        }

        public function save(array $values, int $actorUserId): void
        {
            throw new RuntimeException('installation_settings ilegible');
        }
    });

    olvidarMarcaMemorizada();

    $response = Api::guest()->get('/api/v1/branding')->assertValidResponse(200);

    expect($response->json('application_name'))->toBe('KronoQR')
        ->and($response->json('accent_color'))->toBeNull()
        ->and($response->json('logo_url'))->toBeNull()
        // Y con idiomas utilizables: una lista vacia dejaria al quiosco sin
        // selector y al portal sin saber en que idioma pintarse.
        ->and($response->json('locales.available'))->toBe(['es', 'en']);
})->group('RF-PD-08', 'RQ-11');
