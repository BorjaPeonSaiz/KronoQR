<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\CredentialResolver;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\CredentialRejectionReason;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Attendance\FakeCredentialResolver;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Time\FixedClock;

/*
 * **CON LA TELEMETRIA ACTIVADA Y SIN SALIDA A INTERNET NO CAMBIA NADA**
 * (RF-PD-12, ficha 5.10 pasos 7 y 9, regla dura 19).
 *
 * ## Por que es la prueba que decide si esta parte esta bien hecha
 *
 * El doc 02 seccion 11.6.2 dice que **el escenario normal de este producto es
 * una instalacion sin salida a internet**. Es decir: el caso de esta prueba no
 * es una averia rara, es el caso mayoritario de los clientes que activen la
 * telemetria. Si un envio fallido pudiera enlentecer un fichaje, ensuciar el log
 * con errores o hacer que `doctor` saliera en rojo, el cliente acabaria
 * apagandola -o peor, dudando del registro- por algo accesorio.
 *
 * ## Dos formas de «no hay internet», y las dos importan
 *
 * 1. Un destino **inalcanzable de verdad**: `https://127.0.0.1:9`, el puerto
 *    `discard`, cerrado en el contenedor. Es la unica salida a red real de toda
 *    la suite y es local: no depende de que la CI tenga internet.
 * 2. Una excepcion de conexion simulada, para el caso del enlace que acepta y
 *    corta.
 *
 * ## Por que ademas lleva la etiqueta RL-17
 *
 * RL-17 -«el fabricante no es encargado del tratamiento en la operacion
 * ordinaria, porque no aloja ni accede a los datos»- solo se sostiene si el
 * producto funciona ENTERO sin hablar con el fabricante. Dos de las pruebas de
 * abajo lo afirman: con el unico canal saliente activado y roto, el fichaje
 * sigue y `doctor` sigue diciendo lo mismo. El fabricante no esta en el camino
 * de nada.
 *
 * ## Y `doctor` NO comprueba la telemetria, a proposito
 *
 * Un aviso semanal de «no se pudo enviar» seria justo el recordatorio insistente
 * que RF-PD-12 prohibe. Aqui se comprueba que no existe.
 */

uses(RefreshDatabase::class);

/** El puerto `discard`, cerrado. Salida a red real, y local. */
const TELE_PUERTO_CERRADO = 'https://127.0.0.1:9/telemetria';

const TELE_TARJETA = 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa';

const TELE_TARJETA_FALSA = 'FH1.a3.0000000000000000000000.0000000000000000';

/** @return array{site: int, employee: string, device: int, deviceUuid: string, token: string} */
function escenarioConTelemetriaRota(): array
{
    $escenario = AttendanceFixtures::scenario();

    app()->instance(
        CredentialResolver::class,
        FakeCredentialResolver::new()
            ->resolving(TELE_TARJETA, $escenario['employee'])
            ->rejecting(TELE_TARJETA_FALSA, CredentialRejectionReason::INVALID_SIGNATURE),
    );

    return $escenario;
}

/**
 * Los niveles que se han registrado durante el bloque.
 *
 * @return list<string>
 */
function nivelesRegistrados(callable $block): array
{
    $levels = [];

    Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$levels): void {
        $levels[] = $message->level;
    });

    $block();

    return $levels;
}

beforeEach(function (): void {
    app()->instance(Clock::class, FixedClock::at('2026-06-15 05:40:00'));

    Config::set('product.telemetry_enabled', true);
    Config::set('product.telemetry_endpoint', TELE_PUERTO_CERRADO);
    Config::set('product.telemetry_retry_delay_seconds', 0);
    Config::set('product.telemetry_state_path', sys_get_temp_dir().'/kronoqr-tele-aislada-'.bin2hex(random_bytes(6)).'/state.json');

    LicenseKeys::grantAll();
});

it('un destino inalcanzable de verdad no rompe nada y el comando sale 0', function (): void {
    $escenario = escenarioConTelemetriaRota();

    $niveles = nivelesRegistrados(function (): void {
        $code = Artisan::call('product:telemetry', ['--send' => true, '--no-interaction' => true]);

        expect($code)->toBe(0)
            ->and(Artisan::output())->toContain('No se ha podido enviar');
    });

    // NUNCA por encima de `notice`. Un `error` semanal en una instalacion sana
    // acabaria ensenando a ignorar el log.
    expect($niveles)->not->toContain('error')
        ->and($niveles)->not->toContain('critical')
        ->and($niveles)->not->toContain('alert')
        ->and($niveles)->not->toContain('emergency')
        ->and($niveles)->toContain('notice');

    // Y el fichaje responde exactamente igual, despues del fallo.
    $scanId = Str::uuid7()->toString();

    $respuesta = Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan', [
            'scan_id' => $scanId,
            'occurred_at' => '2026-06-15T05:41:00Z',
            'qr_payload' => TELE_TARJETA,
        ]);

    $respuesta->assertOk();

    expect($respuesta->json('action'))->toBe('clock_in')
        ->and($respuesta->json('scan_id'))->toBe($scanId);

    // Y la sonda de vida tambien.
    Api::guest()->get('/api/v1/health')->assertOk();
})->group('RF-PD-12', 'RF-AT-01', 'RL-17');

it('el fallo queda anotado en el estado, con la clase y sin la URL', function (): void {
    Http::fake([
        '*' => fn () => throw new ConnectionException('cURL error 7: no route to '.TELE_PUERTO_CERRADO),
    ]);

    Artisan::call('product:telemetry', ['--send' => true, '--no-interaction' => true]);

    $path = Config::string('product.telemetry_state_path');

    /** @var array<string, mixed> $estado */
    $estado = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    expect($estado['last_failure'])->toBe(ConnectionException::class)
        ->and($estado['last_attempt_at'])->toBe('2026-06-15T05:40:00.000000Z')
        // No hubo exito, asi que la linea de salida de los contadores no avanza:
        // la semana que viene el envio cubre las dos.
        ->and($estado['last_success_at'])->toBeNull()
        ->and($estado['counters'])->toBe([]);

    // Y en el fichero no hay ni rastro de la URL ni del mensaje de la excepcion:
    // un mensaje de red lleva el host, y a veces la URL entera con su token.
    $crudo = (string) file_get_contents($path);

    expect($crudo)->not->toContain('cURL')
        ->and($crudo)->not->toContain('127.0.0.1')
        ->and($crudo)->not->toContain('no route');
})->group('RF-PD-12', 'RS-08');

it('doctor no comprueba la telemetria y responde igual con ella rota', function (): void {
    escenarioConTelemetriaRota();

    Artisan::call('product:doctor', ['--json' => true, '--no-interaction' => true]);
    /** @var array<string, mixed> $conTelemetria */
    $conTelemetria = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    Config::set('product.telemetry_enabled', false);
    Config::set('product.telemetry_endpoint', '');

    Artisan::call('product:doctor', ['--json' => true, '--no-interaction' => true]);
    /** @var array<string, mixed> $sinTelemetria */
    $sinTelemetria = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    /** @var list<array{id: string, status: string}> $checksCon */
    $checksCon = $conTelemetria['checks'];
    /** @var list<array{id: string, status: string}> $checksSin */
    $checksSin = $sinTelemetria['checks'];

    $ids = static fn (array $checks): array => array_map(static fn (array $c): string => $c['id'], $checks);

    expect($ids($checksCon))->toBe($ids($checksSin))
        ->and($conTelemetria['status'])->toBe($sinTelemetria['status']);

    // Un aviso semanal de «no se pudo enviar» seria justo el recordatorio
    // insistente que RF-PD-12 prohibe. No existe, y no debe existir.
    foreach ($ids($checksCon) as $id) {
        expect($id)->not->toContain('telemetry');
    }
})->group('RF-PD-12', 'RF-PD-13', 'RL-17');

it('deja constancia si no puede guardar su propio estado, y sigue enviando', function (): void {
    // El sintoma que este aviso existe para explicar: con el directorio sin
    // permisos de escritura, cada envio acuña una identidad NUEVA -el fabricante
    // ve una instalacion distinta cada lunes- y `usage_7d` va siempre a `null`,
    // porque nunca hay acumulado anterior con el que restar. En silencio, la
    // unica pista seria un panel raro en casa del fabricante.
    $directorio = sys_get_temp_dir().'/kronoqr-tele-sin-permisos-'.bin2hex(random_bytes(6));

    mkdir($directorio, 0500, true);
    Config::set('product.telemetry_state_path', $directorio.'/subcarpeta/state.json');

    Http::fake([TELE_PUERTO_CERRADO => Http::response('', 202)]);

    $anotados = [];

    $niveles = nivelesRegistrados(function () use (&$anotados): void {
        Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$anotados): void {
            $anotados[$message->message] = $message->context;
        });

        expect(Artisan::call('product:telemetry', ['--send' => true, '--no-interaction' => true]))->toBe(0);
    });

    expect($anotados)->toHaveKey('product.telemetry_state_unwritable');

    /** @var array<string, mixed> $contexto */
    $contexto = $anotados['product.telemetry_state_unwritable'];

    expect($contexto['operation'])->toBe('mkdir')
        // La consecuencia, escrita: es lo que hace util el aviso.
        ->and($contexto['effect'])->toBe('installation_id_not_persisted')
        // Y ni la ruta del servidor ni el mensaje del error, que la lleva dentro.
        ->and(json_encode($contexto))->not->toContain($directorio)
        ->and(json_encode($contexto))->not->toContain(sys_get_temp_dir());

    // `notice`, jamas `error`: no se ha roto nada del registro.
    expect($niveles)->toContain('notice')
        ->and($niveles)->not->toContain('error')
        ->and($niveles)->not->toContain('critical');

    // Y el envio se hizo igual: no poder anotar el resultado no es motivo para
    // no intentarlo.
    Http::assertSentCount(1);

    rmdir($directorio);
})->group('RF-PD-12');
