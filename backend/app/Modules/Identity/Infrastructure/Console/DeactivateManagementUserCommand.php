<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Console;

use App\Modules\Identity\Application\Command\DeactivateManagementAccountCommand;
use App\Modules\Identity\Application\Port\ManagementAccountLifecycle;
use App\Modules\Identity\Application\UseCase\AccountDeactivationOutcome;
use App\Modules\Identity\Application\UseCase\DeactivateManagementAccountHandler;

/**
 * `php artisan identity:deactivate-user` — da de baja una cuenta de gestion
 * (RS-05, RS-06, RL-16, RF-ID-10).
 *
 * **Por que existe, si ya esta el panel.** Desde la 2.2.0 la baja se hace desde
 * el panel (`POST /api/v1/management-accounts/{uuid}/deactivate`); esto queda
 * para cuando el panel no esta disponible, y pasa por el **mismo** caso de uso:
 * tampoco da de baja la ultima cuenta `admin` activa, porque es una invariante
 * de la instalacion y no una regla de pantalla.
 *
 * **Se identifica por correo y no por UUID**, a proposito: es el identificador
 * que el cliente tiene en su lista de personal. El correo se traduce a `uuid`
 * aqui y no sale de la busqueda: ni el asiento ni la salida lo repiten (regla
 * dura 21). **Distingue «no existe» de «ya estaba de baja»**, al contrario que
 * la API: esto es consola del servidor del cliente, no un oraculo de
 * enumeracion.
 *
 * **Lo que la baja NO hace.** No borra nada (regla dura 5) y no reabre el alta
 * publica del primer administrador.
 */
final class DeactivateManagementUserCommand extends AbstractManagementAccountCommand
{
    protected $signature = 'identity:deactivate-user
        {email : Correo de la cuenta, que es su identificador de acceso}
        {--reason= : Por que se da de baja. Queda en audit_log}';

    protected $description = 'Da de baja una cuenta de gestion: deja de poder entrar (RS-05, RS-06).';

    public function handle(DeactivateManagementAccountHandler $handler, ManagementAccountLifecycle $accounts): int
    {
        $uuid = $accounts->uuidOfAccount($this->stringArgument('email'));

        $outcome = $uuid === null
            ? AccountDeactivationOutcome::NotFound
            : $handler->handle(new DeactivateManagementAccountCommand($uuid, $this->reason()));

        return match ($outcome) {
            AccountDeactivationOutcome::NotFound => $this->refuse(
                'No existe ninguna cuenta de gestion con ese correo.'
            ),
            // No es un error del operador, pero se devuelve `FAILURE` para que un
            // script que encadene bajas no de por cerrado un acceso que ya lo
            // estaba.
            AccountDeactivationOutcome::AlreadyInactive => $this->refuse(
                'Esa cuenta ya estaba dada de baja. No se ha cambiado nada ni se ha escrito ningun asiento.'
            ),
            // En consola no hay sesion, asi que no puede ser la propia cuenta;
            // el caso existe para la API y se cubre por completitud.
            AccountDeactivationOutcome::OwnAccount => $this->refuse(
                'No se puede dar de baja la propia cuenta.'
            ),
            AccountDeactivationOutcome::LastActiveAdmin => $this->refuse(
                'Es la ultima cuenta admin activa de la instalacion. Crea otra con identity:create-user '
                .'antes de dar de baja esta. No se ha cambiado nada.'
            ),
            AccountDeactivationOutcome::Deactivated => $this->confirmDeactivation(),
        };
    }

    private function confirmDeactivation(): int
    {
        $this->components->info(
            'Cuenta dada de baja. Sus sesiones abiertas han dejado de valer y no podra volver a entrar.'
        );

        $this->components->warn(
            'La cuenta NO se ha borrado: su historial y sus asientos siguen ahi, y sigue contando '
            .'para que el alta publica del primer administrador continue cerrada.'
        );

        return self::SUCCESS;
    }

    private function refuse(string $message): int
    {
        $this->components->error($message);

        return self::FAILURE;
    }
}
