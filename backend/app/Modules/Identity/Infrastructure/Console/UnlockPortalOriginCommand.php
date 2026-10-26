<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Console;

use App\Modules\Identity\Application\UseCase\UnlockPortalOrigin;
use App\Modules\Identity\Domain\ValueObject\RequestOrigin;
use App\Modules\Shared\Infrastructure\Console\SpeaksInstallationLanguage;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * `identity:origin-unlock <ip>` — levanta el bloqueo por origen del portal
 * (ADR-050 §2).
 *
 * Para el caso del residuo 2: una red compartida —el NAT del hotel, un CGNAT—
 * que se ha quedado sin portal una hora por fallos de otros. Con una IPv6 se
 * levanta su `/64` entero, que es lo que se bloqueo.
 *
 * Deja el asiento `auth.origin_unlocked` con el `ip_hash`, nunca la IP en claro,
 * y con actor `system`, igual que los demas `identity:*`: un comando de consola
 * no tiene sesion detras y atribuirlo a una persona falsearia el trail. **La
 * salida tampoco repite la direccion**: la terminal acaba en el historial del
 * shell y en capturas de soporte.
 */
final class UnlockPortalOriginCommand extends Command
{
    use SpeaksInstallationLanguage;

    /** Seccion de `lang/*\/console.php`, compartida por las ordenes `identity:*` que no tocan cuentas. */
    private const string TEXTS = 'console.identity';

    protected $signature = 'identity:origin-unlock
        {ip : Direccion IPv4 o IPv6 bloqueada (de una IPv6 se levanta su /64)}';

    protected $description = 'Levanta el bloqueo por origen del acceso al portal (RS-12, ADR-050).';

    public function handle(UnlockPortalOrigin $handler): int
    {
        try {
            $origin = RequestOrigin::of((string) $this->argument('ip'));
        } catch (InvalidArgumentException) {
            $this->components->error($this->text('origin_unlock.invalid'));

            return self::INVALID;
        }

        if ($handler->handle($origin)) {
            $this->components->info($this->text('origin_unlock.unlocked'));
        } else {
            $this->components->info($this->text('origin_unlock.nothing'));
        }

        return self::SUCCESS;
    }
}
