<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

/**
 * Que informe se genera en diferido (**RF-IN-06**, **RF-IN-07**, decision 1 de
 * la ficha 3.9).
 *
 * ## Dos, y ninguno es una consulta nueva
 *
 * - `period` — el informe de horas por periodo (RF-IN-01..03), el mismo que
 *   sirve `GET /api/v1/reports/period`, con sus criterios dentro del fichero.
 * - `payroll` — **el mismo informe** por empleado, pasado por la plantilla
 *   configurable de RF-IN-07. No hay SQL propia: cambia la disposicion de las
 *   columnas, no lo que se cuenta. Es lo que hace que la salida a nomina y la
 *   pantalla no puedan discrepar.
 *
 * ## Los dos no valen lo mismo ante la licencia ni ante los roles
 *
 * `period` va tras `Feature::AdvancedReports` y es de `manager+`; `payroll` va
 * tras `Feature::PayrollExport` y es de `rrhh+` (Anexo B del doc 01, decision 6
 * de la ficha). La comprobacion vive en `ReportExportPolicy` y en el
 * controlador, no aqui: este enumerado dice **que** se pide, no quien puede.
 *
 * ## Y no admiten los mismos formatos
 *
 * {@see self::allows()} es la unica lista: un PDF de nomina no lo importa
 * ningun programa de nomina, y ofrecerlo seria ofrecer un fichero que quien lo
 * descarga no puede usar para lo unico que lo pidio.
 */
enum ReportExportKind: string
{
    case Period = 'period';

    case Payroll = 'payroll';

    /**
     * El catalogo, para el `CHECK` de la migracion y para las reglas del
     * `FormRequest`.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }

    /**
     * Los formatos que admite cada clase de informe.
     *
     * **`payroll` no admite PDF** y es deliberado: el fichero de nomina lo lee
     * un programa, no una persona, y un PDF no se importa en ninguno. Ofrecerlo
     * produciria descargas inservibles cuyo unico desenlace es una llamada de
     * soporte.
     *
     * @return list<string>
     */
    public function allows(): array
    {
        return match ($this) {
            self::Period => ['csv', 'xlsx', 'pdf'],
            self::Payroll => ['csv', 'xlsx'],
        };
    }
}
