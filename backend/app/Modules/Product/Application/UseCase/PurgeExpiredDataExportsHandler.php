<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Product\Application\Port\DataExportRepository;
use App\Modules\Product\Application\Port\ProductEventPublisher;
use App\Modules\Product\Domain\Event\DataExportFileMissing;
use App\Modules\Product\Domain\Model\DataExport;
use App\Modules\Product\Domain\ValueObject\DataExportMaintenance;
use App\Modules\Shared\Application\GeneratedFiles\GeneratedFileHousekeeping;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\SerializedLedgerWrite;
use App\Modules\Shared\Domain\ValueObject\GeneratedFileArea;
use App\Modules\Shared\Domain\ValueObject\GeneratedFileClass;
use App\Modules\Shared\Domain\ValueObject\GeneratedFileEntry;
use App\Modules\Shared\Domain\ValueObject\GeneratedFileRemoval;
use App\Modules\Shared\Domain\ValueObject\RecordedFileLocation;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * El mantenimiento horario de la exportacion integra: **desatasca, purga y
 * concilia** (**RF-PD-14**, RL-20, RL-15, regla dura 5; ADR-045).
 *
 * ## Por que la exportacion caduca
 *
 * Porque el fichero es **una copia completa de la plantilla y de cuatro años de
 * fichajes**, en el disco del cliente, con permisos `0600` y sin nadie que se
 * acuerde de el. `PRODUCT_DATA_EXPORT_RETENTION_DAYS`, 7 de serie; quien lo
 * quiera guardar mas tiempo lo saca del servidor.
 *
 * ## Purgar es borrar el fichero y marcar la fila. NUNCA borrarla
 *
 * La fila se queda para siempre con sus fechas, sus recuentos y su huella
 * (regla dura 5): «¿salio de aqui una copia completa de mis datos, y cuando?»
 * hay que poder contestarlo años despues.
 *
 * ## Concilia en los dos sentidos (ADR-045 §b, §d)
 *
 * El ZIP vive en el volumen `app-storage`, que no entra en la copia: una
 * restauracion devuelve filas `completed` sin ZIP y deja ZIP sin fila, y un
 * trabajo que muere a mitad deja su `.work-<uuid>/` con todos los datos en claro.
 * Por eso, en la misma pasada:
 *
 * - **Fila sin fichero → `purged`.** Si aun no habia caducado, es un evento de
 *   seguridad —un borrado a mano o un `mv`— y deja `data_export.file_missing`
 *   con el `uuid` y sin ruta, ademas de una metrica con alerta (C5).
 * - **Fila que apunta fuera de su raiz → `purged` sin borrar nada** (C3).
 * - **Fichero sin fila viva → se borra** al superar la edad minima de su clase:
 *   el plazo de retencion para un ZIP; 2 × `stale_after` para el espacio de
 *   trabajo y los temporales de `ZipArchive`. Log tecnico y metrica, sin asiento.
 *
 * ## Por que el asiento va bajo el candado de la cadena, y primero
 *
 * Marcar la fila y sellar el asiento van en la misma transaccion, y **el candado
 * de `audit_log` se toma antes que la fila** ({@see SerializedLedgerWrite}): es
 * el orden de todo lo que se audita, empezando por el fichaje. Al reves —la fila
 * primero y el asiento despues—, una descarga que se cruzara cerraria un abrazo
 * mortal con el camino del quiosco.
 *
 * ## La purga por caducidad no publica ningun evento
 *
 * Es el vencimiento de un plazo que ya consta en la propia fila (`expires_at`,
 * `purged_at`). Auditar una tarea horaria que casi siempre no hace nada llenaria
 * `audit_log` de ruido. Lo que si se audita es lo excepcional: el fichero que
 * falta antes de tiempo.
 */
final readonly class PurgeExpiredDataExportsHandler
{
    public function __construct(
        private DataExportRepository $exports,
        private GeneratedFileHousekeeping $files,
        private ProductEventPublisher $events,
        private Clock $clock,
        /** `GeneratedFileAreas::dataExportArchives()`: los ZIP. */
        private GeneratedFileArea $archives,
        /** `GeneratedFileAreas::dataExportWorkspaces()`: los `.work-<uuid>/`. */
        private GeneratedFileArea $workspaces,
        /** `GeneratedFileAreas::dataExportArchiveTemporaries()`: lo que deja `ZipArchive` al sellar. */
        private GeneratedFileArea $temporaries,
        /**
         * Segundos tras los cuales una exportacion sin terminar se declara
         * atascada. Ya resuelto por quien construye (regla dura 14).
         */
        private int $staleAfterSeconds,
        /** `PRODUCT_DATA_EXPORT_RETENTION_DAYS`: edad minima de un ZIP sin fila viva. */
        private int $retentionDays,
    ) {}

    public function handle(): DataExportMaintenance
    {
        $now = $this->clock->now();
        $staleAfter = max(1, $this->staleAfterSeconds);

        /*
         * PRIMERO DESATASCAR, y el orden importa dos veces. Una fila `running`
         * que nadie va a terminar bloquea la exportacion integra entera (el
         * indice unico parcial solo deja una `pending|running`), y ademas
         * protege su `.work-<uuid>/`: liberarla antes es lo que deja que el
         * barrido de abajo lo retire cuando supere su edad.
         */
        $released = $this->exports->failStale($now->modify('-'.$staleAfter.' seconds'), $now);

        // Sin la raiz de los ZIP no se concilia nada: una raiz ausente —un
        // `scheduler` sin el volumen— no dice nada de las filas (ADR-045).
        if (! $this->files->isAvailable($this->archives)) {
            return new DataExportMaintenance(0, $released);
        }

        $purged = $this->purgeExpired($now);

        $missing = $this->reconcileRows($now);

        $orphans = $this->sweepOrphans($now, $staleAfter);

        return new DataExportMaintenance($purged, $released, $orphans, $missing);
    }

    /**
     * La purga por caducidad. Un fichero que el sistema de ficheros no deja
     * borrar NO marca la fila: sigue `completed` con su ruta, la siguiente
     * pasada lo reintenta y `generated_files_remove_failed_total` lo dice
     * (ADR-045: si un fichero sobrevive a su plazo, se sabe).
     */
    private function purgeExpired(DateTimeImmutable $now): int
    {
        $purged = 0;

        foreach ($this->exports->expired($now) as $export) {
            $outcome = $export->filePath === null
                ? GeneratedFileRemoval::Absent
                : $this->files->discardRecorded($this->archives, $export->filePath);

            if ($outcome !== GeneratedFileRemoval::Failed && $this->exports->markPurged($export->id, $now)) {
                $purged++;
            }
        }

        return $purged;
    }

    /**
     * Fila → fichero: las `completed` vigentes cuyo ZIP ya no esta.
     *
     * @return int Las que desaparecieron antes de caducar (las que dejan asiento).
     */
    private function reconcileRows(DateTimeImmutable $now): int
    {
        $missing = 0;

        foreach ($this->exports->completedWithFile() as $export) {
            $location = $this->files->locateRecorded($this->archives, (string) $export->filePath);

            if ($location === RecordedFileLocation::Present) {
                continue;
            }

            if ($location === RecordedFileLocation::OutsideArea || $export->expired($now)) {
                // Fuera de su raiz: se marca y no se borra nada (C3). Caducada:
                // es la purga normal de una fila que se cruzo con esta pasada.
                $this->exports->markPurged($export->id, $now);

                continue;
            }

            if ($this->recordMissing($export, $now)) {
                $missing++;
            }
        }

        return $missing;
    }

    /**
     * Marca la fila y sella `data_export.file_missing` **solo si esta pasada la
     * marco**: la regla comun de `GeneratedFileHousekeeping::purgeMissing()`
     * —candado de la cadena primero, `UPDATE` condicional despues—. Otra pasada
     * que leyo la misma fila no deja un segundo asiento ni pisa `purged_at`.
     */
    private function recordMissing(DataExport $export, DateTimeImmutable $now): bool
    {
        return $this->files->purgeMissing(
            GeneratedFileClass::DataExport,
            fn (): bool => $this->exports->markPurged($export->id, $now),
            fn () => $this->events->publish(new DataExportFileMissing(
                uuid: $export->uuid,
                expiresAt: $export->expiresAt?->setTimezone(new \DateTimeZone('UTC'))->format(DateTimeInterface::RFC3339) ?? '',
                detectedAt: $now,
            )),
        );
    }

    /**
     * Fichero → fila: lo que hay en la raiz y ninguna fila viva nombra.
     *
     * La lista de filas vivas se lee **despues** de conciliar, para que un ZIP
     * cuya fila acaba de pasar a `purged` no siga protegido.
     */
    private function sweepOrphans(DateTimeImmutable $now, int $staleAfter): int
    {
        $live = array_map(
            static fn (DataExport $export): string => (string) $export->fileName,
            $this->exports->completedWithFile(),
        );
        $inProgress = $this->exports->inProgress()?->uuid;
        $archiveMinimum = max(1, $this->retentionDays) * 86400;
        $generationMinimum = 2 * $staleAfter;

        $removed = $this->files->sweepOrphans(
            $this->archives,
            static fn (GeneratedFileEntry $entry): ?int => \in_array($entry->name, $live, true) ? null : $archiveMinimum,
            $now,
        );

        $removed += $this->files->sweepOrphans(
            $this->workspaces,
            static fn (GeneratedFileEntry $entry): ?int => $inProgress !== null && $entry->name === '.work-'.$inProgress
                ? null
                : $generationMinimum,
            $now,
        );

        return $removed + $this->files->sweepOrphans(
            $this->temporaries,
            // Siempre huerfano: ninguna fila nombra un temporal de `ZipArchive`.
            // Solo la edad lo separa del sellado en curso.
            static fn (): int => $generationMinimum,
            $now,
        );
    }
}
