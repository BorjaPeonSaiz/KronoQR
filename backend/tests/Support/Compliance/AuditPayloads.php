<?php

declare(strict_types=1);

namespace Tests\Support\Compliance;

/**
 * El `payload` de un asiento de `audit_log` leido de la base de datos, ya
 * decodificado. Un valor que no sea un objeto JSON da un mapa vacio: la prueba
 * que lo lea falla por lo que espera, no por el tipo.
 */
final class AuditPayloads
{
    /**
     * @return array<string, mixed>
     */
    public static function decode(mixed $payload): array
    {
        $decoded = \is_string($payload) ? json_decode($payload, true) : null;

        if (! \is_array($decoded)) {
            return [];
        }

        $map = [];

        foreach ($decoded as $key => $value) {
            $map[(string) $key] = $value;
        }

        return $map;
    }

    public static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
