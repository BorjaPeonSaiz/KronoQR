<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Port;

use App\Modules\Product\Domain\Exception\DataExportAlreadyInProgress;
use App\Modules\Product\Domain\Model\DataExport;
use App\Modules\Product\Domain\ValueObject\DataExportFailure;
use App\Modules\Product\Domain\ValueObject\DataExportOrigin;
use DateTimeImmutable;

/**
 * La tabla `data_exports`, vista desde el caso de uso (**RF-PD-14**, RL-20).
 *
 * ## Ninguna operacion borra
 *
 * Regla dura 5. `markPurged()` marca la fila y limpia la ruta del fichero;
 * quien borra el fichero del disco es {@see DataExportArchiveWriter}. Separarlos
 * es lo que permite que una purga a medias —fichero borrado, fila sin marcar—
 * se note en el listado en lugar de desaparecer.
 *
 * ## La exclusion mutua vive en la base de datos
 *
 * `create()` **lanza** {@see DataExportAlreadyInProgress} cuando ya hay una
 * `pending` o `running`, y lo hace porque el `INSERT` choca contra el indice
 * unico parcial `data_exports_single_in_progress_uidx`, no porque haya
 * consultado antes. Un `SELECT` previo tendria condicion de carrera con la
 * segunda pestaña del mismo administrador, que es exactamente el caso que se
 * quiere cerrar.
 */
interface DataExportRepository
{
    /**
     * Crea la fila en `pending` y la devuelve.
     *
     * @throws DataExportAlreadyInProgress si ya hay una `pending` o `running`.
     */
    public function create(
        string $uuid,
        DataExportOrigin $requestedVia,
        ?int $requestedByUserId,
        DateTimeImmutable $requestedAt,
    ): DataExport;

    public function findByUuid(string $uuid): ?DataExport;

    /** La que ocupa el turno, si la hay. */
    public function inProgress(): ?DataExport;

    /**
     * Las mas recientes, de la mas nueva a la mas antigua.
     *
     * @return list<DataExport>
     */
    public function recent(int $limit): array;

    public function markRunning(int $id, DateTimeImmutable $startedAt): void;

    /**
     * @param  array<string, int>  $rowCounts
     */
    public function markCompleted(
        int $id,
        DateTimeImmutable $completedAt,
        string $filePath,
        string $fileName,
        int $sizeBytes,
        string $sha256,
        array $rowCounts,
        DateTimeImmutable $expiresAt,
    ): void;

    /**
     * @param  DataExportFailure  $failureReason  Un **codigo estable del dominio**, nunca la
     *                                            clase ni el mensaje de la excepcion (regla
     *                                            dura 21): esta columna la enseña el panel y
     *                                            la traduce el cliente.
     */
    public function markFailed(int $id, DateTimeImmutable $failedAt, DataExportFailure $failureReason): void;

    /**
     * Declara `failed` con motivo `stale` toda exportacion **atascada**.
     *
     * ## Por que existe, y por que es una sola sentencia
     *
     * El indice unico parcial solo deja una fila `pending|running` a la vez, asi
     * que una fila que nadie termina **bloquea la exportacion integra para
     * siempre**: `POST` responde `409` eterno y `product:export-all` sale `2`. Y
     * atascarse es facil: el trabajador de cola muere, alguien hace `docker
     * compose down` a mitad —que es el paso 1 de una actualizacion— o el proceso
     * recibe un `SIGKILL`. Sin esto, RL-20 dependeria de que nadie apague el
     * servidor en el minuto equivocado.
     *
     * Un `UPDATE ... WHERE` y no leer-y-escribir: dos peticiones simultaneas
     * barriendo a la vez no pueden marcar dos veces la misma fila ni pisarse, y
     * no hace falta candado.
     *
     * @param  DateTimeImmutable  $staleBefore  Frontera ya resuelta por quien llama (regla
     *                                          dura 14): se declara atascada la fila cuyo
     *                                          `started_at` —o, si nunca arranco, su
     *                                          `requested_at`— sea anterior.
     * @return int Cuantas se han declarado fallidas.
     */
    public function failStale(DateTimeImmutable $staleBefore, DateTimeImmutable $now): int;

    /** Marca la purga y limpia la ruta. La fila **no** se borra (regla dura 5). */
    public function markPurged(int $id, DateTimeImmutable $purgedAt): void;

    /** Suma una descarga y anota la fecha. */
    public function recordDownload(int $id, DateTimeImmutable $downloadedAt): void;

    /**
     * Las que ya vencieron y todavia tienen fichero.
     *
     * @return list<DataExport>
     */
    public function expired(DateTimeImmutable $now): array;
}
