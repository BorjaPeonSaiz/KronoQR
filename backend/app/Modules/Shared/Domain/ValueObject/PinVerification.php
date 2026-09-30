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
 * ## Un solo rechazo hacia fuera; el dueño del codigo, solo hacia la base de datos
 *
 * `rejected()` no distingue «ese codigo no existe» de «ese PIN no es» en nada
 * que pueda llegar a una respuesta: `employeeUuid()` es `null` en los dos, y en
 * el bloqueo tambien. La regla dura 17 exige que no se puedan distinguir desde
 * fuera, y esa garantia vive aqui, en el tipo, y no solo en la capa HTTP.
 *
 * **Desde RN-19 el servidor si tiene el dato, pero solo para la base de datos**
 * (ADR-043). Un PIN rechazado de una persona que puede fichar —con la red caida
 * y encolado, a menudo— era una jornada que se perdia sin que nadie lo supiera.
 * El rechazo lleva ahora un {@see PinClaim} opcional con el `employee_uuid` del
 * dueño del codigo, que el fichaje escribe en `scan_events.claimed_employee_id`
 * para la revision diaria. **No es un rechazo distinto**: sigue sin
 * `employeeUuid()`, `isVerified()` sigue siendo falso y quien construye la
 * respuesta no lo lee. El portal recibe este mismo valor y no usa `claim()`.
 *
 * Lo que si se separa es `locked()`, porque quien lo recibe tiene que poder
 * contarlo y registrarlo —un bloqueo activo es una senal operativa util (§8.2)—
 * y porque el propio bloqueo no puede convertirse en un oraculo: quien llama lo
 * traduce al **mismo** rechazo generico que los otros dos antes de responder.
 *
 * ## Nunca lleva el PIN
 *
 * Ni el PIN, ni su hash, ni el codigo de empleado con el que se pregunto. Lo
 * unico que sale de aqui es un `employeeUuid`, que es el unico identificador de
 * persona admitido en un log tecnico (regla dura 21).
 */
final readonly class PinVerification
{
    private function __construct(
        private ?string $employeeUuid,
        private bool $locked,
        private int $retryAfterSeconds,
        private ?PinClaim $claim,
    ) {}

    /**
     * El PIN es el de este empleado, y el empleado puede usarlo. **Nunca lleva
     * claim**: el dueño ya es `employeeUuid()`.
     */
    public static function verified(string $employeeUuid): self
    {
        if ($employeeUuid === '') {
            throw new InvalidArgumentException('Un PIN verificado necesita el UUID del empleado.');
        }

        return new self($employeeUuid, false, 0, null);
    }

    /**
     * No se verifica: el codigo no existe, el PIN no es, no hay PIN emitido o el
     * empleado no puede fichar (RN-14). **Los cuatro son este mismo valor hacia
     * fuera.**
     *
     * @param  PinClaim|null  $claim  El dueño del codigo, solo si puede fichar (RN-19). Solo
     *                                para `scan_events`; nunca cambia la respuesta.
     */
    public static function rejected(?PinClaim $claim = null): self
    {
        return new self(null, false, 0, $claim);
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
     * @param  PinClaim|null  $claim  El dueño del codigo (RN-19); si viene, con `lockout`.
     */
    public static function locked(int $retryAfterSeconds, ?PinClaim $claim = null): self
    {
        if ($claim instanceof PinClaim && ! $claim->lockout) {
            throw new InvalidArgumentException('El claim de un intento bloqueado tiene que marcar el bloqueo.');
        }

        return new self(null, true, max(0, $retryAfterSeconds), $claim);
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
     * UUID del empleado, o `null` si no se verifico —tambien con claim—.
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

    /**
     * A quien correspondia el codigo de un PIN rechazado (RN-19, ADR-043), o
     * `null`. **Solo lo lee el fichaje del quiosco para escribir la fila**; nada
     * que construya una respuesta debe llamarlo.
     */
    public function claim(): ?PinClaim
    {
        return $this->claim;
    }
}
