<?php

declare(strict_types=1);

namespace App\Support\Observability\Metrics;

use App\Modules\Shared\Infrastructure\Metrics\Exposition\MetricCatalogue;
use App\Modules\Shared\Infrastructure\Metrics\Exposition\MetricDefinition;
use App\Modules\Shared\Infrastructure\Metrics\Exposition\MetricStorage;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Prometheus\MetricFamilySamples;
use Throwable;

/**
 * `queue_jobs_pending{queue}` (doc 02 §8.2), calculada **en el momento del
 * scrape** y no acumulada en Redis (decision 4 de la ficha 3.1).
 *
 * ## Por que aqui y no un contador
 *
 * Es la unica serie tecnica del §8.2 que describe un ESTADO y no un
 * acontecimiento. Un contador incrementado al encolar y decrementado al
 * procesar responde «cuantos se han encolado», que no es la pregunta: la
 * pregunta es «cuantos hay AHORA esperando», y esa solo la contesta la propia
 * cola. Ademas, un contador se desincroniza para siempre con el primer trabajo
 * que muera sin evento —un `SIGKILL` al *worker*, un Redis reiniciado— mientras
 * que `size()` vuelve a la verdad en el siguiente scrape.
 *
 * ## Las colas salen de Horizon, no de una lista
 *
 * `config('horizon.defaults')` es donde el producto declara que supervisores
 * corren y sobre que colas. Con una lista propia aqui, añadir una cola nueva
 * dejaria de medirse sin que fallara nada — y una cola sin vigilancia es
 * exactamente la que se atasca.
 *
 * **Y `horizon.environments.<entorno>` encima**, que es como Horizon las resuelve
 * de verdad: un supervisor puede declarar sus colas en `defaults` y anadir o
 * cambiar unas cuantas para produccion. Leyendo solo `defaults`, esas colas
 * corrian sin que `queue_jobs_pending` supiera de ellas, que es el mismo fallo
 * silencioso que la lista propia — solo que mas dificil de ver.
 *
 * ## Preguntar por una cola no puede tumbar el scrape
 *
 * `size()` va a Redis. Si Redis no contesta, la serie se queda fuera y el resto
 * de `/metrics` sale igual: una averia de metricas no puede parecer una averia
 * del producto ante la sonda.
 */
final readonly class QueueDepthCollector
{
    public function __construct(private QueueFactory $queues) {}

    public function collect(): ?MetricFamilySamples
    {
        $definition = $this->definition();

        if (! $definition instanceof MetricDefinition) {
            return null;
        }

        $samples = [];

        foreach ($this->declaredQueues() as [$connection, $queue]) {
            $size = $this->sizeOf($connection, $queue);

            if ($size === null) {
                continue;
            }

            $samples[] = [
                'name' => $definition->name,
                'labelNames' => [],
                'labelValues' => [$queue],
                'value' => $size,
            ];
        }

        if ($samples === []) {
            return null;
        }

        return new MetricFamilySamples([
            'name' => $definition->name,
            'type' => $definition->type->value,
            'help' => $definition->help,
            'labelNames' => $definition->labels,
            'samples' => $samples,
        ]);
    }

    /**
     * La definicion sale del catalogo y no se escribe dos veces: el `# HELP` y
     * el tipo de esta serie tienen que ser los mismos que declara el §8.2, y la
     * prueba de arquitectura solo mira el catalogo.
     */
    private function definition(): ?MetricDefinition
    {
        foreach (MetricCatalogue::all() as $definition) {
            if ($definition->storage === MetricStorage::Runtime && $definition->name === 'queue_jobs_pending') {
                return $definition;
            }
        }

        return null;
    }

    /**
     * Los pares (conexion, cola) que declaran los supervisores de Horizon, sin
     * repetir.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function declaredQueues(): array
    {
        $pairs = [];

        foreach ($this->supervisors() as $supervisor) {
            $connection = $supervisor['connection'] ?? null;
            $queues = $supervisor['queue'] ?? [];

            if (! \is_string($connection)) {
                continue;
            }

            foreach ((array) $queues as $queue) {
                if (\is_string($queue)) {
                    $pairs[$connection.'|'.$queue] = [$connection, $queue];
                }
            }
        }

        ksort($pairs, SORT_STRING);

        return array_values($pairs);
    }

    /**
     * Los supervisores tal y como los resuelve Horizon: `defaults` con
     * `environments.<entorno>` **encima**, supervisor a supervisor.
     *
     * La fusion es por supervisor y no por bloque: el entorno solo redefine las
     * claves que nombra —`maxProcesses`, y a veces `queue`—, y todo lo que no
     * nombra sigue viniendo de `defaults`. Un `array_replace` del bloque entero
     * borraria la conexion de cualquier supervisor que el entorno solo quisiera
     * escalar.
     *
     * @return list<array<string, mixed>>
     */
    private function supervisors(): array
    {
        $defaults = config('horizon.defaults');
        $defaults = \is_array($defaults) ? $defaults : [];

        $overrides = config('horizon.environments.'.config()->string('app.env'));
        $overrides = \is_array($overrides) ? $overrides : [];

        $merged = [];

        foreach (array_keys($defaults + $overrides) as $name) {
            $base = $defaults[$name] ?? [];
            $over = $overrides[$name] ?? [];

            if (! \is_array($base) || ! \is_array($over)) {
                continue;
            }

            $merged[$name] = array_replace($base, $over);
        }

        return array_values($merged);
    }

    private function sizeOf(string $connection, string $queue): ?int
    {
        try {
            return $this->queues->connection($connection)->size($queue);
        } catch (Throwable) {
            return null;
        }
    }
}
