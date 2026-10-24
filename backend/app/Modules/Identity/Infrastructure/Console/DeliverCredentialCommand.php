<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Console;

use App\Modules\Identity\Application\Command\DeliverCredentialCommand as DeliverCredential;
use App\Modules\Identity\Application\Exception\CredentialDeliverySignerNotAllowed;
use App\Modules\Identity\Application\Support\CredentialManagers;
use App\Modules\Identity\Application\UseCase\DeliverCredential as DeliverCredentialHandler;
use App\Modules\Identity\Domain\Exception\IdentityDomainException;
use App\Modules\Shared\Application\Port\ResponsibleAccounts;
use App\Modules\Shared\Domain\ValueObject\ResponsibleAccount;
use App\Modules\Shared\Domain\ValueObject\UtcInstant;
use App\Modules\Shared\Domain\ValueObject\UtcPrecision;
use App\Modules\Shared\Infrastructure\Console\ConsoleResponsible;
use Illuminate\Console\Command;

/**
 * `php artisan credentials:deliver {credential} --responsible=` — registra que
 * la tarjeta llego a manos de su titular (Anexo C del doc 02, RF-QR-06).
 *
 * **`--responsible` es obligatorio y no tiene valor por defecto.** RF-QR-06
 * pide «fecha y responsable», y responsable significa una persona con nombre en
 * `users`, no «el sistema». La columna es `NOT NULL` cuando hay entrega —CHECK
 * `credentials_chk_delivery_is_signed`— precisamente para que no exista el
 * camino de escribir una entrega sin responsable. Sin este dato, el registro no
 * sirve para lo unico para lo que existe: distinguir *«se perdio antes de
 * darsela»* de *«la perdio el empleado»* (doc 02 §5.5).
 *
 * **Desde la 2.3.0 la cuenta firma el asiento** (doc 01 §7.2, «Firmas por
 * consola»). Hasta la 2.2.1 el asiento decia `system` y el responsable iba
 * dentro, porque nadie sabia quien tecleaba la orden. Ahora la orden enseña la
 * cuenta (`Firma: nombre <correo> · rol · uuid`) y la persona confirma que es
 * ella —sin terminal, con `--responsible-confirm=<correo>`—, asi que el actor
 * es esa cuenta, como cuando la entrega se registra desde el panel. Firma quien
 * puede registrarla desde el panel: `admin` o `rrhh` (`CredentialPolicy::deliver`).
 *
 * `--by` era la opcion de la 2.2.1: se rechaza con `2` y se explica el cambio.
 *
 * **Codigos de salida:** `0` registrada; `1` la credencial no existe, no se
 * puede entregar (sin imprimir, ya entregada, revocada) o la persona responde
 * que no; `2` falta la credencial o `--responsible`, la cuenta no puede firmar,
 * o falta o no cuadra `--responsible-confirm`. Con `1` o `2` no hay asiento.
 *
 * **No es idempotente.** Marcar dos veces la misma entrega falla: sobrescribirla
 * cambiaria el responsable y el momento que ya constan en la auditoria.
 */
final class DeliverCredentialCommand extends Command
{
    protected $signature = 'credentials:deliver
        {credential : UUID de la credencial}
        {--responsible= : Uuid o correo de la cuenta de gestion (admin o rrhh) que responde de la entrega y la firma. Obligatorio}
        {--responsible-confirm= : Correo de la cuenta de --responsible. Obligatorio sin terminal}
        {--by= : Retirada en la 2.3.0: usa --responsible}';

    protected $description = 'Registra la entrega de una tarjeta, con fecha y responsable (RF-QR-06).';

    public function handle(DeliverCredentialHandler $handler, ResponsibleAccounts $accounts): int
    {
        $credentialUuid = trim((string) $this->argument('credential'));

        if ($credentialUuid === '') {
            $this->error('Hace falta el UUID de la credencial.');

            return self::INVALID;
        }

        $responsible = $this->signedBy($accounts, $credentialUuid);

        if (\is_int($responsible)) {
            return $responsible;
        }

        try {
            $delivered = $handler->handle(new DeliverCredential(
                credentialUuid: $credentialUuid,
                deliveredByUserId: $responsible->id,
                // La cuenta que ha confirmado su firma: el asiento es suyo,
                // como cuando la entrega se registra desde el panel.
                actorUserId: $responsible->id,
            ));
        } catch (CredentialDeliverySignerNotAllowed) {
            // La cuenta se dio de baja o perdio el rol entre la pregunta y la
            // firma: no se ha entregado ni escrito nada.
            return ConsoleResponsible::noLongerAllowed($this);
        } catch (IdentityDomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($delivered === null) {
            $this->error('No hay ninguna credencial con ese UUID.');

            return self::FAILURE;
        }

        $this->info('Entrega registrada: '.$delivered->credential->uuid);
        $this->line('Responsable: '.$responsible->email);
        $this->line('Momento: '.(UtcInstant::format($delivered->credential->deliveredAt, UtcPrecision::Seconds) ?? '—'));
        $this->line('Recuerda entregar tambien el PIN del portal y la hoja de instrucciones: docs/runbooks/alta-nuevo-empleado.md');

        return self::SUCCESS;
    }

    /**
     * La cuenta que firma la entrega, o el codigo de salida con el motivo ya
     * escrito: `2` si se usa `--by`, falta `--responsible`, la cuenta no puede
     * firmar o no cuadra `--responsible-confirm`; `1` si la persona responde
     * que no.
     */
    private function signedBy(ResponsibleAccounts $accounts, string $credentialUuid): ResponsibleAccount|int
    {
        $by = $this->option('by');

        if (\is_string($by) && $by !== '') {
            $this->error('--by se retiro en la 2.3.0: la entrega la firma la cuenta de --responsible (su uuid o su correo), que confirma quien es. No se ha registrado nada.');

            return self::INVALID;
        }

        $option = $this->option('responsible');

        if (! \is_string($option) || trim($option) === '') {
            return ConsoleResponsible::missing($this);
        }

        // Los roles que registran una entrega desde el panel
        // (`CredentialPolicy::deliver`): la consola no es la via por la que
        // alguien hace lo que el panel le niega.
        $responsible = ConsoleResponsible::resolve($this, $accounts, $option, CredentialManagers::ROLES);

        if (\is_int($responsible)) {
            return $responsible;
        }

        return ConsoleResponsible::confirm(
            $this,
            $responsible,
            '¿Registrar la entrega de la tarjeta '.$credentialUuid.' con esta firma?',
            ! $this->input->isInteractive(),
        ) ?? $responsible;
    }
}
