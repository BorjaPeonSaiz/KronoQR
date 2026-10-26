<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Generation;

use App\Modules\Reporting\Domain\Exception\ReportExportWasCancelled;

/**
 * Como se escribe el fichero de una clase de informe en diferido.
 *
 * Tres implementaciones —el informe por periodo y la nomina, la exportacion
 * para la Inspeccion y el cuadro de impacto— y un solo caso de uso que arranca
 * la fila, la cierra y la limpia si algo sale mal. Cada una:
 *
 * - **anota el fichero** en `ReportExportRun::claim()` antes de escribirlo;
 * - **avanza las fases** con `ReportExportRun::$progress`, que es tambien donde
 *   se detecta la cancelacion;
 * - **no abre transaccion de cierre ni publica nada**: eso es del caso de uso,
 *   para que el asiento y la fila cerrada vayan juntos.
 */
interface ReportExportGeneration
{
    /**
     * @throws ReportExportWasCancelled si alguien la cancelo mientras se escribia
     */
    public function generate(ReportExportRun $run): GeneratedReportFile;
}
