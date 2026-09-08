<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Persistence;

use App\Modules\Product\Application\Port\DataExportRepository;
use App\Modules\Product\Domain\Exception\DataExportAlreadyInProgress;
use App\Modules\Product\Domain\Model\DataExport;
use App\Modules\Product\Domain\ValueObject\DataExportFailure;
use App\Modules\Product\Domain\ValueObject\DataExportOrigin;
use App\Modules\Product\Domain\ValueObject\DataExportStatus;
use App\Modules\Shared\Infrastructure\Persistence\Row;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * La tabla `data_exports` (**RF-PD-14**, RL-20).
 *
 * ## La exclusion mutua la resuelve el indice, no un `SELECT`
 *
 * `create()` inserta y **traduce** el choque contra
 * `data_exports_single_in_progress_uidx` en
 * {@see DataExportAlreadyInProgress}. Comprobar antes con una consulta dejaria
 * pasar las dos pulsaciones de dos pestañas abiertas a la vez, que es
 * exactamente el caso que la restriccion existe para cerrar.
 *
 * Laravel 11+ levanta `UniqueConstraintViolationException` para el `23505` de
 * PostgreSQL, asi que no hace falta mirar el `SQLSTATE` a mano. Se comprueba
 * ademas el **nombre del indice**: en esta tabla hay otro unico —el de `uuid`— y
 * confundirlos convertiria «he generado dos veces el mismo UUID» en «ya hay una
 * exportacion en curso», que es un mensaje falso.
 *
 * ## La lectura NO es tolerante
 *
 * Al contrario que la licencia, que degrada a `null` ante cualquier fallo porque
 * esta en el camino de todas las pantallas (ADR-019). Aqui una lectura que
 * fallara en silencio seria una copia completa de los datos del cliente que no
 * aparece en la lista, y esa lista es la mitad visible de RL-20. Si algo va mal,
 * tiene que verse.
 *
 * ## El `LEFT JOIN` con `users` es opcional a proposito
 *
 * El contrato admite `requested_by: null` —lo que devuelve una exportacion
 * pedida por consola— y la clave ajena es `nullOnDelete`, asi que una cuenta
 * borrada tampoco puede hacer desaparecer la fila. Un `INNER JOIN` perderia esas
 * dos.
 */
final readonly class DatabaseDataExportRepository implements DataExportRepository
{
    private const string IN_PROGRESS_INDEX = 'data_exports_single_in_progress_uidx';

    private const string SELECT = <<<'SQL'
        SELECT e.id,
               e.uuid::text          AS uuid,
               e.status,
               e.requested_via,
               e.requested_by_user_id,
               u.uuid::text          AS requested_by_uuid,
               u.name                AS requested_by_name,
               e.requested_at,
               e.started_at,
               e.completed_at,
               e.failed_at,
               e.failure_reason,
               e.file_path,
               e.file_name,
               e.size_bytes,
               e.sha256,
               e.row_counts::text    AS row_counts,
               e.expires_at,
               e.purged_at,
               e.downloaded_at,
               e.download_count
          FROM data_exports e
          LEFT JOIN users u ON u.id = e.requested_by_user_id
        SQL;

    public function __construct(private ConnectionInterface $connection) {}

    public function create(
        string $uuid,
        DataExportOrigin $requestedVia,
        ?int $requestedByUserId,
        DateTimeImmutable $requestedAt,
    ): DataExport {
        try {
            $this->connection->table('data_exports')->insert([
                'uuid' => $uuid,
                'requested_by_user_id' => $requestedByUserId,
                'requested_via' => $requestedVia->value,
                'status' => DataExportStatus::Pending->value,
                'requested_at' => self::utc($requestedAt),
                'row_counts' => '{}',
                'download_count' => 0,
                'created_at' => self::utc($requestedAt),
                'updated_at' => self::utc($requestedAt),
            ]);
        } catch (UniqueConstraintViolationException $collision) {
            if (! str_contains($collision->getMessage(), self::IN_PROGRESS_INDEX)) {
                throw $collision;
            }

            /*
             * **Sin consultar cual es la que ocupa el turno**, aunque el `409`
             * la necesite. PostgreSQL deja la transaccion en curso ABORTADA tras
             * el choque: cualquier `SELECT` aqui fallaria con `25P02` y el
             * cliente recibiria un `500` en lugar del `409` que le dice que
             * espere. La relectura la hace `RequestDataExportHandler` cuando la
             * transaccion ya se ha deshecho.
             */
            throw new DataExportAlreadyInProgress(null);
        }

        return $this->findByUuid($uuid)
            ?? throw new \RuntimeException('La exportacion integra recien creada no se puede releer: '.$uuid);
    }

    public function findByUuid(string $uuid): ?DataExport
    {
        $rows = $this->connection->select(self::SELECT.' WHERE e.uuid = CAST(? AS uuid)', [$uuid]);

        return $rows === [] ? null : $this->hydrate(Row::of($rows[0]));
    }

    public function inProgress(): ?DataExport
    {
        $rows = $this->connection->select(
            self::SELECT." WHERE e.status IN ('pending', 'running') ORDER BY e.id DESC LIMIT 1"
        );

        return $rows === [] ? null : $this->hydrate(Row::of($rows[0]));
    }

    public function recent(int $limit): array
    {
        /** @var list<object> $rows */
        $rows = $this->connection->select(
            self::SELECT.' ORDER BY e.requested_at DESC, e.id DESC LIMIT '.max(1, $limit)
        );

        return $this->hydrateAll($rows);
    }

    public function markRunning(int $id, DateTimeImmutable $startedAt): void
    {
        $this->connection->table('data_exports')->where('id', $id)->update([
            'status' => DataExportStatus::Running->value,
            'started_at' => self::utc($startedAt),
            'updated_at' => self::utc($startedAt),
        ]);
    }

    public function markCompleted(
        int $id,
        DateTimeImmutable $completedAt,
        string $filePath,
        string $fileName,
        int $sizeBytes,
        string $sha256,
        array $rowCounts,
        DateTimeImmutable $expiresAt,
    ): void {
        $this->connection->table('data_exports')->where('id', $id)->update([
            'status' => DataExportStatus::Completed->value,
            'completed_at' => self::utc($completedAt),
            'file_path' => $filePath,
            'file_name' => $fileName,
            'size_bytes' => $sizeBytes,
            'sha256' => $sha256,
            // Sin `JSON_THROW_ON_ERROR` no habria forma de distinguir un fallo de
            // codificacion de un `{}` legitimo, y la fila quedaria sin recuentos
            // sin que nadie se enterase.
            'row_counts' => json_encode($rowCounts, JSON_THROW_ON_ERROR),
            'expires_at' => self::utc($expiresAt),
            'updated_at' => self::utc($completedAt),
        ]);
    }

    public function failStale(DateTimeImmutable $staleBefore, DateTimeImmutable $now): int
    {
        /*
         * `COALESCE(started_at, requested_at)` cubre los dos atascos con una
         * sola condicion: la fila `running` cuyo trabajador murio a mitad, y la
         * `pending` que nadie llego a recoger porque la cola estaba parada.
         * Escribirlos como dos `WHERE` distintos daria dos umbrales que algun
         * dia dejarian de coincidir.
         */
        return $this->connection->table('data_exports')
            ->whereIn('status', [DataExportStatus::Pending->value, DataExportStatus::Running->value])
            ->whereRaw('COALESCE(started_at, requested_at) <= ?', [self::utc($staleBefore)])
            ->update([
                'status' => DataExportStatus::Failed->value,
                'failed_at' => self::utc($now),
                'failure_reason' => DataExportFailure::Stale->value,
                'updated_at' => self::utc($now),
            ]);
    }

    public function markFailed(int $id, DateTimeImmutable $failedAt, DataExportFailure $failureReason): void
    {
        $this->connection->table('data_exports')->where('id', $id)->update([
            'status' => DataExportStatus::Failed->value,
            'failed_at' => self::utc($failedAt),
            // Un codigo del catalogo, nunca la clase ni el mensaje de la
            // excepcion: lo lee una persona en el panel y lo traduce el cliente.
            // La clase real va al log tecnico junto al `uuid`.
            'failure_reason' => $failureReason->value,
            'updated_at' => self::utc($failedAt),
        ]);
    }

    public function markPurged(int $id, DateTimeImmutable $purgedAt): void
    {
        $this->connection->table('data_exports')->where('id', $id)->update([
            'status' => DataExportStatus::Purged->value,
            'purged_at' => self::utc($purgedAt),
            // La ruta se limpia para que nadie intente servir un fichero que ya
            // no existe. El NOMBRE se conserva: es lo que permite reconocer la
            // exportacion en una conversacion años despues.
            'file_path' => null,
            'updated_at' => self::utc($purgedAt),
        ]);
    }

    public function recordDownload(int $id, DateTimeImmutable $downloadedAt): void
    {
        $this->connection->table('data_exports')->where('id', $id)->update([
            'downloaded_at' => self::utc($downloadedAt),
            // Incremento atomico y no `$modelo->download_count + 1`: dos
            // descargas simultaneas del mismo fichero contarian una.
            'download_count' => $this->connection->raw('download_count + 1'),
            'updated_at' => self::utc($downloadedAt),
        ]);
    }

    public function expired(DateTimeImmutable $now): array
    {
        /** @var list<object> $rows */
        $rows = $this->connection->select(
            self::SELECT." WHERE e.purged_at IS NULL AND e.status = 'completed' AND e.expires_at <= ? ORDER BY e.id",
            [self::utc($now)],
        );

        return $this->hydrateAll($rows);
    }

    /**
     * @param  list<object>  $rows
     * @return list<DataExport>
     */
    private function hydrateAll(array $rows): array
    {
        return array_map(fn (object $row): DataExport => $this->hydrate(Row::of($row)), $rows);
    }

    private function hydrate(Row $row): DataExport
    {
        /** @var array<string, mixed> $counts */
        $counts = $row->json('row_counts') ?? [];

        $rowCounts = [];

        foreach ($counts as $file => $count) {
            $rowCounts[(string) $file] = is_numeric($count) ? (int) $count : 0;
        }

        return new DataExport(
            id: $row->int('id'),
            uuid: $row->string('uuid'),
            status: DataExportStatus::from($row->string('status')),
            requestedVia: DataExportOrigin::from($row->string('requested_via')),
            requestedByUuid: $row->nullableString('requested_by_uuid'),
            requestedByName: $row->nullableString('requested_by_name'),
            requestedByUserId: $row->nullableInt('requested_by_user_id'),
            requestedAt: $row->instant('requested_at'),
            startedAt: $row->nullableInstant('started_at'),
            completedAt: $row->nullableInstant('completed_at'),
            failedAt: $row->nullableInstant('failed_at'),
            failureReason: self::failureOf($row->nullableString('failure_reason')),
            filePath: $row->nullableString('file_path'),
            fileName: $row->nullableString('file_name'),
            sizeBytes: $row->nullableInt('size_bytes'),
            sha256: $row->nullableString('sha256'),
            rowCounts: $rowCounts,
            expiresAt: $row->nullableInstant('expires_at'),
            purgedAt: $row->nullableInstant('purged_at'),
            downloadedAt: $row->nullableInstant('downloaded_at'),
            downloadCount: $row->int('download_count'),
        );
    }

    /**
     * El motivo del fallo, del catalogo.
     *
     * **Tolerante a proposito, al contrario que el resto de esta clase.** Una
     * fila escrita por una version anterior —o a mano, en una madrugada de
     * incidencia— puede llevar un valor que este binario no conoce; ahi la
     * respuesta correcta es `unexpected` y no romper el listado entero, porque lo
     * que el cliente necesita ver es que esa exportacion fallo. El `CHECK` de la
     * migracion hace que ese caso no pueda ocurrir por el camino normal.
     */
    private static function failureOf(?string $stored): ?DataExportFailure
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        return DataExportFailure::tryFrom($stored) ?? DataExportFailure::Unexpected;
    }

    /** Regla dura 3: a la columna `TIMESTAMPTZ` se escribe siempre UTC. */
    private static function utc(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new \DateTimeZone('UTC'))->format(DateTimeInterface::RFC3339_EXTENDED);
    }
}
