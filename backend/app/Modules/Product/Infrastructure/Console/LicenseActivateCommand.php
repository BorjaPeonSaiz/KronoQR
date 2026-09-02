<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Console;

use App\Modules\Product\Application\Command\ActivateLicenseCommand;
use App\Modules\Product\Application\UseCase\ActivateLicenseHandler;
use App\Modules\Product\Domain\Exception\InvalidLicenseKey;
use App\Modules\Product\Domain\Exception\LicenseKeyRejected;
use App\Modules\Product\Domain\ValueObject\LicenseState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

/**
 * `php artisan license:activate {key?}` — activa una clave de licencia
 * (Anexo C del doc 01, **RF-PD-04**, RL-04).
 *
 * ## La clave puede venir del entorno
 *
 * Sin argumento se toma `LICENSE_KEY` del entorno. **Es la unica cosa que lee
 * esa variable en todo el producto**, y es deliberado: la decision de la tarea
 * 5.1 —**manda la base de datos**— vale aqui igual que para la configuracion. La
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
    /** No se activo nada: la clave no verifica o no hay clave que activar. */
    private const int EXIT_NOT_ACTIVATED = 2;

    protected $signature = 'license:activate
        {key? : La clave firmada, entre comillas. Si se omite, se toma LICENSE_KEY del entorno}';

    protected $description = 'Verifica y activa una clave de licencia, y deja constancia en el registro de auditoria';

    public function handle(ActivateLicenseHandler $activate): int
    {
        $key = $this->keyArgument();

        if ($key === '') {
            $this->error('No has indicado ninguna clave y LICENSE_KEY esta vacia en el entorno.');
            $this->line('Uso:  php artisan license:activate "KQL1...."');
            $this->line('La clave te la entrega el proveedor: es una cadena que empieza por KQL1.');

            return self::EXIT_NOT_ACTIVATED;
        }

        try {
            // Sin actor: por consola no hay sesion detras y no se inventa una.
            // El asiento de `audit_log` lo refleja tal cual, que es la unica
            // forma honesta de distinguir esto de una activacion desde el panel.
            $status = $activate->handle(new ActivateLicenseCommand($key));
        } catch (LicenseKeyRejected $rejected) {
            $this->error('La clave NO se ha activado. La licencia anterior sigue como estaba.');
            $this->line('');

            foreach (self::adviceFor($rejected->rejection->value) as $line) {
                $this->line($line);
            }

            $this->line('');
            $this->line('Nada de esto afecta al fichaje ni al acceso al registro: siguen funcionando.');

            return self::EXIT_NOT_ACTIVATED;
        } catch (InvalidLicenseKey $invalid) {
            // La firma cuadro y la carga util no sirve: es un fallo de emision.
            $this->error('La clave esta firmada pero le falta informacion. Es un fallo de emision.');
            $this->line('Detalle tecnico para el proveedor: '.$invalid->getMessage());

            return self::EXIT_NOT_ACTIVATED;
        }

        $this->info('Licencia activada.');
        $this->line('Cliente: '.($status->license->customerName ?? '—'));
        $this->line('Plan:    '.($status->license->plan ?? '—'));
        $this->line('Estado:  '.$status->state->value);
        $this->line('');
        $this->line('Ejecuta  php artisan license:show  para ver el detalle completo.');

        if ($status->state === LicenseState::Expired) {
            $this->warn('Ojo: esta clave YA ESTA CADUCADA. Se ha guardado igualmente y consta en el');
            $this->warn('registro de auditoria. Las funcionalidades accesorias siguen degradadas.');

            return self::FAILURE;
        }

        if ($status->state === LicenseState::NotYetValid) {
            $this->warn('Ojo: la vigencia de esta clave empieza mas adelante. Se ha guardado y se');
            $this->warn('activara sola ese dia.');

            return self::FAILURE;
        }

        return self::SUCCESS;
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

    /**
     * @return list<string>
     */
    private static function adviceFor(string $rejection): array
    {
        return match ($rejection) {
            'malformed' => [
                'La clave esta incompleta o cortada. Suele pasar al copiarla de un correo.',
                'Copiala entera: empieza por KQL1. y no lleva espacios ni saltos de linea.',
            ],
            'bad_signature' => [
                'La firma no cuadra: la clave no la emitio el fabricante de esta version, o se ha',
                'modificado. Pide una clave nueva al proveedor.',
            ],
            'invalid_payload' => [
                'La clave esta firmada pero su contenido no sirve. Es un fallo de emision:',
                'avisa al proveedor y pide una clave nueva.',
            ],
            'no_public_key' => [
                'Esta instalacion no lleva la clave publica del fabricante, asi que no puede',
                'verificar ninguna licencia. No es un problema de tu clave, es del despliegue:',
                'avisa al proveedor indicando la version que devuelve GET /api/v1/health.',
            ],
            default => ['Avisa al proveedor: la clave no se ha podido verificar.'],
        };
    }
}
