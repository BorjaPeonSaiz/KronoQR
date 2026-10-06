<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

/**
 * Cuantas cifras tiene un PIN que emite esta instalacion (RF-ID-09, ADR-050).
 *
 * **Dos casos y ninguno mas.** Seis es el valor de serie y basta con el portal en
 * la red interna (ADR-015); ocho es la recomendacion cuando el portal es
 * accesible desde internet, porque el bloqueo por origen no frena a quien rota
 * direcciones y 10^8 si (ADR-050, residuo 1). Un enum y no un entero acotado
 * porque **una longitud de siete no se puede construir**: ni el teclado del
 * quiosco ni el contrato (`IssuedPin.pin`) la contemplan, y un rango 6-8 la
 * dejaria pasar.
 *
 * **Describe la EMISION, no la comprobacion.** El PIN se compara por su hash, y
 * un PIN de 6 emitido antes de pasar a 8 sigue valiendo hasta que se
 * restablece: el portal y el quiosco aceptan de 6 a 8 cifras sea cual sea el
 * ajuste, y uno de 7 es simplemente un PIN incorrecto.
 *
 * El valor de la instalacion es configuracion auditada (`IDENTITY_PIN_LENGTH`,
 * regla dura 13) y llega por el puerto `PinLengthProvider` de `Shared/Application`.
 */
enum PinLength: int
{
    case SIX = 6;

    case EIGHT = 8;

    /** El mayor PIN representable con esta longitud: `999999` o `99999999`. */
    public function maximum(): int
    {
        return 10 ** $this->value - 1;
    }

    /** Si una cadena tiene la forma de un PIN de esta longitud: solo cifras, y exactamente estas. */
    public function fits(string $pin): bool
    {
        return \strlen($pin) === $this->value && ctype_digit($pin);
    }
}
