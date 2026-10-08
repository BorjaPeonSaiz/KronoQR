<?php

declare(strict_types=1);

use Tests\Architecture\Support\FrameworkHelpers;
use Tests\Architecture\Support\ModuleTree;

/*
 * Ni helpers globales de Laravel en `Domain/` y `Application/`, ni facades en
 * `Application/` (ADR-002 «Verificacion», reglas duras 1, 2 y 14; R1-AR-01 y H3
 * de la verificacion de la 2.2.0).
 *
 * Deptrac ve clases y no funciones, y `DomainPurityTest` solo mira seis
 * funciones de fecha de PHP. La re-verificacion metio un `config('x')` en un
 * value object del dominio y la CI entera dio verde. Lo que vigila esta prueba:
 *
 *   - **Helpers en `Domain/`** (R1-AR-01): `config()` saltaria el perfil de
 *     cumplimiento (regla dura 14), `now()` el puerto `Clock` (regla dura 2) y
 *     `app()` o `cache()` meterian el contenedor en un objeto que tiene que
 *     poder probarse sin el (regla dura 1).
 *   - **Helpers y facades en `Application/`** (H3): los casos de uso hablan con
 *     el mundo por puertos. Una facade o un `app()` alli es una dependencia que
 *     el doble de prueba del puerto no sustituye, y el caso de uso deja de
 *     poder probarse sin framework. Deptrac ya rechaza el import de
 *     `Illuminate\Support\Facades` en las capas de aplicacion, pero no el alias
 *     global (`\Cache::`) ni la facade en tiempo real (`Facades\App\…`).
 *
 * LA LISTA DE HELPERS LA DA EL FRAMEWORK ({@see FrameworkHelpers::names()}),
 * no esta prueba.
 */

/**
 * Las llamadas a helpers de Laravel en una capa, como «fichero:linea llama a x()».
 *
 * @return list<string>
 */
function frameworkHelpersCalledIn(string $layer): array
{
    $names = FrameworkHelpers::names();
    $offenders = [];

    foreach (ModuleTree::filesInLayer(ModuleTree::MODULES, $layer) as $file) {
        $offenders[] = array_map(
            static fn (array $call): string => ModuleTree::relative($file).':'.$call['line'].' llama a '.$call['function'].'()',
            FrameworkHelpers::callsIn((string) file_get_contents($file), $names),
        );
    }

    return array_merge(...$offenders);
}

/**
 * Los usos de facades en una capa.
 *
 * @return list<string>
 */
function frameworkFacadesUsedIn(string $layer): array
{
    $aliases = FrameworkHelpers::facadeAliases();
    $offenders = [];

    foreach (ModuleTree::filesInLayer(ModuleTree::MODULES, $layer) as $file) {
        $offenders[] = array_map(
            static fn (string $use): string => ModuleTree::relative($file).' '.$use,
            FrameworkHelpers::facadeUsesIn((string) file_get_contents($file), ModuleTree::importsOf($file), $aliases),
        );
    }

    return array_merge(...$offenders);
}

it('lee del framework los helpers globales que vigila', function (): void {
    // El control: si la lectura de `vendor/` se rompiera, las pruebas de abajo
    // pasarian en verde sobre una lista vacia. Los ocho nombrados son los que
    // cita el hallazgo; la cifra es un suelo, no un recuento (hoy son mas de
    // cien).
    expect(FrameworkHelpers::names())
        ->toContain('now', 'app', 'config', 'collect', 'env', 'logger', 'resolve', 'cache')
        ->and(\count(FrameworkHelpers::names()))->toBeGreaterThan(80);
})->group('RNF-M-03');

it('recorre las capas de dominio y de aplicacion', function (): void {
    // El otro control: un recorrido que no viera ficheros daria verde.
    expect(\count(ModuleTree::filesInLayer(ModuleTree::MODULES, 'Domain')))->toBeGreaterThan(400)
        ->and(\count(ModuleTree::filesInLayer(ModuleTree::MODULES, 'Application')))->toBeGreaterThan(300);
})->group('RNF-M-03');

it('no llama a ningun helper global de Laravel desde el dominio', function (): void {
    expect(frameworkHelpersCalledIn('Domain'))->toBe([]);
})->group('RNF-M-03');

it('no llama a ningun helper global de Laravel desde la capa de aplicacion', function (): void {
    expect(frameworkHelpersCalledIn('Application'))->toBe([]);
})->group('RNF-M-03');

it('no usa facades en la capa de aplicacion', function (): void {
    expect(frameworkFacadesUsedIn('Application'))->toBe([]);
})->group('RNF-M-03');

it('no usa facades en el dominio', function (): void {
    expect(frameworkFacadesUsedIn('Domain'))->toBe([]);
})->group('RNF-M-03');

it('detecta un helper llamado como funcion, con y sin barra inicial', function (string $codigo, string $helper): void {
    $source = "<?php\nnamespace App\\Modules\\Attendance\\Domain;\nfinal class Sonda { public function f(): mixed { return ".$codigo."; } }\n";

    expect(FrameworkHelpers::callsIn($source, FrameworkHelpers::names()))
        ->toBe([['function' => $helper, 'line' => 3]]);
})->with([
    'config' => ["config('kronoqr.max_daily_minutes')", 'config'],
    'now con barra' => ['\\now()', 'now'],
    'app' => ['app(Clock::class)', 'app'],
    'cache' => ["cache('x')", 'cache'],
    'en mayusculas' => ['Collect([])', 'collect'],
])->group('RNF-M-03');

it('no confunde un metodo propio con el helper del mismo nombre', function (string $codigo): void {
    // `value`, `with`, `optional` o `last` son nombres corrientes de metodo en un
    // dominio. Si la deteccion los confundiera, la prueba de arriba se
    // llenaria de falsos positivos y alguien acabaria desactivandola.
    $source = "<?php\nfinal class Sonda { public function value(): int { return 1; }\n public function f(): mixed { return ".$codigo."; } }\n";

    expect(FrameworkHelpers::callsIn($source, FrameworkHelpers::names()))->toBe([]);
})->with([
    'metodo de instancia' => ['$this->value()'],
    'metodo nullsafe' => ['$this?->value()'],
    'metodo estatico' => ['self::with(1)'],
    'constructor' => ['new Collection()'],
    'en un comentario' => ["/* config('x') */ 1"],
    'en una cadena' => ["'config()'"],
    'funcion de otro espacio de nombres' => ['\\App\\Support\\now()'],
])->group('RNF-M-03');

it('detecta las cuatro formas de usar una facade', function (string $import, string $codigo): void {
    $source = "<?php\nnamespace App\\Modules\\Attendance\\Application;\nfinal class Sonda { public function f(): mixed { return ".$codigo."; } }\n";
    $imports = $import === '' ? [] : [$import];

    expect(FrameworkHelpers::facadeUsesIn($source, $imports, FrameworkHelpers::facadeAliases()))->not->toBe([]);
})->with([
    'import de la facade' => ['Illuminate\\Support\\Facades\\Cache', "Cache::get('x')"],
    'facade en tiempo real' => ['Facades\\App\\Support\\Clock', 'Clock::now()'],
    'import del alias global' => ['DB', "DB::table('x')"],
    'alias calificado' => ['', "\\Cache::get('x')"],
    'facade calificada' => ['', "\\Illuminate\\Support\\Facades\\Log::info('x')"],
])->group('RNF-M-03');

it('no confunde una clase propia con el alias de una facade', function (): void {
    // `App\Modules\…\Cache` no es la facade `Cache`: solo el alias GLOBAL lo es.
    $source = "<?php\nnamespace App\\Modules\\Attendance\\Application;\nfinal class Sonda { public function f(): mixed { /* \\Cache::get() */ return \\App\\Support\\Cache::get('x'); } }\n";

    expect(FrameworkHelpers::facadeUsesIn($source, ['App\\Support\\Cache'], FrameworkHelpers::facadeAliases()))->toBe([]);
})->group('RNF-M-03');
