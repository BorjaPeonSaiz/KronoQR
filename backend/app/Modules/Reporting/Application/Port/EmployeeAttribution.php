<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Port;

/**
 * A que departamento se atribuyen las horas de una persona, para etiquetar la
 * metrica (doc 02 §8.2).
 *
 * ## Por que un puerto propio y tan estrecho
 *
 * `worked_minutes_total` lleva etiqueta `department` y el evento de dominio que
 * lo dispara —`EmployeeClockedOut`— **no la transporta**: los eventos llevan
 * `employee_uuid` y `site_id` y nada mas, porque un evento con el nombre del
 * departamento seria un evento que caduca en cuanto alguien cambia de area.
 *
 * Devuelve **una sola cadena** y no una ficha. No es tacaneria: esto se consulta
 * en el camino que sigue a un fichaje, y traer nombre y correo de alguien para
 * poner una etiqueta en un contador pondria datos personales en memoria de un
 * componente que no tiene ninguna razon para verlos. Lo que no se carga no se
 * puede filtrar a un log por descuido (regla dura 21).
 *
 * No se reutiliza `Shared\Application\Port\EmployeeScopeDirectory`, que devuelve
 * el **identificador** del departamento: una serie de Prometheus etiquetada con
 * `department=7` no la lee nadie en un panel de Grafana, y resolver el nombre en
 * el panel obligaria a mantener alli un diccionario de la base de datos.
 */
interface EmployeeAttribution
{
    /**
     * Nombre del departamento de esa persona, o cadena vacia si no tiene o si no
     * existe.
     *
     * **Cadena vacia y no `null`**: es una etiqueta de metrica, y el cubo vacio
     * tiene que existir para que la suma de los departamentos cuadre con el
     * total del centro. Es el mismo criterio que la union del gauge de turnos
     * abiertos.
     */
    public function departmentLabelOf(string $employeeUuid): string;
}
