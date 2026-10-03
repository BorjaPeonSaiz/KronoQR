<?php

declare(strict_types=1);

use Tests\Architecture\Support\ComposeEnvironment;

/*
 * QUIEN PUEDE ESCRIBIR EN `BACKUP_PATH`, servicio a servicio (2.2.0, bloque 20:
 * A3-R2, R5-DV-04; condicion C18 del dictamen de seguridad; RL-12, RNF-D-02,
 * RS-08).
 *
 * Hasta la 2.1.0, `app` y `horizon` montaban la raiz de las copias entera en
 * escritura (por el ancla `x-app-image`): quien ejecutara codigo en cualquiera
 * de los dos podia borrar copias, reescribir su `.sha256` o plantar una copia
 * sin la clave. Desde la 2.2.0 la raiz va en SOLO LECTURA y cada servicio
 * recibe en escritura exactamente lo que usa:
 *
 *   servicio    raiz   metrics/  reports/retention  reports/  daily/ base/
 *   app         ro     rw        rw                 -         -
 *   horizon     ro     rw        -                  -         -
 *   scheduler   ro     rw        rw                 -         rw
 *   restore     ro     rw        -                  rw        -
 *
 * Cada montaje bajo `BACKUP_PATH` de esos cuatro servicios va en sintaxis larga
 * con `bind.create_host_path: false`: si falta el directorio en el anfitrion,
 * `docker compose up` falla con un mensaje claro en vez de que Docker lo cree
 * como root y el uid 1000 deje de poder escribir copias y metricas SIN RUIDO.
 *
 * Se lee el compose YA COMPUESTO (anclas y `<<` resueltos, como Compose): la
 * trampa documentada en el propio fichero es que el `volumes:` de un servicio
 * SUSTITUYE al del ancla, no se fusiona. La mitad dinamica —que de verdad no se
 * puede escribir— la prueba `.github/scripts/backup-wal-e2e.sh` en la etapa ⑧.
 */

const BACKUP_MOUNTS_COMPOSE = 'infra/compose.prod.yaml';

/**
 * Lo que cada servicio monta de `BACKUP_PATH`, por subruta (`''` es la raiz) y
 * con su modo. Lo que no aparece aqui NO se monta.
 *
 * @var array<string, array<string, string>>
 */
const BACKUP_MOUNTS_EXPECTED = [
    'app' => ['' => 'ro', '/metrics' => 'rw', '/reports/retention' => 'rw'],
    'horizon' => ['' => 'ro', '/metrics' => 'rw'],
    'scheduler' => ['' => 'ro', '/metrics' => 'rw', '/reports/retention' => 'rw', '/daily' => 'rw', '/base' => 'rw'],
    'restore' => ['' => 'ro', '/metrics' => 'rw', '/reports' => 'rw'],
];

/**
 * Los montajes de un servicio que salen de `BACKUP_PATH`, por subruta.
 *
 * @return list<array{suffix: string, mode: string, long: bool, create_host_path: mixed, target_suffix: ?string}>
 */
function backupMountsOf(string $service): array
{
    $definition = ComposeEnvironment::service(BACKUP_MOUNTS_COMPOSE, $service);
    $mounts = [];

    foreach ((array) ($definition['volumes'] ?? []) as $volume) {
        $mount = \is_array($volume) ? backupMountFromLongSyntax($volume) : backupMountFromShortSyntax($volume);

        if ($mount !== null) {
            $mounts[] = $mount;
        }
    }

    return $mounts;
}

/**
 * @param  array<mixed>  $volume
 * @return array{suffix: string, mode: string, long: bool, create_host_path: mixed, target_suffix: ?string}|null
 */
function backupMountFromLongSyntax(array $volume): ?array
{
    $suffix = backupMountSuffix(backupMountText($volume['source'] ?? null));

    if ($suffix === null) {
        return null;
    }

    $bind = \is_array($volume['bind'] ?? null) ? $volume['bind'] : [];

    return [
        'suffix' => $suffix,
        'mode' => ($volume['read_only'] ?? false) === true ? 'ro' : 'rw',
        'long' => true,
        'create_host_path' => $bind['create_host_path'] ?? null,
        'target_suffix' => backupMountSuffix(backupMountText($volume['target'] ?? null)),
    ];
}

/**
 * `origen:destino[:modo]`. `${BACKUP_PATH:-/var/backups/fichaje}` lleva `:`
 * dentro: la interpolacion se aparta antes de partir y se repone despues.
 *
 * @return array{suffix: string, mode: string, long: bool, create_host_path: mixed, target_suffix: ?string}|null
 */
function backupMountFromShortSyntax(mixed $volume): ?array
{
    $plain = (string) preg_replace('/\$\{BACKUP_PATH(?:[:?-][^}]*)?\}/', '@BACKUP@', backupMountText($volume));
    $parts = array_map(static fn (string $part): string => str_replace('@BACKUP@', '${BACKUP_PATH}', $part), explode(':', $plain));
    $suffix = backupMountSuffix($parts[0]);

    if ($suffix === null) {
        return null;
    }

    return [
        'suffix' => $suffix,
        'mode' => ($parts[2] ?? 'rw') === 'ro' ? 'ro' : 'rw',
        'long' => false,
        'create_host_path' => null,
        'target_suffix' => backupMountSuffix($parts[1] ?? ''),
    ];
}

function backupMountText(mixed $value): string
{
    return \is_scalar($value) ? (string) $value : '';
}

/** La subruta de `BACKUP_PATH` que nombra un origen, o `null` si no sale de ahi. */
function backupMountSuffix(string $path): ?string
{
    if (preg_match('#^\$\{BACKUP_PATH(?:[:?-][^}]*)?\}(/.*)?$#', $path, $parts) !== 1) {
        return null;
    }

    return rtrim($parts[1] ?? '', '/');
}

it('cada servicio monta de BACKUP_PATH exactamente lo suyo, y la raiz en solo lectura', function (string $service): void {
    $declared = [];

    foreach (backupMountsOf($service) as $mount) {
        expect($declared)->not->toHaveKey(
            $mount['suffix'],
            "compose.prod.yaml: «{$service}» monta dos veces BACKUP_PATH{$mount['suffix']}."
        );
        $declared[$mount['suffix']] = $mount['mode'];
    }

    ksort($declared);
    $expected = BACKUP_MOUNTS_EXPECTED[$service];
    ksort($expected);

    expect($declared)->toBe(
        $expected,
        "compose.prod.yaml: «{$service}» no monta BACKUP_PATH como fija A3-R2 (bloque 20). Esperado "
        .json_encode($expected).', encontrado '.json_encode($declared).'. Una raiz en escritura deja borrar copias o '
        .'plantar una sin la clave; un subdirectorio de mas es escritura que el servicio no necesita.'
    );
})->with(array_keys(BACKUP_MOUNTS_EXPECTED))->group('RL-12', 'RNF-D-02', 'RS-08');

it('horizon no puede escribir los informes de retencion ni las copias', function (): void {
    // La mitad del reparto que mas importa: `horizon` ejecuta trabajos de cola
    // con datos de la plantilla y es el servicio con mas codigo corriendo. Ni la
    // propuesta de retencion ni la purga corren ahi.
    $writable = array_column(
        array_filter(backupMountsOf('horizon'), static fn (array $mount): bool => $mount['mode'] === 'rw'),
        'suffix',
    );

    expect($writable)->toBe(['/metrics']);
})->group('RL-12', 'RS-08', 'RL-11');

it('solo el planificador escribe en daily y base, que es quien hace las copias', function (string $service): void {
    $writable = array_column(
        array_filter(backupMountsOf($service), static fn (array $mount): bool => $mount['mode'] === 'rw'),
        'suffix',
    );

    expect(array_values(array_intersect($writable, ['', '/daily', '/base'])))->toBe(
        [],
        "compose.prod.yaml: «{$service}» puede escribir en la raiz de BACKUP_PATH, daily/ o base/ (A3-R2)."
    );
})->with(['app', 'horizon', 'restore', 'node-exporter'])->group('RL-12', 'RS-08');

it('cada montaje de BACKUP_PATH del runtime es un bind largo con create_host_path: false', function (string $service): void {
    foreach (backupMountsOf($service) as $mount) {
        $where = "compose.prod.yaml: «{$service}» monta BACKUP_PATH{$mount['suffix']}";

        expect($mount['long'])->toBeTrue($where.' en sintaxis corta: no admite create_host_path: false.');
        expect($mount['create_host_path'])->toBeFalse(
            $where.' sin bind.create_host_path: false. Si el directorio falta, Docker lo crea como root y el uid '
            .'1000 deja de poder escribir copias y metricas sin que nada avise.'
        );
        // La misma ruta dentro y fuera: los scripts y config/backup.php usan el
        // mismo valor de BACKUP_PATH a los dos lados.
        expect($mount['target_suffix'])->toBe($mount['suffix'], $where.' en otra ruta dentro del contenedor.');
    }
})->with(array_keys(BACKUP_MOUNTS_EXPECTED))->group('RL-12', 'RS-08');

it('reverb, nginx y migrate no montan nada de BACKUP_PATH', function (string $service): void {
    expect(backupMountsOf($service))->toBe(
        [],
        "compose.prod.yaml: «{$service}» monta BACKUP_PATH. No lee ni escribe copias (ADR-042)."
    );
})->with(['reverb', 'nginx', 'migrate'])->group('RS-08', 'RL-12');

it('node-exporter sigue leyendo BACKUP_PATH en solo lectura', function (): void {
    $mounts = backupMountsOf('node-exporter');

    expect($mounts)->not->toBe([], 'node-exporter ya no monta BACKUP_PATH: las metricas de las copias no se publican.');

    foreach ($mounts as $mount) {
        expect($mount['mode'])->toBe('ro', 'node-exporter monta BACKUP_PATH'.$mount['suffix'].' en escritura.');
    }
})->group('RS-08', 'RNF-D-02');

it('postgres solo monta de BACKUP_PATH el archivo de WAL', function (): void {
    expect(array_column(backupMountsOf('postgres'), 'suffix'))->toBe(['/wal']);
})->group('RS-08', 'RL-12', 'RNF-D-02');

it('reconoce las subrutas de BACKUP_PATH en cualquier forma de interpolacion', function (string $path, ?string $suffix): void {
    expect(backupMountSuffix($path))->toBe($suffix);
})->with([
    'con defecto' => ['${BACKUP_PATH:-/var/backups/fichaje}/metrics', '/metrics'],
    'sin defecto' => ['${BACKUP_PATH}/reports/retention', '/reports/retention'],
    'la raiz' => ['${BACKUP_PATH:-/var/backups/fichaje}', ''],
    'la raiz con barra' => ['${BACKUP_PATH:-/var/backups/fichaje}/', ''],
    'obligatoria' => ['${BACKUP_PATH:?define BACKUP_PATH}/daily', '/daily'],
    'otra variable' => ['${BRANDING_PATH:-./branding}', null],
    'volumen con nombre' => ['app-storage', null],
])->group('RS-08');
