<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Metrics;

use App\Modules\Reporting\Application\Port\PresenceMetrics;
use DateTimeImmutable;
use Illuminate\Support\Facades\Config;
use RuntimeException;

/**
 * Publica las dos metricas de la presencia en vivo para el colector *textfile*
 * de `node-exporter` (doc 02 §8.2, doc 01 §9.2).
 *
 * ```
 * open_shifts_current{site="1",site_name="Hotel Marina",department="Cocina"}
 * websocket_connections_active
 * presence_metrics_timestamp_seconds
 * ```
 *
 * **Por que un fichero y no un contador en memoria.** Lo mismo que en
 * `Identity\Infrastructure\Metrics\TextfileCredentialMetrics` y por el mismo
 * motivo: `/metrics` lo expone la aplicacion a partir de la tarea 3.1, y quien
 * produce estos numeros es un comando programado que corre y termina. Un
 * contador en memoria de un proceso que termina no lo lee nadie.
 *
 * **La etiqueta `department` lleva el NOMBRE y no el identificador.** Son una
 * decena por instalacion, asi que la cardinalidad no es un problema, y un panel
 * de Grafana que dijera `department="7"` no lo entiende nadie a las seis de la
 * mañana. La consecuencia asumida: renombrar un departamento mueve la serie —el
 * gauge es un valor actual, asi que lo unico que se pierde es la continuidad del
 * historico de ese nombre.
 *
 * **Se escriben todos los departamentos, tambien los que estan a cero.** Una
 * serie que desaparece es indistinguible de una que nunca tuvo nada, y el cero
 * es justo lo que se mira: «no ha entrado nadie en cocina».
 *
 * **`websocket_connections_active` se omite cuando no se sabe.** No se escribe
 * un cero: cero significa «nadie tiene el panel abierto» —normal de madrugada— y
 * la ausencia de la serie significa «Reverb no contesta», que es la averia que
 * esta metrica existe para detectar (ADR-011). Escribir un cero convertiria esa
 * averia en una jornada tranquila.
 *
 * **Escritura atomica:** temporal en el mismo directorio y `rename()`.
 * `node-exporter` no puede leer media metrica.
 */
final readonly class TextfilePresenceMetrics implements PresenceMetrics
{
    private const string FILE = 'kronoqr_presence.prom';

    public function publish(
        int $siteId,
        string $siteName,
        array $openShiftsByDepartment,
        ?int $websocketConnections,
        DateTimeImmutable $at,
    ): void {
        $lines = [
            '# HELP open_shifts_current Turnos abiertos en este momento, por departamento (doc 01 §9.2). Es cuanta gente hay dentro del centro ahora mismo.',
            '# TYPE open_shifts_current gauge',
        ];

        // Orden estable: un fichero que cambia de orden en cada escritura ensucia
        // cualquier diff y no aporta nada.
        ksort($openShiftsByDepartment);

        foreach ($openShiftsByDepartment as $department => $openShifts) {
            $labels = '{site="'.$siteId.'",site_name="'.$this->escape($siteName).'",department="'.$this->escape($department).'"}';

            $lines[] = 'open_shifts_current'.$labels.' '.$openShifts;
        }

        if ($websocketConnections !== null) {
            $lines[] = '# HELP websocket_connections_active Conexiones vivas al WebSocket de presencia. Su AUSENCIA significa que Reverb no contesta, que no es lo mismo que un cero (ADR-011).';
            $lines[] = '# TYPE websocket_connections_active gauge';
            $lines[] = 'websocket_connections_active '.$websocketConnections;
        }

        $lines[] = '# HELP presence_metrics_timestamp_seconds Momento del ultimo recuento. Su ausencia delata que la tarea programada dejo de ejecutarse.';
        $lines[] = '# TYPE presence_metrics_timestamp_seconds gauge';
        $lines[] = 'presence_metrics_timestamp_seconds '.$at->getTimestamp();

        $this->write($lines);
    }

    /**
     * El formato de exposicion escapa `\`, `"` y el salto de linea dentro del
     * valor de una etiqueta. Sin esto, un departamento llamado `Sala "El Faro"`
     * produciria un fichero que `node-exporter` descarta entero, y con el las
     * series de todos los demas departamentos.
     */
    private function escape(string $value): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\"', '\n'], $value);
    }

    /**
     * @param  list<string>  $lines
     */
    private function write(array $lines): void
    {
        if (! Config::boolean('observability.metrics.enabled', true)) {
            return;
        }

        $directory = rtrim(Config::string('observability.metrics.textfile_path'), '/');

        if (! is_dir($directory) && ! mkdir($directory, 0o750, true) && ! is_dir($directory)) {
            throw new RuntimeException('No se ha podido crear el directorio de metricas «'.$directory.'».');
        }

        $target = $directory.'/'.self::FILE;
        $temporary = $target.'.tmp';

        if (file_put_contents($temporary, implode("\n", $lines)."\n") === false) {
            throw new RuntimeException('No se ha podido escribir la metrica en «'.$temporary.'».');
        }

        if (! rename($temporary, $target)) {
            throw new RuntimeException('No se ha podido publicar la metrica en «'.$target.'».');
        }
    }
}
