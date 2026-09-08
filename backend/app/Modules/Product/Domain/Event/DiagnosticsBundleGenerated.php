<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Se ha generado un paquete de diagnostico (**RF-PD-09**, RL-19, regla dura 6).
 *
 * ## Por que se audita algo que no cambia nada
 *
 * Porque **saca datos de la instalacion**. El paquete es el unico canal por el
 * que informacion del cliente llega al fabricante (ADR-020), y el cliente tiene
 * que poder responder «¿que ha salido de aqui, cuando y a instancia de quien?»
 * sin depender de la palabra de nadie. Es la misma pregunta que responde
 * `legal_export.generated`, y por eso se audita igual.
 *
 * `Compliance` lo sella como `diagnostics.bundle_generated`, en la familia
 * `SupportAccess` del bloque D. Por un evento y no por una llamada directa
 * porque el §1.6 no concede la arista `Product -> Compliance`.
 *
 * ## Lo que viaja, y lo que no
 *
 * Que secciones llevaba, si iba anonimizado, su tamaño y su huella: lo justo
 * para reconocer **ese** paquete si vuelve mas adelante en una conversacion. No
 * viaja el contenido —el asiento acaba en el trail y el trail se exporta— ni el
 * actor, que lo resuelve la sesion en curso.
 */
final readonly class DiagnosticsBundleGenerated implements DomainEvent
{
    /**
     * @param  list<string>  $sections
     */
    public function __construct(
        public bool $anonymized,
        public array $sections,
        public string $sha256,
        public int $sizeBytes,
        public string $generatedBy,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventName(): string
    {
        return 'product.diagnostics_bundle_generated';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
