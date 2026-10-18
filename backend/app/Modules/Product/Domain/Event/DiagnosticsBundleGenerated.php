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
 * viaja el contenido —el asiento acaba en el trail y el trail se exporta—. El
 * actor lo resuelve la sesion en curso, salvo por consola, donde viaja la
 * cuenta que firma (`responsibleUserId`, 2.3.0 bloque 3).
 *
 * ## `deferred`: el asiento que se escribe al volver la base (ADR-020, enmienda
 * del 14-10-2026)
 *
 * Con PostgreSQL caido, el paquete anonimizado de consola se entrega con su
 * asiento pendiente. Cuando la base vuelve, `Product` publica este mismo evento
 * otra vez con `deferred = true` y `generatedAt` = el instante en que se genero
 * el paquete. `occurredAt` es entonces el instante en que se escribe el asiento,
 * nunca el de la generacion: la cadena de ADR-010 y el sellado de ADR-027
 * suponen `occurred_at` creciente. Con `deferred = false` (el caso normal)
 * `generatedAt` es nulo y el asiento se escribe en el momento.
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
        public bool $deferred = false,
        public ?DateTimeImmutable $generatedAt = null,
        /**
         * La cuenta que firma el paquete pedido por consola (2.3.0 bloque 3):
         * el asiento es suyo y no de `system`. `null` por el panel, donde
         * firma la sesion, y en el anonimizado de consola.
         */
        public ?int $responsibleUserId = null,
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
