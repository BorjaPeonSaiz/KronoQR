<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;
use Tests\Architecture\Support\ComposeEnvironment;
use Tests\Architecture\Support\Repo;

/*
 * Quien recibe la clave del WAL y lo que NO se deja puesto (ADR-049, condiciones C7 y
 * C12 del dictamen de seguridad), sobre el compose de produccion ya COMPUESTO.
 *
 *   · `BACKUP_WAL_KEY` llega SOLO al servicio `postgres`, y sin valor por defecto: la
 *     resuelve Compose desde el `.env`. Con un valor en el compose, la clave estaria en el
 *     repositorio; con un valor por defecto, un servidor sin clave archivaria con otra.
 *   · Ningun servicio de runtime (`app`, `horizon`, `scheduler`, `reverb`, `nginx`) la
 *     recibe: `scheduler` ya tiene la maestra y no necesita la derivada.
 *   · `KRONOQR_ACCEPT_UNAUTHENTICATED` (la bandera de copias de la 2.1.0) y
 *     `KRONOQR_WAL_ALLOW_DEV_KEY` (la clave de desarrollo) no estan en el compose de
 *     produccion ni en `.env.example`: la primera se pasa por invocacion, la segunda solo
 *     existe en `compose.dev.yaml`.
 *   · Dev: la clave de desarrollo y su permiso estan en dev y en ningun otro sitio.
 */

const BACKUP_COMPOSE_SECRETS_PROD = 'infra/compose.prod.yaml';
const BACKUP_COMPOSE_SECRETS_DEV = 'infra/compose.dev.yaml';

it('entrega BACKUP_WAL_KEY solo a postgres', function (): void {
    $services = ComposeEnvironment::services(BACKUP_COMPOSE_SECRETS_PROD);
    $receivers = [];

    foreach ($services as $name => $service) {
        $environment = (array) ($service['environment'] ?? []);
        if (array_key_exists('BACKUP_WAL_KEY', $environment)) {
            $receivers[] = $name;
        }
    }

    expect($receivers)->toBe(['postgres']);
})->group('RL-12', 'RS-08');

it('no da valor ni valor por defecto a BACKUP_WAL_KEY en el compose de produccion', function (): void {
    $document = Yaml::parse(Repo::contents(BACKUP_COMPOSE_SECRETS_PROD));
    $environment = (array) ($document['services']['postgres']['environment'] ?? []);

    expect($environment)->toHaveKey('BACKUP_WAL_KEY');
    expect($environment['BACKUP_WAL_KEY'])->toBeNull('BACKUP_WAL_KEY tiene valor en el compose: tiene que resolverla Compose desde el .env.');

    // Y ninguna interpolacion con valor por defecto en todo el fichero.
    expect(Repo::contents(BACKUP_COMPOSE_SECRETS_PROD))->not->toMatch('/\$\{BACKUP_WAL_KEY[:?-]/');
})->group('RL-12', 'RS-08');

it('no menciona la bandera de copias heredadas ni el permiso de la clave de desarrollo en produccion', function (): void {
    // Solo se mira lo que llega a un contenedor (variables y sus interpolaciones), no los comentarios.
    foreach (ComposeEnvironment::services(BACKUP_COMPOSE_SECRETS_PROD) as $name => $service) {
        $environment = (array) ($service['environment'] ?? []);
        foreach (['KRONOQR_ACCEPT_UNAUTHENTICATED', 'KRONOQR_WAL_ALLOW_DEV_KEY'] as $variable) {
            expect(array_key_exists($variable, $environment))->toBeFalse($name.' recibe '.$variable.' desde el compose de produccion.');
        }
    }
    expect(Repo::contents(BACKUP_COMPOSE_SECRETS_PROD))->not->toMatch('/\$\{KRONOQR_(ACCEPT_UNAUTHENTICATED|WAL_ALLOW_DEV_KEY)/');
})->group('RL-12', 'RS-08');

it('no define en .env.example la bandera de copias heredadas', function (): void {
    // Una clave del `.env` queda puesta para siempre: la bandera se pasa por invocacion.
    expect(Repo::contents('.env.example'))->not->toMatch('/^\s*(export\s+)?KRONOQR_ACCEPT_UNAUTHENTICATED=/m');
    // La subclave del WAL SI figura, vacia: la escriben install.sh y update.sh.
    expect(Repo::contents('.env.example'))->toMatch('/^BACKUP_WAL_KEY=$/m');
})->group('RL-12', 'RS-08');

it('deja la clave de desarrollo y su permiso solo en compose.dev.yaml', function (): void {
    $dev = Repo::contents(BACKUP_COMPOSE_SECRETS_DEV);

    expect($dev)->toContain('KRONOQR_WAL_ALLOW_DEV_KEY');
    expect($dev)->toContain('BACKUP_WAL_KEY');
    expect($dev)->toContain('archive_timeout=900');
})->group('RL-12', 'RNF-D-02');

it('fija archive_timeout=900 en produccion y en desarrollo', function (): void {
    foreach ([BACKUP_COMPOSE_SECRETS_PROD, BACKUP_COMPOSE_SECRETS_DEV] as $file) {
        $document = Yaml::parse(Repo::contents($file));
        $command = array_map('strval', (array) ($document['services']['postgres']['command'] ?? []));

        expect(in_array('archive_timeout=900', $command, true))->toBeTrue($file.' no fija archive_timeout=900: el RPO deja de estar acotado (RNF-D-02).');
    }
})->group('RNF-D-02');

it('monta la raiz de BACKUP_PATH en solo lectura y cada hijo con create_host_path: false', function (): void {
    $services = ComposeEnvironment::services(BACKUP_COMPOSE_SECRETS_PROD);
    $base = '${BACKUP_PATH:-/var/backups/fichaje}';

    foreach (['app', 'horizon', 'scheduler', 'restore'] as $name) {
        $mounts = [];
        foreach ((array) ($services[$name]['volumes'] ?? []) as $volume) {
            if (\is_array($volume) && is_string($volume['target'] ?? null) && str_starts_with($volume['target'], $base)) {
                $mounts[$volume['target']] = $volume;
            }
        }

        expect(array_key_exists($base, $mounts))->toBeTrue($name.' no monta la raiz de BACKUP_PATH.');
        expect($mounts[$base]['read_only'] ?? false)->toBeTrue($name.' monta la raiz de BACKUP_PATH en ESCRITURA (A3-R2).');
        foreach ($mounts as $target => $volume) {
            expect($volume['bind']['create_host_path'] ?? true)->toBeFalse($name.': '.$target.' no lleva create_host_path: false.');
        }
    }
})->group('RS-07', 'RS-08');
