<?php

declare(strict_types=1);

use App\Modules\Attendance\Http\Middleware\ThrottleScanFailOpen;
use Illuminate\Support\Facades\Route as Router;

/*
 * QUE LIMITADORES FALLAN ABIERTOS SI REDIS NO RESPONDE, Y NINGUNO MAS
 * (CH1, ADR-038 matizado por ADR-059 §3, RS-02, RS-03).
 *
 * Una lista cerrada, leida del router: las tres rutas de escritura del fichaje y
 * las tres del portal del empleado. Fallar abierto es aceptable donde fallar
 * cerrado dejaria jornadas sin registrar o al empleado sin su registro, y donde
 * las capas que frenan la fuerza bruta siguen en pie sin Redis (Nginx por IP y
 * los bloqueos por empleado y por origen, en disco). En el acceso al panel no
 * lo es: su bloqueo de la contraseña no tiene respaldo en disco. Una ruta de
 * `auth`, `2fa`, `setup` o gestion que apareciera aqui desactivaria esa defensa
 * justo durante una averia.
 */

/**
 * Las rutas cuyo limitador falla abierto, `METODO /uri`.
 *
 * @return list<string>
 */
function throttleFailOpenRoutes(): array
{
    $rutas = [];

    foreach (Router::getRoutes()->getRoutes() as $route) {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (\is_string($middleware) && str_starts_with($middleware, ThrottleScanFailOpen::class.':')) {
                $rutas[] = implode('|', array_diff($route->methods(), ['HEAD'])).' /'.$route->uri();
            }
        }
    }

    sort($rutas);

    return $rutas;
}

it('abre el limitador ante una caida de Redis solo en el fichaje y en el portal del empleado', function (): void {
    expect(throttleFailOpenRoutes())->toBe([
        'GET /api/v1/me/export',
        'GET /api/v1/me/workdays',
        'POST /api/v1/me/login',
        'POST /api/v1/scan',
        'POST /api/v1/scan/batch',
        'POST /api/v1/scan/pin',
    ]);
})->group('RS-02', 'RS-12', 'RF-AT-10', 'RL-05');

it('admite solo las cuatro zonas de la lista cerrada', function (): void {
    expect(ThrottleScanFailOpen::ZONES)->toBe(['scan', 'scan-batch', 'scan-pin', 'portal']);
})->group('RS-02');

it('se niega a abrir el limitador de una zona que no sea del fichaje ni del portal', function (string $zona): void {
    expect(static fn (): string => ThrottleScanFailOpen::zone($zona))
        ->toThrow(InvalidArgumentException::class);
})->with(['auth', '2fa', 'setup', 'management', 'branding'])->group('RS-02');
