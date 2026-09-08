<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * En que formato sale cada conjunto de datos del ZIP (**RF-PD-14**, RL-20).
 *
 * ## CSV para lo que tiene muchas filas, JSON para lo que tiene una
 *
 * **CSV y no XLSX** (decision 1 de la ficha 5.10): XLSX topa en 1 048 576 filas
 * y cuatro años de `scan_events` de una plantilla de 500 personas lo superan; el
 * CSV lo abre cualquier hoja de calculo y lo carga cualquier base de datos. El
 * dialecto es el del producto —`Shared\Infrastructure\Export\CsvDialect`: BOM,
 * RFC 4180, `\r\n`— para que las exportaciones de KronoQR no diverjan entre si.
 *
 * **JSON para el centro, la configuracion, los perfiles de cumplimiento y la
 * licencia.** Son de una fila o de un puñado, y llevan dentro documentos
 * anidados —el calendario de festivos, la lista de funcionalidades, el valor de
 * cada ajuste— que en una celda de CSV serian una cadena que hay que volver a
 * interpretar a mano. Ahi el JSON no es una preferencia: es la unica forma de
 * que el dato salga tal cual es.
 */
enum DataExportFormat: string
{
    case Csv = 'csv';

    case Json = 'json';

    /** La extension del fichero dentro del ZIP. */
    public function extension(): string
    {
        return $this->value;
    }
}
