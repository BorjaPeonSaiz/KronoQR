<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Support;

use App\Modules\Shared\Domain\ValueObject\UtcPrecision;
use App\Modules\Shared\Domain\ValueObject\UtcWallTime;
use DateTimeImmutable;
use DateTimeZone;

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
    public const string FORMAT = UtcPrecision::Microseconds->value;

    /**
     * Fecha y hora con segundos (grupos 1 y 2), decimales (3) y desfase (4).
     * Las horas del desfase, de 00 a 14, que es lo que existe.
     */
    private const string WRITTEN_INSTANT = '/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?(Z|[+-](?:0\d|1[0-4])(?::?[0-5]\d)?)?$/';

    /**
     * Un instante que seguro existe.
     *
     * Separado de {@see self::format()} para que los campos obligatorios del
     * contrato —`manifest.generated_at`, `doctor.checked_at`— no dependan de un
     * valor por defecto puesto en el sitio que los serializa.
     *
     * La precision es la del campo en el contrato ({@see UtcPrecision}); por
     * omision, microsegundos, que es lo que guarda la base de datos.
     */
    public static function of(DateTimeImmutable $instant, UtcPrecision $precision = UtcPrecision::Microseconds): string
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format($precision->value);
    }

    /** Lo mismo para un campo anulable: `null` sigue siendo `null`. */
    public static function format(?DateTimeImmutable $instant, UtcPrecision $precision = UtcPrecision::Microseconds): ?string
    {
        return $instant === null ? null : self::of($instant, $precision);
    }

    /**
     * Una hora UTC para un texto que ya la rotula «UTC» (`caduca el 2026-10-25
     * 01:30 UTC`), sin `Z` y sin desfase: la forma la elige {@see UtcWallTime}.
     *
     * La misma trampa que el instante del contrato: sin `setTimezone()`, una
     * hora en otra zona sale con su hora local y la etiqueta miente.
     */
    public static function wallTime(DateTimeImmutable $instant, UtcWallTime $shape = UtcWallTime::Minute): string
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format($shape->value);
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

        if (! is_string($value)) {
            return null;
        }

        $instant = self::parse($value);

        return $instant instanceof DateTimeImmutable ? self::of($instant) : null;
    }

    /**
     * Un instante **escrito**, en UTC, o `null` si el texto no es uno.
     *
     * Solo la forma ISO-8601 o la de PostgreSQL: fecha, `T` o espacio, hora con
     * segundos, hasta seis decimales y, opcionalmente, `Z` o un desfase
     * (`+00`, `+02:00`, `+0200`). Sin desfase es UTC, como la sesion de la base
     * de datos.
     *
     * **Nunca con el analizador libre de PHP.** `new DateTimeImmutable('now')`,
     * `'tomorrow'` o `'+1 week'` dan la hora del proceso: el dominio leeria el
     * reloj del sistema a traves de una cadena (regla dura 2), y la conciliacion,
     * que compara marcas venidas de un asiento que se puede manipular, dejaria de
     * ser determinista (V1-AR-2). Tampoco desplaza en silencio un 31 de febrero
     * o una hora 25: no son instantes, son errores.
     */
    public static function parse(string $value): ?DateTimeImmutable
    {
        if (preg_match(self::WRITTEN_INSTANT, $value, $parts) !== 1) {
            return null;
        }

        $wallTime = $parts[1].' '.$parts[2];
        $fraction = $parts[3] ?? '';
        $offset = $parts[4] ?? '';

        // `P` lee las cuatro formas del desfase (`Z`, `+02`, `+0200`, `+02:00`);
        // sin desfase es UTC, como la sesion de la base de datos.
        $parsed = DateTimeImmutable::createFromFormat(
            $fraction === '' ? '!Y-m-d H:i:sP' : '!Y-m-d H:i:s.uP',
            $wallTime.($fraction === '' ? '' : '.'.$fraction).($offset === '' ? 'Z' : $offset),
        );

        // `createFromFormat` acepta el 31 de febrero y lo lleva a marzo: si la
        // fecha y la hora no son las escritas, no era un instante.
        if ($parsed === false || $parsed->format('Y-m-d H:i:s') !== $wallTime) {
            return null;
        }

        return $parsed->setTimezone(new DateTimeZone('UTC'));
    }
}
