<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

use App\Modules\Shared\Domain\Support\UtcInstant;
use DateTimeImmutable;
use DateTimeZone;

/**
 * El asiento `diagnostics.bundle_generated` que no se pudo escribir al generar
 * el paquete porque la base de datos no respondia (ADR-020, enmienda del
 * 14-10-2026; RL-04, RL-19).
 *
 * ## Claves cerradas, y ninguna de texto libre
 *
 * `sha256`, `sections`, `size_bytes`, `anonymized`, `generated_by`,
 * `generated_at` y `reason`. Son exactamente los datos del asiento que se
 * escribira al volver la base, mas el motivo del aplazamiento como
 * identificador. **Ni el mensaje de la excepcion, ni el host, ni el usuario**:
 * el mensaje de un `PDOException` lleva la cadena de conexion, y este fichero
 * vive en el volumen del cliente y puede acabar en una copia.
 *
 * ## Solo existe para el paquete anonimizado
 *
 * Por eso `anonymized` es siempre `true` al crearlo. El campo se conserva
 * porque es parte del asiento y porque un fichero que dijera otra cosa al
 * releerlo se rechaza: el paquete con datos personales nunca se entrega sin
 * su asiento (RL-19).
 */
final readonly class PendingDiagnosticsAudit
{
    /** El unico motivo admitido hoy. */
    public const string REASON_DATABASE_UNAVAILABLE = 'database_unavailable';

    /** Las claves del fichero, en este orden y sin ninguna mas. */
    public const array KEYS = ['sha256', 'sections', 'size_bytes', 'anonymized', 'generated_by', 'generated_at', 'reason'];

    /**
     * @param  list<string>  $sections
     */
    private function __construct(
        public string $sha256,
        public array $sections,
        public int $sizeBytes,
        public bool $anonymized,
        public string $generatedBy,
        public DateTimeImmutable $generatedAt,
        public string $reason,
    ) {}

    /**
     * El pendiente de un paquete ya sellado con su constancia.
     */
    public static function of(DiagnosticsBundle $bundle, string $reason): self
    {
        return new self(
            sha256: $bundle->manifest->sha256,
            sections: $bundle->manifest->sections,
            sizeBytes: \strlen($bundle->toJson()),
            anonymized: $bundle->manifest->anonymized,
            generatedBy: $bundle->manifest->generatedBy->value,
            generatedAt: $bundle->manifest->generatedAt,
            reason: $reason,
        );
    }

    /**
     * Relee un pendiente del disco. Nulo si no tiene exactamente la forma que
     * escribe {@see self::toArray()}: un fichero alterado no se convierte en un
     * asiento.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        if (array_keys($data) !== self::KEYS || ! self::wellFormed($data)) {
            return null;
        }

        /** @var array{sha256: string, sections: list<string>, size_bytes: int, anonymized: true, generated_by: string, generated_at: string, reason: string} $data */
        // El formato exacto que escribe `toArray()`, y no cualquier texto que
        // PHP sepa interpretar: «now» seria un instante inventado.
        $generatedAt = DateTimeImmutable::createFromFormat('!'.UtcInstant::FORMAT, $data['generated_at'], new DateTimeZone('UTC'));

        if (! $generatedAt instanceof DateTimeImmutable) {
            return null;
        }

        return new self(
            sha256: $data['sha256'],
            sections: $data['sections'],
            sizeBytes: $data['size_bytes'],
            anonymized: true,
            generatedBy: $data['generated_by'],
            generatedAt: $generatedAt,
            reason: $data['reason'],
        );
    }

    /**
     * Cada clave con su tipo y su dominio. Sin `anonymized: true` ni
     * `generated_by: console` no hay pendiente posible: el paquete con datos
     * personales y el del panel nunca se aplazan. Las secciones, solo de las
     * que existen: un nombre inventado no entra en el trail.
     *
     * @param  array<array-key, mixed>  $data
     */
    private static function wellFormed(array $data): bool
    {
        return self::isDigest($data['sha256'])
            && self::isSectionList($data['sections'])
            && is_int($data['size_bytes']) && $data['size_bytes'] >= 0
            && $data['anonymized'] === true
            && $data['generated_by'] === DiagnosticsActor::Console->value
            && is_string($data['generated_at'])
            && $data['reason'] === self::REASON_DATABASE_UNAVAILABLE;
    }

    private static function isSectionList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value)
            && array_filter($value, static fn (mixed $name): bool => \in_array($name, DiagnosticsBundle::SECTIONS, true)) === $value;
    }

    private static function isDigest(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{64}$/D', $value) === 1;
    }

    /**
     * @return array{sha256: string, sections: list<string>, size_bytes: int, anonymized: bool, generated_by: string, generated_at: string, reason: string}
     */
    public function toArray(): array
    {
        return [
            'sha256' => $this->sha256,
            'sections' => $this->sections,
            'size_bytes' => $this->sizeBytes,
            'anonymized' => $this->anonymized,
            'generated_by' => $this->generatedBy,
            'generated_at' => UtcInstant::of($this->generatedAt),
            'reason' => $this->reason,
        ];
    }
}
