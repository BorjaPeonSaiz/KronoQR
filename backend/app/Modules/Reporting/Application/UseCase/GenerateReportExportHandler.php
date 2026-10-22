<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\UseCase;

use App\Modules\Reporting\Application\Generation\AdoptionReportExportGeneration;
use App\Modules\Reporting\Application\Generation\GeneratedReportFile;
use App\Modules\Reporting\Application\Generation\LegalReportExportGeneration;
use App\Modules\Reporting\Application\Generation\PeriodReportExportGeneration;
use App\Modules\Reporting\Application\Generation\ReportExportGeneration;
use App\Modules\Reporting\Application\Generation\ReportExportRun;
use App\Modules\Reporting\Application\Port\ReportExportNotifier;
use App\Modules\Reporting\Application\Port\ReportExportProgressBoard;
use App\Modules\Reporting\Application\Port\ReportExportRepository;
use App\Modules\Reporting\Application\Port\ReportExportStorage;
use App\Modules\Reporting\Application\Port\ReportingEventPublisher;
use App\Modules\Reporting\Application\Support\ReportExportProgressTracker;
use App\Modules\Reporting\Domain\Event\AdoptionReportExported;
use App\Modules\Reporting\Domain\Event\ReportExportGenerated;
use App\Modules\Reporting\Domain\Exception\ReportExportWasCancelled;
use App\Modules\Reporting\Domain\Exception\ReportExportWriteFailed;
use App\Modules\Reporting\Domain\Exception\ReportTooLargeForSynchronousDelivery;
use App\Modules\Reporting\Domain\Model\ReportExport;
use App\Modules\Reporting\Domain\ValueObject\ReportExportFailure;
use App\Modules\Reporting\Domain\ValueObject\ReportExportKind;
use App\Modules\Reporting\Domain\ValueObject\ReportExportStage;
use App\Modules\Reporting\Domain\ValueObject\ReportExportStatus;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Support\UseCaseInstrumentation;
use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Genera el fichero de un informe en diferido (**RF-IN-06**, **RF-IN-07**,
 * decision 4 de la ficha 3.9; **V5-PA-4** desde la 2.3.0).
 *
 * ## Cuatro clases de informe, un solo ciclo de vida
 *
 * Que se escribe lo decide una {@see ReportExportGeneration} por clase: el
 * informe por periodo y la nomina, la exportacion para la Inspeccion y el
 * cuadro de impacto. **El ciclo de vida es de aqui y es el mismo para las
 * cuatro**: arrancar la fila bajo bloqueo, borrar el fichero a medias si algo
 * sale mal, cerrar la fila y escribir los asientos en la misma transaccion,
 * avisar despues.
 *
 * ## Arranca bajo bloqueo
 *
 * `pending` → `running` se escribe sobre la fila **bloqueada y releida**, no
 * sobre la que se leyo al recoger el trabajo: entre una y otra alguien pudo
 * cancelarla, y un `save()` a ciegas la resucitaria como `running`. Si ya no
 * esta `pending` —cancelada, dada por muerta, o un reintento del trabajador—
 * no se hace nada.
 *
 * ## Se puede cancelar mientras corre (V5-PA-4)
 *
 * Cada fase pregunta a la fila si sigue `running`
 * ({@see ReportExportProgressTracker}), y la exportacion legal ademas cada 500
 * filas. Si no, la generacion lanza {@see ReportExportWasCancelled}, que aqui
 * **no es un fallo**: se borra el fichero a medias, se retira el avance y se
 * devuelve la fila tal como esta, sin marcarla ni publicar nada. Y si la
 * cancelacion llega entre la ultima comprobacion y el cierre, el cierre la ve
 * al releer la fila bloqueada.
 *
 * ## Un fallo deja la fila cerrada, con un CODIGO
 *
 * Uno de los cinco de {@see ReportExportFailure}, nunca el mensaje ni la clase
 * de la excepcion: un error de PostgreSQL puede llevar dentro el valor de una
 * fila (regla dura 21) y esta columna se serializa en la API. **Y el fichero a
 * medias se borra**: un CSV con media plantilla dentro, invisible para la purga
 * —que solo mira filas `completed`—, es una copia de horas nominales abandonada
 * en el disco del cliente.
 *
 * ## Los asientos van con el cierre, a nombre de quien lo pidio
 *
 * `report_export.generated` y lo que acompaña a cada clase
 * —`personal_data.accessed` del informe, `legal_export.generated` de la
 * exportacion legal, `adoption_report.exported` del cuadro— se escriben en la
 * transaccion que cierra la fila (regla dura 6, ADR-027), con el solicitante
 * como actor y no `system` (bloque 11, V4-BE-5).
 *
 * ## El aviso va DESPUES de cerrar la fila, y fuera de su transaccion
 *
 * Porque un SMTP lento o caido no puede impedir que un informe generado conste
 * como generado (regla dura 12, decision 8).
 */
final readonly class GenerateReportExportHandler
{
    /** El `SQLSTATE` con el que PostgreSQL corta por `statement_timeout`. */
    private const string QUERY_CANCELED = '57014';

    public function __construct(
        private ReportExportRepository $exports,
        private PeriodReportExportGeneration $periodReports,
        private LegalReportExportGeneration $legalExports,
        private AdoptionReportExportGeneration $adoptionReports,
        private ReportExportStorage $storage,
        private ReportExportNotifier $notifier,
        private ReportExportProgressBoard $progress,
        private ReportingEventPublisher $events,
        private Clock $clock,
        private ConnectionInterface $connection,
        /** Dias que vive el fichero antes de purgarse (regla dura 14: ya resuelto). */
        private int $retentionDays,
    ) {}

    public function handle(string $uuid): ReportExport
    {
        return UseCaseInstrumentation::run('reporting.generate_report_export', fn (): ReportExport => $this->doHandle($uuid));
    }

    private function doHandle(string $uuid): ReportExport
    {
        $started = $this->startIfPending($uuid);

        if (! $started instanceof ReportExport) {
            return $this->exports->findByUuid($uuid)
                ?? throw new RuntimeException('No existe el informe en diferido solicitado: '.$uuid);
        }

        $run = new ReportExportRun(
            $started,
            new ReportExportProgressTracker($uuid, $this->exports, $this->progress, $this->clock),
        );

        try {
            $generated = $this->generationFor($started->kind)->generate($run);

            $run->progress->enter(ReportExportStage::Sealing);

            $completed = $this->completeAndPublish($uuid, $generated);
        } catch (ReportExportWasCancelled) {
            $this->discard($run);

            // Hasta aqui la cancelada seguia ocupando el turno de su dueño: ya no
            // queda nadie escribiendo, y puede pedir otra.
            $this->exports->confirmStopped($uuid, $this->clock->now());

            return $this->exports->findByUuid($uuid) ?? $started;
        } catch (Throwable $failure) {
            $this->discard($run);
            $this->failIfStillInProgress($uuid, $failure);

            throw $failure;
        }

        $run->progress->finish();

        /*
         * **EL AVISO VA FUERA DEL `try`, Y ESO NO ES ESTILO.** Dentro, un fallo
         * del aviso borraria un fichero ya generado y anunciado en `audit_log`.
         * Aqui, si el aviso revienta, la fila sigue `completed` y el fichero
         * descargable; solo falta el sello de `notified_at`.
         */
        return $this->notify($completed);
    }

    /**
     * `pending` → `running` sobre la fila bloqueada, o nada.
     *
     * Devuelve la fila arrancada, o nulo si ya no estaba `pending`: cancelada,
     * dada por muerta por la obsolescencia, ya terminada o —con un reintento
     * del trabajador— ya `running` en otro proceso. En ningun caso se rehace
     * nada: repetir la generacion cruzaria otra vez la plantilla sin producir
     * nada nuevo, y sobre `failed` ocultaria el fallo que alguien tiene que
     * mirar.
     */
    private function startIfPending(string $uuid): ?ReportExport
    {
        /** @var ReportExport|null $started */
        $started = $this->connection->transaction(function () use ($uuid): ?ReportExport {
            $locked = $this->exports->lockByUuid($uuid)
                ?? throw new RuntimeException('No existe el informe en diferido solicitado: '.$uuid);

            if ($locked->status !== ReportExportStatus::Pending) {
                return null;
            }

            $started = $locked->start($this->clock->now());
            $this->exports->save($started);

            return $started;
        });

        return $started;
    }

    private function generationFor(ReportExportKind $kind): ReportExportGeneration
    {
        return match ($kind) {
            ReportExportKind::Period, ReportExportKind::Payroll => $this->periodReports,
            ReportExportKind::Legal => $this->legalExports,
            ReportExportKind::Adoption => $this->adoptionReports,
        };
    }

    /**
     * Borra el fichero a medias, si llego a haberlo, y retira el avance.
     *
     * **Antes** de marcar la fila: si el fallo fue al cerrarla, el fichero
     * existe y nadie va a poder descargarlo nunca.
     */
    private function discard(ReportExportRun $run): void
    {
        $path = $run->claimedPath();

        if ($path !== null) {
            $this->storage->delete($path);
        }

        $run->progress->finish();
    }

    /**
     * Cierra la fila **sobre una relectura bloqueada**, no sobre la instancia que
     * se leyo al empezar.
     *
     * Entre el arranque y el cierre pasan minutos: en ese hueco la obsolescencia
     * pudo dar el trabajo por muerto, o quien lo pidio pudo cancelarlo. Con
     * `lockByUuid()` dentro de la transaccion, una fila cancelada se ve aqui y
     * se trata como tal, y cualquier otro estado hace que `complete()` lance —y
     * esa excepcion borra el fichero recien escrito—.
     */
    private function completeAndPublish(string $uuid, GeneratedReportFile $generated): ReportExport
    {
        /** @var ReportExport $completed */
        $completed = $this->connection->transaction(
            function () use ($uuid, $generated): ReportExport {
                $locked = $this->exports->lockByUuid($uuid)
                    ?? throw new RuntimeException('El informe en diferido desaparecio al cerrarlo: '.$uuid);

                if ($locked->wasCancelled()) {
                    throw ReportExportWasCancelled::during($uuid);
                }

                $completed = $locked->complete(
                    completedAt: $this->clock->now(),
                    filePath: $generated->path,
                    fileName: $generated->fileName,
                    sizeBytes: $this->storage->sizeOf($generated->path),
                    // La huella se calcula sobre el FICHERO ya cerrado y leido por
                    // bloques, no sobre lo que el escritor creia estar escribiendo:
                    // es la unica que sirve para comprobar que la descarga llego
                    // entera.
                    sha256: $this->storage->digestOf($generated->path),
                    rowCount: $generated->rowCount,
                    criteria: $generated->criteria,
                    expiresAt: $this->clock->now()->modify('+'.max(1, $this->retentionDays).' days'),
                );

                $this->publishGeneration($completed, $generated);

                return $completed;
            }
        );

        return $completed;
    }

    /**
     * Marca fallida la fila **solo si al releerla sigue en curso**.
     *
     * Sin la relectura, un fallo tardio escribiria `failed` sobre una fila que
     * otro camino ya cerro, cancelo o purgo.
     */
    private function failIfStillInProgress(string $uuid, Throwable $failure): void
    {
        $this->connection->transaction(function () use ($uuid, $failure): void {
            $locked = $this->exports->lockByUuid($uuid);

            if ($locked === null || ! $locked->isInProgress()) {
                return;
            }

            $this->exports->save($locked->fail($this->clock->now(), self::failureOf($failure)));
        });
    }

    /**
     * Guarda la fila cerrada y publica los hechos, **sin transaccion propia**:
     * corre dentro de la de {@see self::completeAndPublish()}, que sostiene el
     * bloqueo. Los asientos son sincronos y tienen que poder impedir el cierre
     * (regla dura 6, ADR-027).
     */
    private function publishGeneration(ReportExport $completed, GeneratedReportFile $generated): void
    {
        $this->exports->save($completed);

        $occurredAt = $completed->completedAt ?? $this->clock->now();

        $this->events->publish(new ReportExportGenerated(
            uuid: $completed->uuid,
            kind: $completed->kind->value,
            format: $completed->format,
            // El NOMBRE, nunca la ruta absoluta (regla dura 21 y decision 7).
            fileName: $completed->fileName ?? '',
            sha256: $completed->sha256 ?? '',
            sizeBytes: $completed->sizeBytes ?? 0,
            rowCount: $completed->rowCount ?? 0,
            expiresAt: $completed->expiresAt?->format(DateTimeInterface::RFC3339) ?? '',
            employeeUuids: $generated->employeeUuids,
            requestedByUserId: $completed->requestedByUserId,
            disclosureDataset: $generated->disclosureDataset,
            disclosureRecordCount: $generated->disclosureRecordCount,
            disclosureContext: $generated->disclosureContext,
            occurredAt: $occurredAt,
            legalExport: $generated->legalExport,
        ));

        if ($generated->adoptionPeriod !== null) {
            // El mismo asiento que la descarga sincrona del cuadro: la huella del
            // CONTENIDO —la del pie del PDF, igual en los tres formatos— y el
            // tamaño del fichero que de verdad se genero. La huella del fichero
            // ya va en `report_export.generated`.
            $this->events->publish(new AdoptionReportExported(
                from: $generated->adoptionPeriod['from'],
                to: $generated->adoptionPeriod['to'],
                format: $completed->format,
                sha256: $generated->adoptionPeriod['content_digest'],
                sizeBytes: $completed->sizeBytes ?? 0,
                exportedByUserId: $completed->requestedByUserId,
                occurredAt: $occurredAt,
            ));
        }
    }

    /**
     * Avisa y deja escrito por donde, **fuera de cualquier transaccion y
     * despues de cerrar la fila**: el correo sale por red a un servidor que no
     * controla este producto.
     */
    private function notify(ReportExport $completed): ReportExport
    {
        $now = $this->clock->now();
        $channel = $this->notifier->notify($completed);

        /*
         * `recordNotification()` y no `save()`: tres columnas en lugar de veinte.
         * Entre el cierre y el correo pueden haber pasado cosas —alguien pidio el
         * enlace, se lo descargo— y un `save()` con la instancia de antes del
         * correo las desharia todas.
         */
        $this->exports->recordNotification($completed->id, $now, $channel);

        return $completed->markNotified($now, $channel);
    }

    /**
     * De que fallo se trata, en el vocabulario que lee quien pidio el informe.
     *
     * Se clasifica **aqui y no en el dominio** porque distinguir un error de base
     * de datos exige nombrar los tipos del driver (regla dura 1). El orden
     * importa: el `statement_timeout` es un periodo demasiado grande, no una
     * averia; despues el disco, despues el driver, y lo demas es `unexpected`.
     */
    private static function failureOf(Throwable $failure): ReportExportFailure
    {
        return match (true) {
            $failure instanceof ReportTooLargeForSynchronousDelivery => ReportExportFailure::QueryTimeout,
            // `query_canceled` por `statement_timeout`: la exportacion legal en cola
            // lleva el techo del diferido y su origen no lo traduce (V5-PA-4).
            $failure instanceof QueryException
                && ($failure->errorInfo[0] ?? null) === self::QUERY_CANCELED => ReportExportFailure::QueryTimeout,
            $failure instanceof ReportExportWriteFailed => ReportExportFailure::WriteFailed,
            $failure instanceof QueryException,
            $failure instanceof PDOException => ReportExportFailure::DatabaseError,
            default => ReportExportFailure::Unexpected,
        };
    }
}
