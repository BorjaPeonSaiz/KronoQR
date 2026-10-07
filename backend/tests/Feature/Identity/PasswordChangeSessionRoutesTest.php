<?php

declare(strict_types=1);

use App\Exceptions\ProblemDetails;
use App\Modules\Identity\Domain\ValueObject\TokenAbility;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * La sesion de contrasena temporal FALLA CERRADO en toda la API (RF-ID-10,
 * ADR-051, hallazgo A1 de la revision de seguridad del bloque 12c).
 *
 * Su token lleva el unico ambito `password:change`, asi que el middleware
 * `ability` de cualquier ruta lo rechaza. Esta prueba no se fia de una lista
 * escrita a mano: recorre las rutas REGISTRADAS con `auth:sanctum` y pide cada
 * una con ese token. Toda ruta que aparezca mañana queda cubierta el mismo dia,
 * y si una ruta autenticada sin ambito se olvida de `session.password-settled`,
 * esto falla.
 *
 * Solo tres rutas la admiten: `GET /auth/me`, `POST /auth/logout` y
 * `POST /auth/password`. Lista cerrada.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
    LicenseKeys::install();
});

const PASSWORD_CHANGE_SESSION_EXEMPT = [
    'GET /api/v1/auth/me',
    'POST /api/v1/auth/logout',
    'POST /api/v1/auth/password',
];

/**
 * Las rutas autenticadas de la API, con un camino concreto para cada una.
 *
 * @return list<array{0: string, 1: string, 2: string}> Metodo, camino y nombre legible.
 */
function rutasAutenticadasConCamino(): array
{
    $rutas = [];

    /** @var Route $route */
    foreach (Router::getRoutes()->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1') || ! \in_array('auth:sanctum', $route->gatherMiddleware(), true)) {
            continue;
        }

        $path = '/'.preg_replace_callback('/\{(\w+)\??\}/', static fn (array $m): string => match ($m[1]) {
            'id' => '1',
            'step' => 'site',
            default => '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b99',
        }, $route->uri());

        foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
            $rutas[] = [$method, $path, $method.' /'.$route->uri()];
        }
    }

    return $rutas;
}

it('encuentra rutas autenticadas que recorrer', function (): void {
    expect(\count(rutasAutenticadasConCamino()))->toBeGreaterThan(40);
})->group('RF-ID-10');

it('responde 403 password-change-required en toda ruta autenticada salvo las tres exentas', function (): void {
    $user = ManagementUsers::withRole(UserRole::ADMIN);
    $token = $user->createToken('Panel de gestion', [TokenAbility::PASSWORD_CHANGE->value])->plainTextToken;

    $abiertas = [];

    foreach (rutasAutenticadasConCamino() as [$method, $path, $name]) {
        if (\in_array($name, PASSWORD_CHANGE_SESSION_EXEMPT, true)) {
            continue;
        }

        $response = Api::as($token)->call($method, $path);

        if ($response->status() !== 403 || $response->json('type') !== ProblemDetails::TYPE_PASSWORD_CHANGE_REQUIRED) {
            $type = $response->json('type');
            $abiertas[] = $name.' → '.$response->status().' '.(\is_string($type) ? $type : '');
        }
    }

    expect($abiertas)->toBe([]);
})->group('RF-ID-10', 'RS-04');

it('deja a esa sesion consultar quien es, cerrar sesion y cambiar su contrasena', function (): void {
    $user = ManagementUsers::withRole(UserRole::RRHH);
    $token = $user->createToken('Panel de gestion', [TokenAbility::PASSWORD_CHANGE->value])->plainTextToken;

    Api::as($token)->get('/api/v1/auth/me')->assertStatus(200);
    // Sin cuerpo: 422, que prueba que la ruta se alcanza y valida.
    Api::as($token)->post('/api/v1/auth/password')->assertStatus(422);
    Api::as($token)->post('/api/v1/auth/logout')->assertStatus(204);
})->group('RF-ID-10');
