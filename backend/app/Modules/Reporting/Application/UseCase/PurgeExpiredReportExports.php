<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\UseCase;

use App\Modules\Reporting\Application\Port\ReportExportRepository;
use App\Modules\Reporting\Application\Port\ReportingEventPublisher;
use App\Modules\Reporting\Domain\Event\ReportExportFileMissing;
use App\Modules\Reporting\Domain\Model\ReportExport;
use App\Modules\Reporting\Domain\ValueObject\ReportExportMaintenance;
use App\Modules\Reporting\Domain\ValueObject\ReportExportStatus;
use App\Modules\Shared\Application\GeneratedFiles\GeneratedFileHousekeeping;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\SerializedLedgerWrite;
use App\Modules\Shared\Domain\ValueObject\GeneratedFileArea;
use App\Modules\Shared\Domain\ValueObject\GeneratedFileClass;
use App\Modules\Shared\Domain\ValueObject\GeneratedFileEntry;
use App\Modules\Shared\Domain\ValueObject\RecordedFileLocation;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * El mantenimiento diario de los informes en diferido: **desatasca, purga y
 * concilia** (**RF-IN-06**, RL-15, regla dura 5, decision 3 de la ficha 3.9;
 * ADR-045).
 *
 * ## Por que el fichero caduca
 *
 * Porque contiene **las horas trabajadas de personas identificadas**, en el
 * disco del cliente, y su retencion no puede depender de que alguien se acuerde
 * de borrarlo. `REPORTING_EXPORT_RETENTION_DAYS`, 7 de serie.
 *
 * ## Purgar es borrar el fichero y marcar la fila. NUNCA borrarla
 *
 * La fila se queda para siempre con sus fechas, su huella y su recuento (regla
 * dura 5), minimizada (RL-11). En la lista aparece como `purged`.
 *
 * ## Primero desatascar y despues purgar, y el orden importa
 *
 * Una fila `running` que nadie va a terminar bloquea a **esa persona** (indice
 * unico parcial por cuenta) y ademas protege su directorio del barrido de
 * huerfanos. Liberarla primero es lo que deja que su directorio a medias se
 * retire cuando supere su edad.
 *
 * ## La conciliacion es la de la exportacion integra (ADR-045 §b, §d)
 *
 * - **Fila `completed` sin fichero → `purged`.** Si aun no habia caducado deja
 *   `report_export.file_missing` con el `uuid` y sin ruta, y sube una metrica
 *   con alerta: es un borrado a mano o una exfiltracion (C5).
 * - **Fila que apunta fuera de su raiz → `purged` sin borrar nada** (C3).
 * - **Directorio `<uuid>/` sin fila viva → se borra entero** al superar su edad
 *   minima: el plazo de retencion, o 2 × `stale_after` si su fila no llego a
 *   `completed`. Antes se borraba en cuanto ninguna fila `completed` lo
 *   nombraba, sin edad minima: un trabajo que estuviera escribiendo en ese
 *   momento veia su fichero borrado.
 *
 * ## Diaria y no horaria
 *
 * Al contrario que la de la exportacion integra. Alli el bloqueo es de la
 * instalacion entera y aqui de una sola persona, que tiene el barrido de
 * `RequestReportExport` a un clic de distancia.
 *
 * ## La purga por caducidad no publica ningun evento
 *
 * Es el vencimiento de un plazo que ya consta en la fila. Lo que si se audita es
 * lo excepcional: el fichero que falta antes de tiempo, con el candado de la
 * cadena tomado **antes** que la fila ({@see SerializedLedgerWrite}).
 */
final readonly class PurgeExpiredReportExports
{
    public function __construct(
        private ReportExportRepository $exports,
        private GeneratedFileHousekeeping $files,
        private ReportingEventPublisher $events,
        private SerializedLedgerWrite $serialized,
        private Clock $clock,
        /** `GeneratedFileAreas::reportExports()`: los directorios `<uuid>/`. */
        private GeneratedFileArea $area,
        /**
         * Segundos tras los cuales un informe sin terminar se declara atascado.
         * Ya resuelto por quien construye (regla dura 14).
         */
        private int $staleAfterSeconds,
        /** `REPORTING_EXPORT_RETENTION_DAYS`: edad minima de un directorio que llego a `completed`. */
        private int $retentionDays,
    ) {}

    public function handle(): ReportExportMaintenance
    {
        $now = $this->clock->now();
        $staleAfter = max(1, $this->staleAfterSeconds);

        $released = $this->exports->failStale($now->modify('-'.$staleAfter.' seconds'), $now);

        $purged = 0;

        foreach ($this->exports->expired($now) as $export) {
            if ($export->filePath !== null) {
                $this->files->discardRecorded($this->area, $export->filePath);
            }

            $this->exports->save($export->purge($now));
            $purged++;
        }

        $missing = $this->reconcileRows($now);

        return new ReportExportMaintenance($purged, $released, $this->sweepOrphans($now, $staleAfter), $missing);
    }

    /**
     * Fila → fichero: las `completed` vigentes cuyo fichero ya no esta.
     *
     * @return int Las que desaparecieron antes de caducar (las que dejan asiento).
     */
    private function reconcileRows(DateTimeImmutable $now): int
    {
        $missing = 0;

        foreach ($this->exports->completedWithFile() as $export) {
            $location = $this->files->locateRecorded($this->area, (string) $export->filePath);

            if ($location === RecordedFileLocation::Present) {
                continue;
            }

            if ($location === RecordedFileLocation::OutsideArea || $export->expired($now)) {
                // Fuera de su raiz: se marca y no se borra nada (C3). Caducada:
                // la purga normal de una fila que se cruzo con esta pasada.
                $this->exports->save($export->purge($now));

                continue;
            }

            if ($this->recordMissing($export->uuid, $now)) {
                $missing++;
            }
        }

        return $missing;
    }

    /**
     * Marca la fila y sella el asiento en la misma transaccion, con el candado
     * de la cadena tomado primero y la fila releida bloqueada despues: una
     * descarga que se cruzara no pierde su `download_count` por un `save()` con
     * la instancia de antes.
     */
    private function recordMissing(string $uuid, DateTimeImmutable $now): bool
    {
        $recorded = $this->serialized->withChainLock(function () use ($uuid, $now): bool {
            $fresh = $this->exports->lockByUuid($uuid);

            if (! $fresh instanceof ReportExport || $fresh->status !== ReportExportStatus::Completed || $fresh->purgedAt !== null) {
                return false;
            }

            $this->exports->save($fresh->purge($now));

            $this->events->publish(new ReportExportFileMissing(
                uuid: $fresh->uuid,
                expiresAt: $fresh->expiresAt?->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::RFC3339) ?? '',
                detectedAt: $now,
            ));

            return true;
        });

        if ($recorded) {
            $this->files->fileMissing(GeneratedFileClass::ReportExport);
        }

        return $recorded;
    }

    /**
     * Fichero → fila: los `<uuid>/` que ninguna fila viva nombra.
     *
     * Viva es `pending`/`running`, o `completed` con su `expires_at` por llegar.
     * Lo demas es huerfano y se borra al superar su edad minima, que depende de
     * hasta donde llego su fila: el plazo de retencion si llego a `completed` o
     * no tiene fila —tras una restauracion puede ser un informe terminado—, y
     * 2 × `stale_after` si se quedo a medias.
     */
    private function sweepOrphans(DateTimeImmutable $now, int $staleAfter): int
    {
        $retentionMinimum = max(1, $this->retentionDays) * 86400;
        $generationMinimum = 2 * $staleAfter;

        return $this->files->sweepOrphans(
            $this->area,
            function (GeneratedFileEntry $entry) use ($now, $retentionMinimum, $generationMinimum): ?int {
                $export = $this->exports->findByUuid($entry->name);

                if ($export === null) {
                    return $retentionMinimum;
                }

                if ($export->isInProgress() || ($export->isDownloadable() && ! $export->expired($now))) {
                    return null;
                }

                return $export->completedAt === null ? $generationMinimum : $retentionMinimum;
            },
            $now,
        );
    }
}
