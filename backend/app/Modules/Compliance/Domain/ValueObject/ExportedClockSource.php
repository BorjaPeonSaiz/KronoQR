<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * De donde viene cada marca de un tramo exportado a la Inspeccion (RL-01,
 * RF-IN-05; H5 de la verificacion de la 2.1.0).
 *
 * Los valores son los de `shift_entries.clock_in_source` y `clock_out_source`.
 * `Compliance` no puede importar el enumerado de `Attendance` (doc 02 §1.6),
 * asi que los repite; `ExportedShiftEntryTest` comprueba que no divergen.
 */
enum ExportedClockSource: string
{
    /** Escaneo de la tarjeta fisica en el quiosco (RF-AT-01). */
    case QrKiosk = 'qr_kiosk';

    /** PIN en el quiosco, sin la tarjeta (RF-AT-11). */
    case PinKiosk = 'pin_kiosk';

    /** Marca creada o rectificada desde el panel (RF-PA-04). */
    case ManualAdmin = 'manual_admin';

    /** Carga desde el sistema anterior en la puesta en marcha. */
    case Import = 'import';
}
