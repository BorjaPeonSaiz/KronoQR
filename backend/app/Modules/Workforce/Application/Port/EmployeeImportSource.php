<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Port;

use App\Modules\Workforce\Domain\Exception\UnreadableImportFile;

/**
 * Lee el fichero de plantilla, **en streaming** (RF-GP-05, doc 02 §3.1).
 *
 * ## Por que un puerto
 *
 * Porque leer CSV y XLSX es infraestructura —`spatie/simple-excel` por debajo— y
 * el caso de uso no puede importarla (doc 02 §3.5). Y porque asi una prueba del
 * mapeo de columnas y de las reglas de validacion se escribe con un array, sin
 * fichero y sin base de datos.
 *
 * ## Devuelve las cabeceras **tal cual vienen**
 *
 * Sin normalizar y sin traducir. La correspondencia entre la cabecera del
 * fichero y el campo del producto es **configuracion** (regla dura 13,
 * `config/workforce.php`), y resolverla aqui la escondaria en la infraestructura:
 * el dia que un cliente necesite un alias propio habria que tocar el adaptador.
 * Aqui se lee, alli se decide.
 *
 * ## `maxRows` se pasa a la lectura, no se comprueba despues
 *
 * Un fichero de un millon de filas no se lee entero para luego decir que era
 * demasiado grande: se deja de leer al llegar al tope, y quien llama se entera
 * por {@see self::wasTruncated()}. Es la diferencia entre un `422` en dos
 * segundos y un proceso de PHP muerto sin mensaje.
 */
interface EmployeeImportSource
{
    /**
     * Huella del fichero recibido.
     *
     * Es lo que la fase de validacion devuelve y la de aplicacion tiene que
     * repetir: garantiza que **se aplica exactamente lo que se reviso** sin
     * guardar el fichero en el servidor entre las dos fases, que seria dejar
     * nombres y documentos de identidad en disco esperando a que alguien
     * confirme.
     *
     * @throws UnreadableImportFile
     */
    public function checksum(string $path): string;

    /**
     * Las cabeceras del fichero, en orden y sin tocar.
     *
     * @return list<string>
     *
     * @throws UnreadableImportFile
     */
    public function headers(string $path): array;

    /**
     * Las filas de datos, indexadas por el **numero de linea del fichero**
     * (la primera fila de datos es la 2, porque la 1 es la cabecera).
     *
     * Cada fila viene indexada por su cabecera original, con los valores como
     * cadenas ya recortadas.
     *
     * @return iterable<int, array<string, string>>
     *
     * @throws UnreadableImportFile
     */
    public function rows(string $path, int $maxRows): iterable;

    /**
     * Si la ultima lectura se detuvo por llegar al tope.
     *
     * Se pregunta despues de recorrer {@see self::rows()} y no antes: saberlo
     * exigiria contar las filas, que es leer el fichero dos veces.
     */
    public function wasTruncated(): bool;
}
