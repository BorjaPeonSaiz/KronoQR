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

/**
 * Lo que impide que el servicio sea de una sola replica. Vacio si lo es.
 *
 * `deploy.replicas`, si existe, tiene que ser un entero literal menor o igual
 * que 1: una variable (`${APP_REPLICAS:-1}`) o cualquier otra cosa que no sea un
 * numero la decide quien despliega, y eso es justo lo que no puede decidirse.
 *
 * @param  array<string, mixed>  $app
 * @return list<string>
 */
function singleAppReplicaViolations(array $app): array
{
    $violations = [];
    $deploy = \is_array($app['deploy'] ?? null) ? $app['deploy'] : [];

    if (\array_key_exists('replicas', $deploy)) {
        $violations = singleAppReplicaCountViolations($deploy['replicas']);
    }

    if (\array_key_exists('scale', $app)) {
        $violations[] = 'scale';
    }

    if (($deploy['mode'] ?? 'replicated') === 'global') {
        $violations[] = 'deploy.mode global';
    }

    return $violations;
}

/**
 * @return list<string>
 */
function singleAppReplicaCountViolations(mixed $replicas): array
{
    if (! \is_int($replicas) && ! (\is_string($replicas) && ctype_digit($replicas))) {
        return ['deploy.replicas no es un numero literal'];
    }

    return (int) $replicas > 1 ? ['deploy.replicas mayor que 1'] : [];
}

/** Si un script levanta `app` con `--scale`. */
function singleAppReplicaScriptScales(string $script): bool
{
    return preg_match('/--scale[= ]+app\b/', $script) === 1;
}

it('el servicio app no declara mas de una replica', function (): void {
    expect(singleAppReplicaViolations(singleAppReplicaService()))->toBe([]);
})->group('RS-12', 'RNF-D-03');

it('ningun script de instalacion escala app', function (): void {
    $root = Repo::root().'/infra/scripts';
    $escalan = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile()) {
            continue;
        }

        if (singleAppReplicaScriptScales((string) file_get_contents($file->getPathname()))) {
            $escalan[] = substr($file->getPathname(), \strlen($root) + 1);
        }
    }

    expect($escalan)->toBe([]);
})->group('RS-12', 'RNF-D-03');

it('reconoce un compose que escala app (red de seguridad de la prueba)', function (string $yaml, array $esperadas): void {
    // Las mismas funciones que las dos pruebas de arriba, con lo que tendrian
    // que rechazar: si dejaran de verlo, aquellas pasarian siempre.
    $documento = Yaml::parse($yaml);
    $app = data_get($documento, 'services.app');

    expect($app)->toBeArray();
    /** @var array<string, mixed> $app */
    expect(singleAppReplicaViolations($app))->toBe($esperadas);
})->with([
    'una replica' => ["services:\n  app:\n    deploy:\n      replicas: 1\n", []],
    'sin deploy' => ["services:\n  app:\n    image: x\n", []],
    'dos replicas' => ["services:\n  app:\n    deploy:\n      replicas: 2\n", ['deploy.replicas mayor que 1']],
    'replicas en texto' => ["services:\n  app:\n    deploy:\n      replicas: '3'\n", ['deploy.replicas mayor que 1']],
    'replicas de una variable' => ["services:\n  app:\n    deploy:\n      replicas: \${APP_REPLICAS:-1}\n", ['deploy.replicas no es un numero literal']],
    'scale' => ["services:\n  app:\n    scale: 2\n", ['scale']],
    'global' => ["services:\n  app:\n    deploy:\n      mode: global\n", ['deploy.mode global']],
])->group('RS-12');

it('reconoce un script que escala app (red de seguridad de la prueba)', function (): void {
    expect(singleAppReplicaScriptScales('docker compose up -d --scale app=2'))->toBeTrue()
        ->and(singleAppReplicaScriptScales('docker compose up -d --scale app 2'))->toBeTrue()
        ->and(singleAppReplicaScriptScales('docker compose up -d --scale nginx=0'))->toBeFalse();
})->group('RS-12');
