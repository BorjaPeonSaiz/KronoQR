<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Application\Port;

use App\Modules\Kiosk\Domain\ValueObject\DeviceSummary;
use App\Modules\Kiosk\Domain\ValueObject\ProvisionedDevice;
use DateTimeImmutable;

/**
 * El alta, la lista y la consulta de los quioscos de la instalacion
 * (**RF-PD-06**, RF-PA-07, doc 01 §5.5).
 *
 * ## Por que aqui y no en `Identity`
 *
 * Porque `devices` es de este modulo: el doc 01 §5.5 lo dice con todas las letras
 * —«`Device` es raiz de agregado en `Kiosk`, y `Identity` emite y revoca su
 * token»—. Este puerto cubre la fila; el token es de `Identity` y se pide por su
 * caso de uso publico. Son dos responsabilidades sobre una fila, no dos dueños.
 *
 * Es hermano de {@see DeviceFleet}, que cubre la telemetria, y se mantiene aparte
 * a proposito: aquel lo usa un endpoint que se llama cada minuto por cada tablet
 * y este lo usan el alta y el panel. Juntarlos habria dado una interfaz que casi
 * nadie implementa entera.
 *
 * ## `provision()` crea o REACTIVA, y esa es la decision
 *
 * Confirmar un codigo con el nombre de un quiosco **revocado** reactiva su fila,
 * con el mismo `uuid` (ADR-028). La fila es «el quiosco de Recepcion» y sobrevive
 * a la tablet que lo atiende: es como se sustituye un aparato averiado sin partir
 * en dos la historia del puesto, y ademas nada se borra (regla dura 5). Si el
 * nombre lo tiene un quiosco **activo**, `provision()` devuelve `null` y el borde
 * responde un `422` colgado del campo `name`.
 *
 * ## Habla en escalares y en DTO propios
 *
 * Nunca en modelos Eloquent (ADR-025, restriccion 2).
 */
interface DeviceRegistry
{
    /**
     * Da de alta el quiosco, o reactiva el que ya llevaba ese nombre.
     *
     * **Deja la fila `active` antes de que nadie pida su token**: `IssueDeviceToken`
     * devuelve `null` para un dispositivo que no lo este, asi que hacerlo al reves
     * produciria una tablet confirmada que no puede recoger nada.
     *
     * @return ProvisionedDevice|null `null` si el nombre lo tiene un quiosco activo.
     */
    public function provision(int $siteId, string $name, ?string $appVersion, DateTimeImmutable $now): ?ProvisionedDevice;

    /**
     * Los quioscos del centro, activos y revocados, para `GET /api/v1/devices`.
     *
     * **Sin paginar**: una instalacion es un hotel (ADR-040) con unos pocos
     * quioscos, y la lista cabe entera en la pantalla del panel.
     *
     * @return list<DeviceSummary>
     */
    public function all(): array;

    /**
     * Un quiosco por su identificador publico, para `unpair` y para su respuesta.
     */
    public function findByUuid(string $deviceUuid): ?DeviceSummary;

    /**
     * Un quiosco por su clave interna.
     *
     * Lo necesita el `claim`, que llega con el `device_id` que la solicitud
     * apunto al confirmarse y necesita el `uuid` para pedirle el token a
     * `Identity`. Es la unica via en la que la clave interna es lo que hay a
     * mano: no viene de fuera, viene de una fila que este mismo modulo escribio.
     */
    public function findById(int $deviceId): ?DeviceSummary;
}
