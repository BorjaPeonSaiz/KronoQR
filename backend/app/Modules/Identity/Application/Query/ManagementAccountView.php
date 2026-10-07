<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Query;

use App\Modules\Identity\Application\Port\ManagementAccountRecord;
use App\Modules\Identity\Domain\ValueObject\PasswordStatus;

/**
 * Una cuenta de gestion lista para pintarse (RF-ID-10): la fila leida y el
 * estado de su contrasena ya resuelto con el reloj. Es lo que envuelve
 * `ManagementAccountResource`; nunca un modelo Eloquent.
 */
final readonly class ManagementAccountView
{
    public function __construct(
        public ManagementAccountRecord $account,
        public PasswordStatus $passwordStatus,
    ) {}
}
