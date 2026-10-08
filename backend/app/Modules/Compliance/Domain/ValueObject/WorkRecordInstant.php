<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

use App\Modules\Shared\Domain\ValueObject\UtcInstant;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Como lee la conciliacion una marca escrita como texto, en la fila o en el
 * asiento (ADR-057 §4).
 *
 * La forma canonica es la de {@see UtcInstant}, la misma que escribe
 * `RecordShiftEntryAudit`: dos marcas cuadran si son el mismo instante, aunque
 * una venga con desplazamiento y otra con `Z`.
 */
final class WorkRecordInstant
{
    private function __construct() {}

    /**
     * El instante, o `null` si no se puede leer. Con el instante escrito y no
     * «ahora»: lee una marca, no pregunta la hora (regla dura 2).
     */
    public static function parse(?string $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'))->setTimezone(new DateTimeZone('UTC'));
        } catch (Exception) {
            return null;
        }
    }

    /**
     * La forma canonica, o la cadena tal cual si no se puede leer: dos cadenas
     * ilegibles distintas siguen siendo distintas.
     */
    public static function normalized(string $value): string
    {
        return UtcInstant::fromDatabase($value) ?? $value;
    }

    public static function same(?string $expected, ?string $actual): bool
    {
        if ($expected === null || $actual === null) {
            return $expected === $actual;
        }

        return self::normalized($expected) === self::normalized($actual);
    }

    /**
     * Si dos marcas son distintas **al segundo**, que es como lo decide el
     * registro cuando una correccion cambia el origen de un lado
     * (`Attendance\Domain\Model\ShiftEntry::nextVersion()` compara
     * `getTimestamp()`). Ilegible o ausente cuenta como distinta.
     */
    public static function differsToTheSecond(?string $before, ?string $after): bool
    {
        $a = self::parse($before);
        $b = self::parse($after);

        if (! $a instanceof DateTimeImmutable || ! $b instanceof DateTimeImmutable) {
            return $a instanceof DateTimeImmutable || $b instanceof DateTimeImmutable;
        }

        return $a->getTimestamp() !== $b->getTimestamp();
    }
}
