<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Product\Application\Port\DataExportArchiveWriter;
use App\Modules\Product\Application\Port\DataExportGuide;
use App\Modules\Product\Application\Port\DataExportRepository;
use App\Modules\Product\Application\Port\DataExportSource;
use App\Modules\Product\Application\Port\ProductEventPublisher;
use App\Modules\Product\Domain\Event\DataExportGenerated;
use App\Modules\Product\Domain\Exception\DataExportWriteFailed;
use App\Modules\Product\Domain\Model\DataExport;
use App\Modules\Product\Domain\ValueObject\DataExportArchive;
use App\Modules\Product\Domain\ValueObject\DataExportCatalog;
use App\Modules\Product\Domain\ValueObject\DataExportFailure;
use App\Modules\Product\Domain\ValueObject\DataExportFile;
use App\Modules\Product\Domain\ValueObject\DataExportManifest;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\LocalePolicyProvider;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Escribe el ZIP con **todos** los datos de la instalacion (**RF-PD-14**,
 * RL-20, decisiones 1 a 3 de la ficha 5.10).
 *
 * ## Streaming de verdad, no «que no reviente»
 *
 * Cada conjunto se recorre con un **cursor de servidor** dentro de una unica
 * transaccion y se escribe fila a fila en disco; el ZIP se cierra al final sobre
 * ficheros ya escritos.
 *
 * La prueba de integracion con volumen mide el **incremento** de
 * `memory_get_peak_usage(true)` durante la generacion y exige que quede por
 * debajo de un techo fijo con 500 empleados y 90 dias. Con el cursor, cuatro años
 * cuestan lo mismo en memoria que noventa dias.
 *
 * ## La instantanea es una sola, y la da `REPEATABLE READ`
 *
 * Los dieciocho conjuntos se leen dentro de **una** transaccion, y esa
 * transaccion la abre {@see DataExportSource::within()} **elevando el nivel de
 * aislamiento a `REPEATABLE READ`**. Las dos mitades hacen falta y la segunda no
 * es opcional: en `READ COMMITTED` —el nivel por omision de PostgreSQL y el que
 * usa este producto— cada `DECLARE ... CURSOR` toma **su propia** instantanea al
 * declararse, de modo que un fichaje ocurrido mientras se escribe el ZIP puede
 * aparecer en `scan_events.csv` con un `shift_entry_uuid` que no esta en
 * `shift_entries.csv`, o cuadrar en `daily_totals.csv` y no en los tramos.
 *
 * Eso no es una molestia teorica: es una exportacion **internamente
 * incoherente** entregada como copia de respaldo de un registro con valor legal,
 * y el hotel exporta a las 06:00 igual que a las 22:00. Con `REPEATABLE READ`
 * todos los cursores ven el mismo instante, que es lo que el manifiesto afirma.
 *
 * No `SERIALIZABLE`: esto solo lee, no hay nada que serializar frente a nadie, y
 * ese nivel añadiria fallos `40001` que obligarian a reintentar una exportacion
 * de gigabytes.
 *
 * ## El orden de los tres ficheros que no son datos
 *
 * `README.md` se escribe antes que `manifest.json` y los dos al final: el
 * manifiesto lleva el recuento y la huella de **cada** fichero, incluido el
 * README, asi que no se puede componer antes de que existan todos.
 *
 * El README no entra en `total_rows` —tiene cero filas de datos— para que el
 * recuento del manifiesto siga siendo comparable con un `SELECT count(*)`.
 *
 * ## Idempotente si el trabajador reintenta
 *
 * Si la fila ya esta `completed`, devuelve y **no rehace nada**: un reintento
 * automatico de una exportacion terminada seria un segundo recorrido completo de
 * la base de datos por la que pasa cada fichaje, sin producir nada nuevo. Si
 * esta `failed` o `purged`, tampoco: son estados finales y rehacerlos en
 * silencio ocultaria el fallo que alguien tiene que mirar.
 *
 * ## Un fallo deja la fila en `failed` con un CODIGO, no con una clase de PHP
 *
 * Uno de los cuatro de {@see DataExportFailure}. Ni el mensaje —un error de
 * PostgreSQL puede llevar dentro el valor de una fila (regla dura 21)— ni el
 * nombre de la clase, que no le dice nada a quien lee el panel y cambiaria con
 * cualquier refactor. La clase real la registra quien invoca, junto al `uuid`.
 *
 * **Nada queda a medias.** El directorio de trabajo se borra pase lo que pase
 * —para que un fallo a mitad no deje un `employees.csv` suelto con la plantilla
 * entera— y, si el ZIP ya estaba sellado cuando fallo el cierre, tambien se
 * borra: un fichero que nadie va a poder descargar nunca y que la purga no mira
 * es una copia de la plantilla abandonada en el disco del cliente.
 *
 * ## No consulta la licencia (regla dura 15, ADR-019)
 */
final readonly class GenerateDataExportHandler
{
    public function __construct(
        private DataExportRepository $exports,
        private DataExportSource $source,
        private DataExportArchiveWriter $writer,
        private DataExportGuide $guide,
        private ProductEventPublisher $events,
        private Clock $clock,
        private LocalePolicyProvider $locales,
        private ConnectionInterface $connection,
        private string $productVersion,
        /** Dias que vive el fichero antes de purgarse (regla dura 14: ya resuelto). */
        private int $retentionDays,
    ) {}

    public function handle(string $uuid): DataExport
    {
        $export = $this->exports->findByUuid($uuid)
            ?? throw new RuntimeException('No existe la exportacion integra solicitada: '.$uuid);

        if (! $export->isInProgress()) {
            return $export;
        }

        $this->exports->markRunning($export->id, $this->clock->now());

        /*
         * **`begin()` va DENTRO del `try`**, y esto no es estilo.
         *
         * Es la primera operacion que toca el disco y por tanto la que falla
         * cuando el directorio de exportaciones no se puede escribir —el fallo
         * mas probable de esta tarea en produccion—. Fuera del `try`, esa
         * excepcion dejaria la fila en `running` **para siempre**: ocupando el
         * indice unico parcial, y por tanto impidiendo que la instalacion vuelva
         * a exportar nunca hasta que alguien entre por `psql`. Justo lo contrario
         * de lo que RL-20 promete.
         */
        $workspace = null;
        $archive = null;

        try {
            $workspace = $this->writer->begin($export->uuid);

            $written = $this->write($export, $workspace);
            $archive = $written['archive'];

            /*
             * **`complete()` va DENTRO del mismo `try`**, y por el mismo motivo
             * que `begin()`.
             *
             * Ahi se escribe el asiento `data_export.generated`, que toma el
             * candado global de `audit_log` (ADR-010): un interbloqueo, un
             * reintento agotado o la propia base de datos caida hacen fallar esa
             * transaccion **con el ZIP ya sellado en el disco**. Fuera del `try`
             * eso dejaba la fila en `running` para siempre y, peor, el fichero
             * huerfano: con todos los datos personales de la plantilla dentro,
             * invisible para la purga —que solo mira filas `completed`— y sin
             * nadie que supiera de donde salio.
             */
            return $this->complete($export, $archive, $written['manifest']);
        } catch (Throwable $failure) {
            /*
             * El ZIP se borra ANTES de marcar la fila. Si el fallo fue al
             * cerrarla, el fichero existe y nadie va a poder descargarlo nunca:
             * dejarlo seria abandonar una copia completa de la plantilla en el
             * disco del cliente.
             */
            if ($archive !== null) {
                $this->writer->delete($archive->path);
            }

            // Un codigo del catalogo, nunca la clase ni el mensaje. La clase
            // real la registra quien invoca —el trabajo o el comando— junto al
            // `uuid` (regla dura 21).
            $this->exports->markFailed($export->id, $this->clock->now(), self::failureOf($failure));

            throw $failure;
        } finally {
            if ($workspace !== null) {
                $this->writer->discard($workspace);
            }
        }
    }

    /**
     * De que fallo se trata, en el vocabulario que lee el cliente.
     *
     * Se clasifica **aqui y no en el dominio** porque distinguir un error de base
     * de datos exige nombrar los tipos del driver, y `Domain/` no puede (regla
     * dura 1). La capa de aplicacion si.
     *
     * El orden importa: {@see DataExportWriteFailed} primero, porque es la unica
     * que el producto lanza a proposito y la causa mas frecuente con diferencia
     * —disco lleno o permisos—; despues el driver; y lo que no encaje en ninguna
     * es `unexpected`, que es la unica que justifica abrir incidencia.
     */
    private static function failureOf(Throwable $failure): DataExportFailure
    {
        return match (true) {
            $failure instanceof DataExportWriteFailed => DataExportFailure::WriteFailed,
            $failure instanceof QueryException,
            $failure instanceof PDOException => DataExportFailure::DatabaseError,
            default => DataExportFailure::Unexpected,
        };
    }

    /**
     * Recorre los conjuntos, escribe los documentos y cierra el ZIP.
     *
     * @return array{archive: DataExportArchive, manifest: DataExportManifest}
     */
    private function write(DataExport $export, string $workspace): array
    {
        $locale = $this->locales->current()->default;

        /** @var array{files: list<DataExportFile>, timezone: string} $written */
        $written = $this->source->within(function () use ($workspace, $locale): array {
            $files = [];

            foreach (DataExportCatalog::datasets() as $dataset) {
                $files[] = $this->writer->writeDataset(
                    $workspace,
                    $dataset,
                    $this->source->rows($dataset),
                    $locale,
                );
            }

            return ['files' => $files, 'timezone' => $this->source->siteTimezone()];
        });

        $generatedAt = $this->clock->now();

        // El manifiesto se compone dos veces: la primera para que el README
        // pueda hablar de los ficheros que hay, y la segunda —abajo— ya con el
        // propio README dentro. Componerlo una sola vez obligaria a elegir entre
        // un README que no sabe que hay en el ZIP o un manifiesto que no cuenta
        // el README.
        $partial = $this->manifestOf($export, $generatedAt, $written['timezone'], $written['files']);

        $readme = $this->writer->writeDocument(
            $workspace,
            'README.md',
            $this->guide->render($partial, $locale),
        );

        $manifest = $this->manifestOf(
            $export,
            $generatedAt,
            $written['timezone'],
            [...$written['files'], $readme],
        );

        $this->writer->writeDocument(
            $workspace,
            'manifest.json',
            json_encode($manifest->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );

        return [
            'archive' => $this->writer->seal($workspace, $manifest->fileName()),
            'manifest' => $manifest,
        ];
    }

    /**
     * Marca la fila y publica el asiento, **en la misma transaccion**.
     *
     * Si el asiento no se puede escribir, la exportacion no queda como
     * `completed` y por tanto no se puede descargar: un ZIP con todos los datos
     * personales de la plantilla no se entrega sin dejar rastro (regla dura 6,
     * RS-05).
     */
    private function complete(DataExport $export, DataExportArchive $archive, DataExportManifest $manifest): DataExport
    {
        $completedAt = $this->clock->now();
        $expiresAt = $completedAt->modify('+'.max(1, $this->retentionDays).' days');

        $this->connection->transaction(function () use ($export, $archive, $manifest, $completedAt, $expiresAt): void {
            $this->exports->markCompleted(
                id: $export->id,
                completedAt: $completedAt,
                filePath: $archive->path,
                fileName: $archive->fileName,
                sizeBytes: $archive->sizeBytes,
                sha256: $archive->sha256,
                rowCounts: $manifest->rowCounts(),
                expiresAt: $expiresAt,
            );

            $this->events->publish(new DataExportGenerated(
                uuid: $export->uuid,
                requestedVia: $export->requestedVia,
                // Quien la PIDIO, aunque este metodo corra en la cola sin sesion
                // (decision 7 de la ficha 5.10).
                requestedByUserId: $export->requestedByUserId,
                fileName: $archive->fileName,
                sha256: $archive->sha256,
                sizeBytes: $archive->sizeBytes,
                rowCounts: $manifest->rowCounts(),
                occurredAt: $completedAt,
            ));
        });

        return $this->exports->findByUuid($export->uuid) ?? $export;
    }

    /**
     * @param  list<DataExportFile>  $files
     */
    private function manifestOf(
        DataExport $export,
        DateTimeImmutable $generatedAt,
        string $timezone,
        array $files,
    ): DataExportManifest {
        return new DataExportManifest(
            productVersion: $this->productVersion,
            generatedAt: $generatedAt,
            siteTimezone: $timezone,
            requestedVia: $export->requestedVia,
            requestedByUuid: $export->requestedByUuid,
            requestedByName: $export->requestedByName,
            files: $files,
            notInstalled: DataExportCatalog::notInstalled(),
        );
    }
}
