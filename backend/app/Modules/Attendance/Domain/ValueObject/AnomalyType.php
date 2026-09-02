<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\ValueObject;

use App\Modules\Shared\Domain\ValueObject\ComplianceRule;
use App\Modules\Shared\Domain\ValueObject\ComplianceRuleSuspension;

/**
 * Lo que la revision diaria del registro horario encuentra raro (RF-PR-01).
 *
 * **Por que no es {@see ShiftAnomaly}.** Aquel enum responde a una pregunta mas
 * estrecha —«¿que tiene de raro la duracion de un tramo que se acaba de
 * cerrar?»— y viaja dentro de `EmployeeClockedOut`. Este responde a la de la
 * tarea programada, que mira ademas turnos todavia abiertos, jornadas enteras,
 * el hueco entre dos jornadas y los escaneos marcados para revision. Ampliar
 * `ShiftAnomaly` con `insufficient_rest` habria obligado a cada fichaje a
 * transportar un valor que en ese momento no se puede evaluar.
 *
 * Los valores respaldados son los de `incidents.type` (doc 01 §5.5), igual que
 * en `ShiftAnomaly`, para que `Compliance` abra la incidencia **sin volver a
 * calcular nada ni conocer los umbrales**: `Attendance` emite la conclusion y
 * ellos reaccionan (doc 02 §1.6).
 *
 * **Ninguno de estos valores cierra, corrige ni descarta nada** (regla dura
 * 19). Todos terminan en la bandeja de una persona.
 */
enum AnomalyType: string
{
    /**
     * RN-08 sobre un tramo **todavia abierto**: lleva mas tiempo abierto que el
     * maximo operativo. Es el escenario «Turno olvidado» del doc 01 §11, y el
     * unico que se evalua fuera de la ventana de retroactividad: un turno sin
     * cerrar no es historia, es un hecho que sigue creciendo.
     */
    case OPEN_SHIFT_EXPIRED = 'open_shift_expired';

    /** RN-07: por debajo de la duracion minima computable. El tramo se conserva con sus dos marcas. */
    case SHORT_SHIFT = 'short_shift';

    /**
     * RN-08 sobre un tramo ya cerrado, y RN-11 sobre la suma de la jornada.
     * Los distingue `shift_entry_id`: la del tramo lo señala, la de la jornada
     * va a nulo porque ningun tramo por si solo la explica.
     */
    case LONG_SHIFT = 'long_shift';

    /**
     * RN-12: un tramo continuo por encima del maximo sin pausa registrada. Con
     * ADR-024 la pausa **son dos tramos**, asi que «sin pausa registrada»
     * significa exactamente «un solo tramo continuo».
     */
    case MISSING_BREAK = 'missing_break';

    /** RN-10: entre el fin de una jornada y el inicio de la siguiente median menos horas que el minimo legal. */
    case INSUFFICIENT_REST = 'insufficient_rest';

    /**
     * RN-15: el escaneo llego con un desfase de reloj por encima de la
     * tolerancia y **requiere validacion del responsable**. Nunca se rechazo el
     * fichaje (RF-AT-10, regla dura 19).
     */
    case CLOCK_SKEW = 'clock_skew';

    /**
     * La regla del **perfil de cumplimiento** cuyo umbral gobierna este hallazgo,
     * o `null` si el umbral que lo decide es operativo y no legal.
     *
     * Es el puente entre el vocabulario de este modulo —«que encontro raro la
     * revision»— y el de `Shared`, que es el unico que `Product` alcanza para
     * saber si esa regla abre incidencias hoy (doc 02 §1.6). Sin el, la pantalla
     * del perfil y el asiento de auditoria tendrian que repetir la lista de
     * reglas suspendidas, y una lista repetida es una lista que se queda vieja.
     *
     * `OPEN_SHIFT_EXPIRED`, `SHORT_SHIFT` y `CLOCK_SKEW` devuelven `null` a
     * proposito: sus umbrales los fija el hotel (`installation_settings`), no la
     * jurisdiccion. `LONG_SHIFT` devuelve RN-11 porque es el unico de sus dos
     * origenes que sale del perfil.
     */
    public function complianceRule(): ?ComplianceRule
    {
        return match ($this) {
            self::INSUFFICIENT_REST => ComplianceRule::MinimumRestBetweenWorkDays,
            self::LONG_SHIFT => ComplianceRule::MaximumDailyWorkingTime,
            self::MISSING_BREAK => ComplianceRule::BreakInContinuousShift,
            self::OPEN_SHIFT_EXPIRED, self::SHORT_SHIFT, self::CLOCK_SKEW => null,
        };
    }

    /**
     * Si la apertura de incidencia de este hallazgo esta suspendida hoy.
     *
     * Deriva de {@see ComplianceRuleSuspension}, que es donde la decision vive:
     * vaciar aquella lista reactiva esto y todo lo que cuelga de ello.
     */
    public function openingIsSuspended(): bool
    {
        $rule = $this->complianceRule();

        return $rule instanceof ComplianceRule && ComplianceRuleSuspension::isSuspended($rule);
    }
}
