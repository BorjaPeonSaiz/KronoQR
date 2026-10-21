<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;
use Tests\Architecture\Support\Repo;

/*
 * UNA SOLA REPLICA DE `app` POR INSTALACION (ADR-059 §3, ADR-017, ADR-040).
 *
 * Con Redis caido, el bloqueo del PIN por empleado y el bloqueo del portal por
 * origen siguen contando en el disco del contenedor `app` (cache `resilient`,
 * escrituras bajo `flock`). Ese disco es local: con dos replicas, cada una
 * contaria la mitad de los fallos y el bloqueo llegaria tarde. Es una condicion
 * de despliegue, no una costumbre, y por eso la fija esta prueba:
 *
 * - el servicio `app` de `infra/compose.prod.yaml` no declara `deploy.replicas`
 *   mayor que 1, ni `scale`, ni `deploy.mode: global`;
 * - ningun script de `infra/scripts/` levanta `app` con `--scale app`.
 */

const SINGLE_APP_REPLICA_COMPOSE = 'infra/compose.prod.yaml';

/**
 * @return array<string, mixed>
 */
function singleAppReplicaService(): array
{
    $document = Yaml::parse(Repo::contents(SINGLE_APP_REPLICA_COMPOSE));
    $services = \is_array($document) && \is_array($document['services'] ?? null) ? $document['services'] : [];
    $app = $services['app'] ?? null;

    expect($app)->toBeArray(SINGLE_APP_REPLICA_COMPOSE.' no declara el servicio app.');

    /** @var array<string, mixed> $app */
    return $app;
}

it('el servicio app no declara mas de una replica', function (): void {
    $app = singleAppReplicaService();
    $deploy = \is_array($app['deploy'] ?? null) ? $app['deploy'] : [];
    $replicas = $deploy['replicas'] ?? 1;

    expect(is_numeric($replicas) ? (int) $replicas : 1)->toBeLessThanOrEqual(1)
        ->and($app)->not->toHaveKey('scale')
        ->and($deploy['mode'] ?? 'replicated')->not->toBe('global');
})->group('RS-12', 'RNF-D-03');

it('ningun script de instalacion escala app', function (): void {
    $root = Repo::root().'/infra/scripts';
    $escalan = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile()) {
            continue;
        }

        if (preg_match('/--scale[= ]+app\b/', (string) file_get_contents($file->getPathname())) === 1) {
            $escalan[] = substr($file->getPathname(), \strlen($root) + 1);
        }
    }

    expect($escalan)->toBe([]);
})->group('RS-12', 'RNF-D-03');

it('reconoce un compose que escala app (red de seguridad de la prueba)', function (): void {
    // Si la lectura del compose dejara de ver `deploy`, la primera prueba
    // pasaria siempre.
    $documento = Yaml::parse("services:\n  app:\n    deploy:\n      replicas: 2\n");

    expect(data_get($documento, 'services.app.deploy.replicas'))->toBe(2)
        ->and(preg_match('/--scale[= ]+app\b/', 'docker compose up -d --scale app=2'))->toBe(1)
        ->and(preg_match('/--scale[= ]+app\b/', 'docker compose up -d --scale nginx=0'))->toBe(0);
})->group('RS-12');
