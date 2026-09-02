<?php

declare(strict_types=1);

namespace Tests\Support\Workforce;

use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Ficheros de plantilla para las pruebas de importacion (RF-GP-05).
 *
 * **Ficheros de verdad en disco, no dobles del lector.** El importador se apoya
 * en la deteccion del delimitador y de la codificacion, y las dos se hacen sobre
 * bytes reales: con un doble, las dos decisiones que mas incidencias evitan
 * —el `;` del Excel espanol y el `Windows-1252` de «Guardar como CSV»— quedarian
 * sin probar.
 *
 * Los datos son ficticios y ninguno viene de un cliente (regla dura 13).
 */
final class ImportFiles
{
    /**
     * Un CSV con el contenido dado, ya subido.
     */
    public static function csv(string $contents, string $name = 'plantilla.csv'): UploadedFile
    {
        return self::upload($contents, $name);
    }

    /**
     * El mismo contenido en `Windows-1252`, que es lo que produce «Guardar como
     * CSV» en un Windows en espanol.
     */
    public static function latin1Csv(string $contents, string $name = 'plantilla.csv'): UploadedFile
    {
        $converted = mb_convert_encoding($contents, 'Windows-1252', 'UTF-8');

        return self::upload($converted, $name);
    }

    /**
     * La cabecera y las filas habituales, con el separador que se indique.
     *
     * @param  list<list<string>>  $rows
     * @param  list<string>  $headers
     */
    public static function rows(array $headers, array $rows, string $delimiter = ','): string
    {
        $lines = [implode($delimiter, $headers)];

        foreach ($rows as $row) {
            $lines[] = implode($delimiter, $row);
        }

        return implode("\n", $lines)."\n";
    }

    private static function upload(string $contents, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'kq-import-');

        if ($path === false) {
            throw new RuntimeException('No se ha podido crear el fichero temporal de la prueba.');
        }

        file_put_contents($path, $contents);

        // `test: true` para que Laravel no exija que venga de una subida real de
        // PHP; el fichero de disco es autentico y es lo que el lector abre.
        return new UploadedFile($path, $name, null, null, true);
    }
}
