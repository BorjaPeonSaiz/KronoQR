<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;
use Tests\Architecture\Support\Repo;

/*
 * El JIT de PostgreSQL va apagado en TODO PostgreSQL que arranca el proyecto
 * (bloque 22 de la 2.2.1; RNF-P-02, RNF-P-05).
 *
 * Se apaga en el servidor (`-c jit=off`) y no por conexion: asi vale para todos
 * los roles y servicios, y no pisa el `PGOPTIONS` de `app`. Pero hay cinco sitios
 * que arrancan PostgreSQL con su propia linea de orden —los dos compose y los
 * `docker run` de la CI y del simulacro de copias—, y uno que se quede sin el
 * ajuste mide con otro planificador: la CI aprobaria planes y tiempos que la
 * instalacion no reproduce. Esta prueba los recorre todos.
 *
 * Que el servidor de verdad lo aplica lo comprueba
 * `tests/Integration/Database/PostgresJitTest.php` con `SHOW jit`.
 */

/**
 * @return array<string, array{0: string}>
 */
function composesConPostgresSinJit(): array
{
    return [
        'desarrollo' => ['infra/compose.dev.yaml'],
        'produccion' => ['infra/compose.prod.yaml'],
    ];
}

it('el servicio postgres de cada compose arranca con jit=off', function (string $compose): void {
    /** @var array{services: array{postgres: array{command: list<string>}}} $parsed */
    $parsed = Yaml::parse(Repo::contents($compose));
    $command = $parsed['services']['postgres']['command'];

    expect($command[0] ?? null)->toBe('postgres');

    $settings = [];

    foreach ($command as $index => $argument) {
        if ($argument === '-c' && isset($command[$index + 1])) {
            $settings[] = $command[$index + 1];
        }
    }

    expect($settings)->toContain('jit=off');
})->with(composesConPostgresSinJit())->group('RNF-P-02');

it('cada docker run de PostgreSQL de los workflows lleva -c jit=off', function (string $workflow, int $esperadas): void {
    $lineas = array_values(array_filter(
        explode("\n", str_replace("\r\n", "\n", Repo::contents($workflow))),
        static fn (string $linea): bool => str_contains($linea, 'postgres -c timezone=UTC'),
    ));

    // El recuento ata la lista: si aparece otro arranque, esta prueba lo pide.
    expect($lineas)->toHaveCount($esperadas);

    foreach ($lineas as $linea) {
        expect($linea)->toContain('-c jit=off');
    }
})->with([
    'CI' => ['.github/workflows/ci.yml', 4],
    'simulacro de copias' => ['.github/workflows/backup-drill.yml', 1],
])->group('RNF-P-02');
