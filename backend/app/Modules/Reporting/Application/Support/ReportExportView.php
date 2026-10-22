<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Support;

use App\Modules\Reporting\Domain\Model\ReportExport;
use App\Modules\Reporting\Domain\ValueObject\ReportExportProgress;

/**
 * Una exportacion tal como la enseña la pantalla: la fila y, si esta `running`,
 * por donde va (**V5-PA-4**). El avance no es de la fila —ver
 * `ReportExportProgressBoard`— y por eso viaja al lado y no dentro.
 */
final readonly class ReportExportView
{
    public function __construct(
        public ReportExport $export,
        public ?ReportExportProgress $progress = null,
    ) {}
}
