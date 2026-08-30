<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure\Metrics;

use App\Modules\Attendance\Application\Port\ProjectionMetrics;
use DateTimeImmutable;
use Illuminate\Support\Facades\Config;
use RuntimeException;

/**
 * Publica `projection_divergence_total` y el rastro de la reconciliacion para el
 * colector *textfile* de `node-exporter` (doc 02 §8.2, RF-PR-02).
 *
 * ```
 * projection_divergence_total 0
 * projection_reconciliation_last_run_timestamp_seconds 1773541800
 * projection_reconciliation_work_days_inspected 214
 * projection_reconciliation_last_corrections 0
 * ```
 *
 * ## Por que *textfile* y no Redis
 *
 * `RedisScanMetrics` usa `HINCRBY` porque su hecho ocurre cincuenta veces por
 * segundo en un cambio de turno y un fichero por escaneo seria una escritura de
 * disco por fichaje. Aqui el hecho ocurre **una vez al dia**, de madrugada, y lo
 * que se necesita del contador es lo contrario: que **sobreviva a un reinicio**.
 * Redis es cache en este producto —se puede vaciar en un despliegue sin que
 * nadie pierda nada— y un `FLUSHALL` devolveria `projection_divergence_total` a
 * cero, que es precisamente el valor que significa «todo esta bien». Una
 * divergencia borrada por un reinicio es una divergencia que nadie investigo.
 *
 * El fichero vive en `BACKUP_PATH/metrics`, que es un volumen persistente y
 * entra en la copia diaria. Es el mismo razonamiento —y el mismo formato— que
 * `TextfileAuditMetrics` hace con `audit_chain_verification_failures_total`, la
 * otra metrica que el §8.2 obliga a mantener siempre en cero.
 *
 * ## El contador se acumula, y por eso se lee antes de escribir
 *
 * Un `counter` de Prometheus solo puede subir. Se lee el valor anterior del
 * propio fichero y se le suman las divergencias de esta pasada. Si el fichero se
 * perdiera, la serie volveria a cero y Prometheus lo interpretaria como un
 * reinicio de contador, que es el comportamiento correcto de un `counter` — y
 * seguiria estando el sello de tiempo para delatar que algo paso.
 *
 * ## Se escribe siempre, tambien cuando todo esta bien
 *
 * Una serie que solo aparece cuando hay divergencias es indistinguible de una
 * tarea programada que dejo de ejecutarse. El sello de tiempo es lo que sostiene
 * la alerta `absent()` de `infra/observability/prometheus/rules/projection.yml`:
 * sin el, apagar el planificador seria la forma mas comoda de que la alerta de
 * divergencia no volviera a sonar nunca.
 *
 * **Escritura atomica**: temporal en el mismo directorio y `rename()`.
 * `node-exporter` no puede leer media metrica.
 */
final readonly class TextfileProjectionMetrics implements ProjectionMetrics
{
    private const string FILE = 'kronoqr_projection.prom';

    private const string DIVERGENCE_METRIC = 'projection_divergence_total';

    public function reconciliationCompleted(
        int $workDaysInspected,
        int $divergences,
        int $corrected,
        DateTimeImmutable $at,
    ): void {
        $total = $this->previousCounter() + $divergences;

        $this->write([
            '# HELP '.self::DIVERGENCE_METRIC.' Jornadas cuya proyeccion daily_totals no coincidia con sus eventos origen (RF-PR-02, ADR-007). Debe permanecer siempre en cero.',
            '# TYPE '.self::DIVERGENCE_METRIC.' counter',
            self::DIVERGENCE_METRIC.' '.$total,
            '# HELP projection_reconciliation_last_run_timestamp_seconds Momento de la ultima reconciliacion. Su ausencia delata que la tarea programada dejo de ejecutarse.',
            '# TYPE projection_reconciliation_last_run_timestamp_seconds gauge',
            'projection_reconciliation_last_run_timestamp_seconds '.$at->getTimestamp(),
            '# HELP projection_reconciliation_work_days_inspected Jornadas contrastadas en la ultima pasada.',
            '# TYPE projection_reconciliation_work_days_inspected gauge',
            'projection_reconciliation_work_days_inspected '.$workDaysInspected,
            '# HELP projection_reconciliation_last_corrections Filas reescritas en la ultima pasada. Distinto de cero significa que la proyeccion se habia desviado.',
            '# TYPE projection_reconciliation_last_corrections gauge',
            'projection_reconciliation_last_corrections '.$corrected,
        ]);
    }

    /**
     * @param  list<string>  $lines
     */
    private function write(array $lines): void
    {
        if (! Config::boolean('observability.metrics.enabled', true)) {
            return;
        }

        $directory = $this->directory();

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

    /**
     * Valor actual del contador en el fichero, o 0 si no lo hay.
     *
     * La expresion regular esta anclada al principio de linea para no
     * confundirse con las lineas `# HELP` y `# TYPE`, que contienen el mismo
     * nombre. Es el mismo lector que usa `TextfileAuditMetrics`.
     */
    private function previousCounter(): int
    {
        $target = $this->directory().'/'.self::FILE;

        if (! is_file($target)) {
            return 0;
        }

        $contents = file_get_contents($target);

        if ($contents === false) {
            return 0;
        }

        if (preg_match('/^'.preg_quote(self::DIVERGENCE_METRIC, '/').'\s+(\d+)/m', $contents, $match) !== 1) {
            return 0;
        }

        return (int) $match[1];
    }

    private function directory(): string
    {
        return rtrim(Config::string('observability.metrics.textfile_path'), '/');
    }
}
