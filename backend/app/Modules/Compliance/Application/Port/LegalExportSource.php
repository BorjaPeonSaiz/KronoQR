<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Port;

use App\Modules\Compliance\Domain\ValueObject\LegalExportPeriod;
use App\Modules\Compliance\Domain\ValueObject\LegalExportRecord;
use App\Modules\Compliance\Domain\ValueObject\LegalExportScope;

/**
 * De donde salen las filas de la exportacion legal (RF-IN-05, RL-03, RL-06).
 *
 * ## Devuelve un `iterable`, y eso es el requisito, no una preferencia
 *
 * El doc 02 §3.1 elige `spatie/simple-excel` con una sola justificacion:
 * *«Streaming: no carga en memoria un mes de 500 empleados»*. Si este puerto
 * devolviera un array, la decision quedaria anulada aqui —el array estaria
 * completo en memoria antes de escribir la primera linea— y el fallo aparecería
 * el dia del requerimiento, con el periodo mas grande y el servidor mas
 * pequeño. El adaptador recorre un cursor de PostgreSQL y va cediendo filas.
 *
 * ## Orden garantizado, y forma parte del contrato
 *
 * Las filas llegan agrupadas por **trabajador y jornada** (`work_date`), y
 * dentro de cada jornada primero los tramos por hora de entrada y despues las
 * correcciones por momento de la correccion. No es cosmetica: un documento que
 * se entrega a la Inspeccion se lee de arriba abajo, y un orden que dependiera
 * del plan de ejecucion de la base de datos daria un fichero distinto cada vez
 * para el mismo periodo.
 *
 * ## Que entra y que no
 *
 * Entran los tramos **vigentes** —abiertos, cerrados o marcados como anomalos— y
 * los **anulados**, porque nada se oculta (regla dura 5). No entran las
 * versiones **sustituidas**: lo que decian esta en la columna «antes» de la
 * correccion que las sustituyo, y repetirlas como tramos haria contar dos veces
 * el mismo trabajo. El fichero declara este criterio por escrito en su cabecera.
 */
interface LegalExportSource
{
    /**
     * @return iterable<int, LegalExportRecord>
     */
    public function records(LegalExportPeriod $period, LegalExportScope $scope): iterable;
}
