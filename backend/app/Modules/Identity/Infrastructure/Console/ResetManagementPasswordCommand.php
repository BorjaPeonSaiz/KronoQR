<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Console;

use App\Modules\Identity\Application\Command\ResetManagementPasswordCommand as ResetPassword;
use App\Modules\Identity\Application\Port\ManagementAccountLifecycle;
use App\Modules\Identity\Application\UseCase\ManagementPasswordResetStatus;
use App\Modules\Identity\Application\UseCase\ResetManagementPasswordHandler;

/**
 * `php artisan identity:reset-password` — sustituye la contrasena de una cuenta
 * de gestion por una **temporal** (RS-06, RF-ID-10, OWASP A07).
 *
 * El mismo caso de uso que
 * `POST /api/v1/management-accounts/{uuid}/password/reset`, para cuando el panel
 * no esta. **La genera el servidor y se enseña UNA vez**, con su caducidad; su
 * titular entra con ella y fija la suya antes de poder hacer nada mas. No hay
 * recuperacion por correo (regla dura 12): se entrega en mano.
 *
 * **Se identifica por correo**, que se traduce a `uuid` aqui y no sale de la
 * busqueda (regla dura 21). **El segundo factor no se toca**: retirarlo es
 * `identity:2fa-reset`, otro hecho con su propio asiento.
 */
final class ResetManagementPasswordCommand extends AbstractManagementAccountCommand
{
    protected $signature = 'identity:reset-password
        {email : Correo de la cuenta, que es su identificador de acceso}
        {--reason= : Por que se restablece. Queda en audit_log}';

    protected $description = 'Genera una contrasena temporal para una cuenta de gestion (RS-06, RF-ID-10).';

    public function handle(ResetManagementPasswordHandler $handler, ManagementAccountLifecycle $accounts): int
    {
        $uuid = $accounts->uuidOfAccount($this->stringArgument('email'));

        $outcome = $uuid === null ? null : $handler->handle(new ResetPassword($uuid, $this->reason()));

        if ($outcome === null || $outcome->status !== ManagementPasswordResetStatus::Reset) {
            $this->components->error('No existe ninguna cuenta de gestion activa con ese correo.');

            return self::FAILURE;
        }

        $this->components->info('Contrasena restablecida. Las sesiones abiertas de esa cuenta han dejado de valer.');

        $this->showTemporaryPassword($outcome->password()->plain, $outcome->expiresAt());

        return self::SUCCESS;
    }
}
