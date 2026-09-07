<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Infrastructure\Console;

use App\Modules\Kiosk\Application\Command\ConfirmPairingCommand;
use App\Modules\Kiosk\Application\UseCase\ConfirmPairing;
use App\Modules\Kiosk\Domain\Exception\KioskDomainException;
use App\Modules\Kiosk\Domain\ValueObject\ConfirmOutcome;
use App\Modules\Kiosk\Domain\ValueObject\DeviceName;
use App\Modules\Kiosk\Domain\ValueObject\PairingCode;
use App\Modules\Shared\Domain\Exception\InstallationSiteMissing;
use Illuminate\Console\Command;

/**
 * `kiosk:pairing-code {code} --name=` — **confirma** un codigo mostrado en la
 * tablet (**RF-PD-06**, doc 02 Anexo C).
 *
 * ## CONFIRMA, no genera. Y el nombre del comando se conserva
 *
 * El Anexo C lo describia como «genera el codigo en el servidor», que era la
 * lectura del flujo antes de escribirlo (contradiccion C-3, cerrada el 07-09-2026
 * en la ficha de la tarea 5.6). El codigo lo pide **la tablet**, que es quien
 * tiene que enseñarlo: un codigo generado en el servidor habria que llevarlo hasta
 * la tablet de alguna manera, y esa manera no existe.
 *
 * El nombre del comando se mantiene porque es el que esta escrito en el Anexo C y
 * en la documentacion del cliente. Lo que cambia es su argumento y su docstring.
 *
 * ## Es la via ALTERNATIVA, no la principal
 *
 * El flujo normal es el panel. Esto existe para cuando el panel no esta accesible
 * —un despliegue a medias, una red interna caida— y es lo que permite que RF-PD-06
 * siga diciendo que el cliente **no tiene por que usar SSH**: la consola es
 * opcional, no obligatoria.
 *
 * ## Actor `system`, y es la respuesta honesta
 *
 * Un comando de consola no tiene sesion. Atribuirle el alta a la ultima persona
 * que entro al panel seria falsificar el trail (regla dura 6), y el catalogo de
 * `AuditActorType` contempla `system` justo para esto. Quien audite vera «se dio
 * de alta un quiosco desde consola», que es exactamente lo que paso.
 *
 * ## Sin argumento de centro
 *
 * ADR-040: hay uno por instalacion y lo resuelve el servidor. El comando anterior
 * llevaba `{site}` y ya no tiene sentido.
 */
final class PairingCodeCommand extends Command
{
    protected $signature = 'kiosk:pairing-code
        {code : Los seis digitos que muestra la tablet}
        {--name= : Nombre del quiosco, por ejemplo "Recepcion"}';

    protected $description = 'Confirma el codigo de emparejamiento que muestra una tablet y la vincula como quiosco.';

    public function handle(ConfirmPairing $pairing): int
    {
        // El argumento es obligatorio en la firma, asi que Symfony ya garantiza
        // que llega como cadena. La opcion no: sin `--name` vale `null`, y este
        // comando la exige porque un quiosco sin nombre no se identifica despues
        // en el panel de salud ni en el runbook.
        $rawCode = (string) $this->argument('code');
        $rawName = $this->option('name');

        if (! is_string($rawName) || trim($rawName) === '') {
            $this->error('Falta --name con el nombre del quiosco, por ejemplo --name="Recepcion".');

            return self::INVALID;
        }

        try {
            $command = new ConfirmPairingCommand(
                code: PairingCode::of($rawCode),
                name: DeviceName::of($rawName),
                // Ver el docblock: sin sesion, actor `system`.
                actorUserId: null,
            );
        } catch (KioskDomainException $invalid) {
            $this->error($invalid->getMessage());

            return self::INVALID;
        }

        try {
            $confirmation = $pairing->handle($command);
        } catch (InstallationSiteMissing) {
            $this->error('La instalacion todavia no tiene centro: completa antes el asistente de puesta en marcha.');

            return self::FAILURE;
        }

        if ($confirmation->nameTaken) {
            $this->error('Ya hay un quiosco activo con ese nombre. Elige otro o desvincula el anterior.');

            return self::FAILURE;
        }

        if ($confirmation->outcome === ConfirmOutcome::Rejected || $confirmation->device === null) {
            // El mismo mensaje unico que da la API. Ni aqui se distingue si el
            // codigo no existe, ha caducado o ya se uso: la accion siguiente es
            // la misma —pedirle otro codigo a la tablet— y la tablet ya enseña su
            // cuenta atras.
            $this->error('El codigo no se ha podido confirmar. Pide otro codigo en la tablet e intentalo de nuevo.');

            return self::FAILURE;
        }

        $device = $confirmation->device;

        $this->info(sprintf(
            '%s el quiosco «%s» (%s).',
            $device->reactivated ? 'Reactivado' : 'Vinculado',
            $device->name,
            $device->uuid,
        ));

        // Lo que hay que mirar despues, porque desde la consola no se ve: el
        // token lo recoge la tablet sola en su siguiente sondeo, y hasta que lo
        // haga el quiosco no puede fichar.
        $this->line('La tablet recogera su token en unos segundos. Comprueba con: php artisan kiosk:health');

        return self::SUCCESS;
    }
}
