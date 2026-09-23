<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Export;

use App\Modules\Reporting\Domain\ValueObject\PeriodReport;
use App\Modules\Reporting\Domain\ValueObject\PeriodReportRow;
use App\Modules\Reporting\Domain\ValueObject\ReportCriterion;

/**
 * La huella SHA-256 **del contenido** de un informe por periodo (**RF-IN-04**).
 *
 * ## Que se firma, exactamente
 *
 * El requisito pide «hash del contenido», y contenido no es el binario. Lo que
 * se resume aqui es una **serializacion canonica** de tres cosas:
 *
 *   1. El periodo, la granularidad, la agrupacion y la zona del centro.
 *   2. Los criterios de inclusion, por sus **claves sin traducir**.
 *   3. Las filas, en el orden en el que las devolvio el informe, con todos sus
 *      contadores.
 *
 * Y nada mas. En concreto **no entran** el instante de generacion, la cuenta que
 * lo pidio, el formato ni el idioma. Esa exclusion es la decision de diseño:
 *
 * - Un hash del **binario** cambiaria entre el CSV y el XLSX del mismo informe,
 *   y hasta entre dos PDF identicos generados con dos segundos de diferencia
 *   —el sello temporal va dentro—. No serviria para comparar nada.
 * - Un hash que incluyera el emisor diria que el informe de marzo que saco RRHH
 *   y el que saco el administrador son documentos distintos. No lo son: son el
 *   mismo informe.
 *
 * Con esta definicion, **la huella responde a la unica pregunta que importa**:
 * ¿estos dos papeles dicen lo mismo? Si una hora cambia —por una correccion,
 * por una anulacion, por un tramo que aparece tarde desde la cola offline— el
 * `worked` de esa fila cambia y la huella cambia con el. Si lo que cambia es
 * quien lo imprimio o cuando, no cambia.
 *
 * ## El formato canonico, escrito para poder reproducirlo fuera
 *
 * UTF-8, lineas separadas por `\n`, campos separados por `\x1F` (el separador de
 * unidad de ASCII, que no puede aparecer en ninguno de los valores). Los nulos
 * se escriben como cadena vacia. Las duraciones **no** entran como texto
 * `HH:MM`, sino como los minutos enteros de los que salen: el texto es
 * presentacion y los minutos son el dato.
 *
 * ```
 * kronoqr-period-report/2
 * range<US>2026-03-01<US>2026-03-31
 * shape<US>month<US>employee<US>Europe/Madrid
 * criteria<US>criteria.source<US>criteria.work_date<US>…
 * row<US>employee<US>0199…<US>739104<US>3<US>Lucía Amrani<US>2026-03-01<US>2026-03-31<US>9720<US>9257<US>21<US>31<US>21<US>0<US>1<US>0<US>3<US>1<US>6
 * …
 * ```
 *
 * La version de la primera linea existe para que el dia que haya que añadir una
 * columna se pueda decir «esto es v2» en lugar de que las huellas empiecen a no
 * cuadrar sin explicacion. **Y ese dia llego**: RF-GP-04 añade tres contadores a
 * cada fila —ausencias, festivos y absentismo no justificado— y son contenido
 * del documento, no presentacion: dos informes iguales en horas y distintos en
 * ausencias no son el mismo informe. De ahi `/2`: la huella de un informe de
 * marzo sacado antes y despues de esta version **no coincide**, y la version lo
 * explica en lugar de dejarlo como un misterio.
 *
 * De la linea de criterios entra **la clave**, no el texto traducido, por lo
 * mismo que las duraciones entran en minutos: el mismo informe descargado en
 * castellano y en ingles tiene la misma huella. Lo que si entra son las
 * sustituciones —«cuantos festivos»—, porque cambian lo que el informe afirma.
 *
 * ## Por que vive en `Http/Support` y no en el dominio
 *
 * Porque no es una regla de negocio: es como se **identifica** un documento que
 * sale de la instalacion. El dominio no sabe que existen los ficheros. Mismo
 * criterio que {@see PeriodReportLayout}, que decide las columnas.
 */
final readonly class PeriodReportDigest
{
    /** Version del formato canonico. Ver el docblock. */
    private const string VERSION = 'kronoqr-period-report/2';

    /** Separador de unidad de ASCII: no aparece en ningun valor del informe. */
    private const string SEPARATOR = "\x1F";

    private function __construct(public string $canonicalText, public string $sha256) {}

    public static function of(PeriodReport $report): self
    {
        $text = self::canonicalize($report);

        return new self($text, hash('sha256', $text));
    }

    /**
     * Como se escribe en un pie de pagina o en un bloque de criterios.
     *
     * En minusculas y sin separadores: es lo que se compara a ojo con otro papel,
     * y un grupo de cuatro con guiones invitaria a compararlo por trozos.
     */
    public function toText(): string
    {
        return $this->sha256;
    }

    private static function canonicalize(PeriodReport $report): string
    {
        $lines = [
            self::VERSION,
            self::line(['range', $report->range->isoFrom(), $report->range->isoTo()]),
            self::line(['shape', $report->granularity->value, $report->grouping->value, $report->timeZone]),
            self::line(['criteria', ...self::criteria($report)]),
        ];

        foreach ($report->rows as $row) {
            $lines[] = self::line(self::row($row));
        }

        return implode("\n", $lines);
    }

    /**
     * Los criterios por su clave, con las sustituciones pegadas detras.
     *
     * `criteria.holidays=count:1|profile:ES-hosteleria`. El texto traducido no
     * entra —el mismo informe en dos idiomas tiene la misma huella—, pero el
     * valor si: «hay 1 festivo» y «hay 4 festivos» no son la misma afirmacion.
     *
     * @return list<string>
     */
    private static function criteria(PeriodReport $report): array
    {
        return array_map(static function (ReportCriterion $criterion): string {
            if ($criterion->replacements === []) {
                return $criterion->key;
            }

            $pairs = [];

            // Ordenadas por clave: dos informes identicos no pueden dar huellas
            // distintas porque quien compuso el criterio escribiera los
            // marcadores en otro orden.
            $replacements = $criterion->replacements;
            ksort($replacements);

            foreach ($replacements as $name => $value) {
                $pairs[] = $name.':'.$value;
            }

            return $criterion->key.'='.implode('|', $pairs);
        }, $report->criteria);
    }

    /**
     * @return list<string>
     */
    private static function row(PeriodReportRow $row): array
    {
        return [
            'row',
            $row->subject->kind->value,
            $row->subject->employeeUuid ?? '',
            $row->subject->employeeCode ?? '',
            (string) ($row->subject->departmentId ?? ''),
            // La etiqueta entra porque un informe cuyo departamento se renombro
            // NO dice lo mismo que el anterior, aunque las horas coincidan.
            $row->subject->fullName ?? $row->subject->label ?? '',
            $row->isoPeriodStart(),
            $row->isoPeriodEnd(),
            // Minutos, no `HH:MM`: el texto es presentacion y depende del idioma
            // del documento; los minutos son el dato.
            (string) $row->workedMinutes,
            (string) $row->contractedMinutes,
            (string) $row->shiftCount,
            (string) $row->daysInPeriod,
            (string) $row->daysWithActivity,
            (string) $row->openShiftDays,
            (string) $row->incidentDays,
            (string) $row->daysWithoutContract,
            // RF-GP-04, y por eso la version subio a `/2`: dos informes iguales
            // en horas pero distintos en ausencias no son el mismo informe.
            (string) $row->absenceDays,
            (string) $row->holidayDays,
            (string) $row->unjustifiedAbsenceDays,
        ];
    }

    /**
     * @param  list<string>  $fields
     */
    private static function line(array $fields): string
    {
        return implode(self::SEPARATOR, $fields);
    }
}
