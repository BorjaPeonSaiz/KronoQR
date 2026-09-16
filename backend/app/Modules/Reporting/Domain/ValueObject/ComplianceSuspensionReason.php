<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

/**
 * Por que una regla del perfil **no se evalua hoy** (RF-PA-06).
 *
 * Hay un solo motivo y esto sigue siendo un enum en lugar de un booleano porque
 * lo que el contrato promete es un motivo, no un «no». Una pantalla que solo
 * supiera que RN-12 no se evalua no podria decir «no se evalua hasta que exista
 * la pausa declarada», que es lo unico que le sirve a quien pregunta por que no
 * ve alertas de pausas.
 *
 * La decision de **que** reglas estan suspendidas no vive aqui: vive en
 * `Shared\Domain\ValueObject\ComplianceRuleSuspension`, que es el unico sitio, y
 * la tarea 3.5 la vacia alli.
 */
enum ComplianceSuspensionReason: string
{
    /**
     * RN-12 espera a que el quiosco registre la pausa declarada (ADR-024,
     * RF-AT-12, tarea 3.5).
     *
     * Sin la intencion declarada del fichaje, un hueco entre dos tramos puede ser
     * una pausa para comer o el descanso entre dos turnos, y las dos cosas se
     * leen igual en la tabla.
     */
    case AwaitingDeclaredBreak = 'awaiting_declared_break';
}
