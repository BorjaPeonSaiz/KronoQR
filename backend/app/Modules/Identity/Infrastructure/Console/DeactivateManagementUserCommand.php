<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Console;

use App\Modules\Identity\Application\UseCase\AccountDeactivationOutcome;
use App\Modules\Identity\Application\UseCase\DeactivateManagementAccountHandler;
use Illuminate\Console\Command;

/**
 * `php artisan identity:deactivate-user` — da de baja una cuenta de gestion
 * (RS-05, RS-06, RL-16).
 *
 * **Por que existe.** Hasta la tarea 3.8 el producto no tenia ninguna forma de
 * retirarle el acceso a una cuenta: `users.is_active` se consultaba al
 * autenticar, pero las dos unicas escrituras del campo lo ponian a `true`. La
 * unica salida era editar la fila a mano en PostgreSQL —fuera del producto y
 * fuera del trail—, y mientras tanto quien se iba del hotel conservaba una
 * cuenta valida con acceso a los datos de toda la plantilla y a la correccion de
 * jornadas. Es el hallazgo H-03 de la revision interna ASVS de 2026-09.
 *
 * **Por que es un comando y no un endpoint.** Lo mismo que `identity:2fa-reset`:
 * el Anexo B del doc 01 no tiene ninguna ruta de gestion de usuarios, y un «da
 * de baja a esta persona» por API seria, en manos de un `admin` comprometido, la
 * forma mas comoda de dejar la instalacion sin nadie que pueda revisar lo que
 * hizo. La pantalla del panel es una decision de producto pendiente (ficha 3.8,
 * decision 16).
 *
 * **Se identifica por correo y no por UUID**, al reves que `identity:2fa-reset`,
 * y la diferencia es deliberada. Este comando existe para sustituir a la consulta
 * `psql` que la fila 17 de `docs/cliente/endurecimiento.md` obligaba a hacer cada
 * trimestre: exigir el UUID obligaria a volver a esa consulta para averiguarlo,
 * que es justo lo que se esta quitando de la guia. El correo es el identificador
 * de acceso, es el que el cliente tiene en su lista de personal y es el mismo que
 * pide `identity:create-user`. **La direccion no sale de la busqueda**: ni el
 * asiento ni la salida del comando la repiten (regla dura 21).
 *
 * **Nunca el nombre de la persona en la salida**, ni siquiera para confirmar: este
 * comando se ejecuta con la salida redirigida a un fichero de operacion tan a
 * menudo como `identity:create-user`.
 *
 * **Lo que la baja NO hace.** No borra nada (regla dura 5) y no reabre el alta
 * publica del primer administrador: esa guarda cuenta tambien las cuentas
 * desactivadas, y por eso dar de baja a la unica persona con acceso no convierte
 * `POST /setup/administrator` en una puerta abierta.
 */
final class DeactivateManagementUserCommand extends Command
{
    protected $signature = 'identity:deactivate-user
        {email : Correo de la cuenta, que es su identificador de acceso}
        {--reason= : Por que se da de baja. Queda en audit_log}';

    protected $description = 'Da de baja una cuenta de gestion: deja de poder entrar (RS-05, RS-06).';

    public function handle(DeactivateManagementAccountHandler $handler): int
    {
        // El argumento es obligatorio en la firma: Symfony rechaza la llamada sin
        // el antes de llegar aqui, asi que solo queda estrechar el tipo.
        $email = trim((string) $this->argument('email'));

        $reason = $this->option('reason');
        $reason = \is_string($reason) && trim($reason) !== ''
            // Un motivo por omision y no una cadena vacia: el asiento tiene que
            // decir algo. «Sin motivo declarado» es informacion; el vacio no.
            ? trim($reason)
            : 'Sin motivo declarado';

        $outcome = $handler->handle($email, $reason);

        return match ($outcome) {
            AccountDeactivationOutcome::NotFound => $this->refuse(
                'No existe ninguna cuenta de gestion con ese correo.'
            ),
            // No es un error del operador: es la confirmacion de que no habia
            // nada que hacer. Se devuelve `FAILURE` igualmente para que un script
            // que encadene bajas no de por hecho que acaba de cerrar un acceso
            // que en realidad ya estaba cerrado.
            AccountDeactivationOutcome::AlreadyInactive => $this->refuse(
                'Esa cuenta ya estaba dada de baja. No se ha cambiado nada ni se ha escrito ningun asiento.'
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
