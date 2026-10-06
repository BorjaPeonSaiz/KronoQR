<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\PinAttempts;
use App\Modules\Shared\Application\Port\SerializedLedgerWrite;
use App\Modules\Shared\Domain\ValueObject\PinLength;
use App\Modules\Workforce\Application\Command\IssueEmployeePinCommand;
use App\Modules\Workforce\Application\Pin\PinGenerator;
use App\Modules\Workforce\Application\Port\EmployeePinRepository;
use App\Modules\Workforce\Application\Port\PinHasher;
use App\Modules\Workforce\Application\Port\PinMaterial;
use App\Modules\Workforce\Application\Port\PinStatus;
use App\Modules\Workforce\Application\Port\WorkforceEventPublisher;
use App\Modules\Workforce\Domain\Event\EmployeePinIssued;
use Random\RandomException;

/**
 * Emite el PIN de una persona (RF-ID-09).
 *
 * Es un paso de otra cosa: del alta individual (RF-GP-01, tarea 1.6) y del
 * restablecimiento. En el alta individual corre dentro de la transaccion que la
 * abre, que es lo que impide que de ella salga alguien sin PIN: si la emision
 * falla, el alta no se confirma. La importacion masiva no pasa por aqui: deja
 * el PIN pendiente a proposito (RF-GP-05), y la primera emision de ese
 * pendiente llega despues por el restablecimiento, al entregar la tarjeta.
 *
 * **`pin.issued` o `pin.reset` lo decide la ficha, no quien llama.** Pedir un
 * restablecimiento sobre una ficha sin PIN es su primera emision, y el asiento
 * tiene que decirlo: un `pin.reset` de un PIN que nunca existio falsearia el
 * trail, y cada persona importada inflaria `pin_resets_total`. El estado se lee
 * **dentro** de la cadena porque la cadena serializa a todos los escritores de
 * la ficha: dos «Emitir» simultaneos sobre un pendiente dan un `pin.issued` y un
 * `pin.reset`, que es exactamente lo que paso.
 *
 * **Bajo el candado de la cadena** (ADR-046 §1, tabla de §1.2). La escritura de
 * la ficha y el asiento van dentro de `withChainLock()`, en el orden unico
 * cadena → `employees`: hasta la 2.2.0 se escribia la fila y despues el asiento,
 * al reves que la baja, y una baja y un restablecimiento simultaneos podian
 * cerrar un ciclo. Dentro del alta el candado es reentrante: la transaccion es
 * la suya.
 *
 * **El hash NO se calcula aqui** (A-3). bcrypt cuesta unos 160 ms en
 * produccion y aqui dentro correria con la cadena tomada, congelando los
 * fichajes del hotel. Llega ya calculado en el comando; quien llama lo obtiene
 * con {@see self::freshMaterial()} antes de abrir nada.
 *
 * **Restablecer desbloquea.** El contador de intentos fallidos (RS-12) se limpia
 * aqui y no en el llamante: un PIN nuevo con el bloqueo del anterior todavia
 * activo obligaria a esperar quince minutos delante del quiosco a alguien que
 * acaba de pedir ayuda porque no podia fichar. Se limpia **despues** de soltar
 * la cadena —tras el commit en el restablecimiento—, porque el candado de cache
 * del contador puede esperar y la cadena la toma cada fichaje. En el alta corre
 * aun dentro de la transaccion del alta, pero el empleado es nuevo y nadie
 * compite por su candado. Un fallo contra el PIN anterior anotado entre el
 * commit y la limpieza se borra con ella, que es lo que se quiere.
 *
 * **El PIN sale por el valor de retorno y por ningun otro sitio.** No entra en
 * el evento —que acaba en `audit_log` (regla dura 21)—, ni en el log, ni en la
 * metrica.
 */
final readonly class IssueEmployeePinHandler
{
    public function __construct(
        private EmployeePinRepository $pins,
        private PinGenerator $generator,
        private PinHasher $hasher,
        private PinAttempts $attempts,
        private WorkforceEventPublisher $events,
        private Clock $clock,
        private SerializedLedgerWrite $serialized,
    ) {}

    /**
     * Un PIN nuevo y su hash. **Se llama fuera de toda transaccion y antes de la
     * cadena** (A-3): es el trabajo caro de la emision.
     *
     * @throws RandomException si el sistema no puede dar aleatoriedad
     */
    public function freshMaterial(): PinMaterial
    {
        return $this->hasher->hash($this->generator->generate());
    }

    /**
     * @return IssuedPin|null `null` si el empleado no existe: quien llama lo traduce a 404.
     */
    public function handle(IssueEmployeePinCommand $command): ?IssuedPin
    {
        $issued = $this->serialized->withChainLock(function () use ($command): ?IssuedPin {
            $issuedAt = $this->clock->now();

            // La longitud sale del PIN que se emite y no del ajuste: es la que
            // tiene ESTE PIN, aunque el ajuste haya cambiado entre el calculo del
            // hash y esta escritura (ADR-050).
            $length = PinLength::from(\strlen($command->material->pin));

            // Antes de escribir y con la cadena tomada: si la ficha estaba
            // pendiente, esto es su primera emision aunque se pidiera como
            // restablecimiento. `null` es una ficha que no existe, y entonces
            // `issue()` devuelve `false` justo debajo.
            $replacedPrevious = $command->reset
                && $this->pins->statusFor($command->employeeUuid) !== PinStatus::Pending;

            if (! $this->pins->issue($command->employeeUuid, $command->material->hash, $length, $issuedAt)) {
                return null;
            }

            // Dentro de la transaccion: el listener de auditoria es sincrono, asi
            // que si el asiento falla la emision no se confirma (ADR-027, regla
            // dura 6). Un PIN emitido sin traza es peor que uno no emitido,
            // porque el segundo se repite y el primero no se descubre.
            $this->events->publish(new EmployeePinIssued(
                employeeUuid: $command->employeeUuid,
                siteId: $command->siteId,
                reset: $replacedPrevious,
                occurredAt: $issuedAt,
            ));

            return new IssuedPin(
                employeeUuid: $command->employeeUuid,
                pin: $command->material->pin,
                issuedAt: $issuedAt,
                replacedPrevious: $replacedPrevious,
            );
        });

        // FUERA del candado de la cadena: `clear()` toma el candado de cache de
        // cada puerta y, con contienda, puede esperar hasta medio segundo por
        // puerta. Dentro de `withChainLock()` esa espera congelaria todos los
        // fichajes del hotel (ADR-046).
        if ($issued instanceof IssuedPin) {
            $this->attempts->clear($command->employeeUuid);
        }

        return $issued;
    }
}
