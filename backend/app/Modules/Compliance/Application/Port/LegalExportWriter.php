<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Port;

use App\Modules\Compliance\Domain\ValueObject\LegalExportManifest;
use App\Modules\Compliance\Domain\ValueObject\LegalExportRecord;
use App\Modules\Compliance\Domain\ValueObject\LegalExportTally;

/**
 * Quien escribe el documento que se entrega (RL-06, plan 1.17 paso 4).
 *
 * ## El formato es una decision del requisito, no del adaptador
 *
 * RL-06 exige «formato tabular legible y tratable, no propietario». El
 * adaptador de la Fase 1 escribe CSV en UTF-8 con BOM y separador `;`, con las
 * horas como texto `HH:MM` y **nunca en decimal**. Que sea un puerto y no una
 * llamada directa es lo que permite que las exportaciones ofimaticas de
 * conveniencia de la tarea 2.9 —que son otra cosa y otro requisito— no tengan
 * que reabrir este camino.
 *
 * ## Devuelve el recuento porque es quien lo sabe
 *
 * El caso de uso no puede contar las filas sin recorrer el `iterable`, y
 * recorrerlo dos veces significaria consultar dos veces. Quien escribe es quien
 * cuenta, y ese recuento es lo que despues afirma el asiento de `audit_log`: el
 * numero del trail es el numero de filas que de verdad se escribieron, no una
 * estimacion previa.
 *
 * ## Escribe a un fichero y no a la respuesta HTTP
 *
 * Deliberado. Si la exportacion se transmitiera mientras se genera, las
 * cabeceras saldrian antes de saber si termino, y el asiento de auditoria se
 * escribiria despues de haber entregado los datos —o no se escribiria, si algo
 * fallara a mitad—. Con el fichero cerrado antes de responder, la garantia de la
 * regla dura 6 se mantiene: si la traza falla, la descarga no ocurre. El coste es
 * un fichero temporal que se borra al enviarlo, no memoria.
 */
interface LegalExportWriter
{
    /**
     * @param  string  $destinationPath  Ruta absoluta del fichero a escribir. La elige quien
     *                                   llama: un temporal para la descarga, una ruta del
     *                                   servidor para el comando de consola.
     * @param  iterable<int, LegalExportRecord>  $records
     */
    public function write(
        LegalExportManifest $manifest,
        string $destinationPath,
        iterable $records,
    ): LegalExportTally;
}
