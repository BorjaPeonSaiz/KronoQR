<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\PinAttempts;
use App\Modules\Shared\Application\Port\SerializedLedgerWrite;
use App\Modules\Workforce\Application\Command\IssueEmployeePinCommand;
use App\Modules\Workforce\Application\Pin\PinGenerator;
use App\Modules\Workforce\Application\Port\EmployeePinRepository;
use App\Modules\Workforce\Application\Port\PinHasher;
use App\Modules\Workforce\Application\Port\PinMaterial;
use App\Modules\Workforce\Application\Port\WorkforceEventPublisher;
use App\Modules\Workforce\Domain\Event\EmployeePinIssued;
use Random\RandomException;

/**
 * Emite el PIN de una persona (RF-ID-09).
 *
 * Es un paso de otra cosa: del alta (RF-GP-01, tarea 1.6) y del
 * restablecimiento. En el alta corre dentro de la transaccion que la abre, que
 * es lo que hace que un empleado sin PIN no pueda existir: si la emision falla,
 * el alta no se confirma.
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
 * acaba de pedir ayuda porque no podia fichar. Se limpia **dentro** de la
 * transaccion aunque la cache no sea transaccional: si la escritura se revierte,
 * lo unico perdido es un contador de intentos, y equivocarse hacia el lado de
 * dejar entrar a quien ya se habia identificado es el error barato.
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
        return $this->serialized->withChainLock(function () use ($command): ?IssuedPin {
            $issuedAt = $this->clock->now();

            if (! $this->pins->issue($command->employeeUuid, $command->material->hash, $issuedAt)) {
                return null;
            }

            $this->attempts->clear($command->employeeUuid);

            // Dentro de la transaccion: el listener de auditoria es sincrono, asi
            // que si el asiento falla la emision no se confirma (ADR-027, regla
            // dura 6). Un PIN emitido sin traza es peor que uno no emitido,
            // porque el segundo se repite y el primero no se descubre.
            $this->events->publish(new EmployeePinIssued(
                employeeUuid: $command->employeeUuid,
                siteId: $command->siteId,
                reset: $command->reset,
                occurredAt: $issuedAt,
            ));

            return new IssuedPin(
                employeeUuid: $command->employeeUuid,
                pin: $command->material->pin,
                issuedAt: $issuedAt,
            );
        });
    }
}
