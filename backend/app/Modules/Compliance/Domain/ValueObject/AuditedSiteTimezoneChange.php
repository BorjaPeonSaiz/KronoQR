<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * Un asiento `site.updated` de un centro, tal y como se leyo, para conciliar la
 * historia de su zona horaria (RN-23, ADR-056; bloque 2 de la 2.3.0).
 *
 * Dice de que zona a que zona paso el centro, si el asiento lleva **la firma
 * de una correccion de consola** —`via: console`, motivo, numero de tramos y
 * cuenta que responde, lo que escribe `RecordSiteConfiguration` desde
 * `workforce:correct-site-timezone`— y si se escribio **despues del primer
 * tramo**. Lo ultimo lo resuelve la consulta por el orden de la cadena
 * (ADR-010: el `id` de `audit_log` es el orden de los hechos), comparando con el
 * primer asiento `shift_entry.*`; no con relojes, que en la carrera del primer
 * fichaje podrian decir lo contrario.
 */
final readonly class AuditedSiteTimezoneChange
{
    public function __construct(
        public int $auditEntryId,
        /** `previous_value.timezone`, o `null` si no lo trae. */
        public ?string $from,
        /** `new_value.timezone`, o `null` si no lo trae. */
        public ?string $to,
        /** Lleva motivo, recuento y cuenta de una correccion de consola. */
        public bool $signed,
        /** Hay un asiento `shift_entry.*` anterior en la cadena. */
        public bool $afterWorkRecord,
    ) {}

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public static function fromEntry(int $auditEntryId, array $payload, bool $afterWorkRecord): self
    {
        return new self(
            $auditEntryId,
            self::timezone($payload['previous_value'] ?? null),
            self::timezone($payload['new_value'] ?? null),
            self::isSigned($payload),
            $afterWorkRecord,
        );
    }

    /**
     * La firma de `workforce:correct-site-timezone` en el asiento: `via:
     * console`, un motivo, al menos un tramo y una cuenta.
     *
     * @param  array<array-key, mixed>  $payload
     */
    private static function isSigned(array $payload): bool
    {
        $reason = $payload['reason'] ?? null;

        return ($payload['via'] ?? null) === 'console'
            && \is_string($reason) && trim($reason) !== ''
            && self::positive($payload['shift_entry_count'] ?? null)
            && self::positive($payload['responsible_user_id'] ?? null);
    }

    private static function positive(mixed $value): bool
    {
        return \is_int($value) && $value >= 1;
    }

    /** Si el asiento cambia la zona: las dos conocidas y distintas. */
    public function changesTimezone(): bool
    {
        return $this->from !== null && $this->to !== null && $this->from !== $this->to;
    }

    /** La clave con que se empareja con una fila de la traza. */
    public function key(): string
    {
        return $this->from.' -> '.$this->to;
    }

    private static function timezone(mixed $value): ?string
    {
        $timezone = \is_array($value) ? ($value['timezone'] ?? null) : null;

        return \is_string($timezone) ? $timezone : null;
    }
}
