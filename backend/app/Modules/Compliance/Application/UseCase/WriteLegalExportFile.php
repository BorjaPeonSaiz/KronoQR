<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\UseCase;

use App\Modules\Compliance\Application\Port\LegalExportMetrics;
use App\Modules\Compliance\Application\Port\LegalExportSource;
use App\Modules\Compliance\Application\Port\LegalExportWriter;
use App\Modules\Compliance\Domain\ValueObject\LegalExportManifest;
use App\Modules\Compliance\Domain\ValueObject\LegalExportPeriod;
use App\Modules\Compliance\Domain\ValueObject\LegalExportRecord;
use App\Modules\Compliance\Domain\ValueObject\LegalExportScope;
use App\Modules\Compliance\Domain\ValueObject\LegalExportTally;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Support\UseCaseInstrumentation;
use Closure;
use Illuminate\Database\ConnectionInterface;

/**
 * Escribe la exportacion para la Inspeccion **para la cola de informes**
 * (**RF-IN-05**, RL-06, V5-PA-4).
 *
 * ## Es {@see GenerateLegalExport} sin el asiento
 *
 * El mismo origen, el mismo escritor, el mismo manifiesto y la misma
 * transaccion —la lectura tiene que ser un instante, y el cursor de servidor la
 * exige—. Lo unico que falta es `legal_export.generated`: en diferido lo
 * escribe `Reporting` al cerrar la fila, a nombre de quien la pidio y en la
 * misma transaccion que `report_export.generated`. Aqui, en el trabajo de
 * cola, el contexto de auditoria diria `system`, y eso es justo lo que el
 * bloque 11 corrigio para los informes.
 *
 * ## El avance sale de recorrer, no de suponer
 *
 * Antes de abrir el cursor se cuentan las filas que va a ceder
 * ({@see LegalExportSource::count()}); despues, cada
 * {@see self::PROGRESS_EVERY_ROWS} filas cedidas, se avisa a quien llama. Ese
 * aviso es tambien **el punto de cancelacion**: si lanza, la excepcion recorre
 * el escritor, cierra el cursor, deshace la transaccion y sale tal cual.
 */
final readonly class WriteLegalExportFile
{
    /** Cada cuantas filas se avisa: lo bastante para ver moverse la barra, no tanto como para preguntar en cada una. */
    public const int PROGRESS_EVERY_ROWS = 500;

    public function __construct(
        private ConnectionInterface $connection,
        private LegalExportSource $source,
        private LegalExportWriter $writer,
        private LegalExportMetrics $metrics,
        private Clock $clock,
    ) {}

    /**
     * @param  Closure(int, int): void  $onProgress  Filas escritas y filas esperadas.
     */
    public function handle(
        LegalExportPeriod $period,
        LegalExportScope $scope,
        string $destinationPath,
        Closure $onProgress,
    ): LegalExportTally {
        return UseCaseInstrumentation::run(
            'compliance.write_legal_export_file',
            fn (): LegalExportTally => $this->doHandle($period, $scope, $destinationPath, $onProgress),
        );
    }

    /**
     * @param  Closure(int, int): void  $onProgress
     */
    private function doHandle(
        LegalExportPeriod $period,
        LegalExportScope $scope,
        string $destinationPath,
        Closure $onProgress,
    ): LegalExportTally {
        $manifest = new LegalExportManifest(
            generatedAt: $this->clock->now(),
            period: $period,
            scope: $scope,
        );

        /** @var LegalExportTally $tally */
        $tally = $this->connection->transaction(function () use ($manifest, $destinationPath, $onProgress): LegalExportTally {
            $expected = $this->source->count($manifest->period, $manifest->scope);

            $onProgress(0, $expected);

            return $this->writer->write(
                $manifest,
                $destinationPath,
                self::reporting($this->source->records($manifest->period, $manifest->scope), $expected, $onProgress),
            );
        });

        // Fuera de la transaccion, como en la sincrona: el fichero esta escrito
        // y un fallo de Redis no puede convertirlo en un error.
        $this->metrics->exportGenerated($manifest->scope->metricLabel());

        return $tally;
    }

    /**
     * Cede las filas tal cual y avisa cada {@see self::PROGRESS_EVERY_ROWS}.
     *
     * @param  iterable<int, LegalExportRecord>  $records
     * @param  Closure(int, int): void  $onProgress
     * @return iterable<int, LegalExportRecord>
     */
    private static function reporting(iterable $records, int $expected, Closure $onProgress): iterable
    {
        $done = 0;

        foreach ($records as $record) {
            yield $record;

            $done++;

            if ($done % self::PROGRESS_EVERY_ROWS === 0) {
                $onProgress($done, $expected);
            }
        }

        $onProgress($done, $expected);
    }
}
