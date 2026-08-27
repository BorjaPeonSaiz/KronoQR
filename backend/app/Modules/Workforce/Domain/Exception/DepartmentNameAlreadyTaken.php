<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

/**
 * Ya hay un departamento con ese nombre **en ese centro**.
 *
 * La unicidad es por centro y no global: dos hoteles del mismo cliente tienen
 * los dos una «Recepcion», y prohibirlo obligaria a inventar nombres.
 */
final class DepartmentNameAlreadyTaken extends WorkforceConflict
{
    public static function forName(string $name): self
    {
        return new self('Ese centro ya tiene un departamento llamado «'.$name.'».');
    }
}
