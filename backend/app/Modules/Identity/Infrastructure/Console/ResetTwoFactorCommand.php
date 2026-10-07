<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Console;

use App\Modules\Identity\Application\Command\ResetManagementTwoFactorCommand;
use App\Modules\Identity\Application\UseCase\ResetTwoFactorHandler;
use App\Modules\Identity\Application\UseCase\TwoFactorResetOutcome;

/**
 * `php artisan identity:2fa-reset` — retira el segundo factor de una cuenta de
 * gestion (RS-06, RF-ID-10).
 *
 * El mismo caso de uso que
 * `POST /api/v1/management-accounts/{uuid}/two-factor/reset`, para cuando el
 * panel no esta: perder el telefono deja a alguien fuera de su cuenta, y a una
 * instalacion con una sola `admin`, sin panel. **Siempre deja asiento**
 * (`auth.two_factor_reset`) y cierra todas las sesiones de la cuenta.
 *
 * **Una cuenta sin segundo factor confirmado no se toca**: no hay nada que
 * retirar, y un asiento de restablecimiento sin nada restablecido ensuciaria el
 * trail. **Se identifica por UUID**, el unico identificador admitido en un log o
 * en un asiento (regla dura 21); `identity:list-users` lo muestra.
 */
final class ResetTwoFactorCommand extends AbstractManagementAccountCommand
{
    protected $signature = 'identity:2fa-reset
        {uuid : UUID publico de la cuenta (users.uuid)}
        {--reason= : Por que se retira. Queda en audit_log}';

    protected $description = 'Retira el segundo factor de una cuenta de gestion (RS-06).';

    public function handle(ResetTwoFactorHandler $handler): int
    {
        $uuid = $this->stringArgument('uuid');

        $outcome = $handler->handle(new ResetManagementTwoFactorCommand($uuid, $this->reason()));

        if ($outcome === TwoFactorResetOutcome::NotEnrolled) {
            $this->components->error(
                'Esa cuenta no tenia segundo factor activo: no hay nada que retirar. No se ha escrito ningun asiento.'
            );

            return self::FAILURE;
        }

        if ($outcome !== TwoFactorResetOutcome::Reset) {
            $this->components->error('No existe ninguna cuenta de gestion activa con ese UUID.');

            return self::FAILURE;
        }

        $this->components->info(
            'Segundo factor retirado. La cuenta '.$uuid.' tendra que darlo de alta en su proximo acceso.'
        );

        return self::SUCCESS;
    }
}
