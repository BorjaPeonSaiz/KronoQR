<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Se ha dado de alta algo que deja la instalacion por encima de una cifra del
 * plan (**ADR-028**, RF-PD-04, RL-04).
 *
 * ## El alta ya ocurrio
 *
 * Este evento se publica **despues**, desde un observador que escucha las altas.
 * No es una advertencia previa y no puede cancelar nada: para cuando existe, la
 * persona ya esta dada de alta y puede fichar, o el quiosco ya tiene su token.
 * Es exactamente lo que ADR-028 exige — *«el conteo es un observador, no un
 * guardian»*— y la razon esta escrita ahi: bloquear el alta deja a alguien
 * trabajando sin registro horario, y bloquear el emparejamiento deja un centro
 * sin punto de fichaje el dia que se avería el quiosco.
 *
 * ## `firstCrossing` distingue las dos cosas que hay que poder contar
 *
 * ADR-028 pide asiento **al cruzar el umbral** y **en cada alta posterior en
 * exceso**. Son dos hechos distintos para quien lee el trail: el primero da la
 * fecha desde la que el cliente opera fuera de contrato —que es la que sostiene
 * una reclamacion— y los siguientes dan la magnitud. Sin el campo, habria que
 * deducir cual fue el primero ordenando por fecha y confiando en que no falte
 * ninguno.
 *
 * ## Sin datos personales
 *
 * Aqui no hay nombres ni UUID de empleado: viajan cifras. Quien se dio de alta
 * ya tiene su propio asiento en el trail (regla dura 21).
 */
final readonly class PlanLimitExceeded implements DomainEvent
{
    public function __construct(
        /** `max_employees` o `max_devices`, el nombre del campo de la clave firmada. */
        public string $limit,
        public int $contracted,
        public int $reached,
        public bool $firstCrossing,
        public string $licenseId,
        public ?int $actorUserId,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventName(): string
    {
        return 'product.plan_limit_exceeded';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
