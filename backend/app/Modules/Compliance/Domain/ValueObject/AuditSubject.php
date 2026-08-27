<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * Sobre que recae la accion: `shift_entry`, `credential`, `device`, `employee`,
 * `installation_setting`... y su identificador interno.
 *
 * Es texto libre a proposito, al contrario que `AuditAction`: el sujeto lo
 * nombra el modulo que lo posee y no hay un catalogo comun que pueda
 * mantenerse sin acoplar los ocho modulos entre si. Lo que si esta acotado es
 * que **no lleva datos personales**: un `subject_id` es una clave, nunca un
 * DNI, un nombre ni un correo (regla dura 21).
 *
 * Puede no haber sujeto —una exportacion legal de un periodo, por ejemplo—, y
 * por eso existe `none()`.
 */
final readonly class AuditSubject
{
    private function __construct(
        public ?string $type,
        public ?int $id,
    ) {}

    public static function of(string $type, ?int $id = null): self
    {
        return new self($type, $id);
    }

    public static function none(): self
    {
        return new self(null, null);
    }

    public static function fromStorage(?string $type, ?int $id): self
    {
        return new self($type, $id);
    }

    /**
     * Forma canonica del componente `subject` de la formula del §7.4, con el
     * mismo separador de registro final que `AuditActor::canonical()` y por la
     * misma razon.
     */
    public function canonical(): string
    {
        return ($this->type ?? '').'#'.($this->id === null ? '' : (string) $this->id)."\x1e";
    }
}
