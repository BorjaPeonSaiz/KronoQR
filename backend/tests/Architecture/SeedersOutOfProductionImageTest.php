<?php

declare(strict_types=1);

use Tests\Architecture\Support\Repo;

/*
 * LA SEMILLA DE DESARROLLO NO VIAJA EN LA IMAGEN DEL CLIENTE (SC7-03).
 *
 * `backend/database/seeders/` crea cuentas admin, rrhh y auditor con una
 * contraseña publicada en el README, y plantilla y fichajes inventados. Hasta
 * la 2.2.0 el `COPY backend/ ./` de la etapa `vendor` la metia entera en la
 * imagen de produccion, a un `db:seed --force` de distancia.
 *
 * Tres cerraduras, y esta prueba vigila las dos que no son PHP (la tercera, la
 * guarda de entorno de `DevelopmentSeeder`, esta en
 * `tests/Unit/Support/DevelopmentSeederGuardTest.php`):
 *
 *   1. `.dockerignore` excluye el directorio del contexto de construccion.
 *   2. La etapa `vendor` del Dockerfile de la aplicacion falla si llega igual.
 *
 * Y una precondicion sin la cual excluirla romperia el producto: que nada de lo
 * que si viaja (codigo, configuracion, rutas, migraciones, arranque y scripts
 * del servidor) cargue una semilla ni llame a `db:seed`. Lo que el producto
 * necesita sembrar —catalogo de roles, perfil de cumplimiento, umbrales— lo
 * siembran sus migraciones.
 */

const SEEDERS_OUT_OF_IMAGE_DOCKERFILE = 'infra/docker/php/Dockerfile';

/** Lineas efectivas (sin comentarios ni vacias) de un fichero del repositorio. */
$lineasEfectivas = static function (string $relativo): array {
    $lineas = file(Repo::file($relativo), FILE_IGNORE_NEW_LINES) ?: [];

    return array_values(array_filter(
        array_map(trim(...), $lineas),
        static fn (string $linea): bool => $linea !== '' && ! str_starts_with($linea, '#'),
    ));
};

it('.dockerignore saca la semilla del contexto de construccion y nada la vuelve a meter', function () use ($lineasEfectivas): void {
    $lineas = $lineasEfectivas('.dockerignore');

    expect($lineas)->toContain('backend/database/seeders/');

    // Una excepcion `!` posterior anularia la exclusion sin que nadie lo vea.
    $reinclusiones = array_values(array_filter(
        $lineas,
        static fn (string $linea): bool => str_starts_with($linea, '!')
            && (str_contains($linea, 'seeders') || str_contains($linea, 'backend/database')),
    ));
    expect($reinclusiones)->toBe([], 'Una regla `!` de .dockerignore vuelve a meter la semilla en la imagen.');

    // Si algun dia hay fabricas de modelos, tampoco son de produccion.
    if (is_dir(Repo::file('backend/database/factories'))) {
        expect($lineas)->toContain('backend/database/factories/');
    }
})->group('RS-08');

it('la etapa vendor del Dockerfile falla si la semilla llega a la imagen', function (): void {
    $dockerfile = (string) file_get_contents(Repo::file(SEEDERS_OUT_OF_IMAGE_DOCKERFILE));

    // La etapa que copia el backend y de la que `prod` hereda el arbol entero.
    preg_match('/^FROM base AS vendor$(.*?)^FROM /ms', $dockerfile, $etapa);
    $vendor = $etapa[1] ?? '';
    expect($vendor)->not->toBe('', 'No se encuentra la etapa `vendor` en '.SEEDERS_OUT_OF_IMAGE_DOCKERFILE);

    expect($vendor)->toContain('COPY --chown=app:app backend/ ./')
        ->and($vendor)->toMatch('/^RUN test ! -e database\/seeders\b/m');

    // Ninguna instruccion del Dockerfile la copia a proposito ni copia el
    // repositorio entero, que esquivaria la ruta de `.dockerignore`.
    $copias = preg_grep('/^\s*(COPY|ADD)\b/m', explode("\n", $dockerfile)) ?: [];
    foreach ($copias as $copia) {
        expect($copia)->not->toContain('seeders')
            ->and($copia)->not->toMatch('/^\s*(COPY|ADD)(\s+--\S+)*\s+\.\s/');
    }
})->group('RS-08');

it('nada de lo que viaja en la imagen carga una semilla ni llama a db:seed', function (): void {
    $origenes = [
        'backend/app', 'backend/bootstrap', 'backend/config', 'backend/routes',
        'backend/database/migrations', 'infra/docker/php', 'infra/scripts',
    ];

    $culpables = [];
    foreach ($origenes as $origen) {
        $ficheros = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            Repo::file($origen),
            FilesystemIterator::SKIP_DOTS,
        ));

        foreach ($ficheros as $fichero) {
            /** @var SplFileInfo $fichero */
            if (! in_array($fichero->getExtension(), ['php', 'sh', ''], true)
                || str_starts_with($fichero->getFilename(), '.')
                || str_contains($fichero->getPathname(), DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $codigo = (string) file_get_contents($fichero->getPathname());
            if (preg_match('/Database\\\\Seeders\\\\|db:seed|--seed\b/', $codigo) === 1) {
                $culpables[] = $fichero->getPathname();
            }
        }
    }

    expect($culpables)->toBe([], "Codigo que viaja en la imagen de produccion depende de la semilla de desarrollo,\n"
        ."que la imagen no lleva (SC7-03). Si es dato de producto, va en una migracion:\n- ".implode("\n- ", $culpables));
})->group('RS-08');

it('las pruebas y el utillaje de desarrollo no viajan en la imagen y nada las ejecuta dentro de ella', function () use ($lineasEfectivas): void {
    $excluidos = [
        'backend/tests/', 'backend/tools/', 'backend/phpunit.xml', 'backend/phpstan.neon',
        'backend/deptrac.yaml', 'backend/rector.php', 'backend/pint.json',
    ];
    $lineas = $lineasEfectivas('.dockerignore');
    foreach ($excluidos as $ruta) {
        expect($lineas)->toContain($ruta);
    }

    $reinclusiones = array_values(array_filter(
        $lineas,
        static fn (string $linea): bool => str_starts_with($linea, '!') && str_contains($linea, 'backend'),
    ));
    expect($reinclusiones)->toBe([], 'Una regla `!` de .dockerignore vuelve a meter pruebas o utillaje en la imagen.');

    // Segunda cerradura: la etapa `vendor` no se construye si llegan igualmente.
    $dockerfile = (string) file_get_contents(Repo::file(SEEDERS_OUT_OF_IMAGE_DOCKERFILE));
    expect($dockerfile)->toMatch('/^RUN test ! -e database\/seeders && \\\\\R\s+test ! -e tests && test ! -e tools && test ! -e phpunit\.xml\b/m');

    // Precondicion: ningun flujo de trabajo ni script de la CI ejecuta las
    // suites DENTRO de la imagen de produccion (si lo hiciera, excluirlas la
    // rompe). En CI las suites corren sobre el checkout o la imagen `dev`.
    $culpables = [];
    $ficheros = array_merge(
        glob(Repo::file('.github/workflows').'/*.yml') ?: [],
        glob(Repo::file('.github/scripts').'/*.sh') ?: [],
    );
    foreach ($ficheros as $fichero) {
        foreach (explode("\n", (string) file_get_contents($fichero)) as $numero => $linea) {
            if (preg_match('/kronoqr\/app:ci|kronoqr\/php:/', $linea) === 1
                && preg_match('/\b(pest|phpunit|artisan test|qa:traceability)\b/', $linea) === 1) {
                $culpables[] = basename($fichero).':'.($numero + 1);
            }
        }
    }
    expect($culpables)->toBe([], "Algo ejecuta pruebas dentro de la imagen de produccion, que ya no las lleva:\n- ".implode("\n- ", $culpables));
})->group('RS-08');
