<?php

declare(strict_types=1);

use Illuminate\Contracts\Redis\Factory as Redis;

/*
 * La suite escribe en un Redis que no es el del entorno de desarrollo
 * (V21-4-BE-4, tarea 22.8 de la 2.3.0).
 *
 * Las pruebas de metricas, del limitador y del latido escriben claves y las
 * borran al terminar. Con las bases 0 y 1 y el prefijo de serie lo hacian en el
 * mismo espacio que `make up`: `/metrics` del entorno de desarrollo contaba
 * altas de administrador que solo habian ocurrido en Pest, y una prueba que
 * limpia sus claves podia llevarse por delante las de la aplicacion.
 *
 * Se pregunta al CLIENTE conectado y no a `phpunit.xml`: lo que importa es
 * donde acaban las claves, y el contenedor `app` define variables de Redis en
 * su entorno que un `<env>` sin su `<server>` no habria pisado. Las bases del
 * entorno de desarrollo son la 0 y la 1 (`config/database.php`, sin
 * `REDIS_DB` ni `REDIS_CACHE_DB` en `infra/compose.dev.yaml`).
 */

it('conecta la suite a una base de Redis que el entorno de desarrollo no usa', function (string $conexion, int $baseDeLaSuite): void {
    $cliente = resolve(Redis::class)->connection($conexion)->client();

    \assert($cliente instanceof \Redis);

    expect($cliente->getDbNum())->toBe($baseDeLaSuite);
})->with([
    'conexion por defecto, la 0 en desarrollo' => ['default', 14],
    'conexion de cache, la 1 en desarrollo' => ['cache', 15],
])->group('RQ-14');

it('antepone a cada clave de la suite un prefijo propio de las pruebas', function (): void {
    $cliente = resolve(Redis::class)->connection()->client();

    \assert($cliente instanceof \Redis);

    expect($cliente->getOption(\Redis::OPT_PREFIX))->toBe('kronoqr-pest-');
})->group('RQ-14');
