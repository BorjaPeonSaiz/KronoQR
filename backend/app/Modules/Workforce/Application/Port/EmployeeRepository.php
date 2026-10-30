<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Port;

use App\Modules\Shared\Domain\ValueObject\AccessScope;
use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;
use App\Modules\Workforce\Domain\Exception\EmployeeAlreadyTerminated;
use App\Modules\Workforce\Domain\Exception\EmployeeEmailAlreadyTaken;
use App\Modules\Workforce\Domain\Exception\EmployeeNationalIdAlreadyTaken;
use App\Modules\Workforce\Domain\Exception\EmployeeNotTerminated;
use App\Modules\Workforce\Domain\Model\Employee;
use App\Modules\Workforce\Domain\Model\EmploymentHistory;
use App\Modules\Workforce\Domain\Model\TerminationDateCorrection;
use DateTimeImmutable;

/**
 * La plantilla, vista por los casos de uso de este modulo.
 *
 * Habla en {@see Employee} —modelo de dominio— y en escalares, nunca en modelos
 * Eloquent (ADR-025, restriccion 2): es lo que impide que la capa de aplicacion
 * acabe con un `->save()` a mano y con la persistencia repartida por todas
 * partes.
 *
 * **No hay un metodo que escriba la fila entera** (ADR-046 §3). Cada escritura
 * elige sus columnas —{@see self::saveProfile()}, {@see self::saveTermination()}—
 * y lleva el predicado `status <> 'terminated'`: con un `save()` generico, una
 * modificacion de nombre que leyo la ficha antes de una baja reescribia el estado
 * y deshacia la baja.
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
     * @throws EmployeeNationalIdAlreadyTaken
     */
    public function add(Employee $employee, ?string $nationalId = null): void;

    /**
     * Lectura para **leer**. Un caso de uso que va a escribir la ficha no la usa:
     * usa {@see self::findForUpdate()} (ADR-046 §2).
     */
    public function findByUuid(string $uuid): ?Employee;

    /**
     * Lectura para **escribir** (ADR-046 §2): toma la fila con
     * `SELECT … FOR NO KEY UPDATE`, solo sobre `employees` y sin `JOIN`, hasta el
     * final de la transaccion de quien llama.
     *
     * Es el candado que toma un `UPDATE` que no cambia la clave: serializa a los
     * escritores de la ficha entre si y **no choca** con el `FOR KEY SHARE` de
     * las claves ajenas, asi que un fichaje, una ausencia o una tarjeta de esa
     * persona no esperan. `FOR UPDATE` queda prohibido en esta tabla.
     *
     * **Se llama con la cadena de auditoria ya tomada**
     * (`SerializedLedgerWrite::withChainLock()`): el orden unico es filas padre →
     * cadena → `employees` → `credentials` (ADR-046 §1). Fuera de una
     * transaccion el candado se soltaria al terminar la sentencia y no
     * serializaria nada.
     */
    public function findForUpdate(string $uuid): ?Employee;

    /**
     * Escribe lo que cambia una modificacion de la ficha (ADR-046 §3):
     * `first_name`, `last_name`, `email`, `department_id`, `locale` y
     * `teleworking`, y `status` **solo** si `$statusChanged` (suspension o
     * reincorporacion). **Nunca** `terminated_at`, `id`, `uuid` ni
     * `employee_code` (A-5): los tres ultimos tienen indice unico completo, y
     * un **cambio** de su valor tomaria `FOR UPDATE` (el mismo valor no: Postgres
     * compara el viejo con el nuevo). No escribirlos impide que llegue a ocurrir.
     *
     * `WHERE uuid = ? AND status <> 'terminated'`: cero filas afectadas es
     * {@see EmployeeAlreadyTerminated}. El candado es lo que impide la carrera;
     * el predicado la convierte en un `409` honesto si algun camino se saltara la
     * lectura bloqueante.
     *
     * @throws EmployeeEmailAlreadyTaken
     * @throws EmployeeAlreadyTerminated
     */
    public function saveProfile(Employee $employee, bool $statusChanged): void;

    /**
     * Escribe la baja (ADR-046 §3): `status` y `terminated_at`, y nada mas. La
     * usan la baja en el acto, la programada, su anulacion y la tarea que la
     * aplica. Con el mismo predicado `status <> 'terminated'` y la misma lectura
     * de cero filas que {@see self::saveProfile()}: una baja no se repite ni pisa
     * otra.
     *
     * @throws EmployeeAlreadyTerminated
     */
    public function saveTermination(Employee $employee): void;

    /**
     * Las fichas de esos UUID, **bloqueadas** como {@see self::findForUpdate()} y
     * en orden de `id`, para que dos lotes que comparten personas las tomen en el
     * mismo orden (ADR-062 §6). Las que no existen no aparecen.
     *
     * @param  list<string>  $uuids
     * @return array<string, Employee> indexado por `uuid`
     */
    public function findManyForUpdate(array $uuids): array;

    /**
     * Los periodos de alta de la persona, del mas antiguo al mas reciente
     * (RN-27). Vacia si no existe.
     */
    public function employmentHistory(string $uuid): EmploymentHistory;

    /**
     * Escribe la readmision (RN-27, ADR-062 §6): el periodo nuevo, con
     * `$periodUuid`, y la proyeccion en la ficha —`status`, `hired_at`,
     * `terminated_at`, `department_id` y `locale`— en la misma transaccion. Con
     * el predicado `status = 'terminated'`: cero filas es que otra escritura la
     * readmitio antes.
     *
     * @throws EmployeeNotTerminated
     */
    public function saveRehire(Employee $employee, string $periodUuid, ?int $createdByUserId): void;

    /**
     * Escribe la correccion de la fecha de cese (RN-27, ADR-062 §7): la fila de
     * `employment_period_corrections`, la fecha nueva del periodo y, si es el
     * periodo en curso, la de la ficha. Devuelve el `id` de la correccion.
     */
    public function saveTerminationCorrection(
        Employee $employee,
        TerminationDateCorrection $correction,
        int $correctedByUserId,
        DateTimeImmutable $correctedAt,
    ): int;

    /**
     * La persona **de baja y con la ficha sin minimizar** que tiene ese
     * documento, o `null` (RN-27): el `409` del alta la ofrece para readmitirla.
     * El documento se hashea en la sentencia y no sale de ella (RL-08).
     */
    public function rehirableUuidByNationalId(string $nationalId): ?string;

    /**
     * Las bajas programadas **vencidas** (RN-14, ADR-062 §4): personas que no
     * estan de baja y tienen una fecha de cese anterior a `$today`, en orden de
     * `id` para que dos pasadas tomen las fichas en el mismo orden. Lectura sin
     * candado: la tarea vuelve a leer cada una con {@see self::findForUpdate()}.
     *
     * @param  string  $today  fecha civil del centro, `Y-m-d`
     * @return list<string> los `uuid`
     */
    public function dueScheduledOffboardings(string $today): array;

    /**
     * Cuantas bajas programadas tienen la fecha de cese anterior a `$date`. Con
     * `$date` = ayer, las que siguen sin aplicar pasado el tope de la tarea.
     *
     * @param  string  $date  fecha civil, `Y-m-d`
     */
    public function countScheduledOffboardingsDueBefore(string $date): int;

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
     * @param  bool|null  $teleworking  `true` solo quien teletrabaja, `false` solo
     *                                  quien no, `null` sin filtrar (RF-GP-01).
     *                                  Informativo: filtra el listado y nada mas.
     * @param  AccessScope  $scope  Alcance de quien pregunta (**RF-ID-03**). Se aplica
     *                              **en la consulta** y se combina con `AND` con
     *                              `$departmentId`: pedir un departamento fuera del
     *                              alcance devuelve cero filas, no las de otro. Un
     *                              alcance que no llega a nadie devuelve una pagina
     *                              vacia, nunca la plantilla entera.
     * @return list<Employee>
     */
    public function search(
        AccessScope $scope,
        ?int $departmentId,
        ?EmploymentStatus $status,
        ?string $search,
        ?PinStatus $pinStatus,
        ?bool $teleworking,
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
        AccessScope $scope,
        ?int $departmentId,
        ?EmploymentStatus $status,
        ?string $search,
        ?PinStatus $pinStatus,
        ?bool $teleworking,
    ): int;
}
