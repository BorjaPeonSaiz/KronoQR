<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Product\Application\Port\DataExportRepository;
use App\Modules\Product\Application\Port\ProductEventPublisher;
use App\Modules\Product\Domain\Event\DataExportDownloaded;
use App\Modules\Product\Domain\Exception\DataExportNotReady;
use App\Modules\Product\Domain\Model\DataExport;
use App\Modules\Shared\Application\Port\Clock;
use Illuminate\Database\ConnectionInterface;

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
        private ConnectionInterface $connection,
    ) {}

    /**
     * @param  callable(string): bool  $fileExists  Si el ZIP sigue en el disco. Lo decide el
     *                                              borde, que es quien puede tocar el sistema
     *                                              de ficheros: el caso de uso no abre
     *                                              ficheros ni sabe que hay un disco.
     * @param  ?int  $downloadedByUserId  La cuenta que se lo lleva.
     *
     * @throws DataExportNotReady si la exportacion sigue `pending` o `running`
     */
    public function handle(string $uuid, callable $fileExists, ?int $downloadedByUserId): ?DataExport
    {
        $export = $this->exports->findByUuid($uuid);

        if ($export === null) {
            return null;
        }

        if ($export->isInProgress()) {
            throw new DataExportNotReady($uuid);
        }

        if (! $export->isDownloadable() || ! $fileExists((string) $export->filePath)) {
            return null;
        }

        $now = $this->clock->now();

        $this->connection->transaction(function () use ($export, $now, $downloadedByUserId): void {
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
