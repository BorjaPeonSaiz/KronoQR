<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Port;

use App\Modules\Compliance\Domain\ValueObject\RetentionReport;

/**
 * Deja el informe de la pasada escrito **en el servidor del cliente** (RF-PR-03,
 * regla dura 16).
 *
 * Ni se envia, ni se sube, ni se adjunta a nada: el fabricante no accede a los
 * datos del cliente (ADR-020). El informe se queda donde el responsable pueda
 * archivarlo y, si algun dia hay que defender la purga, ensenarlo.
 *
 * Devuelve la ruta escrita porque un comando que produce un fichero y no dice
 * donde obliga a buscarlo.
 */
interface RetentionReportStore
{
    public function store(RetentionReport $report): string;
}
