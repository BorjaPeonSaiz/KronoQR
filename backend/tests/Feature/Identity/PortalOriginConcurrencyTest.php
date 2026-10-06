<?php

declare(strict_types=1);

use App\Modules\Identity\Application\Port\PortalOriginAttempts;
use App\Modules\Identity\Domain\ValueObject\RequestOrigin;
use App\Modules\Shared\Application\Port\PinAttempts;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concurrency\ParallelRequests;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Time\FrozenTime;

/*
 * El bloqueo por origen del portal BAJO CONCURRENCIA (RS-12, ADR-050 §2).
 *
 * Lo que esta prueba vigila es que quien lanza los intentos EN PARALELO desde
 * una sola direccion no consiga mas intentos que quien los lanza en fila. Veinticinco procesos a la vez,
 * cinco por encima del umbral de veinte: si la cuenta pierde mas de cinco
 * incrementos, el bloqueo no llega y el siguiente intento entra a probar PIN.
 *
 * **Estuvo en rojo** hasta el bloque 12 de la 2.2.0: medido el 06-10-2026,
 * los veinticinco fallos simultaneos dejaban en la cache entre uno y tres (se
 * pisaban entre `historyFor()` y `save()`). Ahora `CachePortalOriginAttempts`
 * cuenta con el candado del origen cogido, en Redis y en el disco.
 *
 * **Procesos de verdad** (`ParallelRequests`), sobre la cache compartida de una
 * instalacion real: un bucle en el mismo proceso nunca pierde un incremento.
 */

uses(CommittedDatabase::class);

const PORTAL_ORIGIN_CONCURRENCY_IP = '203.0.113.77';

const PORTAL_ORIGIN_CONCURRENCY_ATTEMPTS = 25;

beforeEach(function (): void {
    config()->set('identity.portal.rate_limit_per_minute', 10_000);
    config()->set('identity.portal.origin_lockout.max_failures', 20);
    config()->set('identity.portal.origin_lockout.window_seconds', 900);
    config()->set('identity.portal.origin_lockout.lockout_seconds', 3600);

    FrozenTime::at('2026-10-06 09:00:00');
});

afterEach(function (): void {
    app(PortalOriginAttempts::class)->forget(RequestOrigin::of(PORTAL_ORIGIN_CONCURRENCY_IP));
});

/**
 * La cache `resilient` sobre un almacen que comparten los procesos, como en
 * produccion; la de `phpunit.xml` es `array` y cada hijo tendria la suya.
 *
 * @param  list<string>  $almacenes
 */
function portalOriginConcurrencyCache(array $almacenes): void
{
    // Tambien la cache por defecto, como su gemela `PinLockoutConcurrencyTest`:
    // el contador por empleado y los limitadores comparten almacen entre los
    // procesos, como en una instalacion real.
    config()->set('cache.default', 'resilient');
    config()->set('cache.stores.resilient.stores', $almacenes);
    config()->set('cache.prefix', 'kronoqr-test-origin-concurrency-');
    config()->set('cache.stores.file.path', sys_get_temp_dir().'/kronoqr-origin-concurrency-cache');
    config()->set('cache.stores.file.lock_path', sys_get_temp_dir().'/kronoqr-origin-concurrency-cache');

    app()->forgetInstance('cache');
    app()->forgetInstance('cache.store');
    app()->forgetInstance(PortalOriginAttempts::class);
    app()->forgetInstance(PinAttempts::class);
}

it('cierra el portal al origen aunque los fallos lleguen todos a la vez', function (string ...$almacenes): void {
    portalOriginConcurrencyCache(array_values($almacenes));

    $respuestas = ParallelRequests::run(
        PORTAL_ORIGIN_CONCURRENCY_ATTEMPTS,
        static fn (int $indice) => Api::guest()->fromIp(PORTAL_ORIGIN_CONCURRENCY_IP)->post('/api/v1/me/login', [
            'employee_code' => 'NOEXISTE'.$indice,
            'pin' => '000999',
        ]),
    );

    $siguiente = Api::guest()->fromIp(PORTAL_ORIGIN_CONCURRENCY_IP)->post('/api/v1/me/login', [
        'employee_code' => 'NOEXISTE99',
        'pin' => '000999',
    ]);

    expect(array_column($respuestas, 'status'))->each->toBeIn([401, 429])
        ->and($siguiente->getStatusCode())->toBe(429)
        // Y un solo asiento: el del fallo que abrio el bloqueo, no uno por cada
        // proceso que lo encontro abierto (ADR-050 §2).
        ->and(DB::table('audit_log')->where('action', 'auth.origin_locked')->count())->toBe(1);
})->with([
    'sobre Redis' => ['redis', 'file'],
    'sobre el disco' => ['file'],
])->group('RS-12', 'RF-ID-08');
