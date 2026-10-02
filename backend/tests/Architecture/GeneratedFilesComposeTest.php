<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;
use Tests\Architecture\Support\ComposeEnvironment;
use Tests\Architecture\Support\Repo;

/*
 * ADR-045 (R3-PL-01): los ficheros que genera el producto y que cruzan
 * contenedores viven en el volumen con nombre `app-storage`, montado en
 * `/var/www/html/storage/app` por el ancla `x-app-image` de compose.prod.yaml.
 *
 * `app`, `horizon` y `scheduler` son tres contenedores de la misma imagen: sin
 * ese montaje, cada uno tenia su propio `storage/app` en su capa de escritura,
 * la exportacion integra que escribia `horizon` devolvia 404 en `app` y las
 * purgas de `scheduler` no veian nada. Las pruebas funcionales corren en un solo
 * proceso y el desarrollo monta el codigo entero, asi que NINGUNA otra prueba
 * puede detectarlo: esta es la guarda.
 *
 * SE LEE EL COMPOSE YA COMPUESTO (`ComposeEnvironment::service()`), no el texto:
 * el montaje llega por el ancla y un `grep` por el bloque de cada servicio no lo
 * veria. Mismo criterio que RuntimeEnvironmentTest.
 */

const GENERATED_FILES_COMPOSE = 'infra/compose.prod.yaml';
const GENERATED_FILES_MOUNT = '/var/www/html/storage/app';

function generatedFilesText(mixed $value): string
{
    return is_scalar($value) ? (string) $value : '';
}

/**
 * Los montajes de un servicio como mapa destino => fuente, con el modo
 * (`ro`/`rw`). Admite la sintaxis corta (`fuente:destino[:modo]`) y la larga.
 *
 * @param  array<mixed>  $service
 * @return array<string, array{source: string, mode: string}>
 */
function generatedFilesMounts(array $service): array
{
    $mounts = [];

    foreach ((array) ($service['volumes'] ?? []) as $volume) {
        if (\is_array($volume)) {
            $target = generatedFilesText($volume['target'] ?? null);
            $mounts[$target] = [
                'source' => generatedFilesText($volume['source'] ?? null),
                'mode' => ($volume['read_only'] ?? false) === true ? 'ro' : 'rw',
            ];

            continue;
        }

        // `${BACKUP_PATH:-/var/backups/fichaje}:${BACKUP_PATH:-/var/backups/fichaje}`
        // lleva `:` dentro de la interpolacion: se quitan antes de partir.
        $plain = (string) preg_replace('/\$\{[^}]*\}/', 'VAR', generatedFilesText($volume));
        $parts = explode(':', $plain);
        $mounts[$parts[1] ?? $parts[0]] = [
            'source' => $parts[0],
            'mode' => $parts[2] ?? 'rw',
        ];
    }

    return $mounts;
}

it('app, horizon y scheduler montan app-storage en storage/app, en lectura y escritura', function (string $service): void {
    $mounts = generatedFilesMounts(ComposeEnvironment::service(GENERATED_FILES_COMPOSE, $service));

    expect($mounts)->toHaveKey(GENERATED_FILES_MOUNT);
    expect($mounts[GENERATED_FILES_MOUNT]['source'])->toBe('app-storage');
    // Los tres escriben o borran (descarga y consola en app, generacion en
    // horizon, purgas y telemetria en scheduler): no cabe solo lectura.
    expect($mounts[GENERATED_FILES_MOUNT]['mode'])->toBe('rw');
})->with(['app', 'horizon', 'scheduler'])->group('RF-PD-14', 'RL-20', 'RF-IN-06', 'RL-15');

it('reverb, nginx, migrate y restore NO montan app-storage', function (string $service): void {
    $mounts = generatedFilesMounts(ComposeEnvironment::service(GENERATED_FILES_COMPOSE, $service));

    expect(array_column($mounts, 'source'))->not->toContain('app-storage');
    expect($mounts)->not->toHaveKey(GENERATED_FILES_MOUNT);
})->with(['reverb', 'nginx', 'migrate', 'restore'])->group('RF-PD-14', 'RL-15');

it('app-storage esta declarado en los volumenes de primer nivel', function (): void {
    $document = Yaml::parse(Repo::contents(GENERATED_FILES_COMPOSE));

    expect($document)->toBeArray();
    expect(\is_array($document) ? ($document['volumes'] ?? null) : null)->toBeArray()->toHaveKey('app-storage');
})->group('RF-PD-14', 'RL-20');

it('ningun servicio monta storage/ entero ni storage/framework (lo desechable debe poder desecharse)', function (string $service): void {
    $mounts = generatedFilesMounts(ComposeEnvironment::service(GENERATED_FILES_COMPOSE, $service));
    expect($mounts)->toBeArray();

    foreach (array_map(strval(...), array_keys($mounts)) as $target) {
        expect($target)->not->toBe('/var/www/html/storage');
        expect($target)->not->toStartWith('/var/www/html/storage/framework');
        expect($target)->not->toStartWith('/var/www/html/storage/logs');
        expect($target)->not->toBe('/var/www/html/bootstrap/cache');
    }
})->with(static fn (): array => array_keys(ComposeEnvironment::services(GENERATED_FILES_COMPOSE)))->group('RF-PD-14', 'RL-19');

it('las raices de los ficheros generados caen dentro del volumen: las variables *_PATH siguen en runtime-env', function (string $variable): void {
    foreach (['app', 'horizon', 'scheduler'] as $service) {
        expect(ComposeEnvironment::environmentNames(ComposeEnvironment::service(GENERATED_FILES_COMPOSE, $service)))
            ->toContain($variable);
    }
})->with([
    'PRODUCT_DATA_EXPORT_PATH',
    'REPORTING_EXPORT_PATH',
    'PRODUCT_DIAGNOSTICS_PATH',
    'TELEMETRY_STATE_PATH',
    'COMPLIANCE_RETENTION_REPORT_PATH',
])->group('RF-PD-14', 'RF-IN-06', 'RL-19');

it('la raiz del volumen se crea en la imagen con dueño y modo, no se confia a la mascara', function (): void {
    $dockerfile = Repo::contents('infra/docker/php/Dockerfile');

    // C6: Docker copia dueño y modo de la imagen al montar un volumen VACIO.
    expect($dockerfile)->toMatch('#install -d -m 0700 -o app -g app /var/www/html/storage/app\b#');
})->group('RL-20', 'RL-19');

it('reverb conserva volumes: [] aunque el ancla monte el volumen', function (): void {
    expect(ComposeEnvironment::service(GENERATED_FILES_COMPOSE, 'reverb')['volumes'] ?? null)->toBe([]);
})->group('RF-PD-14');
