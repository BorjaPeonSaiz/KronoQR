<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\UseCase;

use App\Modules\Compliance\Domain\ValueObject\RetentionReport;

/**
 * El desenlace de una pasada de retencion: el informe y donde quedo escrito.
 *
 * La ruta sale del caso de uso y no la decide la consola porque el informe se
 * escribe **siempre**, tambien cuando la pasada la lanza el planificador y no
 * hay nadie mirando la salida. Un comando que produce un fichero y no dice donde
 * obliga a buscarlo.
 */
final readonly class RetentionOutcome
{
    public function __construct(
        public RetentionReport $report,
        public string $reportPath,
    ) {}

    /** La frase que hay que pasar en `--confirm` para ejecutar este mismo plan. */
    public function confirmationToken(): string
    {
        return $this->report->confirmationToken();
    }
}
