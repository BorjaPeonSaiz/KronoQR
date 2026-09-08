<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * En que punto esta una exportacion integra (**RF-PD-14**, RL-20, tarea 5.10).
 *
 * ## Cinco estados y ninguno es redundante
 *
 * - `pending` — la fila existe y el trabajo esta en la cola. Es lo que devuelve
 *   el `202` del contrato.
 * - `running` — se estan escribiendo los ficheros.
 * - `completed` — se puede descargar.
 * - `failed` — no se genero. La causa, **sin datos personales**, en
 *   `failure_reason`.
 * - `purged` — existio y su fichero se borro por caducidad. **No es lo mismo que
 *   `failed`**, y confundirlos seria decirle al cliente que su exportacion nunca
 *   se hizo cuando se hizo y se la pudo llevar.
 *
 * `purged` es ademas la razon por la que la fila no se borra (regla dura 5): la
 * pregunta «¿salio de aqui una copia completa de mis datos, y cuando?» hay que
 * poder contestarla años despues, y una fila borrada no contesta nada.
 *
 * ## `pending` y `running` son los dos que ocupan el sitio
 *
 * {@see self::inProgress()} es lo que consulta el caso de uso para responder
 * `409`, y lo que la migracion escribe en el indice unico parcial. Estan en un
 * solo sitio para que la comprobacion de PHP y la de la base de datos no puedan
 * separarse.
 */
enum DataExportStatus: string
{
    case Pending = 'pending';

    case Running = 'running';

    case Completed = 'completed';

    case Failed = 'failed';

    case Purged = 'purged';

    /**
     * Los estados que ocupan el turno: mientras haya una fila en uno de ellos,
     * un segundo `POST` recibe `409`.
     *
     * @return list<self>
     */
    public static function inProgress(): array
    {
        return [self::Pending, self::Running];
    }

    /**
     * El catalogo, para el `CHECK` de la migracion.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }

    public function isInProgress(): bool
    {
        return in_array($this, self::inProgress(), true);
    }

    /** Solo `completed` tiene fichero que entregar. */
    public function isDownloadable(): bool
    {
        return $this === self::Completed;
    }
}
