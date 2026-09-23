<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Support;

/**
 * **Que conjunto de datos personales se ha divulgado** al generar un informe
 * (RS-05, regla dura 6).
 *
 * ## Por que no basta con `period_report`
 *
 * El asiento de `personal_data.accessed` responde a «quien vio que datos de
 * quien», y ante una brecha (RL-15) hay que poder separar «RRHH miro el cuadro de
 * horas en pantalla» de «RRHH se llevo un fichero preparado para importarlo en el
 * programa de nomina». Son la misma consulta y el mismo calculo —la salida a
 * nomina **es** el informe por periodo pasado por una plantilla— pero no son la
 * misma divulgacion: el segundo sale de la instalacion hacia otro sistema.
 *
 * Con un solo valor, esa distincion habria que deducirla del `format`, y `csv`
 * significa cosas distintas en los dos endpoints.
 *
 * ## Los valores son vocabulario estable
 *
 * En ingles y en minusculas porque acaban escritos en `audit_log` con cuatro años
 * de retencion. No se traducen: lo que traduce una persona es el rotulo de una
 * pantalla, no lo que queda en el trail.
 */
enum ReportDataset: string
{
    /** `GET /api/v1/reports/period` y su descarga (RF-IN-01..04). */
    case PeriodReport = 'period_report';

    /** `GET /api/v1/reports/payroll-export` y su generacion en diferido (RF-IN-07). */
    case PayrollExport = 'payroll_export';
}
