<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\ValueObject;

use App\Modules\Attendance\Domain\Policy\UnprocessableScanPolicy;

/**
 * Lo que decide {@see UnprocessableScanPolicy} sobre un fichaje que el servidor
 * acaba de fallar otra vez (RN-22, ADR-061).
 */
enum UnprocessableScanVerdict: string
{
    /** Aun no hay prueba de que el fallo sea permanente: se conserva y el quiosco reintenta (`503`). */
    case KeepRetrying = 'keep_retrying';

    /** El fallo es permanente: se descarta con incidencia y responde `422 scan-discarded`. */
    case Discard = 'discard';

    /**
     * Seria un descarte, pero el quiosco ya agoto su cupo: si el siguiente
     * tambien falla, no es el fichaje, es el servidor. Se conserva (`503`) y
     * salta la alerta critica.
     */
    case BudgetExhausted = 'budget_exhausted';

    /** Solo el descarte es un desenlace: los otros dos dejan el fichaje en la cola. */
    public function isTerminal(): bool
    {
        return $this === self::Discard;
    }
}
