<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Desenlace de comprobar un PIN: o hay un empleado detras, o no lo hay, o el
 * bloqueo por intentos esta activo. Nunca dos cosas y nunca ninguna.
 *
 * Es lo que devuelve `Shared\Application\Port\EmployeePinVerifier`, y vive en
 * `Shared\Domain` por lo mismo que {@see CredentialResolution}: cruza la
 * frontera entre el modulo que tiene el dato —`Workforce`, dueno de
 * `employees.pin_hash`— y los que preguntan —el fichaje de respaldo del quiosco
 * (RF-AT-11) y el portal del empleado (RF-ID-06)—, y ninguno de los tres puede
 * importar nada de los otros (doc 02 §1.6).
 *
 * ## Dos rechazos distintos hacia dentro, uno solo hacia fuera
 *
 * `rejected()` no distingue «ese codigo no existe» de «ese PIN no es»: son la
 * misma respuesta ya aqui, en el tipo, y no solo en la capa HTTP. La regla dura
 * 17 exige que no se puedan distinguir desde fuera, y la forma barata de
 * garantizarlo es que **el servidor tampoco tenga el dato a mano** en el camino
 * que produce la respuesta. Lo que si se separa es `locked()`, porque quien lo
 * recibe tiene que poder contarlo y registrarlo —un bloqueo activo es una senal
 * operativa util (§8.2)— y porque el propio bloqueo no puede convertirse en un
 * oraculo: quien llama lo traduce al **mismo** rechazo generico que los otros
 * dos antes de responder.
 *
 * ## Nunca lleva el PIN
 *
 * Ni el PIN, ni su hash, ni el codigo de empleado con el que se pregunto. Lo
 * unico que sale de aqui cuando se acierta es el `employeeUuid`, que es el unico
 * identificador de persona admitido en un log tecnico (regla dura 21).
 */
final readonly class PinVerification
{
    private function __construct(
        private ?string $employeeUuid,
        private bool $locked,
        private int $retryAfterSeconds,
    ) {}

    /**
     * El PIN es el de este empleado, y el empleado puede usarlo.
     */
    public static function verified(string $employeeUuid): self
    {
        if ($employeeUuid === '') {
            throw new InvalidArgumentException('Un PIN verificado necesita el UUID del empleado.');
        }

        return new self($employeeUuid, false, 0);
    }

    /**
     * No se verifica: el codigo no existe, el PIN no es, no hay PIN emitido o el
     * empleado no puede fichar (RN-14). **Los cuatro son este mismo valor.**
     */
    public static function rejected(): self
    {
        return new self(null, false, 0);
    }

    /**
     * El bloqueo por intentos esta activo, asi que el PIN **ni se ha
     * comprobado** (RS-12).
     *
     * Comprobarlo de todos modos convertiria el bloqueo en un oraculo: bastaria
     * con medir si el bloqueo llega antes o despues de la comparacion del hash
     * para saber si el PIN probado era el bueno.
     *
     * @param  int  $retryAfterSeconds  Lo que falta para el desbloqueo. **No sale por la
     *                                  API**: es para el log y la metrica del servidor.
     */
    public static function locked(int $retryAfterSeconds): self
    {
        return new self(null, true, max(0, $retryAfterSeconds));
    }

    public function isVerified(): bool
    {
        return $this->employeeUuid !== null;
    }

    public function isLocked(): bool
    {
        return $this->locked;
    }

    /**
     * UUID del empleado, o `null` si no se verifico.
     *
     * Devuelve `?string` en lugar de lanzar para que quien llama tenga que
     * estrechar el tipo: con PHPStan 9, olvidarse del rechazo no compila.
     */
    public function employeeUuid(): ?string
    {
        return $this->employeeUuid;
    }

    public function retryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }
}
