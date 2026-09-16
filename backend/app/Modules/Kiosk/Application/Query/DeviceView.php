<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Application\Query;

use App\Modules\Kiosk\Domain\ValueObject\DeviceSummary;
use App\Modules\Kiosk\Domain\ValueObject\KioskHealthRow;

/**
 * Un quiosco con su veredicto: el esquema `Device` del contrato (**RF-PA-07**,
 * tarea 3.3).
 *
 * ## Por que la fila y el veredicto viajan juntos y no fundidos
 *
 * Son dos cosas de naturaleza distinta. {@see DeviceSummary} es **lo que la
 * tabla dice** —nombre, version, cola, bateria, cuando se vinculo— y
 * {@see KioskHealthRow} es **lo que de eso se concluye**, calculado con la misma
 * regla que `php artisan kiosk:health` y con los umbrales de la instalacion.
 * Fundirlas daria un objeto que a veces se construye sin reloj —el alta, la
 * reactivacion— y del que nunca se sabria si su veredicto esta al dia.
 *
 * Mantenerlas separadas es ademas lo que garantiza que **panel, consola y la
 * alerta `QuioscoSinLatido` cuenten lo mismo**: el veredicto sale de una unica
 * clase de dominio y aqui solo se transporta.
 *
 * ## Ni un dato personal, ni el token (regla dura 21)
 *
 * Lo que no esta en `DeviceSummary` no puede estar aqui: el `token_hash` no sale
 * siquiera de la consulta, y la clave interna no llega al `Resource`.
 */
final readonly class DeviceView
{
    public function __construct(
        public DeviceSummary $device,
        public KioskHealthRow $health,
    ) {}
}
