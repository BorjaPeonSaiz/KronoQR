<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Port;

use App\Modules\Reporting\Domain\ValueObject\AdoptionReport;
use App\Modules\Reporting\Domain\ValueObject\WrittenAdoptionReport;

/**
 * Escribe el cuadro de impacto en un fichero, para la generacion en diferido
 * (**RF-IN-08**).
 *
 * **El mismo documento** que `GET /reports/adoption/export`: el mismo bloque de
 * criterios, la misma huella del contenido y, en PDF, el mismo sello. Lo unico
 * que cambia es el destino —un fichero de la cola en vez del cuerpo de una
 * respuesta—.
 */
interface AdoptionReportFileWriter
{
    /** `kronoqr-adopcion-<desde>_<hasta>.<ext>`, sin nombres (regla dura 21). */
    public function fileNameFor(AdoptionReport $report, string $format): string;

    /**
     * @param  string  $format  `csv`, `xlsx` o `pdf`, ya acotado por `ReportExportKind::allows()`.
     * @param  string|null  $issuerUuid  La cuenta que lo pidio, para el pie del documento.
     */
    public function write(AdoptionReport $report, string $format, string $path, ?string $issuerUuid): WrittenAdoptionReport;
}
