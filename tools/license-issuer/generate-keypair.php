#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Genera el par ed25519 del FABRICANTE (RF-PD-04, ADR-018, §7.7, RS-08).
 *
 * SE EJECUTA UNA VEZ EN LA VIDA DEL PRODUCTO, o cada vez que se rote el par.
 * No forma parte de ninguna imagen: este directorio no se copia al contenedor.
 *
 *     php tools/license-issuer/generate-keypair.php
 *     php tools/license-issuer/generate-keypair.php --secret-out=/ruta/segura/kronoqr.key
 *
 * QUE SALE POR DONDE, Y POR QUE IMPORTA:
 *
 *   - La CLAVE PUBLICA sale por la salida ESTANDAR, sola y sin adornos, para
 *     poder canalizarla a un fichero o a un portapapeles sin arrastrar nada mas.
 *     Se pega en `backend/config/license.php`, en el valor por defecto de
 *     `env('LICENSE_PUBLIC_KEY', '')`, antes de construir la imagen de release.
 *
 *   - La CLAVE PRIVADA **no sale por la salida estandar**. Va por la salida de
 *     error, o a un fichero creado con permisos `0600` si se indica
 *     `--secret-out`. El motivo es concreto: quien ejecute esto dentro de una
 *     tuberia, un `tee` o un script de despliegue acabaria con la clave privada
 *     del fabricante en un fichero de log, en el historico del shell o en la
 *     salida de un job de CI. Separar los dos canales hace que el descuido mas
 *     probable no tenga consecuencias.
 *
 * La privada se guarda en el gestor de secretos del fabricante y NO ENTRA JAMAS
 * EN EL REPOSITORIO. Quien la tenga puede emitir licencias validas para
 * cualquier cliente, con cualquier plan y cualquier vigencia.
 *
 * SOBRE ROTAR EL PAR. La clave publica viaja en el producto, asi que rotarla
 * exige publicar una version nueva y que cada cliente actualice. Mientras no lo
 * haga, sus claves nuevas no verificaran — y esa instalacion quedara degradada
 * en lo accesorio, nunca detenida (ADR-019). Conviene preverlo antes de
 * necesitarlo: la rotacion es un despliegue, no un correo.
 */

require __DIR__.'/src/LicenseIssuer.php';

use KronoQR\LicenseIssuer\LicenseIssuer;

$secretOut = null;

foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--secret-out=(.+)$/', $argument, $matches) === 1) {
        $secretOut = $matches[1];
    }
}

try {
    $pair = LicenseIssuer::generateKeyPair();
} catch (RuntimeException $failure) {
    fwrite(STDERR, 'ERROR: '.$failure->getMessage().PHP_EOL);
    exit(1);
}

fwrite(STDERR, PHP_EOL.'    Par ed25519 de emision de licencias de KronoQR'.PHP_EOL);
fwrite(STDERR, '    ============================================='.PHP_EOL.PHP_EOL);

if ($secretOut !== null) {
    // Se crea con 0600 ANTES de escribir nada: con `file_put_contents` a secas,
    // el fichero nace con la umask del proceso y hay una ventana en la que la
    // clave privada es legible por cualquiera de la maquina.
    $handle = @fopen($secretOut, 'x');

    if ($handle === false) {
        fwrite(STDERR, 'ERROR: no se ha podido crear '.$secretOut.'. Si ya existe, no se sobrescribe a proposito.'.PHP_EOL);
        exit(1);
    }

    @chmod($secretOut, 0o600);
    fwrite($handle, $pair['secret'].PHP_EOL);
    fclose($handle);

    fwrite(STDERR, '    CLAVE PRIVADA escrita en '.$secretOut.' con permisos 0600.'.PHP_EOL);
    fwrite(STDERR, '    Llevala al gestor de secretos y borra el fichero.'.PHP_EOL.PHP_EOL);
} else {
    fwrite(STDERR, '    CLAVE PRIVADA (al gestor de secretos; NUNCA al repositorio):'.PHP_EOL.PHP_EOL);
    fwrite(STDERR, '    '.$pair['secret'].PHP_EOL.PHP_EOL);
    fwrite(STDERR, '    Sale por la salida de ERROR a proposito, para que no acabe en un'.PHP_EOL);
    fwrite(STDERR, '    fichero por una tuberia. Con --secret-out=RUTA se escribe en un'.PHP_EOL);
    fwrite(STDERR, '    fichero 0600 y no se imprime.'.PHP_EOL.PHP_EOL);
}

fwrite(STDERR, '    CLAVE PUBLICA (sale por la salida estandar; va en'.PHP_EOL);
fwrite(STDERR, '    backend/config/license.php, en el valor por defecto de'.PHP_EOL);
fwrite(STDERR, "    env('LICENSE_PUBLIC_KEY', '')):".PHP_EOL.PHP_EOL);

fwrite(STDERR, '    Guarda la privada AHORA: no se puede recuperar, y perderla significa no'.PHP_EOL);
fwrite(STDERR, '    poder emitir ni renovar ninguna licencia hasta publicar una version con'.PHP_EOL);
fwrite(STDERR, '    un par nuevo. Las licencias ya emitidas seguirian funcionando.'.PHP_EOL.PHP_EOL);

fwrite(STDOUT, $pair['public'].PHP_EOL);

// La privada deja de estar en memoria antes de salir. Es barato y sensato en un
// proceso que puede volcar.
sodium_memzero($pair['secret']);
