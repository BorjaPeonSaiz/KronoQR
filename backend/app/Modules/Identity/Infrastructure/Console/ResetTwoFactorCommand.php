<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Console;

use App\Modules\Identity\Application\UseCase\ResetTwoFactorHandler;
use Illuminate\Console\Command;

/**
 * `php artisan identity:2fa-reset` — retira el segundo factor de una cuenta de
 * gestion (RS-06).
 *
 * **Por que existe.** Sin esto, perder el telefono deja a alguien fuera de su
 * cuenta para siempre, y a una instalacion con un solo administrador **sin
 * panel**. Es la unica salida que el producto ofrece hoy: los codigos de
 * recuperacion —la alternativa habitual— son otra credencial que emitir, entregar
 * y custodiar, el mismo problema que ADR-014 resolvio para la tarjeta, y quedan
 * anotados como deuda.
 *
 * **Por que es un comando y no un endpoint.** El Anexo B del doc 01 no tiene
 * ninguna ruta de gestion de usuarios —crear cuentas ya es
 * `identity:create-user`— y un «quitale el segundo factor a esta persona» por API
 * seria, en manos de un administrador comprometido, la forma mas comoda de
 * preparar el acceso a la cuenta de otro. En consola queda restringido a quien
 * tiene el servidor del cliente.
 *
 * **Siempre deja asiento** (`auth.two_factor_reset`, regla dura 6): el caso de uso
 * lo publica dentro de la misma transaccion, asi que si la auditoria falla, el
 * segundo factor sigue en su sitio.
 *
 * **Se identifica por UUID y no por correo.** El UUID es el identificador publico
 * y el unico admitido en un log o en un asiento (regla dura 21); aceptar el correo
 * pondria una direccion en el historial del shell del servidor.
 */
final class ResetTwoFactorCommand extends Command
{
    protected $signature = 'identity:2fa-reset
        {uuid : UUID publico de la cuenta (users.uuid)}
        {--reason= : Por que se retira. Queda en audit_log}';

    protected $description = 'Retira el segundo factor de una cuenta de gestion (RS-06).';

    public function handle(ResetTwoFactorHandler $handler): int
    {
        // El argumento es obligatorio en la firma: Symfony rechaza la llamada sin
        // el antes de llegar aqui, asi que solo queda estrechar el tipo.
        $uuid = (string) $this->argument('uuid');

        $reason = $this->option('reason');
        $reason = \is_string($reason) && trim($reason) !== ''
            ? trim($reason)
            // Un motivo por omision y no una cadena vacia: el asiento tiene que
            // decir algo. «Sin motivo declarado» es informacion; el vacio no.
            : 'Sin motivo declarado';

        if (! $handler->handle($uuid, $reason)) {
            $this->components->error('No existe ninguna cuenta de gestion activa con ese UUID.');

            return self::FAILURE;
        }

        $this->components->info(
            'Segundo factor retirado. La cuenta '.$uuid.' tendra que darlo de alta en su proximo acceso.'
        );

        return self::SUCCESS;
    }
}
