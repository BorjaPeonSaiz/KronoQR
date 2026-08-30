<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Command;

use App\Modules\Attendance\Application\Port\WorkDayLedger;
use InvalidArgumentException;

/**
 * La orden de revisar el registro horario y emitir lo que haya que mirar
 * (RF-PR-01).
 *
 * **Un solo parametro, y es la ventana.** La decision de retroactividad (doc 01
 * §4) dice que la deteccion no reprocesa el historico: recalcular el pasado
 * abriria incidencias sobre jornadas ya entregadas a la plantilla o a la
 * Inspeccion. El valor de serie sale de `config/compliance.php`; ampliarlo es
 * una decision consciente de quien lanza el comando, nunca el comportamiento por
 * defecto del planificador.
 *
 * Los tramos **todavia abiertos** quedan fuera de esta ventana por diseno y se
 * revisan siempre: ver {@see WorkDayLedger::openWorkDays()}.
 */
final readonly class DetectAnomaliesCommand
{
    public function __construct(public int $lookbackDays)
    {
        if ($lookbackDays < 1) {
            throw new InvalidArgumentException(
                'La ventana de deteccion es de al menos un dia, y ha llegado '.$lookbackDays.'. '
                .'Para revisar solo los turnos abiertos, ejecutalo con 1: esos se revisan siempre.'
            );
        }
    }
}
