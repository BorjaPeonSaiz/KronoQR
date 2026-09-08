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
        'compliance_profile_changes_total',
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
        'report_exports_total',
        'scan_batch_size',
        'scan_processing_duration_seconds',
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
     * @return array<string, int|float>
     */
    private function labelled(Connection $connection, string $key): array
    {
        $values = [];
        $cursor = '0';
        $rounds = 0;

        do {
            $page = $this->command($connection, 'SCAN', [$cursor, 'MATCH', $key.':*', 'COUNT', (string) self::SCAN_COUNT]);

            if (! is_array($page) || \count($page) < 2) {
                break;
            }

            $cursor = is_scalar($page[0]) ? (string) $page[0] : '0';
            $found = is_array($page[1]) ? $page[1] : [];

            foreach ($found as $candidate) {
                if (! is_string($candidate)) {
                    continue;
                }

                $number = $this->number($this->command($connection, 'GET', [$candidate]));

                if ($number !== null) {
                    $values[substr($candidate, \strlen($key) + 1)] = $number;
                }
            }

            $rounds++;
        } while ($cursor !== '0' && $rounds < self::SCAN_MAX_ROUNDS);

        ksort($values, SORT_STRING);

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
            // Una serie ilegible no tumba las diecisiete restantes, y desde
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
