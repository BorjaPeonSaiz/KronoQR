<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Application\Exception;

/**
 * No se ha podido sortear un codigo de emparejamiento libre (**RF-PD-06**).
 *
 * Ocho colisiones seguidas sobre un espacio de un millon significan que hay
 * cientos de miles de solicitudes **pendientes** a la vez: una averia o un
 * ataque, no un caso a tolerar en silencio.
 *
 * En la practica no deberia llegar nunca, porque la cota de solicitudes vivas
 * ({@see PairingCapacityExhausted}) corta mucho antes; se conserva porque es la
 * unica defensa que queda si esa cota se sube a un valor absurdo, y porque un
 * bucle sin techo dejaria la peticion colgada en lugar de decir que pasa.
 *
 * Sale como `503`, igual que la cota: desde fuera son el mismo sintoma.
 */
final class PairingCodeSpaceExhausted extends PairingUnavailable
{
    public function __construct()
    {
        parent::__construct('No se ha podido sortear un codigo de emparejamiento libre.');
    }
}
