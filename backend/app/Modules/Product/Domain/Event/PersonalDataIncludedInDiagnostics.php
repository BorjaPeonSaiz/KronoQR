<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Un paquete de diagnostico ha salido **con datos personales dentro**
 * (**RL-19**, RF-PD-09, regla dura 6, ADR-020).
 *
 * ## Asiento propio, y no un campo del anterior
 *
 * Es el punto entero de RL-19: *«incluir datos personales es una accion
 * distinta»*. Si fuera un booleano dentro de `diagnostics.bundle_generated`,
 * responder «¿cuando han salido de aqui datos de la plantilla?» obligaria a
 * recorrer todos los asientos de generacion filtrando por un campo, y esa
 * consulta es justo la que un cliente tiene que poder hacer de un vistazo —ante
 * una brecha, RL-15, la capacidad de determinar el alcance es suya—.
 *
 * Con dos acciones distintas, la pregunta es un `WHERE action =`. Con una sola,
 * es una auditoria.
 *
 * ## Se publica ADEMAS del otro, nunca en su lugar
 *
 * El paquete se genero, y eso tambien es cierto. Los dos asientos describen dos
 * hechos que ocurrieron a la vez, no dos versiones del mismo.
 */
final readonly class PersonalDataIncludedInDiagnostics implements DomainEvent
{
    /**
     * @param  list<string>  $collections  Que colecciones llevaba: `employees`, `shift_entries`...
     */
    public function __construct(
        public int $periodDays,
        public array $collections,
        public string $sha256,
        public string $generatedBy,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventName(): string
    {
        return 'product.personal_data_included_in_diagnostics';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
