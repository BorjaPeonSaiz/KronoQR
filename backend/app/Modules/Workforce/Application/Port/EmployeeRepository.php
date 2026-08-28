<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Port;

use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;
use App\Modules\Workforce\Domain\Exception\EmployeeEmailAlreadyTaken;
use App\Modules\Workforce\Domain\Model\Employee;

/**
 * La plantilla, vista por los casos de uso de este modulo.
 *
 * Habla en {@see Employee} —modelo de dominio— y en escalares, nunca en modelos
 * Eloquent (ADR-025, restriccion 2): es lo que impide que la capa de aplicacion
 * acabe con un `->save()` a mano y con la persistencia repartida por todas
 * partes.
 *
 * **El documento de identidad entra por aqui y no vuelve a salir.** `add()` y
 * `updateNationalId()` lo reciben en claro y lo convierten en digest con
 * `pgcrypto` dentro de la misma sentencia (RL-08). No hay ningun metodo que lo
 * devuelva porque no existe ninguna razon para leerlo.
 */
interface EmployeeRepository
{
    /**
     * Da de alta al empleado.
     *
     * **No comprueba antes si el codigo existe**: lo intenta y deja que hable el
     * UNIQUE de `employees.employee_code`. Un `SELECT` previo seria una
     * condicion de carrera con aspecto de comprobacion. Ante el choque lanza
     * {@see EmployeeCodeAlreadyTaken} y quien llama reintenta con otro codigo.
     *
     * @param  string|null  $nationalId  Documento en claro. Se hashea en la sentencia y no se almacena (RL-08).
     *
     * @throws \App\Modules\Workforce\Domain\Exception\EmployeeCodeAlreadyTaken
     * @throws EmployeeEmailAlreadyTaken
     */
    public function add(Employee $employee, ?string $nationalId = null): void;

    /**
     * Persiste los cambios de un empleado que ya existe, identificado por su
     * UUID.
     *
     * @throws EmployeeEmailAlreadyTaken
     */
    public function save(Employee $employee): void;

    public function findByUuid(string $uuid): ?Employee;

    /**
     * Pagina de la plantilla que cumple los filtros, ordenada de forma estable.
     *
     * @param  string|null  $search  Busqueda libre por nombre, apellidos, nombre
     *                               completo o codigo de empleado, insensible a
     *                               mayusculas y a acentos y por subcadena.
     *                               Llega ya recortada, y `null` significa «sin
     *                               busqueda»: la cadena vacia no es un filtro.
     *                               Se combina con `AND` con el resto.
     * @param  PinStatus|null  $pinStatus  Situacion del PIN (RF-ID-09). El
     *                                     adaptador la traduce a las MISMAS
     *                                     columnas de las que
     *                                     {@see EmployeePinRepository::statusesFor()}
     *                                     deriva el estado que se muestra: si
     *                                     las dos reglas divergieran, el panel
     *                                     filtraria por una cosa y pintaria
     *                                     otra.
     * @return list<Employee>
     */
    public function search(
        ?int $siteId,
        ?int $departmentId,
        ?EmploymentStatus $status,
        ?string $search,
        ?PinStatus $pinStatus,
        int $limit,
        int $offset,
    ): array;

    /**
     * Cuantos empleados cumplen los MISMOS filtros que {@see search()}.
     *
     * Los dos metodos tienen que recibir los mismos criterios o `meta.total`
     * describiria un conjunto distinto del que se devuelve, y quien pagina
     * pediria paginas que no existen.
     */
    public function countMatching(
        ?int $siteId,
        ?int $departmentId,
        ?EmploymentStatus $status,
        ?string $search,
        ?PinStatus $pinStatus,
    ): int;
}
