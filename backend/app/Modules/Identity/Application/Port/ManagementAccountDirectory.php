<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Port;

/**
 * El lado de **consulta** de las cuentas de gestion (RF-ID-10):
 * `GET /api/v1/management-accounts`, la respuesta de la baja y del
 * restablecimiento del 2FA, e `identity:list-users`.
 *
 * **Un puerto de lectura aparte de los de escritura**, por lo mismo que en el
 * resto del producto: el listado necesita una sola consulta con el alcance por
 * departamento agregado —sin una consulta por fila— y no tiene nada que ver con
 * el candado y la fila bloqueada con los que se decide una baja.
 *
 * **Solo cuentas de gestion**: las cuatro de RF-ID-02. Los roles `empleado` y
 * `kiosk` no tienen contrasena de panel y no aparecen.
 */
interface ManagementAccountDirectory
{
    /**
     * Una pagina, **activas primero y, dentro de cada grupo, por nombre**
     * (el orden es parte del contrato). Los filtros actuan sobre el conjunto
     * entero.
     */
    public function page(ManagementAccountFilter $filter, int $page, int $perPage): ManagementAccountPage;

    /**
     * La cuenta con ese `uuid`, activa o dada de baja, o `null`.
     */
    public function find(string $uuid): ?ManagementAccountRecord;
}
