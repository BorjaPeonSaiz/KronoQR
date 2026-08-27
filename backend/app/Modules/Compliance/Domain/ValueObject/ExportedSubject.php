<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * De quien y de que jornada habla una fila de la exportacion legal.
 *
 * **Va repetido en cada fila y eso es lo correcto.** Un fichero tabular en el
 * que la persona solo aparece en la primera fila de su bloque se rompe en cuanto
 * alguien ordena por otra columna, y ordenar es lo primero que hace quien recibe
 * una tabla. Con el sujeto en cada fila, cualquier linea del documento se
 * sostiene sola.
 *
 * **Aqui SI van nombres.** Es la unica parte del sistema donde eso es correcto:
 * la finalidad del fichero es identificar a un trabajador ante la Inspeccion, y
 * un registro horario con UUID en lugar de nombres no cumple el art. 34.9 ET. La
 * regla dura 21 gobierna los **logs tecnicos** y `error_events`, que viajan al
 * fabricante; este documento no viaja a ninguna parte que no sea el
 * requerimiento que lo pidio.
 *
 * `employeeUuid` va ademas del nombre porque dos personas pueden llamarse igual
 * y porque es lo que permite casar el fichero con el asiento de `audit_log` que
 * dejo su generacion.
 */
final readonly class ExportedSubject
{
    public function __construct(
        public string $employeeCode,
        public string $lastName,
        public string $firstName,
        public string $employeeUuid,
        public string $siteName,
        /** Puede no tenerlo: `employees.department_id` es opcional. */
        public ?string $departmentName,
        /** Zona horaria del centro, en la que se expresan las horas locales. */
        public string $timezone,
        /** Jornada a la que se atribuye la fila, en forma `YYYY-MM-DD` (RN-05). */
        public string $workDate,
    ) {}

    /**
     * «Apellidos, Nombre»: como se ordena una lista de personal en castellano y
     * como aparece en una nomina.
     */
    public function fullName(): string
    {
        return $this->lastName.', '.$this->firstName;
    }
}
