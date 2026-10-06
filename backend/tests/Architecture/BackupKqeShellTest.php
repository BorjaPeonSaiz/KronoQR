<?php

declare(strict_types=1);

use Tests\Architecture\Support\Repo;

/*
 * Las reglas de «ninguna clave en la linea de ordenes ni en el log» del formato KQE1
 * (ADR-049, condicion C1 del dictamen de seguridad), como configuracion.
 *
 * Un MAC o un cifrado con la clave en `argv` la deja a la vista de cualquier usuario del
 * anfitrion en `ps` (los procesos de un contenedor se ven desde fuera), y un `set -x` la
 * imprime en el log de PostgreSQL, que lee el IT del cliente. No hay otra prueba que lo
 * vea: la salida de los scripts es correcta con y sin fuga. Aqui se vigila lo que
 * la produciria:
 *
 *   · `set -x` (o cualquier `set -...x...`) en los ficheros que manejan la clave;
 *   · `-K` de `openssl enc` (clave hexadecimal en `argv`), `-hmac` y `hexkey:`
 *     (clave de un MAC en `argv`);
 *   · `/usr/bin/printf`, `env printf` o `echo` externo para pasar la clave: el `printf`
 *     de la shell es una orden interna y no crea proceso con la clave en `argv`.
 *
 * Los ficheros son los que ven la clave: la biblioteca y las cuatro herramientas de la
 * imagen de PostgreSQL. Las comprobaciones son sobre el texto, a proposito: es lo que
 * un cambio descuidado introduciria.
 */

/** @return list<string> */
function backupKqeShellFiles(): array
{
    return [
        'infra/scripts/lib/kqe.sh',
        'infra/docker/postgres/bin/archive-wal.sh',
        'infra/docker/postgres/bin/kronoqr-restore-wal',
        'infra/docker/postgres/bin/kronoqr-wal-migrate',
        'infra/docker/postgres/bin/kronoqr-extract-base',
    ];
}

it('existen los ficheros que manejan la clave de las copias', function (): void {
    foreach (backupKqeShellFiles() as $file) {
        expect(is_file(Repo::file($file)))->toBeTrue($file.' no existe: la prueba no vigilaria nada.');
    }
})->group('RL-12', 'RS-08');

it('no activan el trazado de la shell en los ficheros que ven la clave', function (): void {
    foreach (backupKqeShellFiles() as $file) {
        $lines = explode("\n", Repo::contents($file));

        foreach ($lines as $number => $line) {
            if (str_starts_with(ltrim($line), '#')) {
                continue;
            }
            expect(preg_match('/(^|[;&|\s])set\s+-[a-zA-Z]*x/', $line))->toBe(
                0,
                $file.':'.($number + 1).' activa `set -x`: imprimiria la clave en el log. '.trim($line),
            );
            expect(preg_match('/\bxtrace\b/', $line))->toBe(0, $file.':'.($number + 1).' activa xtrace.');
        }
    }
})->group('RL-12', 'RS-08');

it('no pasan la clave por la linea de ordenes de openssl', function (): void {
    foreach (backupKqeShellFiles() as $file) {
        $lines = explode("\n", Repo::contents($file));

        foreach ($lines as $number => $line) {
            if (str_starts_with(ltrim($line), '#')) {
                continue;
            }
            $where = $file.':'.($number + 1).' ('.trim($line).')';

            expect(preg_match('/\s-K\s/', $line))->toBe(0, $where.' usa `-K`: clave hexadecimal en argv.');
            expect(preg_match('/-hmac\b/', $line))->toBe(0, $where.' usa `-hmac`: clave del MAC en argv.');
            expect(preg_match('/hexkey:/', $line))->toBe(0, $where.' usa `hexkey:`: clave del MAC en argv.');
            expect(preg_match('#/usr/bin/printf|\benv\s+printf\b#', $line))->toBe(0, $where.' lanza `printf` como proceso: la clave iria en argv.');
            expect(preg_match('/-pass(word)?\s+pass:/', $line))->toBe(0, $where.' usa `-pass pass:`: clave en argv.');
        }
    }
})->group('RL-12', 'RS-08');

it('no exportan las variables internas de clave ni las dejan sin borrar', function (): void {
    $kqe = Repo::contents('infra/scripts/lib/kqe.sh');

    expect($kqe)->not->toMatch('/\bexport\s+_KQE_(PASS|KMAC)/');
    // Y las borran: `kqe_forget` existe y la usan los que abren o cifran.
    expect($kqe)->toContain('kqe_forget()');
    expect(substr_count($kqe, 'kqe_forget'))->toBeGreaterThanOrEqual(5);
})->group('RL-12', 'RS-08');

it('mantienen el contrato de salida del restore_command: 200 ante MAC malo, 1 solo en el final real', function (): void {
    $restore = Repo::contents('infra/docker/postgres/bin/kronoqr-restore-wal');

    // El estado de salida > 125 es lo que hace fatal la recuperacion en PostgreSQL.
    expect($restore)->toContain('readonly FATAL=200');
    expect($restore)->toContain('exit "$FATAL"');
    // Solo hay DOS `exit 1`: el de un nombre que no es de segmento ni de historia y el final real del
    // archivo (tras la segunda comprobacion y sin segmento posterior). Un tercero seria un fallo que
    // trunca la recuperacion en silencio.
    expect(preg_match_all('/^\s*exit 1$/m', $restore))->toBe(2);
})->group('RL-12', 'RNF-D-02');
