<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Port;

use App\Modules\Product\Domain\ValueObject\DoctorFinding;

/**
 * Lo que un hallazgo de `doctor` puede llevar **cuando sale de la instalacion**
 * dentro del paquete de diagnostico (**RL-19**, RF-PD-09; ADR-020, enmienda del
 * 01-11-2026).
 *
 * Se aplica a cada {@see DoctorFinding} **antes** de redactarlo, para que ni
 * los detalles ni los parametros que el texto sustituye lleven una ruta del
 * servidor o el nombre de `APP_URL`. El `product:doctor` local no lo pasa: quien
 * administra el servidor necesita la ruta real para arreglar lo que falla.
 */
interface DoctorFindingFilter
{
    public function filter(DoctorFinding $finding): DoctorFinding;
}
