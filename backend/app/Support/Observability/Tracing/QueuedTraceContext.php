<?php

declare(strict_types=1);

namespace App\Support\Observability\Tracing;

use Illuminate\Log\Context\Repository as ContextRepository;
use OpenTelemetry\Context\ScopeInterface;
use Throwable;

/**
 * La traza cruza la cola: lo que hace el *worker* cuelga de la peticion que
 * encolo el trabajo (doc 02 §8.1, decision 6 de la ficha 3.1).
 *
 * ## Como viaja
 *
 * Por el `Context` de Laravel, que se **deshidrata** al serializar el trabajo y
 * se **rehidrata** en el proceso que lo ejecuta. Al deshidratar se escribe el
 * `traceparent` del span activo —el del borde HTTP, o el del comando programado—;
 * al rehidratar se activa ese contexto remoto, y a partir de ahi cualquier span
 * que abra el trabajo cuelga de la traza que lo pidio.
 *
 * Ese mismo valor sirve dos veces: el processor de correlacion del log lo lee
 * para poner `trace_id` en cada linea que escriba el trabajo, tambien cuando la
 * instalacion no exporta trazas y no hay ningun span de verdad. Es lo que permite
 * responder «que paso con la sincronizacion de las 06:03» mirando solo el log.
 *
 * ## Quien lo abre lo cierra
 *
 * Un ambito activado y no soltado contamina al **siguiente** trabajo del mismo
 * worker, que es un proceso de vida larga: sus spans colgarian de una traza
 * ajena. Por eso se suelta al terminar cada trabajo —bien o mal— y tambien antes
 * de activar el siguiente, por si el evento de fin no llegara.
 */
final class QueuedTraceContext
{
    private ?ScopeInterface $scope = null;

    /**
     * Al serializar el trabajo: se guarda el `traceparent` mas preciso que haya.
     *
     * Se sobrescribe el que hubiera puesto el borde HTTP a proposito: dentro de
     * una peticion el span activo puede ser mas hondo que el de entrada, y lo que
     * interesa es que el trabajo cuelgue de donde de verdad se encolo.
     */
    public function dehydrating(ContextRepository $context): void
    {
        try {
            $traceparent = TraceContext::currentTraceparent();

            if ($traceparent !== null) {
                $context->add(TraceContext::TRACEPARENT_KEY, $traceparent);
            }
        } catch (Throwable) {
            // Encolar un trabajo no puede fallar porque no haya traza.
        }
    }

    /** Ya en el worker: se reactiva la traza que encolo el trabajo. */
    public function hydrated(ContextRepository $context): void
    {
        try {
            $this->release();

            $traceparent = $context->get(TraceContext::TRACEPARENT_KEY);

            $this->scope = TraceContext::activate(is_string($traceparent) ? $traceparent : null);
        } catch (Throwable) {
            $this->scope = null;
        }
    }

    /** Al terminar el trabajo, con o sin exito. */
    public function release(): void
    {
        TraceContext::detach($this->scope);
        $this->scope = null;
    }
}
