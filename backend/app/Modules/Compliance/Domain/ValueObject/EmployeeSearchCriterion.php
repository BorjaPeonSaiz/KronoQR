<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * Por que se busco a la persona de la exportacion legal (RF-IN-05, ADR-037).
 *
 * Es lo **unico** del criterio que llega al asiento `personal_data.accessed`:
 * el termino buscado no se escribe nunca, porque un nombre o un DNI en
 * `audit_log` serian el dato personal que esa decision prohibe copiar (regla
 * dura 21). Los valores son vocabulario estable del trail, en ingles, y
 * coinciden con los que nombran el contrato y ADR-037.
 */
enum EmployeeSearchCriterion: string
{
    /** Nombre, apellidos o nombre completo por subcadena, o codigo exacto. */
    case NAME_OR_CODE = 'name_or_code';

    /** Documento de identidad, exacto contra `national_id_hash` (RL-08). */
    case NATIONAL_ID = 'national_id';
}
