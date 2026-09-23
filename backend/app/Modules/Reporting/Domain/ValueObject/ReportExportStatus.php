<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

/**
 * En que punto esta un informe generado en diferido (**RF-IN-06**, ADR-041,
 * decision 1 de la ficha 3.9).
 *
 * ## Cinco estados, los mismos que la exportacion integra, y por el mismo motivo
 *
 * - `pending` — la fila existe y el trabajo esta en la cola. Es lo que devuelve
 *   el `202` del contrato.
 * - `running` — se esta escribiendo el fichero.
 * - `completed` — se puede pedir un enlace de descarga.
 * - `failed` — no se genero. La causa, **sin datos personales**, en
 *   `failure_reason`.
 * - `purged` — existio y su fichero se borro al vencer
 *   `REPORTING_EXPORT_RETENTION_DAYS`. **No es lo mismo que `failed`**:
 *   confundirlos seria decirle a quien lo pidio que su informe nunca se hizo
 *   cuando se hizo y se lo pudo llevar.
 *
 * `purged` es ademas la razon por la que la fila no se borra (regla dura 5): el
 * fichero llevaba horas de personas identificadas, y «¿salio de aqui un informe
 * nominal en marzo, y quien lo pidio?» hay que poder contestarlo despues.
 *
 * ## `pending` y `running` son los dos que ocupan el turno
 *
 * {@see self::inProgress()} es lo que consulta el caso de uso para responder
 * `409`, y lo que la migracion escribe en el indice unico parcial **por
 * solicitante**. Estan en un solo sitio para que la comprobacion de PHP y la de
 * la base de datos no puedan separarse.
 */
enum ReportExportStatus: string
{
    case Pending = 'pending';

    case Running = 'running';

    case Completed = 'completed';

    case Failed = 'failed';

    case Purged = 'purged';

    /**
     * Los estados que ocupan el turno **de esa persona**: mientras tenga una
     * fila en uno de ellos, un segundo `POST` suyo recibe `409`. Dos
     * responsables distintos no se estorban (decision 2 de la ficha).
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
        return \in_array($this, self::inProgress(), true);
    }

    /** Solo `completed` tiene fichero que entregar. */
    public function isDownloadable(): bool
    {
        return $this === self::Completed;
    }
}
