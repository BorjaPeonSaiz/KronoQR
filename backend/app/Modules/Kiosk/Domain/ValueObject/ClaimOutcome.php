<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Domain\ValueObject;

/**
 * Lo que puede pasar cuando la tablet sondea `POST /api/v1/kiosk/pair/claim`
 * (**RF-PD-06**).
 *
 * ## Tres casos, y `Rejected` NO se subdivide
 *
 * Y no puede subdividirse. La regla dura 17 —los rechazos son genericos e
 * indistinguibles— es **estructural** en este producto: si existieran
 * `RejectedUnknown`, `RejectedBadSecret` y `RejectedExpired`, el dia que alguien
 * quisiera «mejorar el diagnostico» la causa ya estaria ahi, tipada y a mano, y
 * solo haria falta pasarla al borde. Que sea imposible es el punto, igual que en
 * el esquema `PairingRejected` del contrato, donde todos los campos tienen un
 * unico valor posible.
 *
 * El motivo real existe y vive donde no lo ve quien sondea: en el log del
 * servidor (`pairing_rejected`) y en la etiqueta `reason` de
 * `kiosk_pairing_total`, que `/metrics` sirve solo a la red interna. Lo calcula
 * el caso de uso DESPUES de que este enum haya decidido, sobre datos que ya
 * tiene en memoria: si el camino se ramificara por causa para poder nombrarla,
 * esa ramificacion seria el canal de tiempo que todo lo demas evita.
 *
 * ## Por que un enum y no excepciones
 *
 * Porque ninguno de los tres es excepcional: `Pending` es el caso normal —la
 * tablet sondea cada cinco segundos y casi siempre nadie ha confirmado todavia—
 * y `Rejected` es lo que ocurre cuando un codigo caduca, que pasa a diario.
 * Lanzar para lo habitual convierte el camino feliz en el camino de excepcion y
 * hace que el coste de un rechazo se note desde fuera.
 */
enum ClaimOutcome
{
    /** Nadie ha confirmado todavia. La tablet sigue mostrando el codigo. */
    case Pending;

    /** Confirmada: hay dispositivo y toca emitir su token. **Una sola vez.** */
    case Paired;

    /**
     * No se puede completar. **Un solo caso para las tres causas**: solicitud
     * desconocida, secreto que no coincide, y caducada o ya consumida.
     */
    case Rejected;
}
