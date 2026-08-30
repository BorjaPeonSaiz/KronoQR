<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Metrics;

use App\Modules\Compliance\Application\Port\RetentionMetrics;
use App\Modules\Compliance\Domain\ValueObject\RetentionMode;
use App\Modules\Compliance\Domain\ValueObject\RetentionReport;
use App\Modules\Compliance\Domain\ValueObject\RetentionScope;
use DateTimeImmutable;
use Illuminate\Support\Facades\Config;
use RuntimeException;

/**
 * Publica el estado de la retencion para el colector *textfile* de
 * `node-exporter` (doc 02 §8.2).
 *
 * ## La serie que importa es la de lo PENDIENTE
 *
 * `retention_pending_rows{scope}` dice cuanto dato vencido sigue en la base
 * porque nadie ha confirmado la purga. Es lo que se mira: la purga es manual por
 * diseno (RF-PR-03), asi que el caso normal es que no se ejecute, y un producto
 * que solo publicara «purgas ejecutadas» dejaria ese caso en silencio -RL-02
 * incumpliendose sin que ninguna serie cambiara-.
 *
 * `retention_last_run_timestamp_seconds{mode}` permite que una regla `absent()`
 * u `older_than` descubra que la propuesta programada dejo de correr, que es el
 * fallo que nadie nota.
 *
 * ## Se escribe en cada pasada, tambien en simulacion
 *
 * Una serie que desaparece es indistinguible de una que esta a cero. Mismo
 * criterio que {@see TextfileAuditMetrics}.
 *
 * **Escritura atomica:** temporal en el mismo directorio y `rename()`.
 * `node-exporter` no puede leer media metrica.
 */
final readonly class TextfileRetentionMetrics implements RetentionMetrics
{
    private const string FILE = 'kronoqr_retention.prom';

    public function recordRun(RetentionReport $report, DateTimeImmutable $at): void
    {
        $lines = [
            '# HELP retention_pending_rows Filas vencidas pendientes de purgar por ambito (RL-02, RL-11).',
            '# TYPE retention_pending_rows gauge',
        ];

        $pending = $report->mode === RetentionMode::Simulation;

        foreach (RetentionScope::cases() as $scope) {
            $rows = $report->rowsFor($scope);

            // Tras una ejecucion no queda nada pendiente de lo que se llevo: se
            // publica cero y ademas lo purgado, que es otra pregunta.
            $lines[] = 'retention_pending_rows{scope="'.$scope->value.'"} '.($pending ? $rows : 0);
        }

        $lines[] = '# HELP retention_purged_rows Filas eliminadas en la ultima ejecucion real por ambito.';
        $lines[] = '# TYPE retention_purged_rows gauge';

        foreach (RetentionScope::cases() as $scope) {
            $lines[] = 'retention_purged_rows{scope="'.$scope->value.'"} '.($pending ? 0 : $report->rowsFor($scope));
        }

        $lines[] = '# HELP retention_last_run_timestamp_seconds Momento de la ultima pasada de retencion.';
        $lines[] = '# TYPE retention_last_run_timestamp_seconds gauge';
        $lines[] = 'retention_last_run_timestamp_seconds{mode="'.$report->mode->value.'"} '.$at->getTimestamp();
        $lines[] = '# HELP retention_cutoff_timestamp_seconds Fecha de corte del registro de jornada aplicada.';
        $lines[] = '# TYPE retention_cutoff_timestamp_seconds gauge';
        $lines[] = 'retention_cutoff_timestamp_seconds '.$report->workRecordCutoff->getTimestamp();

        $this->write($lines);
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
