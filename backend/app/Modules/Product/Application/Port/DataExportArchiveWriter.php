<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Port;

use App\Modules\Product\Domain\ValueObject\DataExportArchive;
use App\Modules\Product\Domain\ValueObject\DataExportFile;
use App\Modules\Product\Domain\ValueObject\ExportedDataset;

/**
 * Quien escribe los ficheros del ZIP en el disco de la instalacion
 * (**RF-PD-14**, RL-20).
 *
 * ## Cinco metodos y no uno, porque el orden importa
 *
 * Un unico `write(todo)` obligaria a tener todo «lo que hay que escribir» a la
 * vez, que es justo lo que no cabe en memoria. Con este reparto, quien orquesta
 * abre un directorio de trabajo, escribe un conjunto fila a fila —cediendo la
 * memoria de cada lote antes de pedir el siguiente—, y solo al final comprime
 * ficheros **ya escritos**.
 *
 * El `manifest.json` se escribe el ultimo a proposito: no se puede componer
 * antes, porque lleva el recuento y la huella de cada fichero.
 *
 * ## El directorio de trabajo se borra pase lo que pase
 *
 * {@see self::discard()} se llama tambien cuando la generacion falla. Sin eso, un
 * fallo a mitad dejaria en el disco del cliente un `employees.csv` suelto con la
 * plantilla entera y sin nada que diga de donde salio ni cuando borrarlo.
 *
 * ## Permisos
 *
 * Directorio `0700` y fichero `0600`, como el paquete de diagnostico y por una
 * razon aun mas fuerte: aquel puede llevar datos personales y **este los lleva
 * siempre**. Un ZIP legible por cualquier cuenta del servidor seria la plantilla
 * completa al alcance de quien pase por ahi.
 */
interface DataExportArchiveWriter
{
    /**
     * Prepara un directorio de trabajo vacio y devuelve su ruta absoluta.
     *
     * Se nombra con el `uuid` de la exportacion: dos generaciones no pueden
     * coincidir —el indice unico parcial lo impide— pero un directorio con
     * nombre propio hace que un resto olvidado se pueda atribuir a su fila.
     */
    public function begin(string $exportUuid): string;

    /**
     * Escribe un conjunto entero, fila a fila, y devuelve su recuento y su
     * huella.
     *
     * @param  iterable<array<string, mixed>>  $rows
     * @param  string  $locale  El idioma de la INSTALACION, no el de la peticion: decide el
     *                          delimitador que espera la hoja de calculo que abrira el CSV
     *                          (`CsvDialect::delimiterFor()`).
     */
    public function writeDataset(string $workspace, ExportedDataset $dataset, iterable $rows, string $locale): DataExportFile;

    /**
     * Escribe un documento ya compuesto: `manifest.json` y `README.md`.
     *
     * Devuelve tambien un {@see DataExportFile} para que su tamaño cuente en el
     * ZIP; su `rows` es cero, porque no son filas de datos y sumarlas al total
     * falsearia el recuento que el cliente compara contra su base de datos.
     */
    public function writeDocument(string $workspace, string $fileName, string $contents): DataExportFile;

    /**
     * Comprime el directorio de trabajo, calcula la huella del ZIP y lo deja en
     * su ubicacion definitiva.
     */
    public function seal(string $workspace, string $fileName): DataExportArchive;

    /** Borra el directorio de trabajo. Se llama tambien cuando algo ha fallado. */
    public function discard(string $workspace): void;

    /**
     * Borra un ZIP ya generado (purga por caducidad).
     *
     * Devuelve `true` si el fichero ya no esta —lo haya borrado esta llamada o
     * lo hubiera borrado alguien antes—, porque a quien purga le da igual quien
     * lo quito: lo que necesita saber es si puede marcar la fila.
     */
    public function delete(string $path): bool;
}
