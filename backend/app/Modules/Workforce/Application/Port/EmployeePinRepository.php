<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Port;

use App\Modules\Shared\Domain\ValueObject\PinLength;
use App\Modules\Workforce\Domain\Exception\EmployeeAlreadyTerminated;
use App\Modules\Workforce\Domain\Exception\PinAlreadyDelivered;
use App\Modules\Workforce\Domain\Exception\PinNotIssued;
use DateTimeImmutable;

/**
 * El PIN de cada empleado, visto por los casos de uso (RF-ID-09).
 *
 * **El PIN no entra por aqui: entra ya hasheado.** `issue()` recibe el hash que
 * calculo {@see PinHasher} y lo escribe; no hay ningun metodo que devuelva el
 * PIN, ni su hash, porque no existe ninguna razon legitima para leerlos. El PIN
 * en claro no llega a cruzar esta frontera. Es el mismo trato que
 * {@see EmployeeRepository} da al documento de identidad (RL-08): lo que no se
 * puede leer no se puede filtrar.
 *
 * Lo que si se puede leer es el **estado** —emitido, entregado o pendiente—,
 * porque el panel tiene que saber a quien le falta recibirlo (RF-ID-09) y eso no
 * exige conocer ningun PIN.
 */
interface EmployeePinRepository
{
    /**
     * Fija el PIN del empleado y anota el instante de emision.
     *
     * Sustituye el hash anterior, que con eso queda invalidado —no hay
     * «desactivar»: la unica copia era esa— y **borra la entrega anterior**: un
     * PIN nuevo no esta entregado por el hecho de que lo estuviera el que
     * sustituye.
     *
     * **No escribe sobre una persona dada de baja** (RN-14, ADR-046): lleva el
     * mismo predicado `status <> 'terminated'` que las escrituras de la ficha.
     *
     * @param  string  $pinHash  El hash ya calculado por {@see PinHasher}. El PIN en claro
     *                           no se almacena ni pasa por este puerto (RF-ID-09).
     * @param  PinLength  $pinLength  Con cuantas cifras se emitio (ADR-050). Se escribe en la
     *                                misma sentencia que el hash y no sale por la API.
     * @return bool `false` si el empleado no existe. Quien llama lo traduce a 404.
     *
     * @throws EmployeeAlreadyTerminated si la persona esta de baja
     */
    public function issue(string $employeeUuid, string $pinHash, PinLength $pinLength, DateTimeImmutable $issuedAt): bool;

    /**
     * Anota la entrega presencial: cuando y quien la hizo.
     *
     * @param  string  $deliveredByUserUuid  UUID publico de la cuenta de gestion que entrego. El puerto
     *                                       habla en identificadores publicos y escalares (ADR-025,
     *                                       restriccion 2): la clave interna es cosa del adaptador.
     * @return PinDeliveryRecord|null `null` si el empleado no existe.
     *
     * @throws EmployeeAlreadyTerminated si la persona esta de baja (RN-14): no se entrega nada a quien ya no trabaja
     * @throws PinNotIssued cuando no hay PIN que entregar
     * @throws PinAlreadyDelivered cuando ya consta entregado
     */
    public function recordDelivery(
        string $employeeUuid,
        string $deliveredByUserUuid,
        DateTimeImmutable $deliveredAt,
    ): ?PinDeliveryRecord;

    public function statusFor(string $employeeUuid): ?PinStatus;

    /**
     * Estado del PIN de varios empleados, para pintar un listado sin una
     * consulta por fila.
     *
     * @param  list<string>  $employeeUuids
     * @return array<string, PinStatus> Indexado por UUID. Sin entrada para los que no existen.
     */
    public function statusesFor(array $employeeUuids): array;
}
