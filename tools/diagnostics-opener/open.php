#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Abre un paquete de diagnostico con datos personales (`*.sealed.json`) que un
 * cliente ha enviado a soporte (RL-19; ADR-020, enmienda del 01-11-2026).
 *
 *     php tools/diagnostics-opener/open.php <fichero.sealed.json> --keyring=~/.kronoqr/diagnostics [--output=RUTA]
 *
 * HERRAMIENTA DEL FABRICANTE. No viaja en ninguna imagen: `.dockerignore`
 * excluye `tools/`. El llavero es un directorio con una clave privada por
 * fichero `<key_id>.key` (`generate-keypair.php --keyring`), y se elige la que
 * pide el sobre por su `recipient_key_id`: tambien abre paquetes cifrados con
 * una clave anterior a una rotacion, si sigue en el llavero.
 *
 * SALIDA. El paquete en claro, en `--output` o junto al sobre con `.json` en
 * lugar de `.sealed.json`. Con permisos 0600 y NUNCA SOBRESCRIBE nada. Es una
 * copia de la plantilla de un cliente: se borra en cuanto deja de hacer falta.
 *
 * CODIGOS DE SALIDA:
 *   0  abierto, comprobado (huella integra, cabecera = contenido) y escrito
 *   1  no se puede abrir: falta la clave, no es la suya, el sobre esta alterado
 *      o cortado, lo descifrado no coincide con la cabecera, o no se puede
 *      escribir la salida (por ejemplo, porque ya existe)
 *   2  formato no valido: argumentos, fichero ilegible o que no es un sobre de
 *      esta version
 */

require __DIR__.'/src/InvalidEnvelope.php';
require __DIR__.'/src/EnvelopeNotOpened.php';
require __DIR__.'/src/DiagnosticsOpener.php';

use KronoQR\DiagnosticsOpener\DiagnosticsOpener;
use KronoQR\DiagnosticsOpener\EnvelopeNotOpened;
use KronoQR\DiagnosticsOpener\InvalidEnvelope;

/** `~/` al principio, que el shell no expande dentro de `--opcion=~/...`. */
$home = static function (string $path): string {
    $user = getenv('HOME');

    return str_starts_with($path, '~/') && is_string($user) ? $user.substr($path, 1) : $path;
};

$fail = static function (int $code, string $message): never {
    fwrite(STDERR, 'ERROR: '.$message.PHP_EOL);
    exit($code);
};

$input = null;
$keyring = null;
$output = null;

foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--keyring=(.+)$/', $argument, $matches) === 1) {
        $keyring = $home($matches[1]);
    } elseif (preg_match('/^--output=(.+)$/', $argument, $matches) === 1) {
        $output = $home($matches[1]);
    } elseif (! str_starts_with($argument, '--') && $input === null) {
        $input = $argument;
    } else {
        $fail(2, 'argumento desconocido: '.$argument);
    }
}

if ($input === null || $keyring === null) {
    $fail(2, 'uso: php tools/diagnostics-opener/open.php <fichero.sealed.json> --keyring=DIRECTORIO [--output=RUTA]');
}

$json = @file_get_contents($input);

if (! is_string($json)) {
    $fail(2, 'no se puede leer '.$input.'.');
}

$keys = DiagnosticsOpener::keyringFromDirectory($keyring);

if ($keys === []) {
    $fail(1, 'el llavero '.$keyring.' no tiene ninguna clave privada (*.key).');
}

try {
    $opened = DiagnosticsOpener::open($json, $keys);
} catch (InvalidEnvelope $failure) {
    $fail(2, $failure->getMessage());
} catch (EnvelopeNotOpened $failure) {
    $fail(1, $failure->getMessage());
}

$output ??= str_ends_with($input, '.sealed.json')
    ? substr($input, 0, -strlen('.sealed.json')).'.json'
    : $input.'.opened.json';

// 0600 desde que nace y sin sobrescribir: `x` falla si ya existe.
$previous = umask(0o077);
$handle = @fopen($output, 'x');
umask($previous);

if ($handle === false) {
    $fail(1, 'no se puede crear '.$output.'. Si ya existe, no se sobrescribe a proposito: borralo o usa --output.');
}

fwrite($handle, $opened['plaintext']);
fclose($handle);
@chmod($output, 0o600);

$manifest = $opened['manifest'];
$text = static fn (string $key): string => is_scalar($manifest[$key] ?? null) ? (string) $manifest[$key] : '?';
$sections = is_array($manifest['sections'] ?? null)
    ? implode(', ', array_filter($manifest['sections'], is_string(...)))
    : '?';

fwrite(STDOUT, 'Paquete abierto y comprobado (huella integra, la cabecera describe el contenido).'.PHP_EOL);
fwrite(STDOUT, '  Clave:     '.$opened['key_id'].PHP_EOL);
fwrite(STDOUT, '  Version:   '.$text('product_version').PHP_EOL);
fwrite(STDOUT, '  Generado:  '.$text('generated_at').' por '.$text('generated_by').PHP_EOL);
fwrite(STDOUT, '  Huella:    '.$text('sha256').PHP_EOL);
fwrite(STDOUT, '  Contiene:  '.$sections.PHP_EOL);
fwrite(STDOUT, '  En claro:  '.$output.' (0600)'.PHP_EOL);
fwrite(STDOUT, PHP_EOL.'ES UNA COPIA DE DATOS PERSONALES DE UN CLIENTE. Borrala en cuanto la incidencia no la necesite:'.PHP_EOL);
fwrite(STDOUT, '  shred -u '.escapeshellarg($output).PHP_EOL);
fwrite(STDOUT, 'Antes, confirma con el cliente que la huella coincide con su asiento diagnostics.bundle_generated.'.PHP_EOL);

exit(0);
