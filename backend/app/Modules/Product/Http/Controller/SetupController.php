<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Product\Application\UseCase\CompleteSetupHandler;
use App\Modules\Product\Application\UseCase\GetSetupStateHandler;
use App\Modules\Product\Application\UseCase\RecordSetupStepHandler;
use App\Modules\Product\Domain\ValueObject\SetupState;
use App\Modules\Product\Domain\ValueObject\SetupStep;
use App\Modules\Product\Http\Request\RecordSetupStepRequest;
use App\Modules\Product\Http\Resource\SetupCompletionResource;
use App\Modules\Product\Http\Resource\SetupStatusResource;
use App\Modules\Shared\Application\Port\ManagementActor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Psr\Log\LoggerInterface;

/**
 * Los tres endpoints del asistente que son de `Product`: consultar su estado,
 * marcar un paso y cerrarlo (**RF-PD-03**).
 *
 * **Un controlador con tres metodos y no tres de invocacion unica**, al
 * contrario que el resto del modulo, por el mismo criterio que
 * `TwoFactorController`: son pasos del **mismo** flujo, comparten el estado como
 * entrada y separarlos repartiria por tres ficheros una secuencia que solo se
 * entiende junta.
 *
 * ## Los otros dos endpoints del asistente no estan aqui, y no pueden estarlo
 *
 * `POST /api/v1/setup/administrator` crea una cuenta de gestion y vive en
 * `Identity`; `POST /api/v1/setup/site` crea el centro y vive en `Workforce`.
 * `Product` no puede importar ninguno de los dos (doc 02 §1.6), y tampoco
 * deberia: el prefijo de la ruta es una agrupacion **del contrato**, no una
 * razon para mover la logica de un recurso fuera del modulo que lo posee.
 *
 * ## `show` es publico y solo devuelve booleanos
 *
 * Se llama antes de que exista ninguna cuenta con la que autenticarse: ese es
 * literalmente el estado que describe. Lo unico que revela es que la instalacion
 * aun no se ha configurado, que es lo mismo que revela un panel vacio — y con el
 * asistente ya cerrado ni siquiera enumera los pasos.
 */
final class SetupController extends Controller
{
    /**
     * `GET /api/v1/setup/status` — **publico**, con `throttle:setup`.
     *
     * Devuelve **lo minimo**: si el asistente sigue abierto y cuando se cerro.
     * Es todo lo que el panel necesita para decidir a donde lleva, y es todo lo
     * que se puede publicar sin autenticar.
     *
     * **El detalle esta en `GET /api/v1/setup/steps`**, que si exige sesion. La
     * separacion es una correccion de la revision de la 5.5: la lista de pasos es
     * un inventario de la postura de la instalacion —dice si hay un administrador
     * **sin segundo factor**, si no hay licencia y si no hay ningun quiosco— y
     * ninguna de esas tres cosas hace falta para redirigir un navegador.
     *
     * **Dos rutas y no una que cambie de forma segun quien pregunte.** Se valoro
     * resolver el guard a mano en esta misma ruta y añadir el detalle si el token
     * valia; se descarto por dos razones: `Product` no puede importar el catalogo
     * de ambitos de `Identity` (doc 02 §1.6), asi que la mitad «ambito» de la
     * regla dura 18 habria quedado sin comprobar o escrita con una cadena magica;
     * y una respuesta cuya forma depende de las credenciales es la clase de
     * contrato que un cliente generado no sabe expresar.
     */
    public function show(GetSetupStateHandler $state): JsonResponse
    {
        return (new SetupStatusResource($state->handle()))->response();
    }

    /**
     * `GET /api/v1/setup/steps` — los pasos y su estado.
     *
     * Autenticada por el camino de siempre: el middleware comprueba el ambito
     * `settings:*` y esta policy el rol (regla dura 18, dos controles distintos).
     *
     * Es lo que hace el asistente **reanudable**: se abandona a media puesta en
     * marcha, se vuelve, y el panel sabe donde estaba.
     *
     * Autoriza con `view` y no con `record`, que es el verbo de escritura: hoy
     * conceden a los mismos, pero atarlos haria que abrir la lectura a otro rol
     * abriera con ella el poder de marcar pasos.
     */
    public function steps(GetSetupStateHandler $state): JsonResponse
    {
        Gate::authorize('view', SetupState::class);

        return (new SetupStatusResource($state->handle(), detailed: true))->response();
    }

    /**
     * `PUT /api/v1/setup/steps/{step}`.
     *
     * El paso se resuelve **antes** que nada: uno que no esta en el catalogo es
     * un `404` —el recurso direccionado no existe— y no llega a tocar el caso de
     * uso.
     */
    public function record(
        RecordSetupStepRequest $request,
        string $step,
        RecordSetupStepHandler $handler,
        LoggerInterface $logger,
    ): JsonResponse {
        $command = $request->toCommand(SetupStep::fromString($step));

        $state = $handler->handle($command);

        // Instrumentacion del asistente: el paso y su estado, **nunca quien lo
        // marco por su nombre ni su correo** (regla dura 21). Es lo que permite
        // que el paquete de diagnostico cuente en que paso se atasco una puesta
        // en marcha sin llevarse un solo dato personal (ADR-020).
        $logger->info('setup.step_recorded', [
            'step' => $command->step->value,
            'state' => $command->state->value,
        ]);

        // `detailed`: quien acaba de marcar un paso esta autenticado como
        // administrador y necesita ver el estado resultante entero.
        return (new SetupStatusResource($state, detailed: true))->response();
    }

    /**
     * `POST /api/v1/setup/complete`.
     */
    public function complete(
        Request $request,
        CompleteSetupHandler $handler,
        LoggerInterface $logger,
    ): JsonResponse {
        Gate::authorize('complete', SetupState::class);

        $completed = $handler->handle($this->actorUuidOf($request));

        $logger->info('setup.completed', [
            'employees' => $completed->summary->employees,
            'departments' => $completed->summary->departments,
            // La cifra que decide el primer dia. Va al log a proposito: si
            // alguien llama a soporte porque «no ficha nadie», esta linea
            // responde la pregunta antes de pedir nada mas.
            'credentials_pending' => $completed->summary->credentialsPending,
            'kiosks' => $completed->summary->kiosks,
            'license' => $completed->summary->license->value,
        ]);

        return (new SetupCompletionResource($completed))->response();
    }

    private function actorUuidOf(Request $request): ?string
    {
        $actor = $request->user();

        return $actor instanceof ManagementActor ? $actor->actorUuid() : null;
    }
}
