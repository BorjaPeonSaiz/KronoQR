<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Product\Application\Port\SupportGrantRepository;
use App\Modules\Product\Domain\Model\SupportGrant;
use App\Modules\Shared\Application\Port\Clock;
use DateTimeImmutable;

/**
 * Las concesiones de soporte de esta instalacion (**RF-PD-11**, ADR-020).
 *
 * ## Es la mitad «visible para el cliente» del requisito
 *
 * RF-PD-11 no pide solo que el acceso sea temporal y revocable: pide que el
 * cliente **lo vea**. Sin esta lista, saber si el fabricante entro exigiria leer
 * `audit_log`, que es una tabla tecnica con cuatro años de asientos de todo el
 * producto. Aqui estan las concesiones y ya: quien las autorizo, por que, hasta
 * cuando y cuando se usaron por ultima vez.
 *
 * ## Las revocadas y las caducadas TAMBIEN salen
 *
 * Nada se borra (regla dura 5). Una lista que enseñara solo las activas
 * respondería «no hay ningun acceso» a la pregunta «¿ha entrado alguien alguna
 * vez?», que es la peor respuesta posible: literalmente cierta y completamente
 * engañosa.
 *
 * ## El estado se calcula al leer
 *
 * Con el reloj de este instante y no con una columna (ver
 * `SupportGrantStatus`): asi lo que dice la pantalla es lo mismo que hace el
 * token, sin depender de que ninguna tarea programada haya corrido.
 */
final readonly class ListSupportGrantsHandler
{
    /** Lo que declara el contrato: `maxItems: 100`. */
    public const int LIMIT = 100;

    public function __construct(
        private SupportGrantRepository $grants,
        private Clock $clock,
    ) {}

    /** @return list<SupportGrant> */
    public function handle(): array
    {
        return $this->grants->recent(self::LIMIT);
    }

    /**
     * El instante contra el que se resuelve el estado de cada fila.
     *
     * Se expone para que el Resource use **el mismo** para todas: si cada fila
     * preguntara la hora por su cuenta, una lista larga podria enseñar dos
     * concesiones con estados incoherentes entre si.
     */
    public function asOf(): DateTimeImmutable
    {
        return $this->clock->now();
    }
}
