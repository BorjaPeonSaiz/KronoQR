<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\ValueObject;

/**
 * Por que via se atribuyo un aviso de fichaje descartado (RN-22, ADR-047):
 * `discarded_scan_reports.attribution`.
 *
 * - `credential` — el `qr_payload` era una tarjeta **autentica** (firma
 *   verificada) vigente, o retirada despues de usarse (RN-20).
 * - `employee_code` — por PIN, el codigo era de una persona que **puede
 *   fichar** (el criterio de RN-19, ADR-043). El PIN no viaja ni se comprueba.
 * - `none` — nada de lo anterior. No abre incidencia; se cuenta en
 *   `kiosk_discarded_scans_total{attributed="false"}`.
 */
enum DiscardedScanAttributionMethod: string
{
    case CREDENTIAL = 'credential';
    case EMPLOYEE_CODE = 'employee_code';
    case NONE = 'none';
}
