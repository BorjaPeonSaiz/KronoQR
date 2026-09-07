<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Application\Exception;

/**
 * La instalacion tiene tantas solicitudes de emparejamiento vivas como admite
 * (`kiosk.pairing.max_live_pending`, **RF-PD-06**, RS-02).
 *
 * ## Por que hace falta si ya hay un limitador por IP
 *
 * Porque aquel frena a **un** origen. `POST /api/v1/kiosk/pair` es una escritura
 * publica, y quien reparta el trafico entre direcciones lo esquiva sin esfuerzo:
 * diez peticiones por minuto desde cien direcciones son mil solicitudes vivas en
 * un minuto, y con diez minutos de vida cada una, diez mil a la vez. Eso estrecha
 * el espacio de sorteo de un codigo de seis digitos hasta que el alta legitima de
 * un quiosco empieza a fallar — que es la unica forma de negar ese alta desde
 * fuera, y por tanto lo que hay que impedir.
 *
 * Esta cota mira el **conjunto**, que es justo lo que el techo por origen no
 * puede ver.
 *
 * ## No bloquea a nadie de verdad
 *
 * Veinte solicitudes vivas es un orden de magnitud por encima del uso real: una
 * instalacion es un hotel (ADR-040) y los quioscos se dan de alta de uno en uno.
 * Y el hueco aparece solo, sin que nadie intervenga, porque cada peticion purga
 * antes las pendientes ya caducadas y estas viven diez minutos.
 */
final class PairingCapacityExhausted extends PairingUnavailable
{
    public function __construct(int $live, int $cap)
    {
        parent::__construct(sprintf(
            'Hay %d solicitudes de emparejamiento vivas y el maximo es %d.',
            $live,
            $cap,
        ));
    }
}
