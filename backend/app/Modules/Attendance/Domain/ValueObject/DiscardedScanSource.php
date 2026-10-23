<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\ValueObject;

/**
 * **Quien descarto un fichaje** (RN-22): el quiosco, que lo aviso por
 * `POST /api/v1/scan/discarded` (ADR-047), o el servidor, que lo dio por
 * imposible de procesar tras el umbral (ADR-061). Es el valor de
 * `discarded_scan_reports.source` y del `source` del `context` de la
 * incidencia `discarded_scan`.
 */
enum DiscardedScanSource: string
{
    case Kiosk = 'kiosk';

    case Server = 'server';
}
