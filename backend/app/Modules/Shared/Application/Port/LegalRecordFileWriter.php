<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

use App\Modules\Shared\Domain\ValueObject\LegalRecordFile;
use Closure;

/**
 * Escribe la exportacion para la Inspeccion en un fichero, para la generacion
 * en diferido (**RF-IN-05**, RL-06, V5-PA-4).
 *
 * ## Por que un puerto de `Shared`
 *
 * El escritor y el origen de la exportacion legal son de `Compliance`, y quien
 * la genera en diferido es la cola de informes de `Reporting`. Ningun modulo
 * importa clases de otro (Deptrac): `Compliance` lo implementa y `Reporting` lo
 * usa, igual que {@see PersonalDataAccessLog}. **El fichero es el mismo** que el
 * de `GET /reports/legal-export`: mismas filas, mismo orden, mismo formato y la
 * misma cabecera de criterios.
 *
 * ## Lo que NO hace: el asiento
 *
 * La descarga sincrona escribe `legal_export.generated` dentro de su propia
 * transaccion. Aqui no: el asiento lo deja `Reporting` **al cerrar la fila**,
 * a nombre de quien la pidio y en la misma transaccion que
 * `report_export.generated`. Si el cierre falla, el fichero se borra y no queda
 * asiento de una exportacion que nadie recibio; si el asiento falla, el cierre
 * no ocurre (regla dura 6).
 *
 * ## El avance, y la forma de pararlo
 *
 * `$onProgress` recibe las filas escritas y las que se esperan —contadas antes
 * de empezar—, al principio y cada pocos cientos de filas. **Si lanza, la
 * escritura se detiene** y la excepcion sale tal cual: es como la cola
 * cancela una exportacion en curso. Quien implementa no la captura ni la
 * traduce.
 */
interface LegalRecordFileWriter
{
    /**
     * @param  string  $from  Primera jornada (`work_date`), `AAAA-MM-DD`.
     * @param  string  $to  Ultima jornada, inclusive.
     * @param  string|null  $employeeUuid  Una persona, o nulo para la plantilla completa.
     * @param  string  $destinationPath  Ruta absoluta del fichero. La elige quien llama.
     * @param  Closure(int, int): void  $onProgress  Filas escritas y filas esperadas.
     */
    public function write(
        string $from,
        string $to,
        ?string $employeeUuid,
        string $destinationPath,
        Closure $onProgress,
    ): LegalRecordFile;

    /** El nombre del fichero de ese periodo, antes de escribirlo. */
    public function fileNameFor(string $from, string $to): string;
}
