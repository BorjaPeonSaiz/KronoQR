<?php

declare(strict_types=1);

use Tests\Architecture\Support\ComposeEnvironment;
use Tests\Architecture\Support\Repo;

/*
 * La clave de desarrollo del WAL y el `archive_timeout` del que depende el RPO,
 * en los dos compose (ADR-049, condiciones C7 y C12 del dictamen; RL-12,
 * RNF-D-02, RS-08).
 *
 * Lo demas del bloque 20 sobre el compose vive donde ya se miraba lo mismo:
 * quien recibe `BACKUP_WAL_KEY`, que no tenga valor por defecto en produccion y
 * que `KRONOQR_ACCEPT_UNAUTHENTICATED` no este en ningun compose ni en
 * `.env.example`, en `RuntimeEnvironmentTest`; el reparto de montajes de
 * `BACKUP_PATH`, en `BackupMountsComposeTest`.
 *
 *   · `KRONOQR_WAL_ALLOW_DEV_KEY` deja a `archive-wal.sh` aceptar la clave de
 *     desarrollo, que esta publicada en el repositorio. En produccion seria un
 *     WAL «cifrado» con una clave que conoce cualquiera: solo existe en dev.
 *   · `archive_timeout=900` en los DOS: sin el, el RPO es «lo que tarde en
 *     llenarse un segmento», y la regla `ArchiveTimeoutFueraDeRango` es la misma
 *     en los dos entornos (D7: no se silencia nada por entorno).
 */

const BACKUP_COMPOSE_SECRETS_PROD = 'infra/compose.prod.yaml';

const BACKUP_COMPOSE_SECRETS_DEV = 'infra/compose.dev.yaml';

it('ningun servicio de produccion recibe el permiso de la clave de desarrollo del WAL', function (string $service): void {
    expect(\in_array('KRONOQR_WAL_ALLOW_DEV_KEY', ComposeEnvironment::referencedNames(
        ComposeEnvironment::service(BACKUP_COMPOSE_SECRETS_PROD, $service),
    ), true))->toBeFalse(
        "compose.prod.yaml: «{$service}» recibe KRONOQR_WAL_ALLOW_DEV_KEY: el WAL se cifraria con la clave de desarrollo, que es publica."
    );
})->with(static fn (): array => array_keys(ComposeEnvironment::services(BACKUP_COMPOSE_SECRETS_PROD)))->group('RL-12', 'RS-08');

it('deja la clave de desarrollo del WAL y su permiso en el postgres de desarrollo', function (): void {
    $names = ComposeEnvironment::environmentNames(ComposeEnvironment::service(BACKUP_COMPOSE_SECRETS_DEV, 'postgres'));

    expect(\in_array('KRONOQR_WAL_ALLOW_DEV_KEY', $names, true))->toBeTrue(
        'compose.dev.yaml: postgres no recibe KRONOQR_WAL_ALLOW_DEV_KEY y archive-wal.sh rechazaria la clave de desarrollo.'
    );
    expect(\in_array('BACKUP_WAL_KEY', $names, true))->toBeTrue('compose.dev.yaml: postgres no recibe BACKUP_WAL_KEY.');
})->group('RL-12', 'RNF-D-02');

it('fija archive_timeout=900 en produccion y en desarrollo', function (string $compose): void {
    $command = ComposeEnvironment::service($compose, 'postgres')['command'] ?? [];
    $arguments = \is_array($command)
        ? array_map(static fn (mixed $argument): string => \is_scalar($argument) ? (string) $argument : '', $command)
        : preg_split('/\s+/', \is_scalar($command) ? (string) $command : '');

    expect(\in_array('archive_timeout=900', $arguments ?: [], true))->toBeTrue(
        $compose.': postgres no fija archive_timeout=900: el RPO deja de estar acotado (RNF-D-02).'
    );
})->with([BACKUP_COMPOSE_SECRETS_PROD, BACKUP_COMPOSE_SECRETS_DEV])->group('RNF-D-02');

it('no deja la clave de desarrollo del WAL como valor por defecto fuera de desarrollo', function (): void {
    // El valor por defecto de compose.dev.yaml es la derivada de la clave de
    // relleno de desarrollo: que no aparezca en produccion ni en .env.example.
    preg_match('/BACKUP_WAL_KEY:\s*\$\{BACKUP_WAL_KEY:-([0-9a-f]{64})\}/', Repo::contents(BACKUP_COMPOSE_SECRETS_DEV), $dev);

    expect($dev[1] ?? '')->not->toBe('', 'compose.dev.yaml ya no da a postgres la clave de desarrollo del WAL.');

    foreach ([BACKUP_COMPOSE_SECRETS_PROD, '.env.example'] as $file) {
        expect(str_contains(Repo::contents($file), $dev[1] ?? 'sin-clave'))->toBeFalse(
            $file.' contiene la clave de desarrollo del WAL.'
        );
    }
})->group('RL-12', 'RS-08');
