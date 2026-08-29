<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\UseCase\VerifyAuditChain;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\AuthChannel;
use App\Modules\Shared\Domain\ValueObject\AuthOutcome;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Shared\AuthenticationTrail;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\EmployeePins;

/*
 * OWASP A09 en la tercera puerta: **el PIN de respaldo del quiosco** (RF-AT-11,
 * RS-12).
 *
 * ```
 * ficha por PIN   contador success        SIN asiento propio
 * falla           contador failure + log  SIN asiento
 * se bloquea      contador + log + ASIENTO auth.lockout_started, actor = el quiosco
 * ```
 *
 * **Por que el exito no deja asiento propio, y no es una omision.** El fichaje
 * que viene detras ya escribe `shift_entry.created` con el mismo empleado y el
 * mismo instante: un segundo asiento no responderia ninguna pregunta nueva y
 * tomaria por segunda vez —dentro del camino de fichaje— el candado global de la
 * cadena de ADR-010, que es el unico sitio del producto donde ese coste se nota
 * (RNF-P-02). La prueba de abajo lo fija con la lista exacta de acciones que
 * quedan en la tabla.
 *
 * **El asiento del bloqueo si lleva actor, y es el quiosco.** Aqui la peticion
 * viene con el token del dispositivo, asi que `CurrentAuditContext` resuelve
 * `device#id` y el asiento dice ademas desde que tablet se estaba tecleando —lo
 * que en una investigacion es la mitad de la respuesta—.
 */

uses(RefreshDatabase::class);

const PIN_DEL_RASTRO = '481902';

const PIN_MALO_DEL_RASTRO = '481903';

/**
 * Empleado con PIN, quiosco emparejado, claves de sellado y reloj detenido.
 *
 * @return array{site: int, employee: string, code: string, publicKey: string, device: int, deviceUuid: string, token: string}
 */
function escenarioDelRastroDePin(): array
{
    config()->set('identity.pin.max_attempts', 3);
    config()->set('identity.pin.lockout_seconds', 300);
    config()->set('identity.pin.lockout_tier2_attempts', 5);
    config()->set('identity.pin.lockout_tier2_seconds', 900);
    config()->set('identity.pin.lockout_tier3_attempts', 10);
    config()->set('identity.pin.lockout_tier3_seconds', 3600);
    config()->set('identity.pin.lockout_reset_hours', 24);

    app(Cache::class)->clear();

    $escenario = AttendanceFixtures::scenario('Europe/Madrid');

    EmployeePins::issue($escenario['employee'], PIN_DEL_RASTRO);

    app()->instance(Clock::class, FixedClock::at('2026-03-14 07:02:31'));

    return [
        ...$escenario,
        'code' => EmployeePins::codeOf($escenario['employee']),
        'publicKey' => EmployeePins::configureSealing(),
    ];
}

/**
 * @param  array{token: string, code: string, publicKey: string, ...}  $escenario
 * @param  array<string, mixed>  $overrides
 * @return TestResponse<Response>
 */
function ficharConPinDelRastro(
    array $escenario,
    string $pin = PIN_DEL_RASTRO,
    array $overrides = [],
): TestResponse {
    $scanId = Str::uuid7()->toString();

    return Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan/pin', array_merge([
            'scan_id' => $scanId,
            'occurred_at' => '2026-03-14T07:02:31Z',
            'employee_code' => $escenario['code'],
            'pin_sealed' => EmployeePins::seal($pin, $escenario['publicKey']),
        ], $overrides));
}

it('cuenta el fichaje por PIN sin duplicar el asiento del fichaje', function (): void {
    $contador = AuthenticationTrail::countingMetrics();
    $escenario = escenarioDelRastroDePin();

    ficharConPinDelRastro($escenario)->assertSuccessful();

    $acciones = array_map(
        static fn (array $asiento): string => $asiento['action'],
        AuthenticationTrail::auditEntries(),
    );

    expect($contador->countOf(AuthChannel::KIOSK_PIN, AuthOutcome::SUCCESS))->toBe(1)
        // El fichaje si deja su asiento; la autenticacion que lo precedio, no.
        ->and($acciones)->toBe(['shift_entry.created']);
})->group('RS-13', 'RS-12', 'RF-AT-11');

it('deja el fallo del PIN del quiosco en el log y en el contador', function (): void {
    $contador = AuthenticationTrail::countingMetrics();
    $escenario = escenarioDelRastroDePin();

    /** @var list<array{message: string, context: array<string, mixed>}> $apuntes */
    $apuntes = [];
    AuthenticationTrail::captureLog($apuntes);

    ficharConPinDelRastro($escenario, pin: PIN_MALO_DEL_RASTRO)->assertStatus(422);

    expect(DB::table('audit_log')->count())->toBe(0)
        ->and($apuntes)->toHaveCount(1)
        ->and($apuntes[0]['message'])->toBe('auth.login_failed')
        ->and($apuntes[0]['context']['channel'])->toBe('kiosk_pin')
        ->and($apuntes[0]['context']['reason'])->toBe('invalid_credentials')
        ->and($apuntes[0]['context']['subject_uuid'])->toBeNull()
        ->and($contador->countOf(AuthChannel::KIOSK_PIN, AuthOutcome::FAILURE))->toBe(1);
})->group('RS-13', 'RS-12', 'RS-03', 'RF-AT-11');

it('distingue en el log el sobre que no abre del PIN que no coincide', function (): void {
    // Hacia fuera los dos son el mismo rechazo; hacia dentro no son lo mismo, y
    // la diferencia importa: un criptograma corrupto no dice nada del PIN que
    // lleva dentro y **no cuenta como intento fallido de nadie**, asi que no
    // puede acercar a nadie a su bloqueo.
    $escenario = escenarioDelRastroDePin();

    /** @var list<array{message: string, context: array<string, mixed>}> $apuntes */
    $apuntes = [];
    AuthenticationTrail::captureLog($apuntes);

    ficharConPinDelRastro($escenario, overrides: ['pin_sealed' => str_repeat('A', 72)])
        ->assertStatus(422);

    expect($apuntes)->toHaveCount(1)
        ->and($apuntes[0]['context']['reason'])->toBe('sealed_pin_unreadable');
})->group('RS-13', 'RS-12', 'RF-AT-11');

it('deja asiento del bloqueo del PIN con el quiosco como actor', function (): void {
    $contador = AuthenticationTrail::countingMetrics();
    $escenario = escenarioDelRastroDePin();

    for ($intento = 0; $intento < 3; $intento++) {
        ficharConPinDelRastro($escenario, pin: PIN_MALO_DEL_RASTRO)->assertStatus(422);
    }

    $asiento = AuthenticationTrail::onlyAuditEntry();

    expect($asiento['action'])->toBe('auth.lockout_started')
        ->and($asiento['actor_type'])->toBe('device')
        ->and($asiento['actor_id'])->toBe($escenario['device'])
        ->and($asiento['subject_type'])->toBe('employee')
        ->and($asiento['payload']['channel'])->toBe('kiosk_pin')
        ->and($asiento['payload']['employee_uuid'])->toBe($escenario['employee'])
        ->and($asiento['payload']['lock_seconds'])->toBe(300)
        ->and($asiento['raw'])->not->toContain($escenario['code'])
        ->and($asiento['raw'])->not->toContain(PIN_DEL_RASTRO);

    expect($contador->countOf(AuthChannel::KIOSK_PIN, AuthOutcome::FAILURE))->toBe(3)
        ->and($contador->countOf(AuthChannel::KIOSK_PIN, AuthOutcome::LOCKOUT))->toBe(1)
        ->and(app(VerifyAuditChain::class)->handle()->isIntact())->toBeTrue();
})->group('RS-13', 'RS-12', 'RS-07', 'RF-AT-11');

it('cuenta aparte el intento que llega con el bloqueo puesto y no repite el asiento', function (): void {
    $contador = AuthenticationTrail::countingMetrics();
    $escenario = escenarioDelRastroDePin();

    for ($intento = 0; $intento < 3; $intento++) {
        ficharConPinDelRastro($escenario, pin: PIN_MALO_DEL_RASTRO)->assertStatus(422);
    }

    // Con el PIN BUENO, ya bloqueado: mismo rechazo y ningun asiento nuevo.
    ficharConPinDelRastro($escenario)->assertStatus(422);

    expect(DB::table('audit_log')->count())->toBe(1)
        // Un solo bloqueo abierto, y el cuarto intento —con el PIN bueno— es un
        // fallo mas: no acabo en fichaje.
        ->and($contador->countOf(AuthChannel::KIOSK_PIN, AuthOutcome::LOCKOUT))->toBe(1)
        ->and($contador->countOf(AuthChannel::KIOSK_PIN, AuthOutcome::FAILURE))->toBe(4)
        ->and($contador->countOf(AuthChannel::KIOSK_PIN, AuthOutcome::SUCCESS))->toBe(0);
})->group('RS-13', 'RS-12', 'RF-AT-11');
