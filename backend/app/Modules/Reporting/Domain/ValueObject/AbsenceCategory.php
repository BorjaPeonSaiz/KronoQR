<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

/**
 * Los cuatro tipos de ausencia **tal como los nombra el informe y la metrica**
 * (RF-GP-04, decision 2 de la ficha 3.10).
 *
 * ## Por que hay un enumerado aqui teniendo `Workforce` el suyo
 *
 * Por lo mismo que {@see ComplianceRuleName} convive con
 * `Shared\Domain\ValueObject\ComplianceRule`: `Reporting` no ve `Workforce`
 * (doc 02 §1.6) y Deptrac lo verifica. El catalogo es **cerrado y comun** —lo
 * que hace comparables los informes de dos clientes, decision 2— y los valores
 * son literalmente los de la columna `absences.type`, asi que las dos
 * enumeraciones no pueden separarse sin que una consulta deje de devolver
 * filas.
 *
 * **Lo que las ata es `AbsenceCatalogParityTest`**, que compara estos cuatro
 * valores con la constante `TYPES` de la migracion `2026_09_22_100000_absences`
 * —el mismo `CHECK` que ya ata `AbsenceType` de `Workforce`—. Por la migracion y
 * no directamente contra el otro enum, precisamente porque Deptrac prohibe que
 * `Reporting` lo importe: el esquema es el unico sitio que los dos modulos
 * pueden nombrar sin cruzar la frontera, y es ademas el que decide que filas
 * existen.
 *
 * `AbsenceMetricsTest` aporta la otra mitad: que las cuatro etiquetas salgan en
 * la serie aunque valgan cero.
 *
 * **No se traduce aqui.** Estos valores son etiquetas de una serie de Prometheus
 * y nombres de columna, no texto de pantalla (doc 02 §8.2: la serie no lleva
 * ninguna etiqueta que identifique a una persona, y tampoco texto que dependa
 * del idioma de la instalacion).
 */
enum AbsenceCategory: string
{
    /** Vacaciones. */
    case Vacation = 'vacation';

    /**
     * Baja medica. **Dato de salud** (regla dura 21): el tipo puede contarse en
     * una serie agregada sin nombres, nunca asociarse a una persona en un log.
     */
    case SickLeave = 'sick_leave';

    /** Permiso. */
    case Leave = 'leave';

    /** Cualquier otro, que en el registro exige nota. */
    case Other = 'other';

    /**
     * Los cuatro valores, en el orden del catalogo.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * El recuento por tipo con **las cuatro claves presentes**, rellenando con
     * cero las que falten y descartando cualquier valor desconocido.
     *
     * Es lo que impide que una serie desaparezca: una etiqueta que no se escribe
     * es indistinguible de una que nunca tuvo nada, y «ninguna baja medica hoy»
     * es justo lo que se mira. El descarte de lo desconocido es la otra mitad:
     * una fila con un tipo que este enumerado no conoce no puede colarse como
     * etiqueta nueva en Prometheus.
     *
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    public static function completeCounts(array $counts): array
    {
        $complete = [];

        foreach (self::cases() as $case) {
            $complete[$case->value] = $counts[$case->value] ?? 0;
        }

        return $complete;
    }
}
