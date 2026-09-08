<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Event;

use App\Modules\Product\Domain\ValueObject\DataExportOrigin;
use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Alguien ha pedido la exportacion integra de todos los datos de la instalacion
 * (**RF-PD-14**, RL-20, RS-05).
 *
 * ## Por que se audita el ACTO DE PEDIRLA y no solo el de generarla
 *
 * Porque son dos hechos distintos y pueden no coincidir: entre uno y otro pasa
 * la cola, y una generacion puede fallar. Sin este asiento, una exportacion que
 * revienta al minuto de pedirse no dejaria ningun rastro de que alguien intento
 * llevarse una copia completa de la plantilla — y esa intencion es exactamente
 * lo que una revision de accesos masivos a datos personales busca (RS-05).
 *
 * ## `requestedByUserId` viaja dentro del evento
 *
 * El listener lo necesita para construir `AuditActor::user()`, y aqui hay
 * sesion, asi que en la practica coincide con lo que resolveria
 * `CurrentAuditContext`. Va dentro igualmente porque el hermano
 * {@see DataExportGenerated} **no** tiene sesion —lo publica el trabajador de
 * cola— y los dos tienen que atribuirse igual. Nulo cuando la pidio la consola:
 * `system`.
 */
final readonly class DataExportRequested implements DomainEvent
{
    public function __construct(
        public string $uuid,
        public DataExportOrigin $requestedVia,
        public ?int $requestedByUserId,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventName(): string
    {
        return 'product.data_export_requested';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
