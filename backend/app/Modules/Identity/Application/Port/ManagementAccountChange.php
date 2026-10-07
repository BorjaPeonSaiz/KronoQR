<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Port;

/**
 * Las acciones del ciclo de vida de una cuenta de gestion que se cuentan en
 * `kronoqr_management_account_changes_total` (RF-ID-10). Catalogo cerrado: es
 * el valor de la etiqueta `action`.
 */
enum ManagementAccountChange: string
{
    case Created = 'created';
    case Deactivated = 'deactivated';
    case PasswordReset = 'password_reset';
    case TwoFactorReset = 'two_factor_reset';
    case PasswordChanged = 'password_changed';
}
