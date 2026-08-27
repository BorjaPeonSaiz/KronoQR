<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

/**
 * Ya hay un centro con ese nombre.
 */
final class SiteNameAlreadyTaken extends WorkforceConflict
{
    public static function forName(string $name): self
    {
        return new self('Ya existe un centro llamado «'.$name.'».');
    }
}
