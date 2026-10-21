<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Support;

use App\Modules\Shared\Application\Support\SpanScope;
use App\Modules\Shared\Application\Support\UseCaseInstrumentation;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * El log estructurado de los actos del ciclo de vida de una credencial
 * (doc 02 §8.1). El span y la duracion los pone cada caso de uso con
 * {@see UseCaseInstrumentation}, la medida comun (V1-RV-03); aqui se le añaden
 * los identificadores publicos del acto.
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
 * **Medir no puede romper una impresion.** Todo va envuelto, y lo envuelve
 * {@see SpanScope}: perder una traza es infinitamente mas barato que dejar a
 * alguien sin tarjeta el dia que entra a trabajar.
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
        // Al span del caso de uso, que esta activo (`UseCaseInstrumentation`).
        SpanScope::annotateCurrent($attributes);
        $startedAt = microtime(true);

        try {
            $result = $act();
        } catch (Throwable $failure) {
            $this->logger->warning($name.'_failed', [
                ...$attributes,
                'trace_id' => SpanScope::currentTraceId(),
                // La clase de la excepcion, no su mensaje: el mensaje de una
                // excepcion de dominio puede llevar el UUID de una credencial, que
                // es publico, pero el de una de infraestructura puede llevar una
                // consulta SQL entera.
                'failure' => $failure::class,
            ]);

            throw $failure;
        }

        $this->logger->info($name, [
            ...$attributes,
            'trace_id' => SpanScope::currentTraceId(),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return $result;
    }
}
