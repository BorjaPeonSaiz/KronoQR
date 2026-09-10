<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Metrics\Exposition;

use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Redis\Connections\Connection;
use Prometheus\MetricFamilySamples;
use Throwable;

/**
 * Compone las familias de Prometheus a partir de las series que los catorce
 * adaptadores `Redis*Metrics` llevan escribiendo desde la Fase 1 (decision 1 de
 * la ficha 3.1).
 *
 * ## Lee, no escribe
 *
 * Esta clase no tiene un solo comando de escritura, y es deliberado: `/metrics`
 * lo sondea Prometheus cada quince segundos, y un endpoint de observabilidad que
 * modificara el estado del sistema seria una forma barata de provocar carga
 * desde fuera. Lo unico que sale de aqui es un `HGETALL` por serie y un `SCAN`
 * acotado por las dos que viven como claves sueltas.
 *
 * ## Un fallo de una serie no se lleva las demas
 *
 * Cada serie va envuelta. Si Redis no responde —o responde una basura que no es
 * un numero—, esa familia se queda fuera y el resto sale. La alternativa, un
 * `500`, convertiria una averia de metricas en «la aplicacion esta caida» ante
 * la sonda, que es exactamente lo contrario de lo que hace falta a las tres de
 * la mañana.
 *
 * ## Los histogramas se componen enteros o no se componen
 *
 * Prometheus exige `_bucket` con `le` **ordenados de menor a mayor**, un
 * `le="+Inf"` que coincida con `_count`, y `_sum`. Un histograma incompleto no
 * es medio histograma: `histogram_quantile()` devuelve `NaN` y el panel se
 * queda en blanco sin decir por que. Por eso los cubos se ordenan
 * numericamente aqui —el orden en el que Redis devuelve los campos de un hash
 * no esta definido— y el `+Inf` se sintetiza desde `count` si el escritor no lo
 * dejo.
 *
 * ## Las etiquetas se rellenan hasta la forma declarada
 *
 * `kiosk_pairing_total` escribe `result=requested` en tres de sus cuatro casos y
 * `result=rejected,reason=…` en el cuarto. Sin normalizar, la familia saldria
 * con muestras de dos formas distintas y el renderizador de `promphp` fallaria
 * al combinar nombres y valores. Aqui toda muestra sale con **todas** las
 * etiquetas del catalogo, y la que no estaba sale vacia — que es como Prometheus
 * interpreta una etiqueta ausente.
 */
final readonly class RedisMetricReader
{
    /**
     * Claves por vuelta de `SCAN`, y tope de vueltas. Mismo criterio que
     * `MetricsCollector`: `KEYS` bloquearia el Redis por el que pasa cada
     * fichaje, y un cursor que no cierra dejaria el scrape girando para siempre.
     */
    private const int SCAN_COUNT = 500;

    private const int SCAN_MAX_ROUNDS = 64;

    /**
     * @param  string  $keyPrefix  El prefijo global que el cliente de Redis añade a
     *                             TODA clave (`database.redis.options.prefix`).
     *
     * **Hace falta y es la trampa de esta clase.** `phpredis` antepone el
     * prefijo por su cuenta en `HGETALL` y en `GET`, pero **no** en el patron
     * `MATCH` de un `SCAN` —ni se lo quita a las claves que devuelve—. Con el
     * patron sin prefijar, `SCAN` no encuentra absolutamente nada y las cinco
     * series que viven como claves sueltas —los histogramas con etiquetas
     * incluidos— desaparecen de `/metrics` sin un solo error. Aqui el patron se
     * prefija a mano y el resultado se desprefija antes de volver a pedir su
     * valor, que si se prefijara solo.
     *
     * Llega por constructor y no de `config()` para que esta clase no dependa
     * del contenedor: la resuelve `MetricsServiceProvider`.
     */
    public function __construct(private Redis $redis, private string $keyPrefix = '') {}

    /**
     * @return list<MetricFamilySamples>
     */
    public function read(): array
    {
        try {
            $connection = $this->redis->connection();
        } catch (Throwable) {
            // Sin Redis no hay series de Redis, y ya esta. El endpoint sigue
            // respondiendo 200 con lo que pueda componer el resto.
            return [];
        }

        $families = [];

        foreach (MetricCatalogue::all() as $definition) {
            $family = $this->family($connection, $definition);

            if ($family instanceof MetricFamilySamples) {
                $families[] = $family;
            }
        }

        return $families;
    }

    private function family(Connection $connection, MetricDefinition $definition): ?MetricFamilySamples
    {
        try {
            $samples = match ($definition->storage) {
                MetricStorage::Runtime => [],
                MetricStorage::LabelledHash => $this->labelledHashSamples($connection, $definition),
                MetricStorage::ScalarKeys => $this->scalarKeySamples($connection, $definition),
                MetricStorage::Histogram => $this->histogramSamples($connection, $definition),
            };
        } catch (Throwable) {
            return null;
        }

        if ($samples === []) {
            // Una serie sin muestras no se declara. Publicar `# TYPE` sin datos
            // no aporta nada y ensucia el scrape de una instalacion recien
            // instalada con cuarenta cabeceras vacias.
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
     * Un HASH por serie, con la combinacion de etiquetas como campo.
     *
     * @return list<array<string, mixed>>
     */
    private function labelledHashSamples(Connection $connection, MetricDefinition $definition): array
    {
        $hash = $this->hash($connection, $definition->key(MetricCatalogue::KEY_PREFIX));

        $samples = [];

        foreach ($hash as $labels => $value) {
            $samples[] = [
                'name' => $definition->name,
                'labelNames' => [],
                'labelValues' => $this->labelValues($definition, $labels),
                'value' => $value,
            ];
        }

        return $samples;
    }

    /**
     * Una clave suelta por combinacion de etiquetas, que es lo que deja
     * `INCRBY`.
     *
     * @return list<array<string, mixed>>
     */
    private function scalarKeySamples(Connection $connection, MetricDefinition $definition): array
    {
        $samples = [];

        foreach ($this->scan($connection, $definition->key(MetricCatalogue::KEY_PREFIX)) as $labels => $key) {
            $value = $this->number($this->command($connection, 'GET', [$key]));

            if ($value === null) {
                continue;
            }

            $samples[] = [
                'name' => $definition->name,
                'labelNames' => [],
                'labelValues' => $this->labelValues($definition, $labels),
                'value' => $value,
            ];
        }

        return $samples;
    }

    /**
     * `sum`, `count` y los cubos. Sin etiquetas hay un solo hash; con
     * etiquetas, uno por combinacion.
     *
     * @return list<array<string, mixed>>
     */
    private function histogramSamples(Connection $connection, MetricDefinition $definition): array
    {
        $key = $definition->key(MetricCatalogue::KEY_PREFIX);

        $buckets = $definition->labels === []
            ? ['' => $key]
            : $this->scan($connection, $key);

        $samples = [];

        foreach ($buckets as $labels => $hashKey) {
            $values = $this->labelValues($definition, (string) $labels);

            $samples = [...$samples, ...$this->histogram($connection, $definition, $hashKey, $values)];
        }

        return $samples;
    }

    /**
     * @param  list<string>  $labelValues
     * @return list<array<string, mixed>>
     */
    private function histogram(
        Connection $connection,
        MetricDefinition $definition,
        string $hashKey,
        array $labelValues,
    ): array {
        $hash = $this->hash($connection, $hashKey);

        if ($hash === []) {
            return [];
        }

        $count = $hash['count'] ?? 0;
        $sum = $hash['sum'] ?? 0;

        $bounds = [];

        foreach ($hash as $field => $value) {
            if (! str_starts_with((string) $field, 'le=')) {
                continue;
            }

            $bound = substr((string) $field, 3);

            if ($bound === '+Inf') {
                continue;
            }

            $bounds[$bound] = $value;
        }

        // El orden de los campos de un hash de Redis no esta definido, y
        // Prometheus exige los cubos crecientes: un `_bucket` desordenado se lee
        // como un histograma corrupto.
        uksort($bounds, static fn (string $left, string $right): int => (float) $left <=> (float) $right);

        $samples = [];
        $cumulative = 0;

        foreach ($bounds as $bound => $value) {
            // Los escritores acumulan por cubo, pero un `le` no puede ser menor
            // que el anterior: si dos incrementos se cruzaran, se corrige aqui
            // en vez de publicar un histograma que Prometheus descarta.
            $cumulative = max($cumulative, (int) $value);

            $samples[] = [
                'name' => $definition->name.'_bucket',
                'labelNames' => ['le'],
                'labelValues' => [...$labelValues, (string) $bound],
                'value' => $cumulative,
            ];
        }

        // `+Inf` es obligatorio y tiene que coincidir con `_count`. Se sintetiza
        // desde `count` en lugar de leer su campo: son el mismo numero por
        // definicion, y asi un `+Inf` que se hubiera quedado atras no produce un
        // histograma incoherente.
        $samples[] = [
            'name' => $definition->name.'_bucket',
            'labelNames' => ['le'],
            'labelValues' => [...$labelValues, '+Inf'],
            'value' => $count,
        ];

        $samples[] = [
            'name' => $definition->name.'_sum',
            'labelNames' => [],
            'labelValues' => $labelValues,
            'value' => $sum,
        ];

        $samples[] = [
            'name' => $definition->name.'_count',
            'labelNames' => [],
            'labelValues' => $labelValues,
            'value' => $count,
        ];

        return $samples;
    }

    /**
     * Descompone `site=1,department=Cocina` en los valores del catalogo, EN SU
     * ORDEN.
     *
     * Dos pasadas y no una. La primera casa la cadena entera contra la forma
     * declarada, con el ultimo valor codicioso: es lo unico que sobrevive a un
     * departamento llamado `Sala, Bar`, donde partir por comas daria tres
     * trozos para dos etiquetas. La segunda es la lenient, para las series cuya
     * combinacion de etiquetas varia —`kiosk_pairing_total` escribe
     * `result=requested` sin `reason`—: se toma lo que haya y lo que falte sale
     * vacio.
     *
     * @return list<string>
     */
    private function labelValues(MetricDefinition $definition, string $labels): array
    {
        if ($definition->labels === []) {
            return [];
        }

        $pattern = '#^'.implode(',', array_map(
            static fn (string $label, int $index): string => preg_quote($label, '#').'=('.($index === \count($definition->labels) - 1 ? '.*' : '.*?').')',
            $definition->labels,
            array_keys($definition->labels),
        )).'$#s';

        if (preg_match($pattern, $labels, $matches) === 1) {
            return array_values(array_map(strval(...), \array_slice($matches, 1)));
        }

        $found = [];

        foreach (explode(',', $labels) as $pair) {
            $parts = explode('=', $pair, 2);

            if (\count($parts) === 2) {
                $found[$parts[0]] = $parts[1];
            }
        }

        return array_map(
            static fn (string $label): string => $found[$label] ?? '',
            $definition->labels,
        );
    }

    /**
     * Las claves `<serie>:<etiquetas>` de una serie, indexadas por su parte de
     * etiquetas.
     *
     * `SCAN` y nunca `KEYS`, por lo mismo que en `MetricsCollector`: este Redis
     * es el que sostiene la cola, la cache y el camino de cada fichaje, y un
     * `KEYS` lo bloquea entero mientras recorre el espacio de claves.
     *
     * @return array<string, string>
     */
    private function scan(Connection $connection, string $key): array
    {
        $keys = [];
        // NULL y no CERO en la primera vuelta. `phpredis` recibe el cursor por
        // referencia y trata un `0` de entrada como «el recorrido ya termino»:
        // devuelve `false` sin mirar una sola clave. Con `null` empieza de
        // verdad. Es la segunda trampa de esta clase, y no da ningun error —solo
        // deja de haber series—.
        $cursor = null;
        $pattern = $this->keyPrefix.$key.':*';

        for ($round = 0; $round < self::SCAN_MAX_ROUNDS; $round++) {
            $page = $this->scanPage($connection, $pattern, $cursor);

            // `false` es «se acabo el recorrido y no habia nada».
            if (! \is_array($page) || \count($page) < 2) {
                break;
            }

            $cursor = \is_scalar($page[0]) ? (int) $page[0] : 0;

            $this->collect(\is_array($page[1]) ? $page[1] : [], $key, $keys);

            if ($cursor === 0) {
                break;
            }
        }

        ksort($keys, SORT_STRING);

        return $keys;
    }

    /**
     * Una vuelta de `SCAN`, o `null` si Redis no contesta.
     *
     * Aislada en su propio metodo para que el tipo de vuelta sea `mixed` y las
     * comprobaciones de {@see self::scan()} sean comprobaciones de verdad: el
     * `@mixin \Redis` de Laravel hace creer al analisis estatico que aqui se
     * llama a `Redis::scan()`, cuando lo que corre es
     * `PhpRedisConnection::scan()`, que tiene otra firma y devuelve
     * `[cursor, claves]`.
     */
    private function scanPage(Connection $connection, string $pattern, ?int $cursor): mixed
    {
        try {
            // El envoltorio de Laravel y no `command('SCAN', …)`: `phpredis`
            // recibe el cursor POR REFERENCIA y la lista de argumentos
            // `MATCH … COUNT …` del protocolo crudo no le vale —peta con
            // «expects at most 4 arguments»—.
            //
            // @phpstan-ignore argument.type (`Connection` declara `@mixin \Redis`, asi que el analisis ve la firma cruda `Redis::scan(&$it, ?string $pattern, int $count)`. La que corre es `PhpRedisConnection::scan($cursor, array $options)`, que es la unica que traduce `match`/`count` y devuelve `[cursor, claves]`. Verificado contra el contenedor.)
            return $connection->scan($cursor, ['match' => $pattern, 'count' => self::SCAN_COUNT]);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Indexa las claves encontradas por su parte de etiquetas, quitandoles el
     * prefijo global.
     *
     * @param  array<array-key, mixed>  $found
     * @param  array<string, string>  $keys
     */
    private function collect(array $found, string $key, array &$keys): void
    {
        foreach ($found as $candidate) {
            if (! \is_string($candidate)) {
                continue;
            }

            $unprefixed = str_starts_with($candidate, $this->keyPrefix)
                ? substr($candidate, \strlen($this->keyPrefix))
                : $candidate;

            $keys[substr($unprefixed, \strlen($key) + 1)] = $unprefixed;
        }
    }

    /**
     * @return array<string, int|float>
     */
    private function hash(Connection $connection, string $key): array
    {
        $raw = $this->command($connection, 'HGETALL', [$key]);

        if (! \is_array($raw)) {
            return [];
        }

        $values = [];

        foreach ($raw as $field => $value) {
            $number = $this->number($value);

            if (\is_string($field) && $number !== null) {
                $values[$field] = $number;
            }
        }

        return $values;
    }

    /**
     * @param  list<string>  $parameters
     */
    private function command(Connection $connection, string $command, array $parameters): mixed
    {
        try {
            return $connection->command($command, $parameters);
        } catch (Throwable) {
            return null;
        }
    }

    private function number(mixed $value): int|float|null
    {
        if (! \is_scalar($value) || ! is_numeric($value)) {
            return null;
        }

        $text = (string) $value;

        return str_contains($text, '.') || str_contains($text, 'e') || str_contains($text, 'E')
            ? (float) $text
            : (int) $text;
    }
}
