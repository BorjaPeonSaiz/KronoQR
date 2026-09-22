<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Policy;

/**
 * Que es una ausencia, que es un festivo y que es **absentismo no justificado**
 * en el informe por periodo (**RF-GP-04**, decision 7 de la ficha 3.10).
 *
 * ## La definicion esta escrita aqui una sola vez
 *
 * El informe agrega cientos de miles de dias-persona y por eso lo cuenta
 * PostgreSQL con `FILTER`, no este fichero: el dominio no puede recorrer fila a
 * fila la plantilla de un hotel entero. Lo que si puede —y es lo que hace— es
 * **ser la definicion**: los tres predicados de abajo son el enunciado, y el SQL
 * de `DatabasePeriodReportReader` es su traduccion. Es la misma duplicacion
 * asumida que el prorrateo de contratos, y la ata igual una prueba de
 * integracion que compara las dos sobre el mismo caso.
 *
 * ## Los tres contadores, por dia-persona
 *
 * | Contador | De alta | Ausencia activa | Festivo | Actividad |
 * |---|---|---|---|---|
 * | `absence_days` | si | **si** | da igual | da igual |
 * | `holiday_days` | si | **no** | **si** | da igual |
 * | `unjustified_absence_days` | si | **no** | **no** | **no** |
 *
 * **«De alta» es la condicion previa de los tres** y significa lo mismo que en
 * `days_without_contract`: el dia cae entre `hired_at` y `terminated_at`. Un dia
 * anterior al alta o posterior al cese no es una ausencia de nadie, es un dia en
 * el que esa persona no trabajaba aqui.
 *
 * **Cuando una ausencia cae en festivo, gana la ausencia.** Los dos contadores
 * son disjuntos a proposito: si el mismo dia sumara en los dos, `absence_days +
 * holiday_days` dejaria de ser «dias justificados» y quien los sume para
 * restarlos del periodo contaria uno de mas. El criterio —gana la ausencia— no
 * es arbitrario: lo que el registro afirma es que esa persona tenia una ausencia
 * registrada, y el festivo lo tenia todo el centro.
 *
 * **La actividad solo entra en el tercero.** Una ausencia con fichajes dentro
 * sigue siendo ausencia: es un hecho registrado por RRHH, y el informe lo dice
 * en vez de corregirlo (RN-08 llevado a este terreno: detectar no corrige). Lo
 * que no puede es contar como absentismo, que es lo que el tercero excluye.
 *
 * ## El limite honesto: el producto no conoce el cuadrante
 *
 * `unjustified_absence_days` **no es** «dias que faltó a trabajar». Este
 * producto no modela el cuadrante teorico de nadie —ni RF-* lo pide ni
 * `employment_contracts` lo guarda: el contrato trae horas semanales, que es lo
 * que el informe prorratea por dia natural—, asi que **no sabe que dias le
 * tocaba trabajar a cada persona**. Consecuencia directa y que hay que decir en
 * voz alta: **los dias de descanso semanal cuentan como no justificados**, y en
 * un hotel con turnos rotatorios eso son dos dias por persona y semana.
 *
 * Por eso el contador se llama asi y no «absentismo», por eso `meta.criteria`
 * lleva la advertencia en el idioma de quien pide el informe, y por eso el
 * numero se contrasta con el calendario de turnos antes de usarlo para nada.
 * Restarle un descanso inventado —«cinco dias laborables de siete»— seria
 * fabricar un dato de nomina: en hosteleria el fin de semana no es descanso.
 *
 * Lo que si hace el contador, y es para lo que existe, es **bajar cuando RRHH
 * registra la realidad**: cada dia de vacaciones, baja o permiso que se anota
 * sale de ahi y pasa a `absence_days`, que es exactamente el compromiso del
 * doc 05 §5.5.
 *
 * ## Puro, sin reloj y sin configuracion
 *
 * No se llama al reloj del sistema (regla dura 2) ni se lee el perfil (regla
 * dura 14): el calendario de festivos llega ya resuelto por
 * `CompliancePolicyProvider` y lo unico que aqui se pregunta es si un dia
 * concreto estaba dentro. Aqui no se pregunta nada sobre el presente: los dias
 * que se clasifican ya pasaron, o son los que el informe pidio.
 */
final class AbsenteeismRule
{
    /**
     * No se instancia: es un enunciado, no un colaborador.
     */
    private function __construct() {}

    /**
     * Dia-persona de alta cubierto por una ausencia **activa**.
     *
     * No mira ni el festivo ni la actividad: una ausencia registrada es un hecho
     * y el informe la cuenta tal cual.
     */
    public static function isAbsenceDay(bool $employed, bool $coveredByAbsence): bool
    {
        return $employed && $coveredByAbsence;
    }

    /**
     * Dia-persona de alta, festivo del perfil y **no** cubierto por una ausencia.
     *
     * La exclusion es lo que hace disjuntos los dos contadores de dias
     * justificados. Ver el docblock de la clase.
     */
    public static function isHolidayDay(bool $employed, bool $holiday, bool $coveredByAbsence): bool
    {
        return $employed && $holiday && ! $coveredByAbsence;
    }

    /**
     * Dia-persona de alta, **sin actividad**, sin ausencia y sin festivo.
     *
     * Es el unico de los tres que mira la actividad, y el unico cuyo nombre
     * necesita la advertencia del cuadrante que explica el docblock de la clase.
     */
    public static function isUnjustifiedAbsenceDay(
        bool $employed,
        bool $hasActivity,
        bool $coveredByAbsence,
        bool $holiday,
    ): bool {
        return $employed && ! $hasActivity && ! $coveredByAbsence && ! $holiday;
    }

    /**
     * Los tres a la vez, que es como los cuenta el informe.
     *
     * Existe para que la prueba de integracion pueda recorrer el mismo caso que
     * el SQL sin recomponer el enunciado a mano — recomponerlo seria una cuarta
     * copia de la definicion.
     *
     * @return array{absence: bool, holiday: bool, unjustified: bool}
     */
    public static function classify(
        bool $employed,
        bool $hasActivity,
        bool $coveredByAbsence,
        bool $holiday,
    ): array {
        return [
            'absence' => self::isAbsenceDay($employed, $coveredByAbsence),
            'holiday' => self::isHolidayDay($employed, $holiday, $coveredByAbsence),
            'unjustified' => self::isUnjustifiedAbsenceDay($employed, $hasActivity, $coveredByAbsence, $holiday),
        ];
    }

    /**
     * Si una ausencia de `starts_on` a `ends_on` cubre el dia indicado.
     *
     * **Inclusiva en los dos extremos** (decision 1 de la ficha: `starts_on` y
     * `ends_on` son fechas civiles inclusivas, sin medias jornadas), que es lo
     * mismo que afirma el `BETWEEN` del informe. Las tres fechas son etiquetas
     * ISO `AAAA-MM-DD` y se comparan como texto: en ese formato el orden
     * lexicografico y el cronologico coinciden, y convertirlas a instantes
     * obligaria a elegir una zona horaria para algo que no es un instante.
     *
     * **Esto no es el `Absence::covers()` de `Workforce`**, y no puede serlo:
     * `Reporting` no ve ese modulo (doc 02 §1.6, Deptrac). Es la misma
     * duplicacion asumida que el prorrateo de contratos, con la misma red: el
     * informe y el registro de ausencias tienen que cubrir exactamente los
     * mismos dias, y la prueba de integracion compara el SQL con esto.
     */
    public static function covers(string $startsOn, string $endsOn, string $day): bool
    {
        return $day >= $startsOn && $day <= $endsOn;
    }
}
