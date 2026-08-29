<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

use App\Modules\Shared\Domain\ValueObject\AccessScope;

/**
 * A que departamento pertenece un empleado, para poder decidir si queda dentro
 * del alcance de quien pregunta (**RF-ID-03**).
 *
 * ## Por que un puerto propio y no un metodo mas en {@see EmployeeRegistry}
 *
 * Porque aquel dice de si mismo, por escrito, que traduce identificadores «y nada
 * mas»: se invoca en el camino de fichaje y devuelve dos columnas a proposito
 * para que el nombre y el correo del empleado no acaben en memoria de un
 * componente que no tiene razon para verlos. Esto es otra pregunta —de
 * autorizacion, no de traduccion— y la hacen otros: `Reporting` antes de servir un
 * registro horario y `Attendance` antes de aceptar una correccion.
 *
 * ## La arista
 *
 * ADR-025: lo declara `Shared` porque lo necesitan dos satelites que no pueden
 * importarse entre si, y lo implementa `Workforce`, que es quien posee
 * `employees.department_id`. El adaptador vive en
 * `Workforce/Infrastructure/Adapter/` y se enlaza en `WorkforceServiceProvider`.
 *
 * ## Un solo metodo, y su `null` significa dos cosas
 *
 * Devuelve `null` tanto si el empleado no existe como si existe sin departamento
 * asignado, y **eso no es ambiguedad**: para la pregunta que se hace aqui —«¿lo
 * alcanza este responsable?»— la respuesta es la misma, y es no.
 * {@see AccessScope::reaches()} lo trata
 * asi de forma explicita.
 *
 * Distinguir los dos casos exigiria una segunda consulta cuyo unico efecto seria
 * responder `404` en vez de `403` a un responsable que escribio mal un
 * identificador, y eso es peor: convertiria el endpoint en un comprobador de que
 * UUID existen para quien no puede verlos.
 */
interface EmployeeScopeDirectory
{
    /**
     * Departamento del empleado con ese UUID publico; `null` si no existe o no
     * tiene ninguno.
     *
     * **Incluye a quien esta de baja**: dar de baja no borra la ficha (regla dura
     * 5) y su registro horario se sigue consultando cuatro años (RL-02).
     */
    public function departmentIdOf(string $employeeUuid): ?int;
}
