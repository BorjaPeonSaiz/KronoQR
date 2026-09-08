<?php

declare(strict_types=1);

use App\Modules\Product\Application\Command\ActivateLicenseCommand;
use App\Modules\Product\Application\UseCase\ActivateLicenseHandler;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\FeatureGate;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `php artisan product:telemetry` (Anexo C, **RF-PD-12**, ficha 5.10 punto 8).
 *
 * ## Lo que este comando tiene que conseguir
 *
 * Que el cliente pueda decidir **con el documento delante**. De ahi que sin
 * banderas imprima el documento entero aunque la telemetria este apagada, y que
 * diga cual de las tres condiciones falta en lugar de un «no se envia» que
 * obligaria a llamar a soporte.
 *
 * ## Y que salga `0` pase lo que pase
 *
 * El escenario normal de este producto es un hotel sin salida a internet. Un
 * codigo distinto de cero convertiria a la telemetria en la unica tarea del
 * planificador que «falla» todas las semanas en una instalacion sana.
 */

uses(RefreshDatabase::class);

const TELE_CONSOLA_DESTINO = 'https://telemetria.ejemplo.invalid/v1/reports';

function directorioDeTelemetria(): string
{
    static $directory = null;

    $directory ??= sys_get_temp_dir().'/kronoqr-telemetry-consola-'.bin2hex(random_bytes(6));

    return $directory;
}

/**
 * @param  array<string, mixed>  $parameters
 * @return array{code: int, output: string}
 */
function ejecutarTelemetria(array $parameters = []): array
{
    $code = Artisan::call('product:telemetry', [...$parameters, '--no-interaction' => true]);

    return ['code' => $code, 'output' => Artisan::output()];
}

function activarTelemetria(): void
{
    Config::set('product.telemetry_enabled', true);
    Config::set('product.telemetry_endpoint', TELE_CONSOLA_DESTINO);
    LicenseKeys::grantAll();
}

beforeEach(function (): void {
    WorkforceFixtures::site();
    LicenseKeys::install();
    app()->instance(Clock::class, FixedClock::at('2026-06-15 05:40:00'));

    Config::set('product.telemetry_state_path', directorioDeTelemetria().'/state.json');
    Config::set('product.telemetry_retry_delay_seconds', 0);
    Config::set('product.telemetry_enabled', false);
    Config::set('product.telemetry_endpoint', '');

    Http::preventStrayRequests();
});

afterEach(function (): void {
    @unlink(directorioDeTelemetria().'/state.json');
    @rmdir(directorioDeTelemetria());
});

it('sin banderas ensena el documento entero y no envia nada', function (): void {
    // Nada de red: si el comando intentara enviar algo sin `--send`,
    // `preventStrayRequests()` lo convertiria en un fallo.
    $result = ejecutarTelemetria();

    expect($result['code'])->toBe(0)
        ->and($result['output'])->toContain('NO ACTIVADA')
        ->and($result['output'])->toContain('TELEMETRY_ENABLED esta en false')
        ->and($result['output'])->toContain('Esto es EXACTAMENTE lo que se enviaria')
        // El documento, con sus secciones.
        ->and($result['output'])->toContain('"schema_version": 1')
        ->and($result['output'])->toContain('"employees_active"')
        ->and($result['output'])->toContain('"usage_7d"')
        // Y donde esta la lista campo a campo.
        ->and($result['output'])->toContain('configuracion.md')
        ->and($result['output'])->toContain('product:telemetry --send');
})->group('RF-PD-12');

it('dice cual de las tres condiciones falta', function (
    bool $enabled,
    string $endpoint,
    bool $licencia,
    string $esperado,
): void {
    Config::set('product.telemetry_enabled', $enabled);
    Config::set('product.telemetry_endpoint', $endpoint);

    if ($licencia) {
        LicenseKeys::grantAll();
    }

    expect(ejecutarTelemetria()['output'])->toContain($esperado);
})->with([
    'la variable' => [false, TELE_CONSOLA_DESTINO, true, 'TELEMETRY_ENABLED esta en false'],
    'el destino' => [true, '', true, 'TELEMETRY_ENDPOINT esta vacio'],
    'la licencia' => [true, TELE_CONSOLA_DESTINO, false, 'Tu licencia no incluye la telemetria'],
    'el destino sin cifrar' => [true, 'http://telemetria.ejemplo.invalid/v1', true, 'El destino tiene que empezar por https://'],
])->group('RF-PD-12', 'RF-PD-05');

it('--send no envia nada si falta una condicion, y sale 0', function (): void {
    $result = ejecutarTelemetria(['--send' => true]);

    expect($result['code'])->toBe(0)
        ->and($result['output'])->toContain('No se ha enviado nada, y no se ha construido nada.');

    // Ni siquiera se acuño identidad: con la telemetria apagada no se toca el
    // disco del cliente.
    expect(is_file(directorioDeTelemetria().'/state.json'))->toBeFalse();
})->group('RF-PD-12');

it('--send envia con las tres condiciones y lo dice', function (): void {
    activarTelemetria();
    Http::fake([TELE_CONSOLA_DESTINO => Http::response('', 202)]);

    $result = ejecutarTelemetria(['--send' => true]);

    expect($result['code'])->toBe(0)
        ->and($result['output'])->toContain('ACTIVADA')
        ->and($result['output'])->toContain('Enviado correctamente (codigo 202');

    Http::assertSent(function (Request $request): bool {
        /** @var array<string, mixed> $documento */
        $documento = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);

        expect($documento['schema_version'])->toBe(1)
            ->and($documento['sent_at'])->toBe('2026-06-15T05:40:00.000000Z')
            ->and($request->header('Content-Type'))->toBe(['application/json']);

        return true;
    });

    Http::assertSentCount(1);
})->group('RF-PD-12');

it('reintenta una vez y solo una', function (): void {
    // Un reintento cubre la ventana en la que el enlace del hotel se renegocia.
    // Insistir mas convertiria una tarea de fondo en algo que ocupa un proceso,
    // y el dato de esta semana no vale tanto.
    activarTelemetria();
    Http::fake([TELE_CONSOLA_DESTINO => Http::response('', 503)]);

    $result = ejecutarTelemetria(['--send' => true]);

    expect($result['code'])->toBe(0)
        ->and($result['output'])->toContain('No se ha podido enviar (http_503, intentos: 2)')
        // Y el mensaje no alarma: no es una averia del registro.
        ->and($result['output'])->toContain('el producto funciona igual');

    Http::assertSentCount(2);
})->group('RF-PD-12');

it('el identificador de la instalacion es el mismo entre dos envios', function (): void {
    // Si cambiara en cada envio, el fabricante veria una instalacion nueva cada
    // semana y la telemetria no responderia la unica pregunta que hace.
    activarTelemetria();
    Http::fake([TELE_CONSOLA_DESTINO => Http::response('', 202)]);

    ejecutarTelemetria(['--send' => true]);
    ejecutarTelemetria(['--send' => true]);

    $identificadores = [];

    Http::assertSent(function (Request $request) use (&$identificadores): bool {
        /** @var array<string, mixed> $documento */
        $documento = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);
        $identificadores[] = $documento['installation_id'];

        return true;
    });

    expect($identificadores)->toHaveCount(2)
        ->and($identificadores[0])->toBe($identificadores[1])
        // Y es un UUID v4: aleatorio entero. Un v7 llevaria dentro el instante en
        // que se acuño, es decir, el dia en que la instalacion arranco.
        ->and($identificadores[0])->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');

    /** @var array<string, mixed> $estado */
    $estado = json_decode((string) file_get_contents(directorioDeTelemetria().'/state.json'), true, 512, JSON_THROW_ON_ERROR);

    expect($estado['installation_id'])->toBe($identificadores[0])
        ->and($estado['last_success_at'])->toBe('2026-06-15T05:40:00.000000Z')
        ->and($estado['last_failure'])->toBeNull()
        // Y el fichero se escribe cerrado: lleva el identificador con el que el
        // fabricante reconoce la instalacion.
        ->and(substr(sprintf('%o', (int) fileperms(directorioDeTelemetria().'/state.json')), -3))->toBe('600');
})->group('RF-PD-12', 'RS-08');

it('--json sale en un formato que puede leer otro programa', function (): void {
    $result = ejecutarTelemetria(['--json' => true]);

    /** @var array<string, mixed> $salida */
    $salida = json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR);

    expect($salida['enabled'])->toBeFalse()
        ->and($salida['blocked_by'])->toBe('disabled_by_configuration')
        ->and($salida['sent'])->toBeFalse()
        ->and($salida['report'])->toBeArray();
})->group('RF-PD-12');

it('con la licencia caducada no se envia nada, y el registro sigue igual', function (): void {
    // ADR-023 y regla dura 15: la telemetria es accesoria, asi que una licencia
    // caducada la apaga. Lo que NO se apaga es nada del registro legal, y por eso
    // esta prueba comprueba tambien que el comando sale 0 y que no hay alarma.
    Config::set('product.telemetry_enabled', true);
    Config::set('product.telemetry_endpoint', TELE_CONSOLA_DESTINO);

    // Vigencia terminada antes del reloj de la prueba, con `telemetry` en el plan:
    // lo que la apaga es la caducidad, no el plan.
    app(ActivateLicenseHandler::class)->handle(
        new ActivateLicenseCommand(
            LicenseKeys::current()->issue([
                'features' => ['advanced_reports', 'telemetry'],
                'valid_from' => '2025-01-01T00:00:00Z',
                'valid_until' => '2025-12-31T23:59:59Z',
            ])
        )
    );

    app()->forgetInstance(FeatureGate::class);

    $result = ejecutarTelemetria(['--send' => true]);

    expect($result['code'])->toBe(0)
        ->and($result['output'])->toContain('Tu licencia no incluye la telemetria, o esta caducada')
        ->and($result['output'])->toContain('No se ha enviado nada, y no se ha construido nada.');

    Http::assertNothingSent();
})->group('RF-PD-12', 'RF-PD-05');

it('sin banderas no escribe absolutamente nada en el disco', function (): void {
    // Mirar no puede tener efectos. Antes de esta prueba, `product:telemetry`
    // acuñaba y guardaba `installation_id` aunque la telemetria estuviera
    // apagada y nadie hubiera pedido `--send`: el fichero aparecia en el disco de
    // instalaciones que habian mirado y decidido que no.
    activarTelemetria();

    $result = ejecutarTelemetria();

    expect($result['code'])->toBe(0)
        ->and(is_file(directorioDeTelemetria().'/state.json'))->toBeFalse()
        ->and(is_dir(directorioDeTelemetria()))->toBeFalse()
        // Y lo dice, para que nadie apunte un identificador que va a cambiar.
        ->and($result['output'])->toContain('ESTE IDENTIFICADOR ES PROVISIONAL');
})->group('RF-PD-12');

it('el identificador provisional deja de serlo tras el primer envio', function (): void {
    activarTelemetria();
    Http::fake([TELE_CONSOLA_DESTINO => Http::response('', 202)]);

    ejecutarTelemetria(['--send' => true]);

    $result = ejecutarTelemetria();

    /** @var array<string, mixed> $estado */
    $estado = json_decode((string) file_get_contents(directorioDeTelemetria().'/state.json'), true, 512, JSON_THROW_ON_ERROR);

    /** @var string $identificador */
    $identificador = $estado['installation_id'];

    expect($result['output'])->not->toContain('PROVISIONAL')
        ->and($result['output'])->toContain($identificador);
})->group('RF-PD-12');
