<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Product\Application\Port\DataExportRepository;
use App\Modules\Product\Application\Port\ProductEventPublisher;
use App\Modules\Product\Domain\Event\DataExportDownloaded;
use App\Modules\Product\Domain\Exception\DataExportNotReady;
use App\Modules\Product\Domain\Model\DataExport;
use App\Modules\Shared\Application\GeneratedFiles\GeneratedFileHousekeeping;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\SerializedLedgerWrite;
use App\Modules\Shared\Domain\ValueObject\GeneratedFileArea;
use App\Modules\Shared\Domain\ValueObject\RecordedFileLocation;

/**
 * Autoriza la entrega del ZIP y **deja el asiento antes de entregarlo**
 * (**RF-PD-14**, RL-20, RS-05).
 *
 * ## El asiento va antes, y esa es la decision
 *
 * Si se escribiera despues, una descarga que se corta a la mitad —o un proceso
 * que muere sirviendo dos gigabytes— dejaria el fichero fuera del servidor y el
 * asiento sin escribir. Al reves puede quedar constancia de una descarga que no
 * se completo, y eso es preferible con mucho: sobra informacion en el trail en
 * lugar de faltar justo en la pregunta que hay que contestar ante una brecha
 * (RL-15).
 *
 * ## Tres desenlaces y ninguno es el mismo
 *
 * - **En curso** — {@see DataExportNotReady}, que el borde traduce a `409`. Dice
 *   «espera unos segundos y vuelve», y el panel sigue sondeando.
 * - **No existe, fallo, se purgo o el fichero ya no esta en disco** — `null`, que
 *   el borde traduce a `404`. Ahi no hay nada que esperar. La fila sigue en la
 *   lista con su estado, para que se sepa que existio.
 * - **Descargable** — el modelo, con `filePath` y `sha256` listos.
 *
 * El cuarto caso —fila `completed` cuyo fichero alguien borro a mano para hacer
 * sitio— cae en el segundo por {@see DataExport::isDownloadable()}: `404` en vez
 * de una excepcion de sistema de ficheros.
 */
final readonly class DownloadDataExportHandler
{
    public function __construct(
        private DataExportRepository $exports,
        private ProductEventPublisher $events,
        private Clock $clock,
        private SerializedLedgerWrite $serialized,
        /**
         * El localizador confinado (ADR-045, F3). Antes decidia el controlador
         * con un `is_file()` sobre la ruta de la fila: una fila alterada con
         * `file_path='/proc/self/environ'` entregaba los secretos del proceso.
         * Ahora solo se entrega un ZIP presente dentro de su raiz, con su patron y
         * sin enlaces; lo demas es `404` sin causa y sube `refused`.
         */
        private GeneratedFileHousekeeping $files,
        /** `GeneratedFileAreas::dataExportArchives()`. */
        private GeneratedFileArea $archives,
    ) {}

    /**
     * @param  ?int  $downloadedByUserId  La cuenta que se lo lleva.
     *
     * @throws DataExportNotReady si la exportacion sigue `pending` o `running`
     */
    public function handle(string $uuid, ?int $downloadedByUserId): ?DataExport
    {
        $export = $this->exports->findByUuid($uuid);

        if ($export === null) {
            return null;
        }

        if ($export->isInProgress()) {
            throw new DataExportNotReady($uuid);
        }

        if (! $export->isDownloadable()
            || $this->files->locateRecorded($this->archives, (string) $export->filePath) !== RecordedFileLocation::Present) {
            return null;
        }

        $now = $this->clock->now();

        // Candado de la cadena de `audit_log` ANTES que la fila (ADR-010,
        // ADR-045 §d): la purga horaria toma los dos en ese orden cuando un ZIP
        // desaparece antes de caducar, y al reves se cerraria un abrazo mortal.
        $this->serialized->withChainLock(function () use ($export, $now, $downloadedByUserId): void {
            $this->exports->recordDownload($export->id, $now);

            $this->events->publish(new DataExportDownloaded(
                uuid: $export->uuid,
                fileName: $export->fileName ?? '',
                sha256: $export->sha256 ?? '',
                sizeBytes: $export->sizeBytes ?? 0,
                downloadCount: $export->downloadCount + 1,
                downloadedByUserId: $downloadedByUserId,
                occurredAt: $now,
            ));
        });

        return $export;
    }
}
