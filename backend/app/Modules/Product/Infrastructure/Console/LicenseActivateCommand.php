<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Console;

use App\Modules\Product\Application\Command\ActivateLicenseCommand;
use App\Modules\Product\Application\UseCase\ActivateLicenseHandler;
use App\Modules\Product\Domain\Exception\InvalidLicenseKey;
use App\Modules\Product\Domain\Exception\LicenseKeyRejected;
use App\Modules\Product\Domain\ValueObject\LicenseState;
use App\Modules\Shared\Infrastructure\Console\SpeaksInstallationLanguage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

/**
 * `php artisan license:activate {key?}` — activa una clave de licencia
 * (Anexo C del doc 01, **RF-PD-04**, RL-04).
 *
 * ## La clave puede venir del entorno
 *
 * Sin argumento se toma `LICENSE_KEY` del entorno. **Es la unica cosa que lee
 * esa variable en todo el producto**, y es deliberado: **manda la base de
 * datos**, aqui igual que para la configuracion. La
 * variable es el valor de arranque con el que el instalador (5.4) activara la
 * primera clave llamando a este comando; en ejecucion no se consulta nunca.
 *
 * Si se consultara en ejecucion, una clave activada desde el panel no surtiria
 * efecto mientras el `.env` dijera otra cosa, y el cliente veria la clave nueva
 * guardada y la vieja aplicandose.
 *
 * ## Activar una clave caducada esta permitido
 *
 * Y el comando lo dice en su salida. Un hotel que renueva con dos semanas de
 * retraso recibe una clave cuya vigencia empezo el dia 1: rechazarla obligaria a
 * pedir otra por una diferencia de calendario.
 *
 * ## Codigos de salida
 *
 * | Codigo | Significado |
 * |---|---|
 * | `0` | Clave activada. La instalacion queda vigente o proxima a caducar. |
 * | `1` | Clave activada, pero **no vigente**: caducada o con la vigencia sin empezar. Se guardo igual. |
 * | `2` | **No se activo nada.** La clave no verifica, o falta el argumento y `LICENSE_KEY` esta vacia. La licencia anterior sigue como estaba. |
 *
 * `1` y `2` se distinguen porque la accion siguiente es distinta: con `1` la
 * clave es autentica y hay que hablar de fechas; con `2` hay que conseguir otra
 * clave. Y en ninguno de los tres el sistema deja de fichar (regla dura 15).
 *
 * ## No imprime la clave
 *
 * Ni al aceptarla ni al rechazarla. Se imprime su huella corta, que es lo que
 * sirve para confirmar por telefono cual se activo.
 */
final class LicenseActivateCommand extends Command
{
    use SpeaksInstallationLanguage;

    /** No se activo nada: la clave no verifica o no hay clave que activar. */
    private const int EXIT_NOT_ACTIVATED = 2;

    /** Seccion de `lang/*\/console.php`. */
    private const string TEXTS = 'console.license_activate';

    protected $signature = 'license:activate
        {key? : La clave firmada, entre comillas. Si se omite, se toma LICENSE_KEY del entorno}';

    protected $description = 'Verifica y activa una clave de licencia, y deja constancia en el registro de auditoria';

    public function handle(ActivateLicenseHandler $activate): int
    {
        $key = $this->keyArgument();

        if ($key === '') {
            $this->error($this->text('no_key.title'));
            $this->paragraph('no_key.advice');

            return self::EXIT_NOT_ACTIVATED;
        }

        try {
            // Sin actor: por consola no hay sesion detras y no se inventa una.
            // El asiento de `audit_log` lo refleja tal cual, que es la unica
            // forma honesta de distinguir esto de una activacion desde el panel.
            $status = $activate->handle(new ActivateLicenseCommand($key));
        } catch (LicenseKeyRejected $rejected) {
            $this->error($this->text('rejected.title'));
            $this->line('');

            $this->paragraph('rejected.'.$rejected->rejection->value);

            $this->line('');
            $this->line($this->text('rejected.clocking_unaffected'));

            return self::EXIT_NOT_ACTIVATED;
        } catch (InvalidLicenseKey $invalid) {
            // La firma cuadro y la carga util no sirve: es un fallo de emision.
            $this->error($this->text('invalid.title'));
            $this->line($this->text('invalid.detail', ['detail' => $invalid->getMessage()]));

            return self::EXIT_NOT_ACTIVATED;
        }

        $this->info($this->text('activated.title'));
        $this->line($this->text('activated.customer', ['customer' => $status->license->customerName ?? '—']));
        $this->line($this->text('activated.plan', ['plan' => $status->license->plan ?? '—']));
        $this->line($this->text('activated.state', ['state' => $status->state->value]));
        $this->line('');
        $this->line($this->text('activated.next'));

        return $this->warnUnlessActive($status->state);
    }

    /**
     * Guardada no es lo mismo que en vigor: una clave caducada o que todavia no
     * ha empezado se guarda igual, pero quien la pego tiene que saberlo.
     */
    private function warnUnlessActive(LicenseState $state): int
    {
        $warning = match ($state) {
            LicenseState::Expired => 'activated.already_expired',
            LicenseState::NotYetValid => 'activated.not_yet_valid',
            default => null,
        };

        if ($warning === null) {
            return self::SUCCESS;
        }

        foreach ($this->texts($warning) as $line) {
            $this->warn($line);
        }

        return self::FAILURE;
    }

    private function keyArgument(): string
    {
        $argument = $this->argument('key');

        if (\is_string($argument) && trim($argument) !== '') {
            return trim($argument);
        }

        // El unico sitio del producto que lee `LICENSE_KEY`. Ver el docblock.
        return trim(Config::string('license.bootstrap_key', ''));
    }
}
