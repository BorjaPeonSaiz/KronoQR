<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use App\Modules\Reporting\Application\Port\PayrollDocumentWriter;
use App\Modules\Reporting\Domain\Exception\ReportExportWriteFailed;
use App\Modules\Reporting\Domain\Model\ReportExport;
use App\Modules\Reporting\Domain\ValueObject\PeriodReport;
use App\Modules\Reporting\Infrastructure\Export\ConfigurablePayrollDocumentWriter;

/**
 * Un {@see PayrollDocumentWriter} que **siempre falla**, para probar el desenlace
 * de una generacion de nomina que no se puede escribir (**RF-IN-07**).
 *
 * ## Por que vive en `tests/` y no en el arbol del producto
 *
 * Nacio en `Reporting\Infrastructure\Export` como «implementacion de reserva»
 * mientras la plantilla configurable no existia. Ya existe
 * ({@see ConfigurablePayrollDocumentWriter}),
 * asi que en produccion no lo enlazaba nadie: lo unico que hacia era **mentir en
 * su propio docblock** —decia ser la implementacion por omision— y aparecer en el
 * arbol como si el producto tuviera dos caminos de nomina cuando solo tiene uno.
 *
 * Aqui sirve para lo que de verdad sirve: sustituir el adaptador real en una
 * prueba y comprobar que un fallo de escritura deja la fila cerrada con motivo
 * `write_failed`, sin fichero a medias y sin bloquear a esa persona.
 *
 * `CoreBoundariesTest` exige una sola implementacion de cada puerto en el arbol
 * de modulos; un doble de pruebas no es una implementacion del producto, y este
 * es el sitio donde el resto de los dobles de este repositorio ya viven.
 */
final readonly class UnavailablePayrollDocumentWriter implements PayrollDocumentWriter
{
    public function fileNameFor(ReportExport $export, PeriodReport $report): string
    {
        return 'kronoqr-nomina-'.$report->range->isoFrom().'_'.$report->range->isoTo().'.'.$export->format;
    }

    public function write(ReportExport $export, PeriodReport $report, string $path): int
    {
        throw ReportExportWriteFailed::of(
            'la plantilla de salida a nomina no esta disponible en esta instalacion',
        );
    }
}
