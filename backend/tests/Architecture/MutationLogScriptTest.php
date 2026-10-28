<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
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
        ->and(substr_count($makefile, '$(PEST) $(MUTATE_OPTIONS) --path='))->toBe(3)
        ->and($makefile)->toContain('mutation-log.sh" canary "$(MUTATION_CANARY_EXPECTED)"');
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

    expect(preg_match('/^  mutation:\n(.*?)^  secrets-history:\n/ms', $ci, $mutation))->toBe(1);
    expect($mutation[1] ?? '')->toContain('run: make mutate-scope')
        ->and($mutation[1] ?? '')->toContain('tee "${RUNNER_TEMP}/mutacion-completa.log"')
        ->and($mutation[1] ?? '')->toContain('mutation-log.sh summary')
        ->and($mutation[1] ?? '')->toContain('name: mutacion-completa-log');

    expect(preg_match('/^  unit:\n(.*?)^  integration:\n/ms', $ci, $unit))->toBe(1);
    expect($unit[1] ?? '')->toContain('tee "${RUNNER_TEMP}/mutacion-acotada.log"')
        ->and($unit[1] ?? '')->toContain('name: mutacion-acotada-log');
})->group('RQ-10');
