<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Product\Application\Command\RecordSetupStepCommand;
use App\Modules\Product\Application\Port\SetupProgressRepository;
use App\Modules\Product\Domain\Exception\SetupNotCompletable;
use App\Modules\Product\Domain\Exception\SetupStepNotRecordable;
use App\Modules\Product\Domain\ValueObject\SetupState;
use App\Modules\Product\Domain\ValueObject\SetupStepState;
use App\Modules\Shared\Application\Port\Clock;

/**
 * Deja constancia de que un paso del asistente se termino o se omitio
 * (**RF-PD-03**, `PUT /api/v1/setup/steps/{step}`).
 *
 * ## Es lo que hace el asistente reanudable
 *
 * Sin esto, abandonar la puesta en marcha a la mitad —que es lo normal: falta un
 * dato, hay que llamar a alguien, la tablet llega mañana— obligaria a empezar de
 * cero. Con esto, se vuelve y el asistente sabe donde estaba.
 *
 * ## Tres negativas, y ninguna es de permisos
 *
 * 1. **El asistente ya se cerro.** `409`. No se reabre: lo que se cambia
 *    despues se cambia por el recurso que corresponda, que es donde el cambio
 *    queda auditado con su motivo (RL-04).
 * 2. **El paso es derivado.** `422`. `administrator` y `site` se completan
 *    haciendolos, no declarandolos.
 * 3. **El paso no es omitible.** `422`. El perfil de convenio no se aparca
 *    (RL-21).
 *
 * ## Sin evento de dominio, a proposito
 *
 * Marcar «he mirado el paso de departamentos» no es un hecho con relevancia
 * legal: no cambia un umbral, ni una potestad, ni un minuto trabajado. **Lo que
 * si se audita es lo que ocurre dentro de cada paso** —el alta del centro, la
 * activacion de la licencia, el alta masiva de plantilla, la asignacion del rol
 * del primer administrador—, cada uno desde su propio caso de uso y con su
 * propia accion del catalogo. Auditar tambien la casilla llenaria el trail de
 * ruido justo en la seccion donde importa que no lo haya.
 */
final readonly class RecordSetupStepHandler
{
    public function __construct(
        private SetupProgressRepository $progress,
        private GetSetupStateHandler $state,
        private Clock $clock,
    ) {}

    /**
     * @throws SetupNotCompletable si el asistente ya esta cerrado
     * @throws SetupStepNotRecordable si el paso no admite esa marca
     */
    public function handle(RecordSetupStepCommand $command): SetupState
    {
        $state = $this->state->handle();

        if (! $state->isAvailable()) {
            throw SetupNotCompletable::becauseItAlreadyIs();
        }

        if ($command->step->isDerived()) {
            throw SetupStepNotRecordable::becauseItIsDerived($command->step);
        }

        if ($command->state === SetupStepState::SKIPPED && ! $command->step->isSkippable()) {
            throw SetupStepNotRecordable::becauseItCannotBeSkipped($command->step);
        }

        $this->progress->record(
            $command->step,
            $command->state,
            $this->clock->now(),
            $command->actorUuid,
        );

        return $this->state->handle();
    }
}
