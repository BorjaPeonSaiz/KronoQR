<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Support;

use App\Modules\Shared\Application\Support\SpanScope;
use App\Modules\Shared\Application\Support\UseCaseInstrumentation;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * El log de una pasada de retencion (doc 02 §8.1). El span y la duracion los
 * pone el caso de uso con {@see UseCaseInstrumentation}, la medida comun
 * (V1-RV-03); aqui se le añaden los ambitos y recuentos del acto.
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
        // Al span del caso de uso, que esta activo (`UseCaseInstrumentation`).
        SpanScope::annotateCurrent($attributes);
        $startedAt = microtime(true);

        try {
            $result = $act();
        } catch (Throwable $failure) {
            $this->logger->error($name.'_failed', [
                ...$attributes,
                'trace_id' => SpanScope::currentTraceId(),
                // La clase, no el mensaje: el de una excepcion de infraestructura
                // puede arrastrar una consulta entera al paquete de diagnostico.
                'failure' => $failure::class,
            ]);

            throw $failure;
        }

        $this->logger->notice($name, [
            ...$attributes,
            'trace_id' => SpanScope::currentTraceId(),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return $result;
    }
}
