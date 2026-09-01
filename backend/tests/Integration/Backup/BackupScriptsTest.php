<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Architecture\Support\Repo;

/*
 * Los scripts de copia, ejecutados de verdad (RF-PR-04, RL-12).
 *
 * Aqui se ejecuta el CIFRADO real del producto —las mismas funciones que usa
 * la copia diaria— y los caminos de fallo que tienen que dejar el sistema como
 * estaba. No se toca ninguna base de datos: el ciclo completo (copia,
 * verificacion y restauracion en contenedor limpio sobre PostgreSQL 17) lo
 * ejecuta `.github/workflows/backup-drill.yml`, que es donde hay un servidor.
 *
 * Por que probar shell desde Pest. Porque es codigo del producto (doc 02 §3.5)
 * y porque estas tres propiedades —que el fichero no se lee sin la clave, que
 * un fallo no destruye la copia anterior y que los codigos de salida son los
 * documentados— son justo las que nadie vuelve a comprobar a mano despues del
 * primer dia.
 */

const CLAVE_DE_PRUEBA = 'clave_de_prueba_de_la_suite_1234567890';

/**
 * Ejecuta un fragmento de bash con la biblioteca de copia ya cargada.
 *
 * @param  array<string, string>  $env
 */
function bashConLaBiblioteca(string $script, array $env = []): Process
{
    $lib = Repo::file('infra/scripts/lib/backup-common.sh');

    $process = Process::fromShellCommandline(
        'bash -c '.escapeshellarg('set -euo pipefail; . '.escapeshellarg($lib).'; '.$script),
        env: array_merge(['BACKUP_ENCRYPTION_KEY' => CLAVE_DE_PRUEBA], $env),
        timeout: 60.0,
    );
    $process->run();

    return $process;
}

/**
 * @param  list<string>  $argumentos
 * @param  array<string, string>  $env
 */
function ejecutarScript(string $script, array $argumentos, array $env = []): Process
{
    $process = new Process(
        ['bash', Repo::file('infra/scripts/'.$script), ...$argumentos],
        env: $env,
        timeout: 120.0,
    );
    $process->run();

    return $process;
}

it('cifra la copia de forma que no se puede leer sin la clave', function (): void {
    // RL-12: cifrado en reposo de las copias. La comprobacion no es que el
    // fichero "parezca" cifrado, sino que el contenido original NO aparece y
    // que solo la clave correcta lo devuelve.
    $trabajo = sys_get_temp_dir().'/kronoqr-cifrado-'.bin2hex(random_bytes(4));
    mkdir($trabajo, 0o700, true);
    $claro = $trabajo.'/claro.txt';
    $cifrado = $trabajo.'/cifrado.enc';
    file_put_contents($claro, "PGDMP fichaje shift_entries employee_code\n");

    $cifrar = bashConLaBiblioteca(
        'encrypt_stream <'.escapeshellarg($claro).' >'.escapeshellarg($cifrado)
    );
    expect($cifrar->isSuccessful())->toBeTrue($cifrar->getErrorOutput());

    $contenido = (string) file_get_contents($cifrado);
    expect($contenido)->toStartWith('Salted__');
    expect($contenido)->not->toContain('PGDMP');
    expect($contenido)->not->toContain('shift_entries');
    expect($contenido)->not->toContain('employee_code');
    // Y la clave tampoco esta dentro del fichero, que seria el chiste facil.
    expect($contenido)->not->toContain(CLAVE_DE_PRUEBA);

    $descifrar = bashConLaBiblioteca('decrypt_stream <'.escapeshellarg($cifrado));
    expect($descifrar->isSuccessful())->toBeTrue();
    expect($descifrar->getOutput())->toBe((string) file_get_contents($claro));

    $conOtraClave = bashConLaBiblioteca(
        'decrypt_stream <'.escapeshellarg($cifrado),
        ['BACKUP_ENCRYPTION_KEY' => 'esta_no_es_la_clave_correcta_0000']
    );
    expect($conOtraClave->isSuccessful())->toBeFalse('Una clave equivocada ha descifrado la copia.');

    array_map('unlink', glob($trabajo.'/*') ?: []);
    rmdir($trabajo);
})->group('RL-12', 'RF-PR-04');

it('se niega a empezar sin clave de cifrado, y lo dice', function (): void {
    // RL-12: sin clave no hay copia. El mensaje tiene que decir que hacer y no
    // puede contener ningun secreto.
    //
    // CODIGO 2 desde la tarea 5.4, antes 5. La clave ausente o demasiado corta
    // es un REQUISITO no cumplido comprobado antes de tocar nada, que es lo que
    // significa el 2 en la tabla comun de los cinco scripts
    // (infra/scripts/lib/exit-codes.sh). El 5 pasa a significar otra cosa muy
    // distinta —vuelta atras incompleta, con alguien teniendo que intervenir— y
    // dejarlo aqui haria que un cron no pudiera distinguir «te falta la clave»
    // de «he dejado la copia a medias».
    $sinClave = bashConLaBiblioteca('require_encryption_key', ['BACKUP_ENCRYPTION_KEY' => '']);

    expect($sinClave->getExitCode())->toBe(2)
        ->and($sinClave->getErrorOutput())
        ->toContain('BACKUP_ENCRYPTION_KEY')
        ->toContain('install.sh');

    $claveCorta = bashConLaBiblioteca('require_encryption_key', ['BACKUP_ENCRYPTION_KEY' => 'corta']);
    expect($claveCorta->getExitCode())->toBe(2)
        ->and($claveCorta->getErrorOutput())->toContain('openssl rand');
})->group('RL-12');

it('no escribe nada si el destino de copias no existe o no es escribible', function (): void {
    // Fallo seguro (doc 02 §3.5): los requisitos se comprueban ANTES de tocar
    // nada.
    //
    // CODIGO 2 desde la tarea 5.4, antes 4. «Nada escrito» es exactamente lo
    // que afirma el 2 de la tabla comun; el 4 pasa a significar «fallo con
    // vuelta atras completada», que es un caso distinto: alli SI se llego a
    // escribir algo y se deshizo.
    $inexistente = sys_get_temp_dir().'/kronoqr-destino-que-no-existe-'.bin2hex(random_bytes(4));

    $resultado = bashConLaBiblioteca(
        'load_backup_config; ensure_backup_tree',
        ['BACKUP_PATH' => $inexistente]
    );

    expect($resultado->getExitCode())->toBe(2)
        ->and($resultado->getErrorOutput())->toContain($inexistente)
        ->and(is_dir($inexistente))->toBeFalse('Ha creado el destino en vez de avisar.');
})->group('RF-PR-04');

it('devuelve los codigos de salida documentados ante un uso incorrecto', function (): void {
    // Los ejecuta gente que no conoce el sistema, con un problema delante: un
    // argumento mal escrito no puede parecerse a un fallo de la copia.
    //
    // CODIGO 1 desde la tarea 5.4, antes 2. Es el mismo numero que usan
    // install.sh, update.sh, doctor.sh y restore.sh para «uso incorrecto», y
    // esa es toda la razon: los cinco los teclea la misma persona.
    $ordenDesconocida = ejecutarScript('backup.sh', ['naoquesea']);
    expect($ordenDesconocida->getExitCode())->toBe(1)
        ->and($ordenDesconocida->getErrorOutput())->toContain('run, verify, prune o list');

    $modoInvalido = ejecutarScript('backup.sh', ['run', '--mode', 'naoquesea']);
    expect($modoInvalido->getExitCode())->toBe(1);

    $ayuda = ejecutarScript('backup.sh', ['--help']);
    expect($ayuda->getExitCode())->toBe(0)
        ->and($ayuda->getOutput())->toContain('backup.sh run');

    // restore.sh no restaura nada sin confirmacion explicita.
    $sinConfirmar = ejecutarScript('restore.sh', ['--help']);
    expect($sinConfirmar->getExitCode())->toBe(0)
        ->and($sinConfirmar->getOutput())->toContain('--dry-run');
})->group('RF-PR-04');

it('no imprime la clave de cifrado por ninguna via', function (): void {
    // Regla dura 21 y §7.7: ningun secreto en la salida. La clave viaja por un
    // descriptor de fichero o por el entorno, nunca por la linea de ordenes,
    // donde `ps` la veria desde cualquier sesion del servidor.
    $salida = bashConLaBiblioteca(
        'require_encryption_key; openssl_pass_spec; echo; encrypt_stream </dev/null | wc -c'
    );

    expect($salida->isSuccessful())->toBeTrue()
        ->and($salida->getOutput().$salida->getErrorOutput())->not->toContain(CLAVE_DE_PRUEBA);

    // Y en el codigo tampoco hay ninguna via que la pase como argumento.
    $fuentes = ['backup.sh', 'restore.sh', 'restore-drill.sh', 'lib/backup-common.sh'];
    foreach ($fuentes as $fichero) {
        expect(Repo::contents('infra/scripts/'.$fichero))->not->toContain('-pass pass:');
    }
})->group('RL-12');
