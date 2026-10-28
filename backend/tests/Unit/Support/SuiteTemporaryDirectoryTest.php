<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Support\Shared\SuiteTemporaryDirectory;

/*
 * La suite no deja ficheros en el `/tmp` de la maquina (Q10 de la verificacion
 * de la 2.1.0). El porque esta en `SuiteTemporaryDirectory`.
 *
 * Las tres ultimas pruebas lanzan un `php` aparte porque lo que se comprueba es
 * lo que pasa cuando un proceso termina, y eso no se puede ver desde dentro del
 * propio proceso. Trabajan sobre una base propia dentro del directorio de la
 * pasada, asi que no tocan el `/tmp` de nadie.
 */

/**
 * Un `php` que instala el directorio de la suite sobre `$base`, deja un fichero
 * dentro y un subdirectorio sin permisos, imprime la ruta y termina.
 */
function suiteTemporaryDirectoryChild(string $base): string
{
    $script = 'require '.var_export(dirname(__DIR__, 3).'/vendor/autoload.php', true).';'
        .'$d = Tests\Support\Shared\SuiteTemporaryDirectory::install();'
        .'file_put_contents(sys_get_temp_dir()."/plantilla.csv", "x");'
        .'mkdir($d."/cerrado"); touch($d."/cerrado/dentro"); chmod($d."/cerrado", 0);'
        .'echo $d;';

    $process = new Process([PHP_BINARY, '-r', $script], null, ['TMPDIR' => $base]);
    $process->mustRun();

    return $process->getOutput();
}

function suiteTemporaryDirectorySandbox(): string
{
    $base = sys_get_temp_dir().'/base-'.bin2hex(random_bytes(4));
    mkdir($base);

    return $base;
}

it('apunta el temporal de la pasada a un directorio propio, no al del sistema', function (): void {
    $temporal = sys_get_temp_dir();

    expect(basename($temporal))->toStartWith(SuiteTemporaryDirectory::PREFIX.getmypid().'-')
        ->and(getenv('TMPDIR'))->toBe($temporal)
        ->and(is_dir($temporal))->toBeTrue();
})->group('RQ-12');

it('borra el directorio de la pasada, con todo lo que tenga dentro, cuando termina el proceso', function (): void {
    $base = suiteTemporaryDirectorySandbox();

    $directorioDelHijo = suiteTemporaryDirectoryChild($base);

    expect($directorioDelHijo)->toStartWith($base.'/'.SuiteTemporaryDirectory::PREFIX)
        ->and(file_exists($directorioDelHijo))->toBeFalse()
        ->and(scandir($base))->toBe(['.', '..']);
})->group('RQ-12');

it('recoge lo que dejo una pasada muerta y respeta el de una pasada viva', function (): void {
    $base = suiteTemporaryDirectorySandbox();
    // 2147483646 no es un pid posible: el maximo de Linux es 4194304.
    $muerta = $base.'/'.SuiteTemporaryDirectory::PREFIX.'2147483646-0badc0de';
    $viva = $base.'/'.SuiteTemporaryDirectory::PREFIX.getmypid().'-0badc0de';
    mkdir($muerta);
    touch($muerta.'/kq-import-abandonado');
    mkdir($viva);

    suiteTemporaryDirectoryChild($base);

    expect(file_exists($muerta))->toBeFalse()
        ->and(is_dir($viva))->toBeTrue();
})->group('RQ-12');

it('no deja que un hijo de pcntl_fork que termina con exit borre el directorio de su padre', function (): void {
    // Los hijos heredan la funcion de apagado; sin comparar el pid, el `exit`
    // del hijo borraria el directorio del padre a mitad de la pasada. El
    // padre espera al hijo, mira si su directorio sigue y termina, y entonces
    // si lo borra.
    $base = suiteTemporaryDirectorySandbox();
    $script = 'require '.var_export(dirname(__DIR__, 3).'/vendor/autoload.php', true).';'
        .'$d = Tests\Support\Shared\SuiteTemporaryDirectory::install();'
        .'$hijo = pcntl_fork();'
        .'if ($hijo === 0) { exit(0); }'
        .'pcntl_waitpid($hijo, $estado);'
        .'echo $d, "\n", is_dir($d) ? "sigue" : "borrado";';

    $proceso = new Process([PHP_BINARY, '-r', $script], null, ['TMPDIR' => $base]);
    $proceso->mustRun();
    [$directorioDelPadre, $trasElHijo] = explode("\n", $proceso->getOutput());

    expect($trasElHijo)->toBe('sigue')
        ->and(file_exists($directorioDelPadre))->toBeFalse();
})->group('RQ-12');
