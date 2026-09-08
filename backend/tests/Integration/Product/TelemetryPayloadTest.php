<?php

declare(strict_types=1);

use App\Modules\Product\Application\UseCase\SendTelemetryHandler;
use App\Modules\Product\Domain\ValueObject\TelemetryReport;
use App\Modules\Product\Infrastructure\Telemetry\HttpTelemetrySender;
use App\Modules\Shared\Application\Port\Clock;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **LO QUE SALE DE LA INSTALACION, INSPECCIONADO** (RF-PD-12, ADR-020, regla
 * dura 21).
 *
 * La pregunta que ADR-020 obliga a responder, aqui aplicada al otro canal
 * saliente: *si intercepto el documento semanal de una instalacion real,
 * ¿puedo identificar a alguien, o al cliente?*
 *
 * ## Se siembra lo que se busca
 *
 * Nombres, apellidos, correos, DNI, codigos de empleado, nombres de quiosco con
 * nombre de persona dentro, la URL de la instalacion y la razon social de la
 * licencia. Todos reconocibles: si alguno apareciera en el cuerpo enviado, se
 * sabria por donde entro. Es el patron de `DiagnosticsBundleVolumeTest`, a menor
 * escala -aqui no se busca un fallo de volumen, se busca un campo-.
 *
 * ## Y ademas se comprueba que el documento SIRVE
 *
 * Un documento vacio pasaria todo lo anterior. Por eso al final se afirma que
 * lleva version, estado de licencia, tramo de plantilla y veredictos de
 * `doctor`.
 */

uses(RefreshDatabase::class);

const TELE_NOMBRE = 'Cunegunda';

const TELE_APELLIDO = 'Zaldivar Pou';

const TELE_CORREO = 'cunegunda.zaldivar@hotel-ejemplo.example';

const TELE_DNI = '49871234Z';

const TELE_CODIGO = 'EMP-000042';

const TELE_QUIOSCO = 'Tablet de Anastasio (recepcion)';

const TELE_URL = 'https://fichaje.hotel-ejemplo.example';

const TELE_DESTINO = 'https://telemetria.ejemplo.invalid/v1/reports';

/**
 * Una instalacion pequeña pero con todo lo que no debe salir.
 */
function instalacionSembrada(): void
{
    $siteId = WorkforceFixtures::site();
    $departmentId = WorkforceFixtures::department($siteId, 'Pisos');

    DB::table('employees')->insert([[
        'uuid' => Str::uuid7()->toString(),
        'site_id' => $siteId,
        'department_id' => $departmentId,
        'first_name' => TELE_NOMBRE,
        'last_name' => TELE_APELLIDO,
        'employee_code' => TELE_CODIGO,
        'email' => TELE_CORREO,
        'national_id_hash' => TELE_DNI,
        'status' => 'active',
        'hired_at' => '2026-01-01',
        'locale' => 'es',
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ]]);

    DB::table('devices')->insert([[
        'uuid' => Str::uuid7()->toString(),
        'site_id' => $siteId,
        'name' => TELE_QUIOSCO,
        'app_version' => '2.1.0',
        'status' => 'active',
        'pending_queue_size' => 0,
        'last_seen_at' => '2026-06-15T08:00:00Z',
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ]]);
}

/** El cuerpo del unico `POST` que se envio. */
function cuerpoEnviado(): string
{
    $bodies = [];

    Http::assertSent(function (Request $request) use (&$bodies): bool {
        $bodies[] = $request->body();

        return true;
    });

    expect($bodies)->toHaveCount(1);

    /** @var list<string> $bodies */
    return $bodies[0];
}

beforeEach(function (): void {
    app()->instance(Clock::class, FixedClock::at('2026-06-15 09:00:00'));

    Config::set('app.url', TELE_URL);
    Config::set('product.telemetry_enabled', true);
    Config::set('product.telemetry_endpoint', TELE_DESTINO);
    Config::set('product.telemetry_retry_delay_seconds', 0);
    Config::set('product.telemetry_state_path', sys_get_temp_dir().'/kronoqr-telemetry-'.bin2hex(random_bytes(6)).'/state.json');

    // Con todo contratado, `telemetry` incluida: si no, la tercera condicion
    // cortaria el envio y esta prueba no comprobaria nada.
    LicenseKeys::grantAll();

    Http::preventStrayRequests();
    Http::fake([TELE_DESTINO => Http::response('', 202)]);
});

it('el documento enviado no contiene ni un dato de nadie', function (): void {
    instalacionSembrada();

    $outcome = app(SendTelemetryHandler::class)->handle();

    expect($outcome->isEnabled())->toBeTrue()
        ->and($outcome->delivery?->delivered)->toBeTrue();

    $body = cuerpoEnviado();

    /** @var string $razonSocial */
    $razonSocial = LicenseKeys::defaults()['customer_name'];
    /** @var string $licenseId */
    $licenseId = LicenseKeys::defaults()['license_id'];

    // --- Nada que identifique a una persona ---------------------------------

    foreach ([TELE_NOMBRE, TELE_APELLIDO, TELE_CORREO, TELE_DNI, TELE_CODIGO, TELE_QUIOSCO] as $sembrado) {
        expect($body)->not->toContain($sembrado, 'El documento de telemetria contiene «'.$sembrado.'».');
    }

    // Ni una arroba: un correo suelto en cualquier campo se cazaria aqui aunque
    // no fuera el que se sembro.
    expect($body)->not->toContain('@')
        ->and($body)->not->toContain('hotel-ejemplo')
        // --- Ni nada que identifique al CLIENTE ----------------------------
        ->and($body)->not->toContain(TELE_URL)
        ->and($body)->not->toContain($razonSocial)
        ->and($body)->not->toContain('customer_name')
        ->and($body)->not->toContain($licenseId)
        // Ni rutas del servidor: `/var/www`, `storage/`, el directorio de marca.
        ->and($body)->not->toContain('/var/www')
        ->and($body)->not->toContain('storage/');

    // --- Ni una hora de fichaje ---------------------------------------------

    // `sent_at` es el instante del propio envio y es el UNICO que puede llevar
    // una hora; se recorta antes de buscar. Si apareciera otra `HH:MM` en
    // cualquier campo, seria un dato de jornada y esto lo dice.
    $sinInstanteDeEnvio = (string) preg_replace('/"sent_at":"[^"]*"/', '"sent_at":""', $body);

    expect(preg_match('/\d{2}:\d{2}/', $sinInstanteDeEnvio))->toBe(
        0,
        'El documento lleva una hora fuera de `sent_at`. RF-PD-12: «jamas datos personales ni de jornada».'
    );

    // --- Un solo UUID, y es el de la instalacion ----------------------------

    preg_match_all('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $body, $uuids);

    /** @var array{0: list<string>} $uuids */
    expect($uuids[0])->toHaveCount(1)
        ->and($uuids[0][0])->toBe($outcome->draft?->report->installationId);

    // Y ese uuid no es el de nadie de la instalacion.
    /** @var list<string> $ajenos */
    $ajenos = [
        ...DB::table('employees')->pluck('uuid')->all(),
        ...DB::table('devices')->pluck('uuid')->all(),
    ];

    expect($ajenos)->not->toContain($uuids[0][0]);
})->group('RF-PD-12', 'RL-19', 'RS-08');

it('la plantilla viaja como tramo y no como cifra, y el documento sirve para algo', function (): void {
    instalacionSembrada();

    app(SendTelemetryHandler::class)->handle();

    /** @var array<string, mixed> $documento */
    $documento = json_decode(cuerpoEnviado(), true, 512, JSON_THROW_ON_ERROR);

    /** @var array<string, mixed> $scale */
    $scale = $documento['scale'];

    // Una sola persona activa: el tramo es `1-25`, jamas `1`.
    expect($scale['employees_active'])->toBe('1-25')
        ->and($scale['devices_active'])->toBe(1)
        ->and($scale['departments'])->toBe(1);

    // La otra mitad: un documento vacio pasaria todo lo anterior.
    /** @var array<string, mixed> $product */
    $product = $documento['product'];
    /** @var array<string, mixed> $license */
    $license = $documento['license'];
    /** @var array<string, mixed> $doctor */
    $doctor = $documento['doctor'];

    expect($documento['schema_version'])->toBe(1)
        ->and($product['version'])->not->toBeEmpty()
        ->and($product['database_version'])->toMatch('/^\d+(\.\d+)*$/')
        ->and($license['state'])->toBe('valid')
        ->and($license['features'])->toContain('telemetry')
        ->and($doctor)->not->toBeEmpty();

    // Y de `doctor` solo el veredicto: ni un texto, ni un `fix`, ni un detalle.
    foreach ($doctor as $verdict) {
        expect($verdict)->toBeIn(['ok', 'warning', 'failure']);
    }
})->group('RF-PD-12');

it('el envio lleva las cabeceras acordadas y no sigue redirecciones', function (): void {
    instalacionSembrada();

    app(SendTelemetryHandler::class)->handle();

    Http::assertSent(function (Request $request): bool {
        expect($request->method())->toBe('POST')
            ->and($request->url())->toBe(TELE_DESTINO)
            ->and($request->header('Content-Type'))->toBe(['application/json'])
            // Solo la version. Ni el nombre del hotel, ni su URL.
            ->and($request->header('User-Agent')[0] ?? '')->toStartWith('KronoQR/')
            ->and($request->header('User-Agent')[0] ?? '')->not->toContain('hotel-ejemplo');

        return true;
    });
})->group('RF-PD-12', 'RS-08');

it('una redireccion no se sigue: se trata como fallo de entrega', function (): void {
    // `allow_redirects => false`. Un `302` es la forma mas barata de que un
    // destino comprometido -o el portal cautivo de la wifi del hotel- reenvie el
    // documento a otro sitio, y de que un `https` acabe en `http`. Aqui se
    // comprueba el efecto observable: el remitente NO lo da por entregado.
    instalacionSembrada();

    // Fabrica propia y no `Http::fake()` del `beforeEach`: los dobles de Laravel
    // se ACUMULAN, y el `202` que registra el `beforeEach` para este mismo
    // destino ganaria por haberse declarado antes. Con una fabrica limpia el
    // unico doble es el de aqui.
    $http = new HttpClient;
    $http->fake([TELE_DESTINO => $http->response('', 302, ['Location' => 'http://otro-sitio.invalid/recoge'])]);

    $report = app(SendTelemetryHandler::class)->preview()->draft?->report;

    expect($report)->not->toBeNull();

    /** @var TelemetryReport $report */
    $delivery = (new HttpTelemetrySender($http, '2.1.0', 0))->send($report, TELE_DESTINO);

    expect($delivery->delivered)->toBeFalse()
        ->and($delivery->failure)->toBe('http_302')
        // Un `302` no se sigue, pero si se reintenta: puede ser un balanceador
        // mal configurado un martes y estar bien el siguiente.
        ->and($delivery->attempts)->toBe(2);
})->group('RF-PD-12', 'RS-08');

it('el cliente HTTP sale con TLS verificado, sin redirecciones y con tiempos acotados', function (): void {
    // Las opciones del cliente NO son observables a traves de `Http::fake()`
    // -el doble no llega a la capa de transporte-, asi que se leen del codigo,
    // como hacen las pruebas de arquitectura. Es lo que hace falta para que la
    // promesa de `configuracion.md` §3 quinquies -«sobre HTTPS con el
    // certificado verificado»- no dependa de que nadie toque este fichero.
    $source = (string) file_get_contents(
        base_path('app/Modules/Product/Infrastructure/Telemetry/HttpTelemetrySender.php')
    );

    expect($source)->toContain("'allow_redirects' => false")
        ->toContain('connectTimeout(self::CONNECT_TIMEOUT)')
        ->toContain('timeout(self::TOTAL_TIMEOUT)')
        ->toContain('CONNECT_TIMEOUT = 3')
        ->toContain('TOTAL_TIMEOUT = 10')
        // Y ninguna forma de desactivar la verificacion del certificado. A
        // diferencia de la sonda de `doctor`, que admite autofirmados porque
        // mira el certificado del propio cliente, aqui sale un documento hacia
        // fuera: sin verificacion, cualquiera en la red del hotel lo leeria.
        ->and($source)->not->toContain('withoutVerifying')
        ->and($source)->not->toContain("'verify' => false")
        // Y no consulta configuracion: todo lo del entorno le llega por el
        // constructor, resuelto en el borde (regla dura 14).
        ->and($source)->not->toContain('Config::')
        ->and($source)->not->toContain('config(');
})->group('RF-PD-12', 'RS-08');
