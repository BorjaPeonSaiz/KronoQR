<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics\Collector;

use App\Modules\Product\Application\Port\DiagnosticsCollector;
use App\Modules\Product\Domain\ValueObject\DiagnosticsOptions;
use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Redis\Connections\Connection;
use Throwable;

/**
 * Seccion `metrics`: los contadores agregados que el producto ya lleva
 * (doc 02 §8.2, RF-PD-09).
 *
 * ## Por lista de series, no por un `SCAN` de todo lo que empiece por el prefijo
 *
 * Un `SCAN kronoqr:metrics:*` sacaria tambien la serie que alguien añada mañana
 * con una etiqueta que no deberia salir. La lista de abajo es la misma idea que
 * la de las secciones: **lo que no esta, no viaja**.
 *
 * ## `worked_minutes_total` no esta, a proposito
 *
 * Es la unica serie del producto etiquetada con un **nombre de departamento**
 * (`site=1,department=Cocina`), que es organizacion interna del cliente y no
 * hace falta para diagnosticar nada tecnico. Las etiquetas que si salen son
 * `device=<uuid>`, `result=`, `route=`, `status=` y `limit=`: ADR-020 admite
 * `employee_uuid` y `device_id`, y ninguna de estas es un nombre.
 *
 * ## Las series viven de dos formas en Redis
 *
 * Como **hash** cuando llevan varias etiquetas (`HINCRBY`) y como **claves
 * sueltas con la etiqueta en el nombre** cuando llevan una (`INCRBY` sobre
 * `...:result=accepted`). Se prueban las dos, en ese orden, sin preguntar el
 * tipo: `TYPE` devuelve un objeto en un cliente y una cadena en el otro, y
 * depender de eso romperia el paquete al cambiar de driver de Redis.
 *
 * ## Restos de la 3.1: las claves sueltas nunca llegaban al paquete
 *
 * `installation_setting_changes_total`, `compliance_profile_changes_total` y
 * `license_limit_exceeded_total` viven exactamente asi —una clave por
 * combinacion de etiquetas— y desde la 5.5 no viajaban en el paquete de
 * diagnostico: {@see self::labelled()} llamaba a `command('SCAN', [$cursor,
 * 'MATCH', $pattern, 'COUNT', $count])`, que traduce a
 * `$client->SCAN($cursor, 'MATCH', $pattern, 'COUNT', $count)` —cinco
 * argumentos posicionales—, y `phpredis` no tiene un `SCAN` que acepte eso:
 * su metodo `scan()` es `scan(&$iterator, $pattern, $count, $type)`, con
 * cuatro como mucho. La llamada lanzaba `ArgumentCountError`, {@see
 * self::command()} lo atrapaba en silencio (el mismo `try` que protege una
 * serie individual de tumbar a las demas) y la seccion `metrics` del paquete
 * seguia saliendo sin decir que le faltaban tres series.
 *
 * La correccion usa el mismo envoltorio que ya arreglo esto en
 * `RedisMetricReader` (tarea 3.1, Integration
 * `MetricsExpositionTest::it('encuentra las series que viven como claves
 * sueltas...')`): el `scan()` de `PhpRedisConnection`, que SI traduce
 * `match`/`count` y devuelve `[cursor, claves]`, y a mano las dos trampas que
 * `phpredis` no resuelve solo: el prefijo global de la instalacion
 * (`database.redis.options.prefix`) NO se antepone al patron `MATCH` ni se
 * quita de las claves que `SCAN` devuelve, y un cursor inicial `0` (en vez de
 * `null`) se lee como «el recorrido ya termino» sin mirar una sola clave.
 */
final readonly class MetricsCollector implements DiagnosticsCollector
{
    /** Prefijo comun de todas las series (doc 02 §8.2). */
    private const string PREFIX = 'kronoqr:metrics:';

    /**
     * Combinaciones de etiquetas por serie antes de recortar. Ver
     * {@see self::bounded()}: 200 cubre de sobra la flota de un hotel y acota la
     * unica seccion del paquete que puede crecer sola con los años.
     */
    private const int MAX_LABELS = 200;

    /**
     * Claves por vuelta de `SCAN`. Es una sugerencia, no un limite: Redis
     * puede devolver mas o menos. 500 recorre la flota de un hotel en una o
     * dos vueltas sin retener el hilo lo bastante como para que se note.
     */
    private const int SCAN_COUNT = 500;

    /**
     * Tope de vueltas por serie. Redis no promete cuantas iteraciones cierran
     * el cursor, y un paquete de diagnostico que no termina es peor que uno
     * con un contador incompleto.
     */
    private const int SCAN_MAX_ROUNDS = 64;

    /**
     * Las series que viajan. Ver el docblock sobre la que falta.
     *
     * @var list<string>
     */
    private const array SERIES = [
        // RF-PD-15: `application_errors_total{source,level}`. Catorce campos como
        // maximo y ninguna etiqueta que identifique a nadie; es el contador que
        // sostiene la alerta «errores nuevos de severidad critica» del doc 01
        // §9.3 y el que da el orden de magnitud antes de mirar la seccion
        // `error_events` del propio paquete.
        'anomalous_patterns_detected_total',
        'application_error_groups_opened_total',
        'application_errors_total',
        'compliance_profile_changes_total',
        'db_query_duration_seconds',
        'http_request_duration_seconds',
        'http_requests_total',
        'incident_resolution_seconds',
        'installation_setting_changes_total',
        'kiosk_last_seen_seconds',
        'kiosk_offline_queue_size',
        'kiosk_pairing_total',
        'kronoqr_auth_attempts_total',
        'license_limit_exceeded_total',
        'manual_corrections_total',
        'pin_fallback_scans_total',
        'pin_resets_total',
        'queue_job_duration_seconds',
        'queue_jobs_failed_total',
        'report_exports_total',
        'scan_batch_size',
        'scan_processing_duration_seconds',
        'scans_by_origin_total',
        'scans_total',
        'sync_delay_seconds',
    ];

    public function __construct(private Redis $redis) {}

    public function section(): string
    {
        return 'metrics';
    }

    public function collect(DiagnosticsOptions $options): array
    {
        try {
            $connection = $this->redis->connection();
        } catch (Throwable $failure) {
            return ['status' => 'unavailable', 'reason' => $failure::class];
        }

        $metrics = [];

        foreach (self::SERIES as $series) {
            $values = $this->series($connection, self::PREFIX.$series);

            if ($values !== []) {
                $metrics[$series] = $this->bounded($values);
            }
        }

        return $metrics;
    }

    /**
     * Acota una serie con demasiadas combinaciones de etiquetas.
     *
     * **Es la unica seccion del paquete que puede crecer sin techo por si
     * sola**, y no por un fallo: `scans_total` y `kiosk_last_seen_seconds` van
     * etiquetadas por `device`, y una instalacion de cuatro años acumula una
     * entrada por cada tablet que haya pasado por el hotel —incluidas las que se
     * emparejaron una tarde y se devolvieron—. Una serie de diez mil entradas se
     * comeria el tope del paquete entero y dejaria fuera secciones que si hacen
     * falta.
     *
     * **Se recortan las MAS PEQUEÑAS**, no las ultimas por orden alfabetico: una
     * tablet con doce escaneos en cuatro años no explica nada, y la que tiene
     * ochenta mil es la que esta en la puerta de personal.
     *
     * @param  array<string, int|float>  $values
     * @return array<string, mixed>
     */
    private function bounded(array $values): array
    {
        if (\count($values) <= self::MAX_LABELS) {
            return $values;
        }

        $total = \count($values);

        arsort($values, SORT_NUMERIC);
        $top = \array_slice($values, 0, self::MAX_LABELS, true);
        ksort($top, SORT_STRING);

        return ['truncated' => true, 'total' => $total, 'top' => $top];
    }

    /**
     * @return array<string, int|float>
     */
    private function series(Connection $connection, string $key): array
    {
        $hash = $this->hash($this->command($connection, 'HGETALL', [$key]));

        if ($hash !== []) {
            return $hash;
        }

        $scalar = $this->number($this->command($connection, 'GET', [$key]));

        if ($scalar !== null) {
            return ['value' => $scalar];
        }

        return $this->labelled($connection, $key);
    }

    /**
     * Las series que Redis guarda como claves sueltas por etiqueta
     * (`...:result=accepted`), que es lo que deja `INCRBY`.
     *
     * ## `SCAN` y nunca `KEYS`
     *
     * `KEYS` recorre el espacio de claves entero **bloqueando Redis** hasta que
     * termina. Y este Redis no es un Redis cualquiera: es el que sostiene la
     * cola, la cache y las sesiones, y esta en el camino por el que pasa cada
     * fichaje. Un `KEYS` sobre una instalacion con años de metricas congela el
     * quiosco de la puerta de personal durante el cambio de turno — el peor
     * momento posible, y provocado por una funcionalidad de diagnostico que no
     * deberia notar nadie.
     *
     * `SCAN` recorre en trozos de `SCAN_COUNT` y suelta el hilo entre uno y
     * otro. A cambio no garantiza una foto exacta: puede repetir una clave y
     * puede perder una que se cree a mitad del recorrido. Para un contador de
     * diagnostico eso da igual —las repetidas se colapsan solas en el mapa— y
     * es un cambio excelente frente a bloquear el fichaje.
     *
     * **Con tope de vueltas.** Un cursor que no cierra —Redis no promete cuantas
     * iteraciones hacen falta— dejaria el paquete girando para siempre. Al
     * llegar al tope se devuelve lo encontrado: un recuento incompleto es una
     * degradacion aceptable de una seccion accesoria; un comando que no termina,
     * no.
     *
     * **El patron se prefija a mano y el resultado se desprefija antes de
     * pedir su valor.** Ver el docblock de la clase: `phpredis` no hace ni lo
     * uno ni lo otro por su cuenta.
     *
     * @return array<string, int|float>
     */
    private function labelled(Connection $connection, string $key): array
    {
        $prefix = $this->keyPrefix();
        $pattern = $prefix.$key.':*';

        $values = [];
        // NULL y no CERO en la primera vuelta: `phpredis` recibe el cursor por
        // referencia y trata un `0` de entrada como «el recorrido ya
        // termino», sin mirar una sola clave.
        $cursor = null;

        for ($round = 0; $round < self::SCAN_MAX_ROUNDS; $round++) {
            $page = $this->scanPage($connection, $pattern, $cursor);

            if (! is_array($page) || \count($page) < 2) {
                break;
            }

            $cursor = is_scalar($page[0]) ? (int) $page[0] : 0;

            $this->collectFound(is_array($page[1]) ? $page[1] : [], $connection, $prefix, $key, $values);

            if ($cursor === 0) {
                break;
            }
        }

        ksort($values, SORT_STRING);

        return $values;
    }

    /**
     * Una vuelta de claves encontradas, desprefijadas y con su valor pedido.
     *
     * Separado de {@see self::labelled()} para que la complejidad ciclomatica
     * de la vuelta de `SCAN` no se sume a la del recorrido de sus claves (doc
     * 02 §3.5): son dos decisiones distintas —cuantas vueltas hacer, y que
     * hacer con lo que trae cada una—.
     *
     * @param  array<array-key, mixed>  $found
     * @param  array<string, int|float>  $values
     */
    private function collectFound(array $found, Connection $connection, string $prefix, string $key, array &$values): void
    {
        foreach ($found as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $unprefixed = str_starts_with($candidate, $prefix)
                ? substr($candidate, \strlen($prefix))
                : $candidate;

            $number = $this->number($this->command($connection, 'GET', [$unprefixed]));

            if ($number !== null) {
                $values[substr($unprefixed, \strlen($key) + 1)] = $number;
            }
        }
    }

    /**
     * Una vuelta de `SCAN`, o `null` si Redis no contesta.
     *
     * Aislada en su propio metodo por lo mismo que en `RedisMetricReader`: el
     * tipo de vuelta es `mixed` para que las comprobaciones de {@see
     * self::labelled()} sean comprobaciones de verdad, y no una promesa del
     * analisis estatico sobre una firma que no es la que corre.
     */
    private function scanPage(Connection $connection, string $pattern, ?int $cursor): mixed
    {
        try {
            // El envoltorio de Laravel y no `command('SCAN', …)`: `phpredis`
            // recibe el cursor POR REFERENCIA y la lista de argumentos
            // `MATCH … COUNT …` del protocolo crudo no le vale —peta con
            // «expects at most 4 arguments»—. Ver el docblock de la clase.
            //
            // @phpstan-ignore argument.type (`Connection` declara `@mixin \Redis`, asi que el analisis ve la firma cruda `Redis::scan(&$it, ?string $pattern, int $count)`. La que corre es `PhpRedisConnection::scan($cursor, array $options)`, que es la unica que traduce `match`/`count` y devuelve `[cursor, claves]`. Verificado contra el contenedor, igual que en `RedisMetricReader::scanPage()`.)
            return $connection->scan($cursor, ['match' => $pattern, 'count' => self::SCAN_COUNT]);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * El prefijo global que el cliente de Redis antepone a toda clave.
     *
     * Misma lectura que `MetricsServiceProvider::keyPrefix()` (que alimenta a
     * `RedisMetricReader`): vacio si la instalacion no configuro ninguno, que
     * es una configuracion legitima y el paquete tiene que seguir funcionando
     * igual.
     */
    private function keyPrefix(): string
    {
        $prefix = config('database.redis.options.prefix');

        return is_string($prefix) ? $prefix : '';
    }

    /**
     * @param  list<string>  $parameters
     */
    private function command(Connection $connection, string $command, array $parameters): mixed
    {
        try {
            return $connection->command($command, $parameters);
        } catch (Throwable) {
            // Una serie ilegible no tumba a las demas de la lista, y desde
            // luego no tumba el paquete.
            return null;
        }
    }

    /**
     * @return array<string, int|float>
     */
    private function hash(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $values = [];

        foreach ($raw as $label => $value) {
            $number = $this->number($value);

            if (is_string($label) && $number !== null) {
                $values[$label] = $number;
            }
        }

        ksort($values, SORT_STRING);

        return $values;
    }

    private function number(mixed $value): int|float|null
    {
        if (! is_scalar($value) || ! is_numeric($value)) {
            return null;
        }

        $text = (string) $value;

        return str_contains($text, '.') ? (float) $text : (int) $text;
    }
}
