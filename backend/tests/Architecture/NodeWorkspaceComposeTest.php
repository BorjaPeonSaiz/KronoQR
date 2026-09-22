<?php

declare(strict_types=1);

use Tests\Architecture\Support\Repo;

/*
 * LOS TRES SERVICIOS `node-*` VIVEN EN EL WORKSPACE DE NPM (ADR-036), NO EN
 * SU FRONTEND SUELTO (correccion de esta tarea; doc 02 §3.5, §10.2).
 *
 * ## El fallo que esta prueba impide que vuelva
 *
 * Antes de esta correccion, `node-kiosk`/`node-admin`/`node-portal` montaban
 * `../frontend-<nombre>:/app` -un solo frontend, sin la raiz del
 * repositorio- e instalaban con `npm ci --prefix /app`. Desde que el
 * repositorio es un workspace de npm (`package.json` de la raiz,
 * `workspaces: frontend-admin, frontend-portal, frontend-kiosk,
 * packages/*`, un unico `package-lock.json`), los binarios -`vite`
 * incluido- se hozan en `/app/node_modules/.bin` de la RAIZ, no en el
 * `node_modules` de cada frontend. El resultado fue "sh: vite: not found" en
 * bucle de reinicio en los tres contenedores: las tres URL de `make up`
 * (`/kiosk/`, `/admin/`, `/portal/`) dejaron de responder.
 *
 * ## Que exige esta prueba, y por que
 *
 * - El bind mount es la RAIZ del repositorio (`..:/app`), no
 *   `frontend-<nombre>` suelto: es lo unico que permite un unico `npm ci`
 *   contra el `package-lock.json` de la raiz.
 * - `working_dir: /app/frontend-<nombre>`: mueve el arranque de Vite a la
 *   SPA correcta dentro de ese arbol compartido.
 * - Volumen con nombre en `/app/node_modules` (y en cada `node_modules`
 *   anidado del workspace que ya existe en el host): el `node_modules` del
 *   HOST es de Windows -binarios nativos de esbuild/rollup que no valen en
 *   el contenedor Linux-, mismo motivo que `backend-vendor` con `vendor/`
 *   (`PgOptionsComposeTest.php` no lo cubre porque es un problema distinto,
 *   pero el patron -volumen con nombre tapando una ruta que el bind mount
 *   dejaria pasar sin filtrar- es el mismo).
 * - SOLO `node-kiosk` lleva `NODE_WORKSPACE_INSTALLER: "true"`: es el unico
 *   que ejecuta `npm ci` (infra/docker/node/entrypoint.sh). Los tres
 *   servicios comparten el MISMO volumen de `node_modules`, asi que si mas
 *   de uno instalara a la vez correrian instalaciones concurrentes sobre el
 *   mismo arbol.
 *
 * Sigue el mismo patron de analisis textual que `PgOptionsComposeTest.php`
 * (bloque de servicio ya compuesto, no `docker compose config`): la prueba
 * de arquitectura no depende de tener Docker disponible.
 */

/**
 * El bloque de un servicio con nombre, hasta el siguiente servicio de primer
 * nivel o el final del fichero. Copia deliberada de `bloqueDeServicio()` en
 * `PgOptionsComposeTest.php`: una funcion global declarada en un `*Test.php`
 * solo esta disponible si Pest ya cargo ese fichero, y el orden de carga no
 * es algo sobre lo que convenga construir nada (mismo criterio que ya explica
 * el comentario de `servicioAlertmanager()` en `AlertmanagerConfigTest.php`).
 */
function bloqueDeServicioNode(string $compose, string $servicio): string
{
    $encontrado = preg_match(
        '/^  '.preg_quote($servicio, '/').':\n(?:.*\n)*?(?=^  [a-zA-Z0-9_-]+:|\z)/m',
        str_replace("\r\n", "\n", Repo::contents($compose)),
        $partes,
    );

    expect($encontrado)->toBe(1, "No se encuentra el servicio {$servicio} en {$compose}.");

    return $partes[0] ?? '';
}

/** El bloque `volumes:` de primer nivel (las declaraciones de volumen con nombre). */
function bloqueDeVolumenesNombrados(string $compose): string
{
    $encontrado = preg_match(
        '/^volumes:\n(?:.*\n)*/m',
        str_replace("\r\n", "\n", Repo::contents($compose)),
        $partes,
    );

    expect($encontrado)->toBe(1, "No se encuentra el bloque volumes: de primer nivel en {$compose}.");

    return $partes[0] ?? '';
}

it('node-kiosk monta la raiz del workspace, no frontend-kiosk suelto', function (): void {
    $bloque = bloqueDeServicioNode('infra/compose.dev.yaml', 'node-kiosk');

    expect($bloque)
        ->toContain('- ..:/app')
        ->not->toContain('../frontend-kiosk:/app');
})->group('RNF-M-04', 'RNF-M-06');

it('node-kiosk arranca Vite desde working_dir dentro del workspace', function (): void {
    $bloque = bloqueDeServicioNode('infra/compose.dev.yaml', 'node-kiosk');

    expect($bloque)->toContain('working_dir: /app/frontend-kiosk');
})->group('RNF-M-04', 'RNF-M-06');

it('node-kiosk lleva los cinco volumenes con nombre del workspace de npm', function (): void {
    $bloque = bloqueDeServicioNode('infra/compose.dev.yaml', 'node-kiosk');

    expect($bloque)
        ->toContain('node-modules-root:/app/node_modules')
        ->toContain('node-modules-kiosk:/app/frontend-kiosk/node_modules')
        ->toContain('node-modules-admin:/app/frontend-admin/node_modules')
        ->toContain('node-modules-portal:/app/frontend-portal/node_modules')
        ->toContain('node-modules-web-kit:/app/packages/web-kit/node_modules');
})->group('RNF-M-04', 'RNF-M-06');

it('node-kiosk es el UNICO servicio marcado para instalar el workspace', function (): void {
    $compose = str_replace("\r\n", "\n", Repo::contents('infra/compose.dev.yaml'));

    expect(substr_count($compose, 'NODE_WORKSPACE_INSTALLER:'))->toBe(1);

    $kiosk = bloqueDeServicioNode('infra/compose.dev.yaml', 'node-kiosk');
    expect($kiosk)->toContain('NODE_WORKSPACE_INSTALLER: "true"');
})->group('RNF-M-04', 'RNF-M-06');

it('node-admin y node-portal comparten el mismo arbol del workspace, sin instalar ellos mismos', function (string $servicio, string $frontend): void {
    $bloque = bloqueDeServicioNode('infra/compose.dev.yaml', $servicio);

    expect($bloque)
        ->toContain('working_dir: /app/frontend-'.$frontend)
        ->toContain('volumes: *node-workspace-volumes')
        ->not->toContain('NODE_WORKSPACE_INSTALLER')
        ->toContain('depends_on:')
        ->toContain('node-kiosk');
})->with([
    ['node-admin', 'admin'],
    ['node-portal', 'portal'],
])->group('RNF-M-04', 'RNF-M-06');

it('el compose declara los cinco volumenes con nombre del workspace de npm', function (): void {
    $bloque = bloqueDeVolumenesNombrados('infra/compose.dev.yaml');

    expect($bloque)
        ->toContain("node-modules-root:\n")
        ->toContain("node-modules-kiosk:\n")
        ->toContain("node-modules-admin:\n")
        ->toContain("node-modules-portal:\n")
        ->toContain("node-modules-web-kit:\n");
})->group('RNF-M-04', 'RNF-M-06');

it('el entrypoint de node ya no invoca npm con --prefix (roto por el workspace de npm)', function (): void {
    $entrypoint = Repo::contents('infra/docker/node/entrypoint.sh');

    // Las dos invocaciones EXACTAS que rompian con el workspace de npm: los
    // binarios se hozan en la raiz, no en el node_modules de cada frontend, y
    // `--prefix` los buscaba justo ahi. Se comprueban las invocaciones
    // literales, no la subcadena "--prefix" a secas, porque el comentario de
    // cabecera de este mismo fichero la menciona a proposito al explicar el
    // fallo -y una prueba que no distinguiera las dos cosas se rompería con
    // su propia documentacion-.
    expect($entrypoint)
        ->not->toContain('npm ci --prefix')
        ->not->toContain('npm --prefix');
})->group('RNF-M-04', 'RNF-M-06');
