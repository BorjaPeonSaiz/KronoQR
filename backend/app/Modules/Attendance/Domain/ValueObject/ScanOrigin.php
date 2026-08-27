<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\ValueObject;

/**
 * De donde viene la marca de un tramo (doc 01 §5.3, `scan_events.origin`,
 * `shift_entries.clock_in_source` y `clock_out_source`).
 *
 * Un `enum` y no constantes de clase (doc 02 §3.5). Los valores respaldados van
 * en minusculas para coincidir con la columna, y los nombres de caso en
 * mayusculas tal como los enumera el documento 01.
 *
 * Cada marca lleva el suyo porque entrada y salida pueden tener origen
 * distinto: se entra con la tarjeta y se sale con el PIN de respaldo porque la
 * tarjeta se quedo en la taquilla (RF-AT-11).
 */
enum ScanOrigin: string
{
    /** Escaneo de la tarjeta fisica en el quiosco. El camino normal (RF-AT-01). */
    case QR_KIOSK = 'qr_kiosk';

    /** PIN de 6 digitos en el quiosco, cuando el empleado no puede presentar su tarjeta (RF-AT-11). */
    case PIN_KIOSK = 'pin_kiosk';

    /** Marca creada o rectificada por una persona autorizada desde el panel (RF-PA-04). */
    case MANUAL_ADMIN = 'manual_admin';

    /** Carga desde el sistema anterior en la puesta en marcha. */
    case IMPORT = 'import';
}
