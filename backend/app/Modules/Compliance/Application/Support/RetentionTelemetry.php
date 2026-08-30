<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Support;

use App\Modules\Shared\Application\Support\SpanScope;
use OpenTelemetry\API\Trace\SpanKind;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * La traza y el log de una pasada de retencion (doc 02 §8.1).
 *
 * **En `Application` y no en el borde**, por la misma razon que
 * `Identity\Application\Support\CredentialTelemetry`: este acto solo entra por
 * consola, no hay peticion HTTP de la que colgar el span y aun asi tiene que
 * quedar medido. Que una purga tarde tres horas sobre un historico grande es
 * exactamente lo que hay que saber antes de programar la ventana.
 *
 * **Ni un nombre ni un `employee_uuid`** (regla dura 21): ambitos, recuentos y
 * fechas de corte. La purga no se hace sobre personas, se hace sobre plazos.
 */
final readonly class RetentionTelemetry
{
    public function __construct(private LoggerInterface $logger) {}

    /**
     * @template T
     *
     * @param  non-empty-string  $name
     * @param  array<string, scalar|null>  $attributes
     * @param  callable(): T  $act
     * @return T
     */
    public function measure(string $name, array $attributes, callable $act): mixed
    {
        // KIND_INTERNAL: no hay peticion detras; lo lanza el planificador o una
        // persona en una consola del servidor.
        $span = SpanScope::start('kronoqr.compliance', $name, SpanKind::KIND_INTERNAL, $attributes);
        $startedAt = microtime(true);

        try {
            $result = $act();
        } catch (Throwable $failure) {
            $span->end(['outcome' => 'error']);

            $this->logger->error($name.'_failed', [
                ...$attributes,
                'trace_id' => $span->traceId(),
                // La clase, no el mensaje: el de una excepcion de infraestructura
                // puede arrastrar una consulta entera al paquete de diagnostico.
                'failure' => $failure::class,
            ]);

            throw $failure;
        }

        $span->end(['outcome' => 'ok']);

        $this->logger->notice($name, [
            ...$attributes,
            'trace_id' => $span->traceId(),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return $result;
    }
}
