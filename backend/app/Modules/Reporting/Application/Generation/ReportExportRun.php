<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Generation;

use App\Modules\Reporting\Application\Support\ReportExportProgressTracker;
use App\Modules\Reporting\Domain\Model\ReportExport;

/**
 * Una generacion en curso: la fila arrancada, su avance y **el fichero que
 * esta escribiendo**.
 *
 * El fichero se anota aqui antes de escribir la primera linea, y no al
 * terminar, porque quien tiene que borrarlo si algo sale mal —un fallo, una
 * cancelacion— es el caso de uso, que no sabe en que punto de que generacion
 * ocurrio. Un CSV con media plantilla abandonado en el disco del cliente, y
 * fuera de la purga, es exactamente lo que no puede quedar.
 */
final class ReportExportRun
{
    private ?string $path = null;

    public function __construct(
        public readonly ReportExport $export,
        public readonly ReportExportProgressTracker $progress,
    ) {}

    /** El fichero que se va a escribir. Se anota antes de abrirlo. */
    public function claim(string $path): string
    {
        $this->path = $path;

        return $path;
    }

    /** El fichero que hay que borrar si la generacion no termina, si llego a haberlo. */
    public function claimedPath(): ?string
    {
        return $this->path;
    }
}
