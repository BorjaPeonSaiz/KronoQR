<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

use App\Modules\Shared\Domain\Exception\BusinessRejection;

/**
 * Ya hay un centro con ese nombre.
 */
final class SiteNameAlreadyTaken extends WorkforceConflict implements BusinessRejection
{
    public static function forName(string $name): self
    {
        return new self('Ya existe un centro llamado «'.$name.'».');
    }
}
