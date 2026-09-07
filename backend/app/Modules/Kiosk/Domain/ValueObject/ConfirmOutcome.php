<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Domain\ValueObject;

/**
 * Lo que puede pasar cuando un `admin` teclea un codigo en
 * `POST /api/v1/kiosk/pair/confirm` (**RF-PD-06**).
 *
 * ## `Rejected` tampoco se subdivide aqui, y el motivo es otro
 *
 * En el `claim` —publico— la respuesta unica protege un secreto. Aqui la ruta
 * esta autenticada y a un administrador no hay que ocultarle nada: lo que hace
 * que las tres causas —el codigo no existe, ha caducado, ya se uso— compartan
 * respuesta es que **tienen exactamente la misma accion siguiente**, que es
 * pedirle a la tablet que muestre otro codigo. Distinguirlas seria un mensaje mas
 * que traducir, mantener y probar sin que nadie hiciera nada distinto al leerlo.
 * Quien si necesita ver la caducidad es la tablet, y la tiene en su cuenta atras.
 *
 * ## Un `confirm` rechazado no consume nada
 *
 * No hay caso `Consumed` ni contador de intentos: un codigo mal tecleado deja la
 * solicitud exactamente como estaba. Si la gastara, un dedo torpe obligaria a
 * empezar de cero cada vez (regla dura 19).
 */
enum ConfirmOutcome
{
    /** El codigo era bueno: el dispositivo queda creado o reactivado y activo. */
    case Confirmed;

    /** Codigo inexistente, caducado o ya usado. **Una sola respuesta para los tres.** */
    case Rejected;
}
