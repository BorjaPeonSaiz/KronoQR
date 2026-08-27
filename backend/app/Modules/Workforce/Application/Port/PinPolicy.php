<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Port;

use App\Modules\Shared\Domain\ValueObject\OperationalSettings;
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
 * **La longitud no es configurable y por eso es una constante.** Son seis
 * digitos porque lo dice RF-ID-09 y porque el contrato los fija
 * (`IssuedPin.pin`, `^[0-9]{6}$`): una instalacion que emitiera PIN de cinco
 * emitiria PIN que su propio cliente TypeScript rechaza.
 */
final readonly class PinPolicy
{
    /**
     * RF-ID-09 y el contrato. No es configuracion: es la forma del dato.
     */
    public const int LENGTH = 6;

    /**
     * @param  list<string>  $forbidden  PIN que el generador nunca emite.
     */
    public function __construct(public array $forbidden)
    {
        foreach ($this->forbidden as $pin) {
            if (preg_match('/^[0-9]{'.self::LENGTH.'}$/', $pin) !== 1) {
                // Un patron excluido que no puede generarse nunca es, casi
                // siempre, un error de tecleo en la configuracion: quien lo
                // escribio cree haber excluido algo y no ha excluido nada.
                throw new InvalidArgumentException(
                    'La lista de PIN excluidos solo admite valores de '.self::LENGTH.' digitos; llego «'.$pin.'».'
                );
            }
        }
    }

    public function forbids(string $pin): bool
    {
        return \in_array($pin, $this->forbidden, true);
    }
}
