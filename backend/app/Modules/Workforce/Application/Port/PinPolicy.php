<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Port;

use App\Modules\Shared\Domain\ValueObject\OperationalSettings;
use App\Modules\Shared\Domain\ValueObject\PinLength;
use InvalidArgumentException;

/**
 * La politica de PIN de esta instalacion, ya resuelta (RF-ID-09).
 *
 * **Ningun valor por defecto vive aqui** (misma razon que
 * {@see OperationalSettings}, regla dura
 * 13 y ADR-017): la lista de PIN excluidos es configuracion del cliente y llega
 * por {@see PinPolicyProvider}. Si estuviera escrita en el codigo, endurecerla
 * para un hotel obligaria a tocar el repositorio.
 *
 * **La longitud tampoco vive aqui** (ADR-050): es el ajuste auditado
 * `IDENTITY_PIN_LENGTH` y llega por `Shared\Application\Port\PinLengthProvider`.
 * Por eso la lista admite entradas de las dos longitudes posibles, 6 y 8, y el
 * generador solo descarta las de la longitud con la que emite: cambiar el
 * ajuste no obliga a reescribir la lista.
 */
final readonly class PinPolicy
{
    /**
     * @param  list<string>  $forbidden  PIN que el generador nunca emite, de 6 o de 8 cifras.
     */
    public function __construct(public array $forbidden)
    {
        foreach ($this->forbidden as $pin) {
            if (! self::hasAnAdmissibleLength($pin)) {
                // Un patron excluido que no puede generarse nunca es, casi
                // siempre, un error de tecleo en la configuracion: quien lo
                // escribio cree haber excluido algo y no ha excluido nada.
                throw new InvalidArgumentException(
                    'La lista de PIN excluidos solo admite valores de 6 u 8 digitos; llego «'.$pin.'».'
                );
            }
        }
    }

    public function forbids(string $pin): bool
    {
        return \in_array($pin, $this->forbidden, true);
    }

    private static function hasAnAdmissibleLength(string $pin): bool
    {
        foreach (PinLength::cases() as $length) {
            if ($length->fits($pin)) {
                return true;
            }
        }

        return false;
    }
}
