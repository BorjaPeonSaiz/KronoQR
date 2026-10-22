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
 *
 * ## Y desde la 2.3.0, dos mas que no son el informe por periodo (V5-PA-4)
 *
 * - `legal` — **la exportacion para la Inspeccion** (RF-IN-05, RL-06): el mismo
 *   fichero que `GET /reports/legal-export`, generado por el mismo escritor de
 *   `Compliance`. Existe en diferido porque la plantilla entera de un periodo
 *   largo no cabe en los 60 s de nginx, y un requerimiento no puede acabar en
 *   un error generico. **No se degrada nunca** (regla dura 15).
 * - `adoption` — el cuadro de impacto (RF-IN-08), el mismo de
 *   `GET /reports/adoption/export`. Un agregado sin personas: el PDF arranca un
 *   navegador y el cuadro cruza dos periodos.
 *
 * Ninguna de las dos usa la consulta del informe por periodo, y por eso
 * {@see self::isPeriodReport()} distingue las dos familias: quien genera el
 * fichero elige el camino con ella y no con una lista de casos repetida.
 */
enum ReportExportKind: string
{
    case Period = 'period';

    case Payroll = 'payroll';

    case Legal = 'legal';

    case Adoption = 'adoption';

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
            self::Period, self::Adoption => ['csv', 'xlsx', 'pdf'],
            self::Payroll => ['csv', 'xlsx'],
            // RL-06: tabular, legible y no propietario. El escritor de la
            // Inspeccion solo sabe escribir CSV, y es a proposito.
            self::Legal => ['csv'],
        };
    }

    /**
     * Todos los formatos que admite alguna clase, sin repetir: el catalogo del
     * `CHECK` de la migracion y de la regla del `FormRequest`.
     *
     * @return list<string>
     */
    public static function formats(): array
    {
        $formats = [];

        foreach (self::cases() as $kind) {
            foreach ($kind->allows() as $format) {
                $formats[$format] = $format;
            }
        }

        return array_values($formats);
    }

    /**
     * Sale de la consulta del informe por periodo (`GeneratePeriodReport`): los
     * parametros llevan granularidad y agrupacion, y el alcance del solicitante
     * acota las filas.
     */
    public function isPeriodReport(): bool
    {
        return $this === self::Period || $this === self::Payroll;
    }
}
