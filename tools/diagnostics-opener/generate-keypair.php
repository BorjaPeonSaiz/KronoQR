#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Genera el par X25519 de SOPORTE del fabricante, con el que los clientes
 * cifran el paquete de diagnostico con datos personales (RL-19, RS-08; ADR-020,
 * enmienda del 01-11-2026).
 *
 * NO ES EL PAR DE LICENCIAS (`tools/license-issuer/`). Con la privada de
 * soporte se abren paquetes; con la de licencias se emiten licencias. Separarlos
 * evita que el equipo de soporte pueda emitir licencias, y que el compromiso de
 * uno arrastre al otro.
 *
 *     php tools/diagnostics-opener/generate-keypair.php --keyring=~/.kronoqr/diagnostics
 *     php tools/diagnostics-opener/generate-keypair.php
 *
 * QUE SALE POR DONDE:
 *
 *   - La CLAVE PUBLICA, por la salida ESTANDAR, sola: se pega como valor por
 *     defecto de `env('DIAGNOSTICS_RECIPIENT_PUBLIC_KEY', '')` en
 *     `backend/config/product.php` antes de construir la imagen de release.
 *     `make release-gate` falla mientras este vacia.
 *
 *   - La CLAVE PRIVADA, con `--keyring=DIR`, a `DIR/<key_id>.key` (directorio
 *     0700, fichero 0600, nunca sobrescribe): es el llavero que lee `open.php`.
 *     Sin `--keyring`, por la salida de ERROR, para que no acabe en un fichero
 *     por una tuberia.
 *
 * La privada NO ENTRA JAMAS EN EL REPOSITORIO. Quien la tenga puede leer la
 * plantilla y los fichajes de cualquier paquete con datos personales que un
 * cliente haya enviado con esa clave.
 *
 * CODIGOS DE SALIDA: 0 generado; 1 no se pudo escribir en el llavero; 2
 * argumento desconocido.
 */

require __DIR__.'/src/InvalidEnvelope.php';
require __DIR__.'/src/EnvelopeNotOpened.php';
require __DIR__.'/src/DiagnosticsOpener.php';

use KronoQR\DiagnosticsOpener\DiagnosticsOpener;

$keyring = null;

foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--keyring=(.+)$/', $argument, $matches) === 1) {
        $user = getenv('HOME');
        $keyring = str_starts_with($matches[1], '~/') && is_string($user) ? $user.substr($matches[1], 1) : $matches[1];
    } else {
        fwrite(STDERR, 'ERROR: argumento desconocido: '.$argument.PHP_EOL);
        exit(2);
    }
}

$pair = DiagnosticsOpener::generateKeyPair();

fwrite(STDERR, PHP_EOL.'    Par X25519 de soporte de KronoQR (paquete de diagnostico)'.PHP_EOL);
fwrite(STDERR, '    ========================================================='.PHP_EOL.PHP_EOL);
fwrite(STDERR, '    Identificador de la clave: '.$pair['key_id'].PHP_EOL.PHP_EOL);

if ($keyring !== null) {
    if (! is_dir($keyring) && ! @mkdir($keyring, 0o700, true) && ! is_dir($keyring)) {
        fwrite(STDERR, 'ERROR: no se ha podido crear el llavero '.$keyring.'.'.PHP_EOL);
        exit(1);
    }

    $file = rtrim($keyring, '/').'/'.$pair['key_id'].'.key';
    $previous = umask(0o077);
    $handle = @fopen($file, 'x');
    umask($previous);

    if ($handle === false) {
        fwrite(STDERR, 'ERROR: no se ha podido crear '.$file.'. Si ya existe, no se sobrescribe a proposito.'.PHP_EOL);
        exit(1);
    }

    fwrite($handle, $pair['secret'].PHP_EOL);
    fclose($handle);
    @chmod($file, 0o600);

    fwrite(STDERR, '    CLAVE PRIVADA escrita en '.$file.' (0600). Copia el llavero al'.PHP_EOL);
    fwrite(STDERR, '    gestor de secretos de soporte: perderla es no poder abrir ningun'.PHP_EOL);
    fwrite(STDERR, '    paquete cifrado con esta clave.'.PHP_EOL.PHP_EOL);
} else {
    fwrite(STDERR, '    CLAVE PRIVADA (al llavero de soporte; NUNCA al repositorio):'.PHP_EOL.PHP_EOL);
    fwrite(STDERR, '    '.$pair['secret'].PHP_EOL.PHP_EOL);
    fwrite(STDERR, '    Guardala como <identificador>.key en el directorio del llavero.'.PHP_EOL.PHP_EOL);
}

fwrite(STDERR, '    CLAVE PUBLICA (sale por la salida estandar; va en backend/config/product.php,'.PHP_EOL);
fwrite(STDERR, "    en el valor por defecto de env('DIAGNOSTICS_RECIPIENT_PUBLIC_KEY', '')):".PHP_EOL.PHP_EOL);

fwrite(STDOUT, $pair['public'].PHP_EOL);

sodium_memzero($pair['secret']);
