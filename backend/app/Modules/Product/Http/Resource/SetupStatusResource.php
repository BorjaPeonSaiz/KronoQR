<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Resource;

use App\Modules\Product\Domain\ValueObject\SetupState;
use App\Modules\Product\Domain\ValueObject\SetupStep;
use App\Modules\Shared\Domain\ValueObject\UtcInstant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializa el esquema `SetupStatus` (RF-PD-03).
 *
 * ## Sin sesion viaja **solo `available`**
 *
 * `GET /api/v1/setup/status` es **publico** —tiene que serlo: se llama antes de
 * que exista ninguna cuenta— y `available` es lo unico que el panel necesita
 * para decidir si lleva al asistente o a la pantalla de acceso. Todo lo demas
 * se queda fuera por minimizacion.
 *
 * `completed_at` incluido: **cuando** se puso en marcha la instalacion no le
 * hace falta a un navegador sin credenciales para elegir a donde ir, y a quien
 * mira desde fuera le dice si el servidor lleva anos funcionando o se monto
 * ayer —que es justo la clase de dato con el que se decide si merece la pena
 * insistir— (correccion de la revision del cierre de la Fase 5). Con sesion de
 * administrador sigue viajando, que es donde tiene consumidor: el resumen de
 * `POST /setup/complete` y `GET /setup/steps`.
 *
 * ## `steps`, por la misma razon, tampoco
 *
 * La lista de pasos es un **inventario de la postura de la instalacion**. Dice
 * tres cosas que no se regalan sin autenticar:
 *
 * 1. Que hay un administrador **sin segundo factor confirmado**, es decir, una
 *    cuenta con acceso total a medio configurar.
 * 2. Que no se ha activado ninguna licencia.
 * 3. Que no hay ningun quiosco vinculado.
 *
 * Ninguna de las tres hace falta para lo unico que el panel necesita decidir sin
 * credenciales —si lleva al asistente o a la pantalla de acceso—, y las tres son
 * utiles para quien esta mirando si merece la pena insistir. **Es una correccion
 * de la revision de la 5.5**: antes viajaban siempre.
 *
 * Con el asistente ya cerrado la lista viaja **vacia** incluso para el
 * administrador: terminada la puesta en marcha, el inventario de lo que se
 * omitio no tiene ningun consumidor.
 *
 * ## Envuelve un objeto de valor, nunca un modelo
 *
 * Como el resto de recursos del modulo: asi no hay ninguna via por la que una
 * columna nueva de `setup_progress` —el `recorded_by_user_id`, por ejemplo—
 * aparezca en una respuesta publica sin que nadie lo decida.
 *
 * @property-read SetupState $resource
 */
final class SetupStatusResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @param  bool  $detailed  `true` solo con sesion de administrador.
     */
    public function __construct(SetupState $state, private readonly bool $detailed = false)
    {
        parent::__construct($state);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SetupState $state */
        $state = $this->resource;

        $body = ['available' => $state->isAvailable()];

        if (! $this->detailed) {
            // Las claves NO viajan vacias ni a nulo: viajan AUSENTES. Un array
            // vacio significa «el asistente esta cerrado y no queda nada que
            // enumerar», y un `completed_at` nulo, «sigue abierto»: usarlos
            // tambien para «no tienes permiso para verlo» haria indistinguibles
            // estados que el panel trata de forma distinta.
            return $body;
        }

        // Regla dura 3: en UTC con sufijo Z, como todo instante del contrato.
        // Por `UtcInstant` y no a mano: convierte a UTC ANTES de escribir la
        // `Z`, asi que la invariante no depende de la zona de la sesion de
        // base de datos ni del proceso (tarea 5.9, el mismo helper que el
        // resto de recursos del modulo).
        $body['completed_at'] = UtcInstant::format($state->completedAt);
        $body['steps'] = $state->isAvailable() ? self::steps($state) : [];

        return $body;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function steps(SetupState $state): array
    {
        return array_map(
            static fn (SetupStep $step): array => [
                'step' => $step->value,
                'state' => $state->stateOf($step)->value,
                // Las dos banderas viajan en la respuesta y **no se compilan en
                // la SPA**: el panel se construye una vez y se instala en el
                // servidor de cada cliente (ADR-017, regla dura 13). Si el dia de
                // mañana un paso deja de ser omitible, el panel se entera al
                // pedir el estado y no al recompilarse.
                'required' => $step->isRequired(),
                'skippable' => $step->isSkippable(),
            ],
            SetupStep::cases(),
        );
    }
}
