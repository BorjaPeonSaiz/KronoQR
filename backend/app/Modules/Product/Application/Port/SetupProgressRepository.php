<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Port;

use App\Modules\Product\Domain\ValueObject\SetupStep;
use App\Modules\Product\Domain\ValueObject\SetupStepState;
use DateTimeImmutable;

/**
 * Lo que el asistente de puesta en marcha ya ha resuelto (RF-PD-03).
 *
 * **Solo guarda lo que no se puede deducir.** Los pasos derivados
 * —`administrator` y `site`— no pasan por aqui: su estado se lee del dato real,
 * y {@see SetupState} los recompone ignorando cualquier marca. Este puerto
 * guarda decisiones («omito la licencia por ahora») que no tienen ningun dato
 * del que deducirse.
 */
interface SetupProgressRepository
{
    /**
     * Las marcas guardadas, indexadas por el valor del paso.
     *
     * @return array<string, SetupStepState>
     */
    public function recorded(): array;

    /**
     * Guarda —o sustituye— la marca de un paso. Idempotente: es lo que hace que
     * el `PUT` del contrato lo sea sin ninguna comprobacion previa.
     */
    public function record(
        SetupStep $step,
        SetupStepState $state,
        DateTimeImmutable $at,
        ?string $actorUuid,
    ): void;

    /**
     * Cierra el asistente. **No se deshace**: no hay metodo para reabrirlo, y no
     * lo hay a proposito (RF-PD-03: es de un solo uso).
     */
    public function complete(DateTimeImmutable $at, ?string $actorUuid): void;

    /** Cuando se cerro, o `null` si sigue abierto. */
    public function completedAt(): ?DateTimeImmutable;
}
