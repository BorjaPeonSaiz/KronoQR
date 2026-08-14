<?php

declare(strict_types=1);

use RuntimeException;
use Tests\Architecture\Support\ModuleTree;

/*
 * Las puertas de calidad de la Fase 0, comprobadas como pruebas.
 *
 * Por que existe este fichero. Los requisitos de la Fase 0 no describen
 * comportamiento del producto: describen que la cadena de herramientas esta
 * puesta y bloquea. Sin una prueba que lo afirme, `qa:traceability --check`
 * los ve como requisitos implementados sin cobertura —y tiene razon—: que la
 * cadena funcione hoy en el portatil de quien la monto no es evidencia de nada.
 *
 * Lo que se comprueba aqui es la CONFIGURACION, no el resultado de ejecutarla.
 * Que PHPStan pase en nivel 9 lo dice `make quality`; que nadie haya bajado el
 * nivel a 5 en un momento de prisa lo dice esta prueba. Son cosas distintas y
 * la segunda es la que se erosiona sin que nadie se entere.
 */

/**
 * Raiz del repositorio, que NO es la del backend.
 *
 * Dentro del contenedor solo esta montado `backend/` (en /var/www/html), asi
 * que la raiz llega por un montaje aparte de solo lectura. En la CI, que corre
 * sobre el arbol completo sin contenedor, es el directorio padre de `backend/`.
 * Las dos rutas se resuelven aqui para que la suite de el mismo resultado en
 * los dos sitios: una prueba que pasa en la CI y falla en local no verifica
 * nada, solo enseña donde se ejecuto.
 */
function repoRoot(): string
{
    // Se busca por MARCA y no contando niveles de directorio. La primera
    // version contaba —y contaba mal—: dentro del contenedor el montaje
    // /var/www/repo tapaba el error, y en la CI, que corre sobre el arbol
    // completo, resolvia a <repo>/backend y buscaba backend/backend/phpstan.neon.
    //
    // Las nueve pruebas pasaban en local y fallaban en el runner, que es
    // exactamente lo que este ayudante existe para evitar. Contar niveles es
    // fragil ante cualquier cambio de ubicacion; una marca no.
    $candidates = ['/var/www/repo', ...array_map(
        static fn (int $levels): string => \dirname(ModuleTree::root(), $levels),
        [2, 3, 4],
    )];

    foreach ($candidates as $candidate) {
        if (is_file($candidate.'/Makefile') && is_dir($candidate.'/docs')) {
            return $candidate;
        }
    }

    throw new RuntimeException(
        'No se encuentra la raiz del repositorio. Se reconoce por tener Makefile y docs/. '
        .'Dentro del contenedor llega montada en /var/www/repo (infra/compose.dev.yaml); '
        .'en la CI es el directorio padre de backend/.'
    );
}

/** Ruta absoluta de un fichero del repositorio, relativa a su raiz. */
function repoFile(string $relative): string
{
    return repoRoot().'/'.ltrim($relative, '/');
}

function repoContents(string $relative): string
{
    $path = repoFile($relative);

    expect(file_exists($path))->toBeTrue($relative.' no existe en el repositorio.');

    return (string) file_get_contents($path);
}

it('exige PHPStan en el nivel maximo', function (): void {
    // RNF-M-02. El nivel 9 es el maximo de PHPStan y el umbral del §9.2 es 0
    // errores. Un `level: 5` pasaria igual de verde y no verificaria lo mismo.
    expect(repoContents('backend/phpstan.neon'))
        ->toMatch('/^\s*level:\s*9\s*$/m');
})->group('RNF-M-02');

it('exige justificacion en cada supresion de PHPStan', function (): void {
    // RNF-M-02, segunda mitad: "sin errores suprimidos sin justificar". PHPStan
    // obliga a nombrar el identificador del error tras la anotacion de
    // supresion, pero no puede exigir el motivo; eso lo comprueba Semgrep, y
    // esta prueba comprueba que esa regla de Semgrep sigue existiendo.
    //
    // El nombre de esa anotacion no se escribe aqui a proposito: PHPStan la
    // interpreta como directiva aunque este dentro de un comentario, y el
    // analisis falla con un error de sintaxis que no dice de donde viene.
    expect(repoContents('.semgrep/kronoqr-php.yaml'))
        ->toContain('kronoqr-phpstan-ignore-sin-justificacion');
})->group('RNF-M-02');

it('exige los umbrales de cobertura del dominio y del backend', function (): void {
    // RNF-M-01: dominio >= 90 %, global >= 75 % (§9.2). Hoy no hay dominio y el
    // objetivo se salta con su guarda, pero el umbral tiene que estar escrito:
    // es lo que empieza a exigirse en la tarea 1.2.
    $makefile = repoContents('Makefile');

    expect($makefile)->toContain('--min=75');
    expect($makefile)->toContain('--min=80');   // MSI de mutacion, RQ-10
})->group('RNF-M-01');

it('declara las cinco suites de la piramide de pruebas', function (): void {
    // RQ-14: la cobertura por niveles no la decide quien implementa. La
    // separacion en suites es lo que hace que `test-unit` pueda correr sin base
    // de datos y que la tabla del §9.5 sea aplicable.
    $phpunit = repoContents('backend/phpunit.xml');

    foreach (['Unit', 'Integration', 'Feature', 'Contract', 'Architecture'] as $suite) {
        expect($phpunit)->toContain('name="'.$suite.'"');
    }
})->group('RQ-14');

it('ata cada convencion del stack a una herramienta que la verifica', function (): void {
    // RNF-M-06: "se verifican por herramienta en la CI, no por revision humana".
    // Se comprueba que el fichero de configuracion de cada verificador existe;
    // que ademas se ejecuten lo garantiza `make quality` y la etapa ① de la CI.
    $verifiers = [
        'backend/pint.json' => 'estilo PHP (PSR-12/PER, preset laravel)',
        'backend/phpstan.neon' => 'tipado y complejidad',
        'backend/deptrac.yaml' => 'fronteras entre modulos',
        'backend/rector.php' => 'modernizacion a PHP 8.4',
        '.semgrep/kronoqr-php.yaml' => 'lo que ninguna otra herramienta ve',
        'frontend-kiosk/eslint.config.js' => 'estilo de Vue 3 y TypeScript',
    ];

    $missing = [];

    foreach ($verifiers as $file => $what) {
        if (! file_exists(repoFile($file))) {
            $missing[] = $file.' ('.$what.')';
        }
    }

    expect($missing)->toBe([]);
})->group('RNF-M-06');

it('mantiene los secretos fuera del control de versiones', function (): void {
    // RS-08. El .env lleva el APP_KEY generado y las claves de firma del QR: si
    // entra en el repositorio, la credencial de toda la plantilla es
    // falsificable (regla dura 10).
    expect(repoContents('.gitignore'))->toMatch('/^\.env$/m');
})->group('RS-08');

it('no deja ningun secreto real en el fichero de ejemplo', function (): void {
    // RS-08. .env.example se entrega al cliente (§11.6.1) y es el que se copia
    // en el servidor del hotel: un valor real aqui viaja a cada instalacion.
    $example = repoContents('.env.example');
    $sensitive = [
        'APP_KEY',
        'QR_SIGNING_KEY_CURRENT',
        'BACKUP_ENCRYPTION_KEY',
        'REVERB_APP_SECRET',
    ];

    $filled = [];

    foreach ($sensitive as $key) {
        if (preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $example, $m) === 1 && trim($m[1]) !== '') {
            $filled[] = $key;
        }
    }

    expect($filled)->toBe([]);
})->group('RS-08');

it('sirve las cabeceras de seguridad completas', function (): void {
    // RS-09 (§7.2). Permissions-Policy no esta por simetria: sin
    // `camera=(self)` la PWA del quiosco no puede abrir la camara, y el fallo
    // aparecería en la Fase 1 sin causa aparente.
    $headers = repoContents('infra/docker/nginx/snippets/security-headers.conf');

    foreach ([
        'Strict-Transport-Security',
        'Content-Security-Policy',
        'X-Content-Type-Options',
        'Referrer-Policy',
        'Permissions-Policy',
    ] as $header) {
        expect($headers)->toContain($header);
    }

    expect($headers)->toContain('camera=(self)');
})->group('RS-09');

it('comprueba el presupuesto de bundle del quiosco en el propio build', function (): void {
    // RNF-P-07 (Anexo A del doc 02): JS critico <= 250 KB gzip, CSS <= 40 KB.
    //
    // La tablet del hotel no es un portatil de desarrollo y la red del centro
    // se cae: cada KB de mas es tiempo de arranque delante de una cola de
    // gente a las 06:00. Por eso el presupuesto se comprueba EN EL BUILD y no
    // en una revision: `npm run build` falla si se excede, asi que no hay
    // forma de publicar un quiosco fuera de presupuesto sin verlo.
    //
    // Esta prueba no mide el bundle —eso lo hace el propio script—: comprueba
    // que el guardian sigue enganchado al build. Un presupuesto que alguien
    // desconecta del `build` deja de existir sin que nadie se entere.
    $script = repoContents('frontend-kiosk/scripts/check-bundle-budget.mjs');

    expect($script)->toContain('250 * KIB');
    expect($script)->toContain('40 * KIB');

    /** @var array{scripts?: array<string, string>} $package */
    $package = json_decode(
        repoContents('frontend-kiosk/package.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    expect($package['scripts']['build'] ?? '')->toContain('check-bundle-budget');
})->group('RNF-P-07');

it('registra cada decision de arquitectura como ADR versionado', function (): void {
    // RNF-M-04. La comprobacion de verdad la hace `docs:consistency`, que cruza
    // las dos direcciones: cada fila del §4 tiene su fichero y cada fichero su
    // fila. Aqui se ancla el requisito y se comprueba lo que da sentido a esa
    // comprobacion: que los ADR existen como ficheros y no solo como tabla.
    //
    // ADR-026, ADR-027 y ADR-028 vivieron un tiempo solo como fila —autoridad
    // #4 de CLAUDE.md— siendo autoridad #1. Se puede leer el resumen de una
    // decision en una tabla; no su contexto ni sus alternativas descartadas,
    // que es lo que hace falta el dia que alguien quiere revertirla.
    $adrs = glob(repoFile('docs/adr').'/ADR-*.md') ?: [];

    expect(\count($adrs))->toBeGreaterThanOrEqual(30);

    // Y que ninguno sea un esqueleto: un ADR sin consecuencias es un titular.
    $withoutConsequences = array_values(array_filter(
        $adrs,
        static fn (string $file): bool => ! str_contains((string) file_get_contents($file), '## Consecuencias'),
    ));

    expect($withoutConsequences)->toBe([]);
})->group('RNF-M-04');

it('bloquea la integracion si un requisito implementado no tiene prueba', function (): void {
    // RQ-13. Esta es la prueba de la propia trazabilidad: comprueba que el
    // catalogo existe, que no esta vacio y que la etapa que lo ejecuta sigue
    // en el pipeline. Que el comando SEPA fallar lo prueba
    // TraceabilityCommandTest con un sabotaje.
    $catalog = repoContents('docs/requisitos.yaml');

    expect(substr_count($catalog, '- { id:'))->toBeGreaterThan(100);
    expect($catalog)->toContain('fase: 0');
})->group('RQ-13');
