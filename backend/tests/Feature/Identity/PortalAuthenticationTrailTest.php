<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\Port\AuditTrail;
use App\Modules\Compliance\Application\UseCase\VerifyAuditChain;
use App\Modules\Compliance\Domain\ValueObject\AuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditEntryDraft;
use App\Modules\Shared\Domain\ValueObject\AuthChannel;
use App\Modules\Shared\Domain\ValueObject\AuthOutcome;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\PortalLogins;
use Tests\Support\Shared\AuthenticationTrail;
use Tests\Support\Workforce\EmployeePins;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * OWASP A09 en la segunda puerta: **el portal del empleado** (RF-ID-06, RS-12).
 *
 * El reparto no es el mismo que en el panel, y la diferencia no es un descuido:
 *
 * ```
 * entra           contador success        SIN asiento
 * falla           contador failure + log  SIN asiento
 * se bloquea      contador + log + ASIENTO auth.lockout_started
 * ```
 *
 * **Por que entrar al portal no deja asiento.** `audit_log.actor_type` esta
 * acotado por una restriccion de la tabla a `user`, `device`, `system` y
 * `maintenance`: no hay tipo de actor para un empleado. Un asiento de «esta
 * persona abrio su portal» saldria atribuido a `system` —una entrada que miente
 * en la tabla que se enseña en una inspeccion—, y ADR-037 deja escrito que
 * arreglarlo es un cambio de dominio y de esquema, no un `if`.
 *
 * **Por que el bloqueo si.** Porque lo decide el servidor. Su actor es `system`
 * de verdad: nadie «hizo» el bloqueo, lo abrio el contador de intentos al
 * cruzar un escalon. Es el hecho de esta puerta que mas falta hace en una
 * investigacion —«¿a quien estuvieron sondeando anoche?»— y el unico que se
 * puede escribir hoy sin que la tabla mienta.
 */

uses(RefreshDatabase::class);

/**
 * Un empleado con PIN, con los umbrales de serie escritos y el limite de tasa
 * levantado: lo que se observa aqui es el contador de fallos por empleado, no el
 * limitador por origen.
 *
 * @return array{uuid: string, code: string}
 */
function empleadoDelRastro(): array
{
    config()->set('identity.portal.rate_limit_per_minute', 10_000);
    config()->set('identity.pin.max_attempts', 3);
    config()->set('identity.pin.lockout_seconds', 300);
    config()->set('identity.pin.lockout_tier2_attempts', 5);
    config()->set('identity.pin.lockout_tier2_seconds', 900);
    config()->set('identity.pin.lockout_tier3_attempts', 10);
    config()->set('identity.pin.lockout_tier3_seconds', 3600);
    config()->set('identity.pin.lockout_reset_hours', 24);

    app(Cache::class)->clear();

    $uuid = WorkforceFixtures::employee(WorkforceFixtures::site('Hotel del rastro'));

    EmployeePins::issue($uuid, PortalLogins::PIN);

    return ['uuid' => $uuid, 'code' => EmployeePins::codeOf($uuid)];
}

it('cuenta el acceso correcto al portal sin escribir en audit_log', function (): void {
    $contador = AuthenticationTrail::countingMetrics();
    $empleado = empleadoDelRastro();

    Api::guest()->post('/api/v1/me/login', [
        'employee_code' => $empleado['code'],
        'pin' => PortalLogins::PIN,
    ])->assertStatus(200);

    expect($contador->countOf(AuthChannel::PORTAL, AuthOutcome::SUCCESS))->toBe(1)
        ->and(DB::table('audit_log')->count())->toBe(0);
})->group('RS-13', 'RS-12', 'RF-ID-06');

it('deja el fallo del portal en el log y en el contador, y nunca en la cadena', function (): void {
    $contador = AuthenticationTrail::countingMetrics();
    $empleado = empleadoDelRastro();

    /** @var list<array{message: string, context: array<string, mixed>}> $apuntes */
    $apuntes = [];
    AuthenticationTrail::captureLog($apuntes);

    Api::guest()->post('/api/v1/me/login', [
        'employee_code' => $empleado['code'],
        'pin' => '000999',
    ])->assertStatus(401);

    expect(DB::table('audit_log')->count())->toBe(0)
        ->and($apuntes)->toHaveCount(1)
        ->and($apuntes[0]['message'])->toBe('auth.login_failed')
        ->and($apuntes[0]['context']['channel'])->toBe('portal')
        ->and($apuntes[0]['context']['reason'])->toBe('invalid_credentials')
        // Ni el codigo de empleado —va impreso en la tarjeta que la persona
        // lleva encima— ni el UUID: el verificador no devuelve ninguno en un
        // rechazo, para que no exista la rama que distingue «no existe» de «no
        // coincide» (RS-03).
        ->and($apuntes[0]['context']['subject_uuid'])->toBeNull()
        ->and(json_encode($apuntes[0]['context'], JSON_THROW_ON_ERROR))
        ->not->toContain($empleado['code']);

    expect($contador->countOf(AuthChannel::PORTAL, AuthOutcome::FAILURE))->toBe(1);
})->group('RS-13', 'RS-12', 'RS-03', 'RF-ID-06');

it('registra igual el fallo de un codigo que existe y el de uno que no', function (): void {
    $empleado = empleadoDelRastro();

    /** @var list<array{message: string, context: array<string, mixed>}> $apuntes */
    $apuntes = [];
    AuthenticationTrail::captureLog($apuntes);

    Api::guest()->post('/api/v1/me/login', [
        'employee_code' => $empleado['code'],
        'pin' => '000999',
    ])->assertStatus(401);

    Api::guest()->post('/api/v1/me/login', [
        'employee_code' => 'ENOEXISTE',
        'pin' => '000999',
    ])->assertStatus(401);

    expect($apuntes)->toHaveCount(2)
        ->and($apuntes[0]['context'])->toBe($apuntes[1]['context']);
})->group('RS-13', 'RS-03', 'RS-12');

it('deja asiento del bloqueo del portal, con el empleado y sin su codigo', function (): void {
    $contador = AuthenticationTrail::countingMetrics();
    $empleado = empleadoDelRastro();

    for ($intento = 0; $intento < 3; $intento++) {
        Api::guest()->post('/api/v1/me/login', [
            'employee_code' => $empleado['code'],
            'pin' => '000999',
        ])->assertStatus(401);
    }

    $asiento = AuthenticationTrail::onlyAuditEntry();

    expect($asiento['action'])->toBe('auth.lockout_started')
        ->and($asiento['actor_type'])->toBe('system')
        ->and($asiento['subject_type'])->toBe('employee')
        ->and($asiento['payload']['channel'])->toBe('portal')
        ->and($asiento['payload']['employee_uuid'])->toBe($empleado['uuid'])
        ->and($asiento['payload']['lock_seconds'])->toBe(300)
        ->and($asiento['raw'])->not->toContain($empleado['code'])
        ->and($asiento['raw'])->not->toContain(PortalLogins::PIN);

    expect($contador->countOf(AuthChannel::PORTAL, AuthOutcome::FAILURE))->toBe(3)
        ->and($contador->countOf(AuthChannel::PORTAL, AuthOutcome::LOCKOUT))->toBe(1)
        ->and(app(VerifyAuditChain::class)->handle()->isIntact())->toBeTrue();
})->group('RS-13', 'RS-12', 'RS-07', 'RF-ID-06');

it('no repite el asiento por cada intento que llega con el bloqueo puesto', function (): void {
    // Repetirlo llenaria la cadena de hash de ADR-010 con la insistencia de
    // quien ataca, que es justo el trafico que no puede meterse en el camino
    // por el que pasa cada fichaje.
    $contador = AuthenticationTrail::countingMetrics();
    $empleado = empleadoDelRastro();

    for ($intento = 0; $intento < 6; $intento++) {
        Api::guest()->post('/api/v1/me/login', [
            'employee_code' => $empleado['code'],
            'pin' => '000999',
        ])->assertStatus(401);
    }

    expect(DB::table('audit_log')->count())->toBe(1)
        // Los seis intentos son `failure` —los tres ultimos ni llegaron a
        // comprobar el PIN, y siguen siendo intentos que no acabaron en
        // sesion—, y `lockout` vale UNO, que es el numero de bloqueos abiertos.
        // Si contara los rechazos, una sola persona insistiendo contra una sola
        // cuenta dispararia `KronoqrAuthLockouts`, que existe para reconocer
        // varias cuentas alcanzando su limite a la vez.
        ->and($contador->countOf(AuthChannel::PORTAL, AuthOutcome::FAILURE))->toBe(6)
        ->and($contador->countOf(AuthChannel::PORTAL, AuthOutcome::LOCKOUT))->toBe(1);
})->group('RS-13', 'RS-12', 'RS-07');

it('no convierte el rechazo en un 500 cuando audit_log no puede escribir', function (): void {
    // ADR-039. El asiento del bloqueo es el unico que **provoca quien ataca**, y
    // antes se escribia dentro de la respuesta: una `audit_log` averiada subia la
    // excepcion y el rechazo salia como `500`. En el quiosco eso es alguien sin
    // fichar por un problema de auditoria (regla dura 19); aqui, alguien sin ver
    // sus horas (RL-05).
    //
    // Ahora se escribe despues de responder y su fallo se queda en un `error` del
    // log tecnico —sin el mensaje de la excepcion, que en una `QueryException`
    // lleva los valores enlazados y entre ellos la IP en claro—.
    $empleado = empleadoDelRastro();

    app()->instance(AuditTrail::class, new class implements AuditTrail
    {
        public function append(AuditEntryDraft $draft): AuditEntry
        {
            throw new RuntimeException('audit_log no responde');
        }
    });

    /** @var list<array{message: string, context: array<string, mixed>}> $errores */
    $errores = [];
    Log::listen(function (MessageLogged $evento) use (&$errores): void {
        // Solo el apunte del asiento que no se pudo escribir: el `401` de cada
        // rechazo tambien se registra como `error` desde el manejador de
        // excepciones, y contarlo aqui haria pasar la prueba por el motivo
        // equivocado.
        if ($evento->level === 'error' && str_starts_with($evento->message, 'audit.')) {
            $errores[] = ['message' => $evento->message, 'context' => $evento->context];
        }
    });

    for ($intento = 0; $intento < 3; $intento++) {
        Api::guest()->post('/api/v1/me/login', [
            'employee_code' => $empleado['code'],
            'pin' => '000999',
        ])->assertStatus(401);
    }

    expect(DB::table('audit_log')->count())->toBe(0)
        ->and($errores)->toHaveCount(1)
        ->and($errores[0]['message'])->toBe('audit.deferred_entry_failed')
        ->and($errores[0]['context']['action'])->toBe('auth.lockout_started')
        // Regla dura 21: la clase de la excepcion, nunca su mensaje.
        ->and($errores[0]['context'])->not->toHaveKey('message')
        ->and($errores[0]['context']['exception'])->toBe(RuntimeException::class);
})->group('RS-13', 'RS-12', 'RS-07', 'RF-ID-06');
