<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * El unico sitio donde se escribe la forma de un instante en la API
 * (esquema `UtcTimestamp` del contrato, regla dura 3).
 *
 * ## Por que existe
 *
 * `Y-m-d\TH:i:s.u\Z` estaba escrito a mano en varios recursos, y el formato
 * llevaba dos trampas que no se ven leyendo:
 *
 * 1. **La `Z` es literal.** `format()` no convierte nada: si el objeto viene en
 *    otra zona, se escribe su hora local con una `Z` detras y el instante queda
 *    desplazado sin que nada falle. Aqui el `setTimezone(UTC)` es obligatorio y
 *    esta una sola vez.
 * 2. **El patron del contrato exige `Z` y no `+00:00`.** `DateTimeInterface::ATOM`
 *    produce lo segundo, valida igual de bien como fecha y **no** cumple el
 *    esquema, asi que la respuesta se rechaza en la prueba de contrato y no
 *    antes.
 *
 * Vive en `Shared/Domain` por el criterio de admision de ADR-021: lo necesitan
 * varios modulos, no es una regla de negocio de ninguno y no depende de nada.
 */
final readonly class UtcInstant
{
    /** ISO-8601 en UTC con microsegundos, la forma del esquema `UtcTimestamp`. */
    public const string FORMAT = 'Y-m-d\TH:i:s.u\Z';

    /**
     * Un instante que seguro existe.
     *
     * Separado de {@see self::format()} para que los campos obligatorios del
     * contrato —`manifest.generated_at`, `doctor.checked_at`— no dependan de un
     * valor por defecto puesto en el sitio que los serializa.
     */
    public static function of(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format(self::FORMAT);
    }

    public static function format(?DateTimeImmutable $instant): ?string
    {
        return $instant === null ? null : self::of($instant);
    }

    /**
     * Un instante para un nombre de fichero: `20260908T090905Z`, sin separadores
     * ni microsegundos.
     *
     * Existe aqui y no en quien compone el nombre porque arrastra la misma
     * trampa: la `Z` es literal y sin `setTimezone()` el nombre mentiria sobre
     * la hora a la que se genero el fichero.
     */
    public static function compact(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    /**
     * Un instante leido de la base de datos como texto
     * (`2026-09-08 09:07:33.123456+00`).
     *
     * **Nulo si no se puede interpretar, en lugar de lanzar.** Quien lo usa es
     * el paquete de diagnostico, y un paquete que reventara por una fecha rara
     * en una fila seria inutil justo en la instalacion donde hay una fecha rara
     * en una fila.
     */
    public static function fromDatabase(mixed $value): ?string
    {
        if ($value instanceof DateTimeImmutable) {
            return self::of($value);
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return self::of(new DateTimeImmutable($value, new DateTimeZone('UTC')));
        } catch (Throwable) {
            return null;
        }
    }
}
