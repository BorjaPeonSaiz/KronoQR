<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Exception;

use App\Modules\Shared\Domain\Exception\BusinessRejection;
use DomainException;

/**
 * La exportacion que se estaba generando ya no esta `running`: la cancelo
 * quien la pidio (**V5-PA-4**).
 *
 * La lanza la comprobacion que hace el trabajo entre fases y cada pocos
 * cientos de filas, y la recoge el propio caso de uso de la generacion para
 * **borrar el fichero a medias y terminar sin publicar nada**. No es una
 * averia —nadie tiene que mirar nada— y por eso es un rechazo de negocio y no
 * un `error` en las metricas.
 */
final class ReportExportWasCancelled extends DomainException implements BusinessRejection
{
    public static function during(string $uuid): self
    {
        return new self('La exportacion de informe '.$uuid.' se cancelo mientras se generaba.');
    }
}
