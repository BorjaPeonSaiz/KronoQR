<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * El actor de una entrada de auditoria: su tipo y, si lo hay, su identificador.
 *
 * **Nunca su nombre** (regla dura 21). Un `audit_log` con nombres en claro se
 * convierte en un directorio de plantilla exportable; con identificadores, la
 * inspeccion resuelve el nombre contra `users`/`devices` cuando de verdad hace
 * falta, y el trail sigue siendo minimo (RGPD, minimizacion).
 */
final readonly class AuditActor
{
    private function __construct(
        public AuditActorType $type,
        public ?int $id,
    ) {}

    public static function user(int $userId): self
    {
        return new self(AuditActorType::User, $userId);
    }

    public static function device(int $deviceId): self
    {
        return new self(AuditActorType::Device, $deviceId);
    }

    /** Scheduler, colas y comandos: no hay persona detras. */
    public static function system(): self
    {
        return new self(AuditActorType::System, null);
    }

    /** Rol de mantenimiento de base de datos (ADR-027, tarea 2.10). */
    public static function maintenance(): self
    {
        return new self(AuditActorType::Maintenance, null);
    }

    /**
     * Reconstruccion desde la fila persistida, para el verificador. No valida
     * nada que la base de datos no valide ya: su trabajo es reproducir el valor
     * exacto que se encadeno.
     */
    public static function fromStorage(string $type, ?int $id): self
    {
        return new self(AuditActorType::from($type), $id);
    }

    /**
     * Forma canonica del componente `actor` de la formula del §7.4.
     *
     * Termina en separador de registro (`\x1e`) para que la concatenacion de los
     * seis componentes no tenga frontera ambigua: sin el, un actor `user` con
     * accion `x.y` y un actor `userx` con accion `.y` producirian la misma
     * cadena y, por tanto, el mismo hash.
     */
    public function canonical(): string
    {
        return $this->type->value.'#'.($this->id === null ? '' : (string) $this->id)."\x1e";
    }
}
