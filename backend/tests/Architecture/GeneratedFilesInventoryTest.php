<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\PathOverlap;
use App\Modules\Shared\Infrastructure\GeneratedFiles\GeneratedFileAreas;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Facade;
use Tests\Architecture\Support\ModuleTree;

/*
 * ADR-045, «Verificacion», y condicion C10: **todo lo que el producto escribe
 * en disco esta clasificado**, y cada clase vive donde le corresponde.
 *
 * ## Por que una lista cerrada
 *
 * El fallo que cierra ADR-045 (R3-PL-01) no era un error de nadie: cada
 * comentario de `config/` razonaba bien que no debia mezclarse con
 * `BACKUP_PATH`, pero daba por supuesto que `storage/app` era un sitio unico y
 * compartido. La unica forma de que una escritura nueva no vuelva a nacer en la
 * capa de un contenedor es obligar a quien la añade a decir **de que clase es**,
 * y eso lo hace esta prueba, no la memoria de quien revise.
 *
 * ## Que se busca
 *
 * Cada llamada a `storage_path(…)`, `sys_get_temp_dir()` y `tempnam(…)` de
 * `backend/app` y `backend/config`, leida con el tokenizador de PHP —los
 * comentarios no cuentan—. Cada una tiene que figurar abajo con su clase:
 *
 * - `shared`: efimero que cruza contenedores. **Tiene que estar bajo
 *   `storage/app`**, que es el volumen `app-storage`.
 * - `disposable`: desechable, en la capa del contenedor. **No puede estar bajo
 *   `storage/app`**: lo que se tira no ocupa el volumen.
 * - `probe`: `product:doctor` mira el directorio, no escribe en el.
 * - `unused`: los discos `local` y `public` de Laravel, sin un solo `Storage::`
 *   en el producto. Si alguien los usa, esta prueba le obliga a clasificarlos.
 * - `dev_only`: la raiz de marca por defecto; en produccion es
 *   `BRANDING_LOGO_ROOT=/var/kronoqr/branding`.
 *
 * Lo duradero (copias, informes de retencion) no usa `storage_path`: vive en
 * `BACKUP_PATH`, y la ultima prueba lo comprueba para los informes.
 *
 * ## Las dos direcciones, y por que la segunda no recorre el arbol
 *
 * Una llamada sin clasificar falla; una entrada de la lista que ya no existe
 * tambien. La segunda se comprueba **leyendo cada fichero de la lista por su
 * ruta**, no recorriendo `app/`: el bind mount de Docker Desktop pierde ficheros
 * al recorrer el arbol, y una entrada «obsoleta» por un fichero no listado seria
 * un falso rojo en local.
 */

/**
 * `fichero relativo a backend/` => [`llamada:argumento` => clase].
 *
 * @var array<string, array<string, string>>
 */
const GENERATED_FILES_INVENTORY = [
    'config/branding.php' => ['storage_path:app/branding' => 'dev_only'],
    'config/cache.php' => ['storage_path:framework/cache/data' => 'disposable'],
    'config/compliance.php' => [
        'storage_path:app/tmp/legal-exports' => 'shared',
        'storage_path:app/legal-exports' => 'shared',
        'storage_path:logs' => 'disposable',
    ],
    'config/filesystems.php' => [
        'storage_path:app/private' => 'unused',
        'storage_path:app/public' => 'unused',
    ],
    'config/logging.php' => ['storage_path:logs/laravel.log' => 'disposable'],
    'config/product.php' => [
        'storage_path:app/diagnostics' => 'shared',
        'storage_path:app/exports' => 'shared',
        'storage_path:app/telemetry/state.json' => 'shared',
    ],
    'config/reporting.php' => ['storage_path:app/reports' => 'shared'],
    'config/session.php' => ['storage_path:framework/sessions' => 'disposable'],
    'app/Modules/Compliance/Infrastructure/Retention/FilesystemTechnicalLogArchive.php' => [
        'storage_path:logs' => 'disposable',
    ],
    'app/Modules/Product/ProductServiceProvider.php' => [
        'storage_path:' => 'probe',
        'storage_path:app' => 'probe',
    ],
    'app/Modules/Reporting/Http/Response/AdoptionReportDocument.php' => [
        'tempnam:' => 'disposable',
        'sys_get_temp_dir:' => 'disposable',
    ],
];

const GENERATED_FILES_INVENTORY_CLASSES = ['shared', 'disposable', 'probe', 'unused', 'dev_only'];

function generatedFilesInventoryBackend(): string
{
    return \dirname(ModuleTree::root(), 2);
}

/**
 * Las llamadas de un fichero, como `llamada:argumento`. El argumento es el
 * literal de cadena si es el primero; vacio si no hay o no es un literal.
 *
 * @return list<string>
 */
function generatedFilesInventoryCalls(string $source): array
{
    $tokens = array_values(array_filter(
        token_get_all($source),
        static fn (mixed $token): bool => ! \is_array($token)
            || ! \in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
    ));

    $calls = [];

    foreach (array_keys($tokens) as $index) {
        $call = generatedFilesInventoryCallAt($tokens, $index);

        if ($call !== null) {
            $calls[] = $call;
        }
    }

    return array_values(array_unique($calls));
}

/**
 * El nombre de la funcion vigilada que se llama en esa posicion, o nulo.
 *
 * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
 */
function generatedFilesInventoryWatchedName(array $tokens, int $index): ?string
{
    $token = $tokens[$index];

    if (! \is_array($token) || ! \in_array($token[1], ['storage_path', 'sys_get_temp_dir', 'tempnam'], true)) {
        return null;
    }

    $previous = $tokens[$index - 1] ?? null;
    $isMember = \is_array($previous) && \in_array($previous[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true);

    return $token[0] === T_STRING && ($tokens[$index + 1] ?? null) === '(' && ! $isMember ? $token[1] : null;
}

/** El texto de un literal de cadena, o vacio si el token no lo es. */
function generatedFilesInventoryLiteral(mixed $token): string
{
    return \is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING && \is_string($token[1])
        ? trim($token[1], '\'"')
        : '';
}

/**
 * La llamada que empieza en esa posicion, o nula si no es una de las tres
 * funciones vigiladas. Un metodo o una declaracion con el mismo nombre no
 * cuentan.
 *
 * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
 */
function generatedFilesInventoryCallAt(array $tokens, int $index): ?string
{
    $name = generatedFilesInventoryWatchedName($tokens, $index);

    if ($name === null) {
        return null;
    }

    $argument = $tokens[$index + 2] ?? null;
    $literal = $name === 'storage_path' ? generatedFilesInventoryLiteral($argument) : '';

    return $name.':'.$literal;
}

/** @return list<string> Ficheros PHP bajo `backend/app` y `backend/config`, relativos a `backend/`. */
function generatedFilesInventorySources(): array
{
    $backend = generatedFilesInventoryBackend();
    $files = [];

    foreach (['app', 'config'] as $directory) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($backend.'/'.$directory, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = ltrim(substr(str_replace('\\', '/', $file->getPathname()), \strlen($backend)), '/');
            }
        }
    }

    sort($files);

    return $files;
}

it('cada storage_path, sys_get_temp_dir y tempnam del producto esta clasificado', function (): void {
    $unclassified = [];

    foreach (generatedFilesInventorySources() as $relative) {
        $calls = generatedFilesInventoryCalls((string) file_get_contents(generatedFilesInventoryBackend().'/'.$relative));

        foreach ($calls as $call) {
            if (! isset(GENERATED_FILES_INVENTORY[$relative][$call])) {
                $unclassified[] = $relative.' → '.$call;
            }
        }
    }

    expect($unclassified)->toBe([], implode("\n", [
        'Escrituras en disco sin clasificar (ADR-045). Añadelas a GENERATED_FILES_INVENTORY con su clase:',
        ...$unclassified,
    ]));
})->group('RF-PD-14', 'RF-IN-06');

it('ninguna entrada del inventario esta obsoleta', function (): void {
    $stale = [];

    foreach (GENERATED_FILES_INVENTORY as $relative => $entries) {
        $path = generatedFilesInventoryBackend().'/'.$relative;
        $calls = is_file($path) ? generatedFilesInventoryCalls((string) file_get_contents($path)) : [];

        foreach (array_keys($entries) as $call) {
            if (! \in_array($call, $calls, true)) {
                $stale[] = $relative.' → '.$call;
            }
        }
    }

    expect($stale)->toBe([], 'Entradas del inventario que ya no existen en el codigo: '.implode(', ', $stale));
})->group('RF-PD-14');

it('lo efimero que cruza contenedores vive en storage/app y lo desechable no', function (): void {
    $misplaced = [];

    foreach (GENERATED_FILES_INVENTORY as $relative => $entries) {
        foreach ($entries as $call => $class) {
            expect(\in_array($class, GENERATED_FILES_INVENTORY_CLASSES, true))->toBeTrue($relative.' → '.$call.': clase desconocida «'.$class.'».');

            $argument = (string) substr($call, (int) strpos($call, ':') + 1);
            $inVolume = $argument === 'app' || str_starts_with($argument, 'app/');

            if (($class === 'shared' && ! $inVolume) || ($class === 'disposable' && $inVolume)) {
                $misplaced[] = $relative.' → '.$call.' ('.$class.')';
            }
        }
    }

    expect($misplaced)->toBe([], 'Rutas en el sitio equivocado: '.implode(', ', $misplaced));
})->group('RF-PD-14', 'RF-IN-06');

/**
 * Ejecuta `$work` con la configuracion **de serie** de las clases de fichero:
 * los `config/*.php` de verdad, sin la variable que `phpunit.xml` fija para los
 * informes de retencion.
 *
 * La suite de arquitectura no arranca Laravel, asi que se monta lo minimo para
 * que `storage_path()`, `env()` y `Config::` respondan: una `Application` con su
 * raiz y un repositorio de configuracion con los cuatro ficheros. Se desmonta
 * al terminar, pase lo que pase.
 *
 * @template T
 *
 * @param  callable(): T  $work
 * @return T
 */
function generatedFilesInventoryWithDefaults(callable $work): mixed
{
    $variable = 'COMPLIANCE_RETENTION_REPORT_PATH';
    $saved = [$_ENV[$variable] ?? null, $_SERVER[$variable] ?? null, getenv($variable)];

    unset($_ENV[$variable], $_SERVER[$variable]);
    putenv($variable);

    $application = new Application(generatedFilesInventoryBackend());

    try {
        $configuration = [];

        foreach (['backup', 'compliance', 'product', 'reporting'] as $name) {
            $configuration[$name] = require generatedFilesInventoryBackend().'/config/'.$name.'.php';
        }

        $application->instance('config', new Repository($configuration));
        Facade::setFacadeApplication($application);

        return $work();
    } finally {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        if (\is_string($saved[0])) {
            $_ENV[$variable] = $saved[0];
        }

        if (\is_string($saved[1])) {
            $_SERVER[$variable] = $saved[1];
        }

        if (\is_string($saved[2])) {
            putenv($variable.'='.$saved[2]);
        }
    }
}

it('las raices de clase de serie no coinciden, no se solapan y no son storage/app ni BACKUP_PATH', function (): void {
    [$roots, $storage, $backup] = generatedFilesInventoryWithDefaults(static fn (): array => [
        array_map(PathOverlap::normalise(...), GeneratedFileAreas::configuredRoots()),
        PathOverlap::normalise(storage_path('app')),
        PathOverlap::normalise(Config::string('backup.path')),
    ]);

    $problems = [];
    $labels = array_keys($roots);

    foreach ($labels as $index => $first) {
        if (PathOverlap::contains($roots[$first], $storage) || PathOverlap::between($roots[$first], $backup)) {
            $problems[] = $first;
        }

        if (! PathOverlap::contains($storage, $roots[$first])) {
            $problems[] = $first.' fuera de storage/app';
        }

        foreach (\array_slice($labels, $index + 1) as $second) {
            if (PathOverlap::between($roots[$first], $roots[$second])) {
                $problems[] = $first.' y '.$second;
            }
        }
    }

    expect(\count($roots))->toBe(6)
        ->and($problems)->toBe([]);
})->group('RF-PD-14', 'RF-IN-06');

it('nadie usa los discos de Laravel: los ficheros generados pasan por sus puertos', function (): void {
    $users = [];

    foreach (generatedFilesInventorySources() as $relative) {
        $code = '';

        foreach (token_get_all((string) file_get_contents(generatedFilesInventoryBackend().'/'.$relative)) as $token) {
            if (! \is_array($token) || ! \in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $code .= \is_array($token) ? $token[1] : $token;
            }
        }

        if (str_contains($code, 'Facades\Storage') || preg_match('/\bStorage::/', $code) === 1) {
            $users[] = $relative;
        }
    }

    expect($users)->toBe([], 'Usan Storage:: y sus discos no estan clasificados en ADR-045: '.implode(', ', $users));
})->group('RF-PD-14');

it('el informe de retencion resuelve por defecto bajo BACKUP_PATH, no en storage/app', function (): void {
    // La suite lo apunta a un directorio de pruebas (phpunit.xml). Aqui se lee
    // el valor de SERIE de config/compliance.php, sin esa variable.
    [$default, $backup, $storage] = generatedFilesInventoryWithDefaults(static fn (): array => [
        Config::string('compliance.retention.report_path'),
        Config::string('backup.path'),
        storage_path('app'),
    ]);

    expect($default)->toBe(rtrim($backup, '/').'/reports/retention')
        ->and(PathOverlap::contains($storage, $default))->toBeFalse();
})->group('RF-PR-03');
