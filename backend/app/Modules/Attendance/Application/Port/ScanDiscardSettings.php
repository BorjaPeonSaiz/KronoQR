<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Port;

use App\Modules\Attendance\Domain\Policy\UnprocessableScanPolicy;
use App\Modules\Attendance\Domain\ValueObject\ScanDiscardThreshold;

/**
 * El umbral con el que el servidor deja de intentar un fichaje que no consigue
 * procesar (RN-22, ADR-061), **ya resuelto** para {@see UnprocessableScanPolicy}.
 *
 * Es configuracion tecnica del servidor —`SCAN_DISCARD_MIN_ATTEMPTS`,
 * `SCAN_DISCARD_MIN_MINUTES` y `SCAN_DISCARD_DAILY_BUDGET` en el `.env`, con 10,
 * 60 y 3 de serie (regla dura 13, ADR-029)— y no un umbral legal: por eso no
 * sale del perfil de cumplimiento (regla dura 14). Un valor por debajo de los
 * suelos de {@see ScanDiscardThreshold} hace fallar la construccion: el
 * adaptador no lo corrige en silencio.
 */
interface ScanDiscardSettings
{
    public function threshold(): ScanDiscardThreshold;
}
