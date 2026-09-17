<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

/**
 * Por que una regla del perfil **no se evalua hoy** (RF-PA-06).
 *
 * Hay un solo motivo y esto sigue siendo un enum en lugar de un booleano porque
 * lo que el contrato promete es un motivo, no un «no». Una pantalla que solo
 * supiera que RN-12 no se evalua no podria decir **que hacer** para que se
 * evalue, que es lo unico que le sirve a quien pregunta por que no ve alertas
 * de pausas.
 *
 * La decision de **que** reglas estan suspendidas no vive aqui: vive en
 * `Shared\Domain\ValueObject\ComplianceRuleSuspension`, que es el unico sitio.
 */
enum ComplianceSuspensionReason: string
{
    /**
     * El fichaje de pausa esta **desactivado en esta instalacion**
     * (`ATTENDANCE_BREAK_CLOCKING`, RF-AT-12, ADR-024).
     *
     * Sin pausa declarada, un hueco entre dos tramos puede ser una comida o el
     * descanso entre dos turnos, y las dos cosas se leen igual en la tabla:
     * evaluar RN-12 ahi abriria `missing_break` contra gente que descanso sin
     * fichar.
     *
     * **El motivo dejo de ser «esperando a la tarea 3.5» en la propia tarea
     * 3.5** (decision 8): ya no es una limitacion del producto sino un ajuste
     * del hotel, y la diferencia importa en la pantalla —lo primero solo se
     * puede esperar; lo segundo se puede cambiar—.
     */
    case BreakClockingDisabled = 'break_clocking_disabled';
}
