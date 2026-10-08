<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\Exception;

/**
 * Una ventana de conciliacion que no cubre ni un dia (ADR-057 §4).
 *
 * Una ventana de cero dias no mira ningun tramo y terminaria «sin
 * discrepancias» todas las noches: la alerta callaria sin que nadie hubiera
 * comprobado nada. Por eso se rechaza en voz alta en lugar de ajustarse sola.
 */
final class InvalidWorkRecordReconciliationWindow extends ComplianceDomainException
{
    public static function notPositive(int $days): self
    {
        return new self(
            'La ventana del registro horario tiene que ser de al menos un dia, no '.$days.'. '
            .'Revisa compliance.work_record_reconciliation.window_days o --days.'
        );
    }
}
