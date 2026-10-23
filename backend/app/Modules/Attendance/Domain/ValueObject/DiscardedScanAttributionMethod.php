<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\ValueObject;

/**
 * Por que via se atribuyo un fichaje descartado (RN-22, ADR-047, ADR-061):
 * `discarded_scan_reports.attribution`.
 *
 * - `credential` — el `qr_payload` era una tarjeta **autentica** (firma
 *   verificada) vigente, o retirada despues de usarse (RN-20).
 * - `employee_code` — por PIN, el codigo era de una persona que **puede
 *   fichar** (el criterio de RN-19, ADR-043). El PIN no viaja ni se comprueba.
 * - `fingerprint_match` — solo en un descarte del **servidor** de una tarjeta que
 *   no se pudo atribuir por su firma (ADR-061, A2): el titular del fichaje
 *   **aceptado** mas reciente con la misma huella del contenido. No autentica a
 *   nadie: el dueño consta solo en `discarded_scan_reports`, nunca en
 *   `scan_events.employee_id`.
 * - `none` — nada de lo anterior. El aviso del quiosco no abre incidencia; el
 *   descarte del servidor abre una sin persona, por quiosco y jornada.
 */
enum DiscardedScanAttributionMethod: string
{
    case CREDENTIAL = 'credential';
    case EMPLOYEE_CODE = 'employee_code';
    case FINGERPRINT_MATCH = 'fingerprint_match';
    case NONE = 'none';
}
