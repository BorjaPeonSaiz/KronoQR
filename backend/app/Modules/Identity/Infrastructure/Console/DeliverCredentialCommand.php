<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Console;

use App\Modules\Identity\Application\Command\DeliverCredentialCommand as DeliverCredential;
use App\Modules\Identity\Application\UseCase\DeliverCredential as DeliverCredentialHandler;
use App\Modules\Identity\Domain\Exception\IdentityDomainException;
use App\Modules\Identity\Infrastructure\Persistence\User;
use Illuminate\Console\Command;

/**
 * `php artisan credentials:deliver {credential} --by=` — registra que la tarjeta
 * llego a manos de su titular (Anexo C del doc 02, RF-QR-06).
 *
 * **`--by` es obligatorio y no tiene valor por defecto.** RF-QR-06 pide «fecha y
 * responsable», y responsable significa una persona con nombre en `users`, no «el
 * sistema». La columna es `NOT NULL` cuando hay entrega —CHECK
 * `credentials_chk_delivery_is_signed`— precisamente para que no exista el camino
 * de escribir una entrega sin responsable. Sin este dato, el registro no sirve
 * para lo unico para lo que existe: distinguir *«se perdio antes de darsela»* de
 * *«la perdio el empleado»* (doc 02 §5.5).
 *
 * **El actor de la auditoria sigue siendo el sistema.** Una consola no tiene
 * sesion, y atribuirle la accion a la persona de `--by` seria decir que ella
 * ejecuto el comando, cosa que nadie sabe. En `audit_log` queda `system` como
 * actor y el responsable, dentro del asiento. Son dos hechos distintos y se
 * escriben como tales.
 *
 * **No es idempotente.** Marcar dos veces la misma entrega falla: sobrescribirla
 * cambiaria el responsable y el momento que ya constan en la auditoria.
 */
final class DeliverCredentialCommand extends Command
{
    protected $signature = 'credentials:deliver
        {credential : UUID de la credencial}
        {--by= : Correo del usuario de gestion que responde de la entrega. Obligatorio}';

    protected $description = 'Registra la entrega de una tarjeta, con fecha y responsable (RF-QR-06).';

    public function handle(DeliverCredentialHandler $handler): int
    {
        $responsibleEmail = $this->option('by');

        $credentialUuid = trim($this->argument('credential'));
        $by = \is_string($responsibleEmail) ? mb_strtolower(trim($responsibleEmail)) : '';

        if ($credentialUuid === '') {
            $this->error('Hace falta el UUID de la credencial.');

            return self::INVALID;
        }

        if ($by === '') {
            $this->error('Una entrega tiene que declarar su responsable: --by=persona@ejemplo.es');

            return self::INVALID;
        }

        $responsible = User::query()->where('email', $by)->first();

        if (! $responsible instanceof User) {
            $this->error('No hay ningun usuario de gestion con el correo «'.$by.'».');

            return self::FAILURE;
        }

        try {
            $delivered = $handler->handle(new DeliverCredential(
                credentialUuid: $credentialUuid,
                deliveredByUserId: $responsible->id,
                // Sin actor: la consola no tiene sesion. El asiento dira
                // `system`, que es lo honesto.
                actorUserId: null,
            ));
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
        $this->line('Momento: '.($delivered->credential->deliveredAt?->format('Y-m-d\TH:i:s\Z') ?? '—'));
        $this->line('Recuerda entregar tambien el PIN del portal y la hoja de instrucciones: docs/runbooks/alta-nuevo-empleado.md');

        return self::SUCCESS;
    }
}
