<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Port;

use App\Modules\Workforce\Domain\Exception\PinAlreadyDelivered;
use App\Modules\Workforce\Domain\Exception\PinNotIssued;
use DateTimeImmutable;

/**
 * El PIN de cada empleado, visto por los casos de uso (RF-ID-09).
 *
 * **El PIN entra por aqui y no vuelve a salir.** `issue()` lo recibe en claro y
 * lo convierte en hash con el algoritmo de contrasenas de la instalacion; no hay
 * ningun metodo que lo devuelva, ni que devuelva su hash, porque no existe
 * ninguna razon legitima para leerlos. Es el mismo trato que
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
     * @param  string  $pin  El PIN en claro. Se hashea aqui y no se almacena (RF-ID-09).
     * @return bool `false` si el empleado no existe. Quien llama lo traduce a 404.
     */
    public function issue(string $employeeUuid, string $pin, DateTimeImmutable $issuedAt): bool;

    /**
     * Anota la entrega presencial: cuando y quien la hizo.
     *
     * @param  string  $deliveredByUserUuid  UUID publico de la cuenta de gestion que entrego. El puerto
     *                                       habla en identificadores publicos y escalares (ADR-025,
     *                                       restriccion 2): la clave interna es cosa del adaptador.
     * @return PinDeliveryRecord|null `null` si el empleado no existe.
     *
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
