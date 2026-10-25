<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

/**
 * Donde guarda la tablet su cola de fichajes ahora mismo, segun su latido
 * (`devices.queue_storage`, ADR-047, RF-KI-04).
 *
 * - `durable` — IndexedDB. Lo normal, y lo que declara sin decirlo una PWA
 *   anterior a la 2.2.0.
 * - `memory` — IndexedDB fallo y no se pudo reabrir: lo que se encola ahora se
 *   pierde si la tablet se reinicia, y lo que hubiera en el disco no se ve.
 * - `unavailable` — ni IndexedDB ni memoria aceptan escrituras: cada fichaje se
 *   intenta enviar al instante.
 *
 * Fuera de `durable` **la tablet no sabe cuantos fichajes tiene** y declara el
 * tamaño de la cola como desconocido, nunca como cero.
 *
 * Vive en `Shared` y no en `Kiosk` porque viaja tambien en
 * {@see KioskAppVersionEntry}, que `Product` lee sin poder importar el dominio
 * de `Kiosk` (V21-1-AR-4).
 */
enum QueueStorage: string
{
    case Durable = 'durable';
    case Memory = 'memory';
    case Unavailable = 'unavailable';

    public function isDurable(): bool
    {
        return $this === self::Durable;
    }
}
