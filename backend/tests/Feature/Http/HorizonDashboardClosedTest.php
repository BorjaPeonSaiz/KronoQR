<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Persistence\User;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Providers\HorizonServiceProvider;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route as Router;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;

/*
 * El panel de Horizon y sus rutas `horizon/api/*` estan cerrados a TODO el
 * mundo, en cualquier entorno (regla dura 18, RQ-07, hallazgo T1 de la 2.1.0).
 *
 * Lo que exponen son las cargas de los trabajos de cola, que llevan datos de la
 * plantilla (RS-05). Sin `HorizonServiceProvider` el paquete solo las cerraba
 * por `environment('local')`: una instalacion arrancada con `APP_ENV=local`
 * las servia sin sesion.
 *
 * La lista de rutas NO se escribe a mano: se recorre el router. Una ruta que
 * anada una version nueva del paquete entra sola en la prueba.
 */

uses(RefreshDatabase::class);

/**
 * Las rutas de Horizon tal y como las registra el paquete, con un metodo y una
 * URI concreta para llamarlas (los parametros se rellenan con un valor ficticio:
 * lo que se prueba es que la puerta se cierra ANTES de buscar nada).
 *
 * @return array<string, array{0: string, 1: string}>
 */
function horizonClosedRoutes(): array
{
    $rutas = [];

    /** @var Route $route */
    foreach (Router::getRoutes()->getRoutes() as $route) {
        $nombre = (string) $route->getName();

        if (! str_starts_with($route->uri(), 'horizon') && ! str_starts_with($nombre, 'horizon.')) {
            continue;
        }

        $metodo = array_values(array_diff($route->methods(), ['HEAD']))[0] ?? 'GET';
        $uri = '/'.preg_replace('/\{[^}]+\}/', 'x1', $route->uri());

        $rutas[$metodo.' '.$route->uri()] = [$metodo, $uri];
    }

    return $rutas;
}

/**
 * Una peticion NUEVA contra el kernel, sin nadie autenticado salvo, si se pasa,
 * `$usuario` en el guard `web` (que es el que consulta `$request->user()` en
 * las rutas de Horizon). Devuelve el codigo de estado.
 */
function horizonClosedStatus(string $metodo, string $uri, ?User $usuario): int
{
    Auth::forgetGuards();

    if ($usuario !== null) {
        Auth::guard('web')->setUser($usuario);
    }

    /** @var Kernel $kernel */
    $kernel = app(Kernel::class);
    $request = Request::create($uri, $metodo);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    return $response->getStatusCode();
}

it('registra el proveedor que cierra Horizon', function (): void {
    expect(app()->getProviders(HorizonServiceProvider::class))->not->toBeEmpty();
})->group('RQ-07', 'RS-05');

it('encuentra las rutas de Horizon en el router (si no, la prueba de abajo no probaria nada)', function (): void {
    // 22 en laravel/horizon 5: el panel y 21 rutas `horizon/api/*`. Se exige un
    // minimo y no el numero exacto para que una version del paquete no la rompa.
    expect(count(horizonClosedRoutes()))->toBeGreaterThanOrEqual(20);
})->group('RQ-07', 'RS-05');

it('deniega el gate viewHorizon incluso al admin', function (): void {
    $admin = ManagementUsers::withRole(UserRole::ADMIN);

    expect(Gate::forUser($admin)->allows('viewHorizon'))->toBeFalse()
        ->and(Gate::allows('viewHorizon'))->toBeFalse();
})->group('RQ-07', 'RS-05');

it('responde 403 en toda ruta de Horizon, sin sesion y con admin, en local y en produccion', function (string $entorno): void {
    $rutas = horizonClosedRoutes();
    $admin = ManagementUsers::withRole(UserRole::ADMIN);

    app()->instance('env', $entorno);
    expect(app()->environment())->toBe($entorno);

    // Sin CSRF para que las rutas de escritura lleguen a la puerta de Horizon:
    // con el token ausente responderian 419 y la prueba mediria el CSRF, no el
    // gate. Lo que se exige aqui es que el gate las cierre por si solo. Es lo
    // mismo que hace `withoutMiddleware()`, sin depender de `$this`.
    app()->instance(PreventRequestForgery::class, new class
    {
        public function handle(Request $request, Closure $next): mixed
        {
            return $next($request);
        }
    });

    $tokenAdmin = ManagementUsers::tokenFor($admin);
    $abiertas = [];

    foreach ($rutas as $clave => [$metodo, $uri]) {
        $estados = [
            'sin sesion' => horizonClosedStatus($metodo, $uri, null),
            // El panel se abriria con la sesion del guard `web`: es la via real.
            'admin con sesion web' => horizonClosedStatus($metodo, $uri, $admin),
            // Y con el token de Sanctum del panel de gestion, por si acaso.
            'admin con token' => Api::as($tokenAdmin)->call($metodo, $uri)->getStatusCode(),
        ];

        foreach ($estados as $quien => $estado) {
            if ($estado !== 403) {
                $abiertas[] = "{$quien} {$clave} -> {$estado}";
            }
        }
    }

    expect($abiertas)->toBe([], 'Rutas de Horizon que no responden 403: '.implode(', ', $abiertas));
})->with(['local', 'production'])->group('RQ-07', 'RS-05');
