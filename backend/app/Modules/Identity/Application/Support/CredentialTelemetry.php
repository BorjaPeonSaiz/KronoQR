<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Support;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * El span y el log estructurado de los actos del ciclo de vida de una credencial
 * (doc 02 §8.1).
 *
 * **Por que dentro de `Application` y no en el borde HTTP**, al reves que
 * `ScanTelemetry`: estos tres actos —imprimir, imprimir por lotes, entregar—
 * entran por **dos puertas**, el endpoint y el comando de consola, y la mitad de
 * las veces la que se usa de verdad es la segunda. Instrumentar solo el
 * controlador dejaria sin traza precisamente el alta masiva de una temporada, que
 * es la operacion que mas tarda y la unica que puede fallar a mitad. Aqui dentro,
 * las dos puertas quedan cubiertas por el mismo codigo.
 *
 * **Lo que el log NO lleva** (regla dura 21): ningun nombre de empleado, ningun
 * `key_id`... el `key_id` si, que no identifica a nadie; lo que no puede aparecer
 * es el token, su firma, su hash o el nombre del titular. Se identifica por
 * `employee_uuid`, `credential_uuid` y `trace_id`, que es lo que hace falta para
 * reconstruir un problema y nada mas.
 *
 * **Medir no puede romper una impresion.** Todo va envuelto: perder una traza es
 * infinitamente mas barato que dejar a alguien sin tarjeta el dia que entra a
 * trabajar.
 */
final readonly class CredentialTelemetry
{
    public function __construct(private LoggerInterface $logger) {}

    /**
     * Envuelve un acto y devuelve lo que devuelva.
     *
     * @template T
     *
     * @param  non-empty-string  $name
     * @param  array<string, scalar|null>  $attributes  Solo identificadores publicos.
     * @param  callable(): T  $act
     * @return T
     */
    public function measure(string $name, array $attributes, callable $act): mixed
    {
        $span = $this->startSpan($name, $attributes);
        $startedAt = microtime(true);

        try {
            $result = $act();
        } catch (Throwable $failure) {
            $this->endSpan($span, 'error');

            $this->logger->warning($name.'_failed', [
                ...$attributes,
                'trace_id' => $this->traceIdOf($span),
                // La clase de la excepcion, no su mensaje: el mensaje de una
                // excepcion de dominio puede llevar el UUID de una credencial, que
                // es publico, pero el de una de infraestructura puede llevar una
                // consulta SQL entera.
                'failure' => $failure::class,
            ]);

            throw $failure;
        }

        $this->endSpan($span, 'ok');

        $this->logger->info($name, [
            ...$attributes,
            'trace_id' => $this->traceIdOf($span),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return $result;
    }

    /**
     * @param  non-empty-string  $name
     * @param  array<string, scalar|null>  $attributes
     */
    private function startSpan(string $name, array $attributes): ?SpanInterface
    {
        try {
            // `Globals` devuelve un proveedor inerte mientras el SDK no este
            // configurado, asi que esto no cuesta nada en la instalacion de un
            // cliente que no exporta trazas.
            $builder = Globals::tracerProvider()
                ->getTracer('kronoqr.identity')
                ->spanBuilder($name)
                ->setSpanKind(SpanKind::KIND_INTERNAL)
                ->setParent(Context::getCurrent());

            foreach ($attributes as $key => $value) {
                $builder->setAttribute($key, $value);
            }

            return $builder->startSpan();
        } catch (Throwable) {
            return null;
        }
    }

    private function endSpan(?SpanInterface $span, string $outcome): void
    {
        if (! $span instanceof SpanInterface) {
            return;
        }

        try {
            $span->setAttribute('outcome', $outcome);
            $span->end();
        } catch (Throwable) {
            // Ver el docblock: medir no puede romper una impresion.
        }
    }

    private function traceIdOf(?SpanInterface $span): ?string
    {
        if (! $span instanceof SpanInterface) {
            return null;
        }

        $traceId = $span->getContext()->getTraceId();

        // Un `trace_id` a ceros es el de un span inerte: escribirlo seria peor
        // que no escribir nada, porque parece un identificador.
        return trim($traceId, '0') === '' ? null : $traceId;
    }
}
