<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Model;

use App\Modules\Product\Domain\ValueObject\DataExportFailure;
use App\Modules\Product\Domain\ValueObject\DataExportOrigin;
use App\Modules\Product\Domain\ValueObject\DataExportStatus;
use DateTimeImmutable;

/**
 * Una exportacion integra de los datos de la instalacion (**RF-PD-14**, RL-20,
 * tarea 5.10).
 *
 * ## Es la fila de `data_exports`, contada en el lenguaje del dominio
 *
 * Y es tambien el **sujeto de la policy**: `Gate::policy(DataExport::class,
 * DataExportPolicy::class)`. Se registra contra este modelo de dominio y no
 * contra una fila de Eloquent por lo mismo que las concesiones de soporte: la
 * policy no autoriza sobre una exportacion concreta —todas son iguales ante
 * ella— sino sobre «llevarse todos los datos de esta instalacion», y las tres
 * operaciones existen antes de que haya ninguna fila.
 *
 * ## Sin reloj propio (regla dura 2)
 *
 * Ningun metodo lee la hora. `expired()` recibe el instante de quien lo tiene
 * resuelto por el puerto `Clock`. Sin eso, la prueba de la purga tendria que
 * mover el reloj de la maquina o esperar siete dias.
 *
 * ## Los campos del fichero son nulos hasta que termina
 *
 * `fileName`, `filePath`, `sha256`, `sizeBytes` y `expiresAt` solo tienen valor
 * en `completed` (y siguen tras `purged`, salvo `filePath`, que se limpia al
 * borrar el fichero para que nadie intente servirlo). El `CHECK`
 * `data_exports_chk_completed_is_complete` es la mitad que lo garantiza en la
 * base de datos.
 */
final readonly class DataExport
{
    /**
     * @param  array<string, int>  $rowCounts  Filas de datos por fichero del ZIP.
     */
    public function __construct(
        /** Clave interna. Nunca sale de la base de datos (doc 01 §5.5). */
        public int $id,
        /** Identificador publico: el de la URL de descarga y el de la respuesta. */
        public string $uuid,
        public DataExportStatus $status,
        public DataExportOrigin $requestedVia,
        public ?string $requestedByUuid,
        public ?string $requestedByName,
        public ?int $requestedByUserId,
        public DateTimeImmutable $requestedAt,
        public ?DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $completedAt,
        public ?DateTimeImmutable $failedAt,
        public ?DataExportFailure $failureReason,
        public ?string $filePath,
        public ?string $fileName,
        public ?int $sizeBytes,
        public ?string $sha256,
        public array $rowCounts,
        public ?DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $purgedAt,
        public ?DateTimeImmutable $downloadedAt,
        public int $downloadCount,
    ) {}

    /**
     * Hay fichero que entregar.
     *
     * Las dos condiciones y no una: `completed` dice que la generacion termino y
     * `filePath` dice que el fichero sigue ahi. Se separan porque una purga
     * manual —alguien que borra `storage/app/exports` para hacer sitio— deja la
     * fila `completed` con un fichero que ya no existe, y ese caso tiene que
     * responder `404` y no romper.
     */
    public function isDownloadable(): bool
    {
        return $this->status->isDownloadable() && $this->filePath !== null && $this->purgedAt === null;
    }

    /** Sigue ocupando el turno: un segundo `POST` recibe `409`. */
    public function isInProgress(): bool
    {
        return $this->status->isInProgress();
    }

    /**
     * El fichero ya deberia haberse borrado.
     *
     * `<=` y no `<`: en el instante exacto de `expires_at` la exportacion ya
     * caduco. Es el lado seguro —una copia completa de la plantilla se borra
     * antes, no despues— y es la lectura contraria a la de una concesion de
     * soporte, donde el limite protege un acceso y aqui protege un fichero.
     */
    public function expired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= $now;
    }
}
