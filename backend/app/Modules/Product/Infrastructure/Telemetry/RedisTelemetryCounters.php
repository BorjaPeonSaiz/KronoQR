<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Telemetry;

use App\Modules\Product\Application\Port\TelemetryCounters;
use App\Modules\Product\Domain\ValueObject\TelemetryUsage;
use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Redis\Connections\Connection;
use Throwable;

/**
 * Los cuatro contadores acumulados de `usage_7d`, leidos de las series del
 * doc 02 §8.2 (**RF-PD-12**).
 *
 * ## Cuatro series y no las dieciocho del paquete de diagnostico
 *
 * `MetricsCollector` recoge las dieciocho **con sus etiquetas**, y las etiquetas
 * llevan `device=<uuid>`. Un uuid de quiosco no sale de la instalacion por este
 * canal (ficha 5.10 punto 8: «ni `uuid` de nadie»), asi que aqui se **suman** y
 * lo que viaja es un entero por serie. Reutilizar aquel recolector obligaria a
 * traer las etiquetas para tirarlas despues, que es como se filtra un campo.
 *
 * ## Las series viven de dos formas en Redis, y las dos hay que leerlas
 *
 * - `scans_total` y `report_exports_total` son **un hash** con la etiqueta en el
 *   campo (`device=…,result=clock_in`).
 * - `scan_batch_size` es **un hash por dispositivo** en una clave propia
 *   (`…:scan_batch_size:device=<uuid>`), con `sum` y `count` dentro. Los lotes
 *   sincronizados son la suma de los `count`.
 *
 * Las claves se escriben aqui como texto y no importando las constantes de
 * `Attendance` o `Reporting`: `Product` no puede depender de esos modulos (doc
 * 02 §1.6) y `MetricsCollector` hace exactamente lo mismo por el mismo motivo.
 *
 * ## `SCAN` y nunca `KEYS`
 *
 * Mismo argumento que `MetricsCollector`, y aqui pesa mas: este Redis sostiene
 * la cola, la cache y las sesiones, y esta en el camino de cada fichaje. Un
 * `KEYS` congelaria el quiosco de la puerta de personal. Con tope de vueltas: un
 * recuento incompleto es una degradacion aceptable de algo accesorio; una tarea
 * que no termina, no.
 *
 * ## Lo que no se puede leer NO aparece en el mapa
 *
 * Y eso se convierte en `null` en el documento, no en `0`. Ver
 * {@see TelemetryUsage}.
 */
final readonly class RedisTelemetryCounters implements TelemetryCounters
{
    private const string PREFIX = 'kronoqr:metrics:';

    private const int SCAN_COUNT = 500;

    private const int SCAN_MAX_ROUNDS = 64;

    /** Los resultados de un escaneo que son un fichaje y no un rechazo (`ScanResult`). */
    private const array ACCEPTED = ['clock_in', 'clock_out', 'break_start', 'break_end'];

    public function __construct(private Redis $redis) {}

    public function snapshot(): array
    {
        try {
            $connection = $this->redis->connection();
        } catch (Throwable) {
            return [];
        }

        $counters = [];

        $scans = $this->hash($connection, self::PREFIX.'scans_total');

        if ($scans !== []) {
            $counters['scans_accepted'] = $this->sumWhere($scans, true);
            $counters['scans_rejected'] = $this->sumWhere($scans, false);
        }

        $batches = $this->batches($connection);

        if ($batches !== null) {
            $counters['batches_synced'] = $batches;
        }

        $exports = $this->hash($connection, self::PREFIX.'report_exports_total');

        if ($exports !== []) {
            $counters['reports_generated'] = array_sum($exports);
        }

        return $counters;
    }

    /**
     * Suma los campos cuyo `result=` es (o no es) un fichaje aceptado.
     *
     * Un campo sin `result=` —que hoy no existe— no cuenta en ninguno de los
     * dos: preferimos una cifra corta a una inventada.
     *
     * @param  array<string, int>  $hash
     */
    private function sumWhere(array $hash, bool $accepted): int
    {
        $total = 0;

        foreach ($hash as $label => $value) {
            $result = $this->labelValue($label, 'result');

            if ($result === null) {
                continue;
            }

            if (\in_array($result, self::ACCEPTED, true) === $accepted) {
                $total += $value;
            }
        }

        return $total;
    }

    /**
     * El valor de una etiqueta dentro de `device=<uuid>,result=clock_in`.
     */
    private function labelValue(string $label, string $name): ?string
    {
        foreach (explode(',', $label) as $pair) {
            if (str_starts_with($pair, $name.'=')) {
                return substr($pair, \strlen($name) + 1);
            }
        }

        return null;
    }

    /**
     * Los lotes sincronizados: la suma de `count` de un hash por dispositivo.
     *
     * `null` si no hay ninguna clave, que es lo que significa «todavia no ha
     * sincronizado nadie o Redis no lo sabe».
     */
    private function batches(Connection $connection): ?int
    {
        $cursor = '0';
        $rounds = 0;
        $total = null;

        do {
            [$cursor, $keys] = $this->scanPage($connection, self::PREFIX.'scan_batch_size:*', $cursor);

            foreach ($keys as $key) {
                $count = $this->hash($connection, $key)['count'] ?? null;

                if ($count !== null) {
                    $total = ($total ?? 0) + $count;
                }
            }

            $rounds++;
        } while ($cursor !== '0' && $rounds < self::SCAN_MAX_ROUNDS);

        return $total;
    }

    /**
     * Una vuelta de `SCAN`: el cursor siguiente y las claves encontradas.
     *
     * Un cursor `'0'` cierra el recorrido, y es tambien lo que se devuelve
     * cuando la respuesta no tiene la forma esperada: preferimos un recuento
     * corto a un bucle que no termina.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function scanPage(Connection $connection, string $match, string $cursor): array
    {
        $page = $this->command($connection, 'SCAN', [$cursor, 'MATCH', $match, 'COUNT', (string) self::SCAN_COUNT]);

        if (! is_array($page) || \count($page) < 2 || ! is_array($page[1])) {
            return ['0', []];
        }

        return [
            is_scalar($page[0]) ? (string) $page[0] : '0',
            array_values(array_filter($page[1], is_string(...))),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function hash(Connection $connection, string $key): array
    {
        $raw = $this->command($connection, 'HGETALL', [$key]);

        if (! is_array($raw)) {
            return [];
        }

        $values = [];

        foreach ($raw as $field => $value) {
            if (is_string($field) && is_scalar($value) && is_numeric($value)) {
                $values[$field] = (int) $value;
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
            // Una serie ilegible deja su hueco en `null` y no tumba las otras
            // tres, ni el envio, ni la tarea del planificador.
            return null;
        }
    }
}
