<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Tests\Architecture\Support\Repo;

/*
 * La mutacion mide lo que dice medir (RQ-10; R2-QB-06, M6 y M8 de la 2.2.0,
 * tarea 22.5 de la 2.3.0).
 *
 * Pest Mutate da por muerto un mutante cuando el proceso hijo sale con error,
 * tambien si el error es del entorno: con `--processes=8` el canario dio
 * «7 tested», 100 %, y su reparto real es «4 untested, 3 tested». Estas
 * pruebas fijan las dos mitades de la defensa: que el script que lee la
 * salida de Pest rechaza cualquier reparto del canario que no sea el
 * esperado, y que el Makefile y la CI lo ejecutan antes de mutar el dominio.
 * Las salidas de Pest son fabricadas, con el formato y los colores reales.
 */

const MUTATION_LOG_SCRIPT_CANARY = '4 untested, 3 tested';

/**
 * Una salida de Pest Mutate con el reparto dado, coloreada como la real.
 */
function mutationLogScriptPestOutput(string $reparto, string $nota, string $vivos = ''): string
{
    return "\n  7 Mutations for 1 Files created\n\n  x.xx.x.\n"
        .$vivos
        ."\n  \e[90mMutations:\e[39m\e[39;1m \e[39;22m\e[31;1m".$reparto."\e[39;22m\n"
        ."\e[39m  \e[39m\e[90mScore:\e[39m\e[39m     \e[39m\e[31m".$nota."\e[39m\n";
}

/**
 * Ejecuta `.github/scripts/mutation-log.sh` sobre una salida fabricada.
 *
 * @param  list<string>  $argumentos  Los argumentos que van antes del fichero.
 * @return array{0: int|null, 1: string}
 */
function mutationLogScriptRun(string $modo, array $argumentos, string $salida): array
{
    $log = (string) tempnam(sys_get_temp_dir(), 'mutation-log-');
    file_put_contents($log, $salida);

    $script = new Process(['sh', Repo::file('.github/scripts/mutation-log.sh'), $modo, ...$argumentos, $log]);
    $script->run();
    unlink($log);

    return [$script->getExitCode(), $script->getOutput().$script->getErrorOutput()];
}

it('aprueba el canario que da exactamente el reparto esperado', function (): void {
    [$codigo, $texto] = mutationLogScriptRun('canary', [MUTATION_LOG_SCRIPT_CANARY], mutationLogScriptPestOutput('4 untested, 3 tested', '42.86%'));

    expect($codigo)->toBe(0)
        ->and($texto)->toContain('Canario correcto: 4 untested, 3 tested');
})->group('RQ-10');

it('para la mutacion cuando el canario no da el reparto esperado', function (string $reparto): void {
    [$codigo, $texto] = mutationLogScriptRun('canary', [MUTATION_LOG_SCRIPT_CANARY], mutationLogScriptPestOutput($reparto, '0.00%'));

    expect($codigo)->toBe(1)
        ->and($texto)->toContain('el canario ha dado «'.$reparto.'»');
})->with([
    'todos muertos: el hijo falla por su cuenta (R2-QB-06)' => ['7 tested'],
    'todos vivos: el mutante no llega al hijo' => ['7 untested'],
    'un vivo de menos' => ['3 untested, 4 tested'],
    'un tiempo agotado que se cuela' => ['4 untested, 1 timeout, 2 tested'],
])->group('RQ-10');

it('para la mutacion cuando la salida no trae el reparto de Pest', function (): void {
    [$codigo, $texto] = mutationLogScriptRun('canary', [MUTATION_LOG_SCRIPT_CANARY], "  Tests:    2 passed (2 assertions)\n");

    expect($codigo)->toBe(1)
        ->and($texto)->toContain('no tiene la linea «Mutations:»');
})->group('RQ-10');

it('resume la puntuacion y cuenta los mutantes vivos de cada fichero, de mas a menos', function (): void {
    $vivos = "   UNTESTED  app/Modules/Kiosk/Domain/B.php  > Line 9: FalseToTrue - ID: 1\n"
        ."   UNTESTED  app/Modules/Kiosk/Domain/A.php  > Line 3: MaxToMin - ID: 2\n"
        ."   UNTESTED  app/Modules/Kiosk/Domain/A.php  > Line 4: AlwaysReturnNull - ID: 3\n";
    $salida = "[mutate-canary] Canario correcto: 4 untested, 3 tested. La pasada distingue un mutante muerto de uno vivo.\n"
        .mutationLogScriptPestOutput('3 untested, 37 tested', '92.50%', $vivos);

    [$codigo, $texto] = mutationLogScriptRun('summary', [], $salida);

    expect($codigo)->toBe(0)
        ->and($texto)->toContain('| Puntuacion (MSI) | **92.50%** |')
        ->and($texto)->toContain('| Canario | 4 untested, 3 tested |')
        ->and($texto)->toContain("| 2 | `app/Modules/Kiosk/Domain/A.php` |\n| 1 | `app/Modules/Kiosk/Domain/B.php` |");
})->group('RQ-10');

it('no presenta la puntuacion del canario como la del dominio cuando el canario falla', function (): void {
    $salida = mutationLogScriptPestOutput('7 tested', '100.00%')
        ."[mutate-canary] ERROR: el canario ha dado «7 tested» y tiene que dar «4 untested, 3 tested».\n";

    [$codigo, $texto] = mutationLogScriptRun('summary', [], $salida);

    expect($codigo)->toBe(0)
        ->and($texto)->toContain('El canario ha fallado')
        ->and($texto)->not->toContain('100.00%');
})->group('RQ-10');

it('ejecuta el canario con las mismas opciones que la mutacion del dominio y antes de ella', function (): void {
    $makefile = Repo::contents('Makefile');

    expect($makefile)->toMatch('/^MUTATION_CANARY_EXPECTED := '.MUTATION_LOG_SCRIPT_CANARY.'$/m')
        ->and($makefile)->toMatch('/^mutate: mutate-canary\b/m')
        ->and($makefile)->toContain('$(MAKE) --no-print-directory mutate-canary || exit $$?;')
        ->and($makefile)->toContain('$(PEST) $(MUTATE_OPTIONS) --path=tests/MutationCanary/MutationCanary.php --testsuite=MutationCanary')
        ->and($makefile)->toContain('$(PEST) $(MUTATE_OPTIONS) --path=$(MUTATE_PATHS) --testsuite=Unit --min=80')
        ->and($makefile)->toContain('$(PEST) $(MUTATE_OPTIONS) --path=$$paths --testsuite=Unit --min=80')
        ->and($makefile)->toContain('mutation-log.sh" canary "$(MUTATION_CANARY_EXPECTED)"');
})->group('RQ-10');

it('muta tambien por separado cada clase de dominio cambiada, con su minimo y su tope, y marca cada pasada para el resumen', function (): void {
    // R2-QB-01: Pest da una nota por ejecucion y un fichero flojo quedaba
    // tapado por los buenos del mismo cambio. La pasada por fichero usa las
    // MISMAS opciones que el conjunto, que son las que el canario valida.
    $makefile = Repo::contents('Makefile');

    expect($makefile)->toMatch('/^MUTATE_FILE_MIN := 70$/m')
        ->and($makefile)->toMatch('/^MUTATE_FILE_MAX \?= 12$/m')
        ->and($makefile)->toContain('$(PEST) $(MUTATE_OPTIONS) --path=$$f --testsuite=Unit --min=$(MUTATE_FILE_MIN)')
        ->and($makefile)->toContain('echo "[mutate-file] >>> $$f')
        ->and($makefile)->toContain('echo "[mutate-file] <<< $$f: $$estado"')
        ->and($makefile)->toContain('[ "$$n" -gt $(MUTATE_FILE_MAX) ]')
        ->and($makefile)->toContain('echo "[mutate-file] AVISO: ');
})->group('RQ-10');

it('distingue un fichero sin codigo que mutar de uno sin pruebas repitiendo la pasada sin --covered-only', function (): void {
    // Con `--covered-only`, «No mutations created» lo dan igual un enum sin
    // metodos y una clase que ninguna prueba unitaria ejecuta. La sonda quita
    // solo esa opcion: si tampoco crea mutantes, no hay codigo; si los crea,
    // es codigo sin cubrir y la puerta falla, en el conjunto y por fichero.
    $makefile = Repo::contents('Makefile');

    expect($makefile)->toMatch('/^MUTATE_PROBE_OPTIONS := \$\(filter-out --covered-only,\$\(MUTATE_OPTIONS\)\)$/m')
        ->and($makefile)->toContain('( $(RUN_APP_XDEBUG) sh -c "PHP_INI_SCAN_DIR=\":\$$(pwd)/tools/mutation\" $(PEST) $(MUTATE_PROBE_OPTIONS) --path=$$1 --testsuite=Unit" ) >"$$plog" 2>&1;')
        ->and($makefile)->toContain('elif sin_codigo "$$paths"; then')
        ->and($makefile)->toContain('elif sin_codigo "$$f"; then')
        ->and($makefile)->toContain('estado="ERROR: sin pruebas unitarias que lo cubran');
})->group('RQ-10');

it('resume la pasada del conjunto aparte de las pasadas por fichero, sin contar dos veces un vivo', function (): void {
    $vivoDelConjunto = "   UNTESTED  app/Modules/Kiosk/Domain/Weak.php  > Line 3: MaxToMin - ID: 1\n";
    $vivosDeWeak = $vivoDelConjunto
        ."   UNTESTED  app/Modules/Kiosk/Domain/Weak.php  > Line 4: FalseToTrue - ID: 2\n";
    $salida = "[mutate-canary] Canario correcto: 4 untested, 3 tested. La pasada distingue un mutante muerto de uno vivo.\n"
        .mutationLogScriptPestOutput('1 untested, 39 tested', '97.50%', $vivoDelConjunto)
        ."[mutate-file] >>> app/Modules/Kiosk/Domain/Good.php (minimo 70 por ciento)\n"
        .mutationLogScriptPestOutput('1 untested, 9 tested', '90.00%')
        ."[mutate-file] <<< app/Modules/Kiosk/Domain/Good.php: llega al minimo\n"
        ."[mutate-file] >>> app/Modules/Kiosk/Domain/NoCode.php (minimo 70 por ciento)\n"
        ."\n  No mutations created.\n"
        ."[mutate-file] <<< app/Modules/Kiosk/Domain/NoCode.php: sin codigo que mutar\n"
        ."[mutate-file] >>> app/Modules/Kiosk/Domain/Weak.php (minimo 70 por ciento)\n"
        .mutationLogScriptPestOutput('2 untested, 2 tested', '50.00%', $vivosDeWeak)
        ."[mutate-file] <<< app/Modules/Kiosk/Domain/Weak.php: ERROR: no llega al 70 por ciento por si solo; el conjunto lo tapaba\n";

    [$codigo, $texto] = mutationLogScriptRun('summary', [], $salida);

    expect($codigo)->toBe(0)
        ->and($texto)->toContain('| Puntuacion (MSI) | **97.50%** |')
        ->and($texto)->toContain('| Mutantes | 1 untested, 39 tested |')
        ->and($texto)->toContain('| 1 | `app/Modules/Kiosk/Domain/Weak.php` |')
        ->and($texto)->not->toContain('| 3 | `app/Modules/Kiosk/Domain/Weak.php` |')
        ->and($texto)->not->toContain('No mutations created')
        ->and($texto)->toContain("| `app/Modules/Kiosk/Domain/Good.php` | 90.00% | llega al minimo |\n"
            ."| `app/Modules/Kiosk/Domain/NoCode.php` | - | sin codigo que mutar |\n"
            .'| `app/Modules/Kiosk/Domain/Weak.php` | 50.00% | ERROR: no llega al 70 por ciento por si solo; el conjunto lo tapaba |');
})->group('RQ-10');

it('dice en el resumen que una pasada por fichero no termino', function (): void {
    $salida = mutationLogScriptPestOutput('0 untested, 40 tested', '100.00%')
        ."[mutate-file] >>> app/Modules/Kiosk/Domain/Slow.php (minimo 70 por ciento)\n"
        ."\n  7 Mutations for 1 Files created\n";

    [, $texto] = mutationLogScriptRun('summary', [], $salida);

    expect($texto)->toContain('| Puntuacion (MSI) | **100.00%** |')
        ->and($texto)->toContain('| `app/Modules/Kiosk/Domain/Slow.php` | - | sin terminar |');
})->group('RQ-10');

it('avisa en el resumen cuando el tope deja sin pasadas por fichero', function (): void {
    $salida = mutationLogScriptPestOutput('0 untested, 40 tested', '100.00%')
        ."[mutate-file] AVISO: cambian 13 clases, traits o enums de dominio y el tope de pasadas por fichero es 12 (MUTATE_FILE_MAX).\n";

    [, $texto] = mutationLogScriptRun('summary', [], $salida);

    expect($texto)->toContain('| Puntuacion (MSI) | **100.00%** |')
        ->and($texto)->toContain('**Sin pasadas por fichero.** cambian 13 clases');
})->group('RQ-10');

/**
 * Los pasos `run:` de los workflows que pasan una orden por `tee` y no
 * conservan su codigo de salida: el `run:` de GitHub es `bash -e` SIN
 * `pipefail` salvo con `shell: bash` explicito, y entonces el paso toma el
 * codigo de `tee`, que es 0.
 *
 * @param  array<mixed>  $workflow  El workflow ya leido como YAML.
 * @return list<string> «job › paso» de cada paso que pierde el codigo.
 */
function mutationLogScriptTeeWithoutPipefail(array $workflow): array
{
    $sinPipefail = [];

    foreach (mutationLogScriptMap($workflow['jobs'] ?? null) as $job => $definicion) {
        $definicion = mutationLogScriptMap($definicion);
        $shellDelJob = mutationLogScriptDefaultShell($definicion) ?? mutationLogScriptDefaultShell($workflow);

        foreach (mutationLogScriptMap($definicion['steps'] ?? null) as $indice => $paso) {
            $paso = mutationLogScriptMap($paso);

            if (mutationLogScriptLosesExitCode($paso, $shellDelJob)) {
                $sinPipefail[] = $job.' › '.(\is_string($paso['name'] ?? null) ? $paso['name'] : '#'.$indice);
            }
        }
    }

    return $sinPipefail;
}

/**
 * Un nodo del YAML como mapa, o vacio si no lo es.
 *
 * @return array<mixed>
 */
function mutationLogScriptMap(mixed $nodo): array
{
    return \is_array($nodo) ? $nodo : [];
}

/**
 * El `defaults.run.shell` de un workflow o de un job, si lo declara.
 *
 * @param  array<mixed>  $nodo
 */
function mutationLogScriptDefaultShell(array $nodo): ?string
{
    $shell = mutationLogScriptMap(mutationLogScriptMap($nodo['defaults'] ?? null)['run'] ?? null)['shell'] ?? null;

    return \is_string($shell) ? $shell : null;
}

/**
 * ¿Pasa el paso una orden por `tee` sin conservar su codigo de salida?
 *
 * @param  array<mixed>  $paso
 */
function mutationLogScriptLosesExitCode(array $paso, ?string $shellHeredado): bool
{
    $orden = \is_string($paso['run'] ?? null) ? $paso['run'] : '';
    $shell = \is_string($paso['shell'] ?? null) ? $paso['shell'] : $shellHeredado;

    return preg_match('/\|\s*tee\b/', $orden) === 1
        && $shell !== 'bash'
        && preg_match('/^\s*set\s+-[a-z]*o\s+pipefail\b/m', $orden) !== 1;
}

it('reconoce el paso que pasa una orden por tee sin conservar su codigo de salida', function (array $paso, bool $loPierde): void {
    $workflow = ['jobs' => ['j' => ['steps' => [['name' => 'p', ...$paso]]]]];

    expect(mutationLogScriptTeeWithoutPipefail($workflow))->toBe($loPierde ? ['j › p'] : []);
})->with([
    'tee sin pipefail' => [['run' => "make mutate 2>&1 | tee log\n"], true],
    'pipefail en otra orden que no es set' => [['run' => "echo set -o pipefail\nmake mutate | tee log\n"], true],
    'set -euo pipefail' => [['run' => "set -euo pipefail\nmake mutate | tee log\n"], false],
    'set -o pipefail' => [['run' => "set -o pipefail\nmake mutate | tee log\n"], false],
    'shell: bash explicito' => [['shell' => 'bash', 'run' => "make mutate | tee log\n"], false],
    'sin tee' => [['run' => "make mutate >log\n"], false],
])->group('RQ-10');

it('ningun paso de los workflows pasa una orden por tee sin pipefail', function (): void {
    $workflows = glob(Repo::file('.github/workflows/*.yml')) ?: [];
    $sinPipefail = array_merge(...array_map(
        static fn (string $fichero): array => array_map(
            static fn (string $paso): string => basename($fichero).' › '.$paso,
            mutationLogScriptTeeWithoutPipefail((array) Yaml::parseFile($fichero)),
        ),
        $workflows,
    ));

    expect(count($workflows))->toBeGreaterThan(5)
        ->and($sinPipefail)->toBe([], 'Pasos con «| tee» que toman el codigo de tee y no el de la orden');
})->group('RQ-10');

it('deja que el directorio de OPcache apagado lo resuelva el shell que ejecuta Pest', function (): void {
    // En la receta entre comillas dobles de `mutate-changed`, un `$$(pwd)` sin
    // escapar lo expandia el anfitrion: con `docker compose exec` apuntaba a
    // una ruta de Windows y la mutacion local corria con OPcache encendido.
    $makefile = Repo::contents('Makefile');

    expect($makefile)->toContain('sh -c "PHP_INI_SCAN_DIR=\":\$$(pwd)/tools/mutation\" $(PEST)')
        ->and($makefile)->not->toContain("sh -c \"PHP_INI_SCAN_DIR=':\$\$(pwd)");
})->group('RQ-10');

it('declara el canario como suite propia y como codigo medido', function (): void {
    $phpunit = Repo::contents('backend/phpunit.xml');

    expect($phpunit)->toContain('<testsuite name="MutationCanary">')
        ->and($phpunit)->toContain('<file>tests/MutationCanary/MutationCanary.php</file>');
})->group('RQ-10');

it('mide de noche la parte del dominio que la mutacion ve y deja el log entero como artefacto', function (): void {
    $ci = Repo::contents('.github/workflows/ci.yml');

    // La mutacion nocturna son tres jobs (31-10-2026): la matriz de partes
    // (`mutation`), la puerta de lo que la mutacion ve (`mutation-scope`) y la
    // suma (`mutation-total`), que es la que deja el log entero.
    expect(preg_match('/^  mutation:\n(.*?)^  mutation-scope:\n/ms', $ci, $parte))->toBe(1);
    expect($parte[1] ?? '')->toContain('make mutate-part MUTATE_MODULES="${MUTATE_MODULES}"')
        ->and($parte[1] ?? '')->toContain('tee "${RUNNER_TEMP}/${PART}.log"')
        ->and($parte[1] ?? '')->toContain('mutation-log.sh summary')
        ->and($parte[1] ?? '')->toContain('name: mutacion-parte-${{ matrix.part }}-log');

    expect(preg_match('/^  mutation-scope:\n(.*?)^  mutation-total:\n/ms', $ci, $alcance))->toBe(1);
    expect($alcance[1] ?? '')->toContain('run: make mutate-scope');

    expect(preg_match('/^  mutation-total:\n(.*?)^  secrets-history:\n/ms', $ci, $total))->toBe(1);
    expect($total[1] ?? '')->toContain('mutation-log.sh total 80')
        ->and($total[1] ?? '')->toContain('name: mutacion-completa-log');

    expect(preg_match('/^  unit:\n(.*?)^  integration:\n/ms', $ci, $unit))->toBe(1);
    expect($unit[1] ?? '')->toContain('tee "${RUNNER_TEMP}/mutacion-acotada.log"')
        ->and($unit[1] ?? '')->toContain('name: mutacion-acotada-log');
})->group('RQ-10');

/**
 * Ejecuta `mutation-log.sh total` sobre varios logs de parte fabricados.
 *
 * @param  array<string, string>  $partes  Nombre de la parte => salida de Pest.
 * @return array{0: int|null, 1: string}
 */
function mutationLogScriptTotal(int $minimo, array $partes): array
{
    $directorio = sys_get_temp_dir().'/mutation-total-'.bin2hex(random_bytes(4));
    mkdir($directorio);
    $ficheros = [];
    foreach ($partes as $nombre => $salida) {
        $ficheros[] = $directorio.'/'.$nombre.'.log';
        file_put_contents(end($ficheros), $salida);
    }

    $script = new Process(['sh', Repo::file('.github/scripts/mutation-log.sh'), 'total', (string) $minimo, ...$ficheros]);
    $script->run();
    array_map('unlink', glob($directorio.'/*.log') ?: []);
    rmdir($directorio);

    return [$script->getExitCode(), $script->getOutput().$script->getErrorOutput()];
}

it('suma los mutantes de todas las partes y exige el minimo sobre el conjunto, no sobre cada parte', function (): void {
    // Una parte al 78 % y otra al 98 %: ninguna regla «80 % por parte» lo
    // aceptaria, y el conjunto (1025 de 1250 = 82 %) cumple RQ-10.
    $partes = [
        'product' => mutationLogScriptPestOutput('220 untested, 780 tested', '78.00%'),
        'workforce' => mutationLogScriptPestOutput('5 untested, 245 tested', '98.00%'),
    ];
    [$codigo, $texto] = mutationLogScriptTotal(80, $partes);

    expect($codigo)->toBe(0)
        ->and($texto)->toContain('82.00 %')
        ->and($texto)->toContain('| product | 780 | 1000 |')
        ->and($texto)->toContain('| workforce | 245 | 250 |');
})->group('RQ-10');

it('cuenta los tiempos agotados como muertos, como hace Pest, y los pendientes y sin cubrir como vivos', function (): void {
    // (60 tested + 20 timeout) / (60 + 20 + 10 untested + 5 uncovered + 5 pending) = 80 %
    [$codigo] = mutationLogScriptTotal(80, [
        'a' => mutationLogScriptPestOutput('10 untested, 5 uncovered, 5 pending, 20 timeout, 60 tested', '80.00%'),
    ]);
    [$suspenso] = mutationLogScriptTotal(81, [
        'a' => mutationLogScriptPestOutput('10 untested, 5 uncovered, 5 pending, 20 timeout, 60 tested', '80.00%'),
    ]);

    expect($codigo)->toBe(0)
        ->and($suspenso)->toBe(1);
})->group('RQ-10');

it('suspende la mutacion global por debajo del minimo y dice cuanto', function (): void {
    [$codigo, $texto] = mutationLogScriptTotal(80, [
        'a' => mutationLogScriptPestOutput('40 untested, 60 tested', '60.00%'),
        'b' => mutationLogScriptPestOutput('10 untested, 90 tested', '90.00%'),
    ]);

    expect($codigo)->toBe(1)
        ->and($texto)->toContain('Mutation score below expected: 75.00 %. Minimum: 80 %.');
})->group('RQ-10');

it('no da nota global si una parte no llego al final, aunque las demas sean perfectas', function (): void {
    [$codigo, $texto] = mutationLogScriptTotal(80, [
        'a' => mutationLogScriptPestOutput('1 untested, 99 tested', '99.00%'),
        'b' => "  7 Mutations for 1 Files created\n  x.xx.x.\n",
    ]);

    expect($codigo)->toBe(1)
        ->and($texto)->toContain('la parte b no tiene la linea «Mutations:»')
        ->and($texto)->toContain('Sin puntuacion global');
})->group('RQ-10');

it('no aprueba una mutacion global sin ningun mutante ni un estado de mutante que no conoce', function (): void {
    [$vacio, $textoVacio] = mutationLogScriptTotal(80, [
        'a' => mutationLogScriptPestOutput('0 tested', '0.00%'),
    ]);
    [$raro, $textoRaro] = mutationLogScriptTotal(80, [
        'a' => mutationLogScriptPestOutput('3 flaky, 97 tested', '97.00%'),
    ]);

    expect($vacio)->toBe(1)
        ->and($textoVacio)->toContain('ninguna parte ha generado mutantes')
        ->and($raro)->toBe(1)
        ->and($textoRaro)->toContain('estado de mutante desconocido');
})->group('RQ-10');

it('lista los vivos de todas las partes en el resumen global', function (): void {
    [$codigo, $texto] = mutationLogScriptTotal(80, [
        'a' => mutationLogScriptPestOutput('2 untested, 98 tested', '98.00%', "   UNTESTED  app/Modules/Shared/Domain/Money.php  > Line 3: Plus - ID: 1\n   UNTESTED  app/Modules/Shared/Domain/Money.php  > Line 4: Minus - ID: 2\n"),
        'b' => mutationLogScriptPestOutput('1 untested, 99 tested', '99.00%', "   UNTESTED  app/Modules/Product/Domain/Support/Licence.php  > Line 9: Equal - ID: 3\n"),
    ]);

    expect($codigo)->toBe(0)
        ->and($texto)->toContain('| 2 | `app/Modules/Shared/Domain/Money.php` |')
        ->and($texto)->toContain('| 1 | `app/Modules/Product/Domain/Support/Licence.php` |');
})->group('RQ-10');
