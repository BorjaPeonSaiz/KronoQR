<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

use DateTimeImmutable;

/**
 * **Los hechos de una jornada**, sin ningun veredicto: lo que el evaluador
 * necesita saber para decidir si esa jornada incumple (RF-PA-06).
 *
 * ## Por que es un modelo de lectura y no `WorkDay`
 *
 * Porque `Reporting` no puede importar el dominio de `Attendance` (doc 02 §1.6),
 * y no deberia aunque pudiera: aquel es el **agregado** que se modifica al
 * fichar, con sus invariantes y su ciclo de vida, y esto es una fila plana que
 * describe un dia que ya pasó. Cargar quinientas personas por noventa dias como
 * agregados seria cuarenta y cinco mil objetos con sus tramos para sumar cuatro
 * numeros.
 *
 * ## Lo que NO se recalcula aqui
 *
 * `totalMinutes` sale de `daily_totals`, la proyeccion reconstruible de RN-06
 * (regla dura 7, ADR-007): **no se vuelve a sumar desde `shift_entries`**. Es el
 * mismo criterio que el informe por periodo, y por el mismo motivo: dos formas de
 * calcular el mismo total son dos numeros que algun dia discrepan y nadie sabe
 * cual creer.
 *
 * ## Los instantes son UTC y las fechas son civiles
 *
 * `workDate` es la fecha civil del centro con RN-05 ya aplicada —un turno
 * 22:00 → 06:00 pertenece entero al dia en que empezo (ADR-006, regla dura 4)— y
 * las cuatro marcas son instantes en UTC. La aritmetica de descansos se hace
 * restando instantes, nunca fechas civiles: es lo que hace que el cambio de hora
 * no mueva un descanso (RN-09).
 */
final readonly class ComplianceFacts
{
    public function __construct(
        public ComplianceEmployee $employee,
        /** Fecha civil de la jornada, `YYYY-MM-DD` en la zona del centro. */
        public string $workDate,
        /**
         * Primera entrada de la jornada, o `null` si no hay ningun tramo vigente.
         *
         * Es la marca contra la que se mide el descanso de RN-10.
         */
        public ?DateTimeImmutable $firstInAt,
        /** Ultima salida de la jornada, o `null` si ninguna se ha cerrado. */
        public ?DateTimeImmutable $lastOutAt,
        /**
         * Fin del ultimo tramo de la jornada **anterior** de esta persona, o
         * `null` si no consta.
         *
         * Cuando no consta **no se evalua RN-10**: suponer que el dia anterior
         * termino a medianoche produciria una alerta sobre alguien que acaba de
         * incorporarse. Lo resuelve el lector mirando una jornada antes del
         * rango pedido, para que el primer dia de la ventana se evalue igual que
         * los demas.
         */
        public ?DateTimeImmutable $previousLastOutAt,
        /** Total de la proyeccion (`daily_totals.total_minutes`). */
        public int $totalMinutes,
        /**
         * Si la jornada tiene algun tramo todavia abierto.
         *
         * Un tramo abierto **vale cero** en `totalMinutes` —el registro legal suma
         * lo fichado, no lo que va corriendo—, asi que lo que ese tramo lleve
         * acumulado no puede hacer saltar RN-11 hoy: alertara cuando el dia sea un
         * hecho. Mientras tanto, lo que tiene de raro lo dice RN-08, con su propio
         * umbral y su propia incidencia.
         *
         * **Marcar no es callar.** Si la jornada YA supera el umbral con lo
         * cerrado, el hallazgo sale igual y ademas viaja marcado: el aviso dice
         * «este total puede crecer», no «este total no cuenta». Las dos mitades
         * tienen su caso en `ComplianceEvaluationTest`.
         */
        public bool $hasOpenShift,
        /** El tramo cerrado mas largo de la jornada, o `null` si no hay ninguno. */
        public ?ComplianceShiftSegment $longestClosedSegment,
        /** Identificador publico del tramo que **abre** la jornada, o `null`. */
        public ?string $openingShiftEntryUuid,
    ) {}

    /**
     * Minutos de descanso entre el fin de la jornada anterior y la primera
     * entrada de esta, o `null` cuando no hay nada que medir.
     *
     * Devuelve `null` en dos casos distintos y los dos significan «no se evalua»:
     * cuando no consta la jornada anterior, y cuando esta jornada empieza **antes**
     * de que termine aquella. Lo segundo no describe un descanso corto sino un
     * solape, del que responde RN-02 en el esquema; medirlo daria un descanso
     * negativo, que ademas se leeria como el incumplimiento mas grave posible.
     *
     * Empezar **exactamente** cuando termino la anterior si es descanso: cero
     * minutos, el peor cumplimiento de RN-10 que se puede registrar sin solapar.
     */
    public function restMinutes(): ?int
    {
        if (! $this->previousLastOutAt instanceof DateTimeImmutable || ! $this->firstInAt instanceof DateTimeImmutable) {
            return null;
        }

        $seconds = $this->firstInAt->getTimestamp() - $this->previousLastOutAt->getTimestamp();

        return $seconds < 0 ? null : intdiv($seconds, 60);
    }
}
