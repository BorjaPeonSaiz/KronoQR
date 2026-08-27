<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Lo que hay que saber de un empleado para **imprimir su tarjeta** y para
 * **contarlo en el panel de estado** (RF-QR-04, RF-QR-08).
 *
 * **Por que no se reutiliza {@see EmployeeSnapshot}.** Aquel resuelve otra
 * pregunta —«¿puede fichar esta persona y a que centro pertenece?»— y por eso
 * lleva `EmploymentStatus` y los identificadores internos de centro y
 * departamento, pero **no sus nombres**: en el camino de fichaje nadie los
 * necesita y cargarlos seria poner en memoria datos que ese camino no tiene por
 * que ver. Aqui pasa justo lo contrario: RF-QR-04 obliga a imprimir «nombre,
 * departamento y centro», que son tres textos, y el identificador numerico del
 * departamento no se puede imprimir en una tarjeta. Ampliar `EmployeeSnapshot`
 * con dos cadenas mas habria metido esos textos en el camino caliente para
 * servir a un caso de uso que ocurre unas decenas de veces al año.
 *
 * **Vive en `Shared` porque cruza la frontera entre dos modulos**, igual que
 * `EmployeeSnapshot`: lo necesita `Identity` —que posee `credentials`— y lo
 * produce `Workforce` —que posee `employees`, `departments` y `sites`—, y
 * ninguno de los dos satelites puede importar nada del otro (doc 02 §1.6). El
 * puerto es `EmployeeCardDirectory`, en `Shared/Application/Port`: este Value
 * Object, por ser `Domain`, no puede importarlo.
 *
 * **`fullName` no puede acabar en un log tecnico ni en `error_events`** (regla
 * dura 21). Sale por dos sitios y solo dos: el PDF de la tarjeta, que se entrega
 * en mano a su titular, y el panel de RRHH, que es quien tiene que reconocer a
 * quien le falta la tarjeta. En cualquier traza se usa `employeeUuid`.
 */
final readonly class EmployeeCardProfile
{
    public function __construct(
        /** UUID v7 publico del empleado (`employees.uuid`). */
        public string $employeeUuid,
        /** `employees.employee_code`: opaco y aleatorio, nunca derivado de datos personales. */
        public string $employeeCode,
        /** Nombre completo tal y como se imprime en la tarjeta. Jamas en un log. */
        public string $fullName,
        /** Nombre del centro. Se imprime porque una cadena tiene varios y las tarjetas se mezclan. */
        public string $siteName,
        public int $siteId,
        /**
         * Nombre del departamento, si lo tiene.
         *
         * Es opcional porque `employees.department_id` lo es: hay personal que
         * no esta adscrito a ninguno, y la tarjeta se imprime igual.
         */
        public ?string $departmentName = null,
        /** Clave interna. No sale nunca por la API; la usa el repositorio para cruzar credenciales. */
        public int $employeeId = 0,
    ) {
        if ($employeeUuid === '') {
            throw new InvalidArgumentException('EmployeeCardProfile necesita el UUID del empleado.');
        }

        if ($employeeCode === '') {
            throw new InvalidArgumentException('EmployeeCardProfile necesita el codigo de empleado.');
        }

        if ($fullName === '') {
            throw new InvalidArgumentException('EmployeeCardProfile necesita el nombre que se imprime en la tarjeta.');
        }

        if ($siteName === '') {
            throw new InvalidArgumentException('EmployeeCardProfile necesita el nombre del centro.');
        }

        if ($siteId < 1) {
            throw new InvalidArgumentException('EmployeeCardProfile necesita el centro al que esta adscrito el empleado.');
        }

        if ($departmentName === '') {
            throw new InvalidArgumentException('El departamento, si existe, tiene nombre.');
        }
    }
}
