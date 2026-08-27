<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Metrics;

use App\Modules\Compliance\Application\Port\AuditMetrics;
use App\Modules\Compliance\Domain\ValueObject\AuditChainVerification;
use App\Modules\Compliance\Domain\ValueObject\AuditPartitionStatus;
use DateTimeImmutable;
use Illuminate\Support\Facades\Config;
use RuntimeException;

/**
 * Publica las metricas de auditoria para el colector *textfile* de
 * `node-exporter` (doc 02 §8.2).
 *
 * **`audit_chain_verification_failures_total` es un contador y se acumula.** Se
 * lee el valor anterior del propio fichero y se le suman los hallazgos de esta
 * ejecucion. Si el fichero se pierde, la serie vuelve a cero y Prometheus lo
 * trata como un reinicio de contador, que es el comportamiento correcto para un
 * `counter`.
 *
 * **Se escribe siempre, tambien cuando todo esta bien.** Una serie que
 * desaparece es indistinguible de una que nunca fallo, y la regla `absent()` de
 * `infra/observability/prometheus/rules/audit.yml` es la que descubre que el
 * comando programado dejo de ejecutarse. El silencio es el peor de los fallos.
 *
 * **Escritura atomica:** fichero temporal en el mismo directorio y `rename()`.
 * `node-exporter` no puede leer media metrica.
 */
final readonly class TextfileAuditMetrics implements AuditMetrics
{
    private const string CHAIN_FILE = 'kronoqr_audit_chain.prom';

    private const string PARTITIONS_FILE = 'kronoqr_audit_partitions.prom';

    private const string FAILURES_METRIC = 'audit_chain_verification_failures_total';

    public function recordVerification(AuditChainVerification $result, DateTimeImmutable $at): void
    {
        $total = $this->previousCounter(self::CHAIN_FILE, self::FAILURES_METRIC) + $result->failureCount();

        $this->write(self::CHAIN_FILE, [
            '# HELP '.self::FAILURES_METRIC.' Roturas de la cadena de hash de audit_log detectadas (RS-07). Debe permanecer siempre en cero.',
            '# TYPE '.self::FAILURES_METRIC.' counter',
            self::FAILURES_METRIC.' '.$total,
            '# HELP audit_chain_last_verification_timestamp_seconds Momento de la ultima verificacion completa de la cadena.',
            '# TYPE audit_chain_last_verification_timestamp_seconds gauge',
            'audit_chain_last_verification_timestamp_seconds '.$at->getTimestamp(),
            '# HELP audit_chain_last_verification_result 1 si la cadena estaba integra, 0 si se detecto alguna rotura.',
            '# TYPE audit_chain_last_verification_result gauge',
            'audit_chain_last_verification_result '.($result->isIntact() ? '1' : '0'),
            '# HELP audit_chain_rows_verified Filas de audit_log recorridas en la ultima verificacion.',
            '# TYPE audit_chain_rows_verified gauge',
            'audit_chain_rows_verified '.$result->rowsVerified,
        ]);
    }

    public function recordPartitionStatus(AuditPartitionStatus $status, DateTimeImmutable $at): void
    {
        $this->write(self::PARTITIONS_FILE, [
            '# HELP audit_log_partition_ready 1 si existe la particion anual de audit_log indicada. A 0, toda accion auditable falla.',
            '# TYPE audit_log_partition_ready gauge',
            'audit_log_partition_ready{horizon="current"} '.($status->currentYearWasMissing ? '0' : '1'),
            'audit_log_partition_ready{horizon="next"} '.($status->nextYearReady ? '1' : '0'),
            '# HELP audit_log_partition_check_timestamp_seconds Momento de la ultima comprobacion de particiones.',
            '# TYPE audit_log_partition_check_timestamp_seconds gauge',
            'audit_log_partition_check_timestamp_seconds '.$at->getTimestamp(),
        ]);
    }

    /**
     * @param  list<string>  $lines
     */
    private function write(string $file, array $lines): void
    {
        if (! Config::boolean('observability.metrics.enabled', true)) {
            return;
        }

        $directory = $this->directory();

        if (! is_dir($directory) && ! mkdir($directory, 0o750, true) && ! is_dir($directory)) {
            throw new RuntimeException('No se ha podido crear el directorio de metricas «'.$directory.'».');
        }

        $target = $directory.'/'.$file;
        $temporary = $target.'.tmp';

        if (file_put_contents($temporary, implode("\n", $lines)."\n") === false) {
            throw new RuntimeException('No se ha podido escribir la metrica en «'.$temporary.'».');
        }

        if (! rename($temporary, $target)) {
            throw new RuntimeException('No se ha podido publicar la metrica en «'.$target.'».');
        }
    }

    /**
     * Valor actual del contador en el fichero, o 0 si no lo hay. Se lee con una
     * expresion regular anclada al principio de linea para no confundirse con
     * las lineas `# HELP` y `# TYPE`, que contienen el mismo nombre.
     */
    private function previousCounter(string $file, string $metric): int
    {
        $target = $this->directory().'/'.$file;

        if (! is_file($target)) {
            return 0;
        }

        $contents = file_get_contents($target);

        if ($contents === false) {
            return 0;
        }

        if (preg_match('/^'.preg_quote($metric, '/').'\s+(\d+)/m', $contents, $match) !== 1) {
            return 0;
        }

        return (int) $match[1];
    }

    private function directory(): string
    {
        return rtrim(Config::string('observability.metrics.textfile_path'), '/');
    }
}
