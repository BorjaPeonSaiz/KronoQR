<?php

declare(strict_types=1);

namespace App\Support\Observability\Tracing;

use App\Modules\Shared\Application\Support\SpanScope;
use App\Modules\Shared\Application\Support\TraceparentHeader;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ScopeInterface;
use Throwable;

/**
 * El W3C Trace Context ACTIVADO y PROPAGADO en un solo sitio (doc 02 §8.1).
 *
 * La cabecera `traceparent` aparece en cuatro caminos —el borde HTTP, el
 * `Context` de Laravel que viaja con un job encolado, el `error_events` de
 * RF-PD-15 y el processor de correlacion del log— y en todos hace falta lo
 * mismo: saber el `trace_id` en curso, componer el `traceparent` que se envia y
 * volver a activar el que llego. Tenerlo en cuatro copias es como se acaba con
 * cuatro respuestas distintas a «que `trace_id` se escribe».
 *
 * ## Lo que esta clase NO hace, y donde vive
 *
 * **No parsea la cabecera.** Eso es {@see TraceparentHeader}, en
 * `Shared\Application\Support`, y es la unica copia del patron del W3C y de la
 * regla del identificador «significativo» que existe en el producto: la usan
 * tambien `ServerErrorReporter` (RF-PD-15) y el processor de correlacion. Aqui
 * quedan las dos operaciones que si son de propagacion —activar un contexto
 * remoto y soltarlo—, mas los alias que hacen legible el codigo del armazon.
 *
 * **Tampoco abre spans.** Eso es {@see SpanScope}, alcanzable desde aqui por la
 * arista `AppFramework -> SharedTracingSupport` de Deptrac.
 *
 * Nada de aqui lanza nunca (regla dura 19): sin traza en curso se devuelve
 * `null`, que es una respuesta perfectamente utilizable.
 */
final class TraceContext
{
    /**
     * `00-<32 hex>-<16 hex>-<2 hex>`, la forma que fija el W3C.
     *
     * Es un alias de {@see TraceparentHeader::PATTERN}, no una segunda copia:
     * quien lo cambie ahi lo cambia para todo el producto.
     */
    public const string TRACEPARENT_PATTERN = TraceparentHeader::PATTERN;

    /** La clave del `Context` de Laravel donde vive el identificador de traza. */
    public const string TRACE_ID_KEY = 'trace_id';

    /** La clave del `Context` de Laravel donde viaja la cabecera entera. */
    public const string TRACEPARENT_KEY = TraceparentHeader::NAME;

    /**
     * El `trace_id` del span activo, o `null` si no hay SDK configurado o no hay
     * traza en curso.
     */
    public static function currentTraceId(): ?string
    {
        return SpanScope::currentTraceId();
    }

    /**
     * El `traceparent` que representa al span activo, listo para viajar en una
     * cabecera o dentro de un job encolado.
     */
    public static function currentTraceparent(): ?string
    {
        try {
            $carrier = [];
            TraceContextPropagator::getInstance()->inject($carrier, null, Context::getCurrent());

            $traceparent = $carrier[self::TRACEPARENT_KEY] ?? null;

            return is_string($traceparent) && $traceparent !== '' ? $traceparent : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Activa el contexto remoto que describe un `traceparent`, de modo que lo que
     * se abra a partir de ahora cuelgue de la traza que lo envio.
     *
     * Devuelve el ambito para poder soltarlo; `null` si no habia nada que
     * activar. **Quien lo abre lo cierra**: un ambito que no se suelta contamina
     * al siguiente job del mismo worker.
     */
    public static function activate(?string $traceparent): ?ScopeInterface
    {
        if ($traceparent === null || $traceparent === '') {
            return null;
        }

        try {
            return TraceContextPropagator::getInstance()
                ->extract([self::TRACEPARENT_KEY => $traceparent])
                ->activate();
        } catch (Throwable) {
            return null;
        }
    }

    /** Suelta un ambito sin que soltarlo pueda ser la causa de un error. */
    public static function detach(?ScopeInterface $scope): void
    {
        if (! $scope instanceof ScopeInterface) {
            return;
        }

        try {
            $scope->detach();
        } catch (Throwable) {
            // `Context` es un estado de proceso; esta rama existe para que nunca
            // se convierta en el motivo de una peticion fallida.
        }
    }

    /**
     * El `trace_id` que lleva dentro una cabecera `traceparent`, o `null` si la
     * cabecera no tiene la forma del W3C.
     */
    public static function traceIdOf(mixed $traceparent): ?string
    {
        return TraceparentHeader::traceIdOf($traceparent);
    }

    /** Si el valor tiene la forma exacta que fija el W3C. */
    public static function isWellFormed(mixed $traceparent): bool
    {
        return TraceparentHeader::valid($traceparent);
    }
}
