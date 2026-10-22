<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Exception;

use App\Modules\Reporting\Domain\Model\ReportExport;
use App\Modules\Shared\Domain\Exception\BusinessRejection;
use DomainException;

/**
 * Se pidio cancelar una exportacion que ya termino (**V5-PA-4**): `completed`,
 * `failed` o `purged`.
 *
 * Es el `409` del contrato, y lleva la fila **tal como esta** para que la
 * pantalla la enseñe en vez de insistir: si el trabajo cerro la fila un
 * instante antes que la cancelacion, gana el cierre y el fichero se puede
 * descargar. Borrar un fichero ya generado y anunciado en `audit_log` no es
 * cancelar (regla dura 5): caduca solo.
 */
final class ReportExportNotCancellable extends DomainException implements BusinessRejection
{
    public function __construct(public readonly ReportExport $current)
    {
        parent::__construct('La exportacion de informe ya termino y no se puede cancelar.');
    }
}
