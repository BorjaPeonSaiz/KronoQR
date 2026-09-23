<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Command;

use App\Modules\Reporting\Domain\ValueObject\ReportExportKind;
use App\Modules\Reporting\Domain\ValueObject\ReportExportParameters;
use App\Modules\Shared\Domain\ValueObject\AccessScope;

/**
 * Lo que hace falta para pedir un informe en diferido (**RF-IN-06**).
 *
 * ## El alcance viaja aqui y no se resuelve dentro
 *
 * Lo pone la capa HTTP a partir del token (`ScopeGuard`), igual que en el
 * informe sincrono, y desde este momento queda **congelado** en la fila: el
 * trabajo lo aplicara tal cual dentro de unos minutos, sin volver a preguntar.
 * Es la decision 1 de la ficha, y la razon es que el fichero tiene que describir
 * el alcance con el que se autorizo, que es el unico que quedo en `audit_log`.
 *
 * ## `requestedByUserId` no es opcional
 *
 * Al contrario que en la exportacion integra, que se puede pedir por consola sin
 * sesion. Aqui siempre hay alguien: la exportacion es **suya** —solo el
 * solicitante la ve y la descarga— y una fila sin dueño no tendria quien la
 * consultara ni a quien avisar.
 */
final readonly class RequestReportExportCommand
{
    public function __construct(
        public ReportExportKind $kind,
        /** `csv`, `xlsx` o `pdf`, ya acotado por {@see ReportExportKind::allows()}. */
        public string $format,
        public ReportExportParameters $parameters,
        public AccessScope $scope,
        public int $requestedByUserId,
    ) {}
}
