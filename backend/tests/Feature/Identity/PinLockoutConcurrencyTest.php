<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Port\PinAttempts;
use App\Modules\Shared\Domain\ValueObject\PinOrigin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Concurrency\ParallelRequests;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Shared\TalliedPinAttempts;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\EmployeePins;

/*
 * El bloqueo del PIN POR EMPLEADO bajo concurrencia (RS-12, doc 02 §7.5,
 * ADR-015, ADR-043, ADR-050).
 *
 * Lo que vigila: que una rafaga no compre mas comparaciones contra el PIN real
 * que las que caben antes del primer escalon. Veinticinco procesos simultaneos
 * con el mismo codigo y un PIN equivocado: **como mucho tres** se comparan
 * contra el PIN de verdad —los demas llegan bloqueados y se comparan contra el
 * señuelo—, los tres quedan en el contador y hay un solo asiento
 * `auth.lockout_started`.
 *
 * **Estuvo en rojo dos veces** en el bloque 12 de la 2.2.0. Sin candado (medido
 * el 06-10-2026) de nueve a veinte intentos llegaban al PIN real y el contador
 * guardaba de tres a nueve, con hasta nueve asientos de apertura. Con candado
 * pero anotando el fallo DESPUES de comparar, los fallos ya no se perdian pero
 * de 4 a 22 de 25 seguian comparandose contra el PIN real: la cota por empleado
 * dependia del tamaño de la botnet. Ahora `HashedEmployeePinVerifier` reserva
 * el intento con el candado del empleado cogido ANTES de comparar
 * (`PinAttempts::reserve()`).
 *
 * Las dos puertas, porque las dos cuentan por este contador: el portal
 * (`/me/login`) y el fichaje de respaldo del quiosco (`/scan/pin`).
 *
 * **Procesos de verdad** (`ParallelRequests`), sobre la cache compartida de una
 * instalacion real: un bucle en el mismo proceso nunca pierde un incremento.
 */

uses(CommittedDatabase::class);

const PIN_LOCKOUT_CONCURRENCY_ATTEMPTS = 25;

const PIN_LOCKOUT_CONCURRENCY_PIN = '904471';

const PIN_LOCKOUT_CONCURRENCY_WRONG_PIN = '222222';

beforeEach(function (): void {
    // Los limitadores de peticiones y el bloqueo por origen, fuera de juego: lo
    // que se mide aqui es el contador de fallos POR EMPLEADO, que es otro
    // control (§7.5).
    config()->set('identity.portal.rate_limit_per_minute', 10_000);
    config()->set('identity.portal.origin_lockout.max_failures', 10_000);
    config()->set('kiosk.rate_limits.pin_scan_per_device', 10_000);
    config()->set('kiosk.rate_limits.pin_scan_per_ip', 10_000);

    // Los escalones de serie, escritos para no depender del `.env`.
    config()->set('identity.pin.max_attempts', 3);
    config()->set('identity.pin.lockout_seconds', 300);
    config()->set('identity.pin.lockout_tier2_attempts', 5);
    config()->set('identity.pin.lockout_tier2_seconds', 900);
    config()->set('identity.pin.lockout_tier3_attempts', 10);
    config()->set('identity.pin.lockout_tier3_seconds', 3600);
    config()->set('identity.pin.lockout_reset_hours', 24);
});

/**
 * La cache `resilient` sobre un almacen que comparten los procesos, como en
 * produccion; la de `phpunit.xml` es `array` y cada hijo tendria la suya.
 *
 * @param  list<string>  $almacenes
 */
function pinLockoutConcurrencyCache(array $almacenes): void
{
    config()->set('cache.default', 'resilient');
    config()->set('cache.stores.resilient.stores', $almacenes);
    config()->set('cache.prefix', 'kronoqr-test-pin-concurrency-');
    config()->set('cache.stores.file.path', sys_get_temp_dir().'/kronoqr-pin-concurrency-cache');
    config()->set('cache.stores.file.lock_path', sys_get_temp_dir().'/kronoqr-pin-concurrency-cache');

    app()->forgetInstance('cache');
    app()->forgetInstance('cache.store');
    app()->forgetInstance(PinAttempts::class);
}

/**
 * Un empleado con PIN y quiosco emparejado, con el reloj detenido.
 *
 * @return array{employee: string, code: string, token: string, publicKey: string}
 */
function pinLockoutConcurrencyScenario(): array
{
    // Nada de cache aqui: una conexion a Redis abierta en el padre la heredan
    // los hijos al bifurcar, y veinticinco procesos sobre el mismo socket se
    // roban las respuestas. Cada prueba usa un empleado nuevo, asi que no hay
    // contador que limpiar antes.
    $escenario = AttendanceFixtures::scenario();

    EmployeePins::issue($escenario['employee'], PIN_LOCKOUT_CONCURRENCY_PIN);

    FrozenTime::at('2026-10-06 09:00:00');

    $escenario = [
        'employee' => $escenario['employee'],
        'code' => EmployeePins::codeOf($escenario['employee']),
        'token' => $escenario['token'],
        'publicKey' => EmployeePins::configureSealing(),
    ];

    return $escenario;
}

it('compara como mucho tres intentos simultaneos contra el PIN real y los cuenta', function (PinOrigin $puerta, string ...$almacenes): void {
    pinLockoutConcurrencyCache(array_values($almacenes));
    $escenario = pinLockoutConcurrencyScenario();

    // Cada intento que reserva sin encontrar el bloqueo abierto se compara
    // contra el PIN real; los demas, contra el señuelo. El decorador cuenta los
    // primeros, y los que abren el bloqueo, en todos los hijos.
    $recuento = new TalliedPinAttempts(
        app(PinAttempts::class),
        $escenario['employee'],
        sys_get_temp_dir().'/kronoqr-pin-tally-'.bin2hex(random_bytes(6)),
    );
    app()->instance(PinAttempts::class, $recuento);

    try {
        $respuestas = ParallelRequests::run(
            PIN_LOCKOUT_CONCURRENCY_ATTEMPTS,
            static fn () => $puerta === PinOrigin::PORTAL
                ? Api::guest()->post('/api/v1/me/login', [
                    'employee_code' => $escenario['code'],
                    'pin' => PIN_LOCKOUT_CONCURRENCY_WRONG_PIN,
                ])
                : Api::as($escenario['token'])
                    ->withHeaders(['Idempotency-Key' => $scanId = Str::uuid7()->toString()])
                    ->post('/api/v1/scan/pin', [
                        'scan_id' => $scanId,
                        'occurred_at' => '2026-10-06T09:00:00Z',
                        'employee_code' => $escenario['code'],
                        'pin_sealed' => EmployeePins::seal(PIN_LOCKOUT_CONCURRENCY_WRONG_PIN, $escenario['publicKey']),
                    ]),
        );

        // Lo que guarda el contador, leido en el padre sobre la misma cache que
        // acaban de escribir los hijos. La clave se escribe aqui a proposito: es
        // el dato que se pierde, y ninguna respuesta del puerto lo da sin
        // redondearlo a un escalon.
        $guardados = app('cache')->get('workforce:pin-failures:'.$puerta->value.':'.$escenario['employee']);
        $probados = $recuento->tally();

        expect(array_unique(array_column($respuestas, 'status')))->toBe([$puerta === PinOrigin::PORTAL ? 401 : 422])
            // EL CENTRO DE LA PRUEBA: como mucho tres comparaciones contra el PIN
            // real de veinticinco, las que caben antes del primer escalon. Al
            // menos una, o nadie habria llegado a reservar.
            ->and($probados)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(3)
            // Y todas ellas contadas: reservar es anotar.
            ->and(\is_array($guardados) ? \count($guardados) : 0)->toBe($probados)
            ->and($recuento->secondsUntilUnlock($escenario['employee'], $puerta))->toBeGreaterThan(0)
            // Un solo flanco y un solo asiento: el de la reserva que abrio el
            // bloqueo, no uno por cada proceso que lo encontro abierto.
            ->and($recuento->openedTally())->toBe(1)
            ->and(DB::table('audit_log')->where('action', 'auth.lockout_started')->count())->toBe(1);
    } finally {
        $recuento->clear($escenario['employee']);
        $recuento->forgetTally();
    }
})->with([
    'portal' => [PinOrigin::PORTAL],
    'quiosco' => [PinOrigin::KIOSK],
])->with([
    'sobre Redis' => ['redis', 'file'],
    'sobre el disco' => ['file'],
])->group('RS-12', 'RF-ID-06', 'RF-AT-11');
