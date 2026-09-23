<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Export;

use App\Modules\Reporting\Application\Port\ReportCriteriaNarrator;
use App\Modules\Reporting\Domain\ValueObject\PeriodReport;

/**
 * Traduce los criterios con **las mismas lineas** que escriben los ficheros
 * (**RF-IN-06**).
 *
 * ## Por que delega en `PeriodReportLayout` y no repite nada
 *
 * Esas lineas ya existen: son las que el CSV pone en su bloque de cabecera y las
 * que el XLSX pone en la hoja «Criterios». Si esta clase compusiera las suyas,
 * el fichero diria una cosa y la pantalla otra sobre el mismo informe — y al
 * cabo de dos versiones nadie sabria cual era la buena.
 *
 * Incluye por tanto el aviso de cobertura de contrato cuando lo hay, que es
 * exactamente lo que hace falta: un informe comparado contra un contrato que no
 * existe sale con una desviacion enorme y con aspecto de dato bueno, y esa
 * advertencia tiene que viajar con la exportacion.
 */
final readonly class PeriodReportCriteriaNarrator implements ReportCriteriaNarrator
{
    /**
     * @return list<string>
     */
    public function linesFor(PeriodReport $report): array
    {
        return PeriodReportLayout::criteria($report);
    }
}
