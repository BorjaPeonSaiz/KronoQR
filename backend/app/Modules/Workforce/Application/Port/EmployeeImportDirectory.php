<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Port;

/**
 * A quien reconoce ya la instalacion, para que reimportar el mismo fichero no
 * duplique a nadie (**RF-GP-05**, regla dura 5).
 *
 * ## Dos metodos y no uno con dos parametros
 *
 * Porque el orden importa y tiene que estar escrito donde se decide, no aqui: el
 * documento manda sobre el correo. El correo de una persona cambia —se casa,
 * cambia de dominio, deja de tener— y su documento no; si mandara el correo,
 * cambiarlo en el fichero crearia un alta nueva en lugar de actualizar la ficha.
 *
 * ## El documento nunca viaja hacia abajo en claro mas alla del adaptador
 *
 * {@see self::uuidByNationalId()} recibe el documento y lo compara **hasheado en
 * la propia sentencia** (`digest(?, 'sha256')`, RL-08): el valor en claro no
 * llega a existir como columna ni aparece en el texto de la consulta que podria
 * acabar en un log lento de PostgreSQL. Es la misma via por la que se escribe en
 * el alta.
 */
interface EmployeeImportDirectory
{
    /** UUID publico de quien tiene ese documento, o `null`. */
    public function uuidByNationalId(string $nationalId): ?string;

    /** UUID publico de quien tiene ese correo, o `null`. */
    public function uuidByEmail(string $email): ?string;

    /**
     * UUID publico de quien tiene ese codigo de empleado, o `null`
     * (tarea 3.10, RF-GP-04).
     *
     * **Lo usa la carga de ausencias y no la de plantilla**, y la asimetria es
     * deliberada: el codigo lo genera el servidor y es opaco (doc 01 §5.5), asi
     * que un fichero de altas **no puede** traerlo —{@see ImportColumnMap} lo
     * dice: no hay alias para `employee_code`— pero un fichero de ausencias
     * habla de gente que **ya existe**, y el codigo es lo unico estable y
     * publico con lo que referirse a ella. El nombre se repite y el documento de
     * identidad no se almacena (RL-08).
     *
     * La comparacion no baja a minusculas: la columna es `citext` y ya es
     * insensible a mayusculas. Hacerlo aqui ademas seria una segunda regla que
     * podria separarse de la del esquema.
     */
    public function uuidByEmployeeCode(string $employeeCode): ?string;

    /**
     * Departamentos de la instalacion por su nombre normalizado, para resolver
     * la columna del fichero sin una consulta por linea.
     *
     * **Normalizado** —minusculas, sin acentos y sin espacios de sobra— porque
     * quien escribe «Recepción» en el Excel y quien creo «Recepcion» en el panel
     * se refieren al mismo departamento, y rechazar la linea por una tilde seria
     * un rechazo que nadie entiende.
     *
     * @return array<string, int>
     */
    public function departmentsByNormalisedName(): array;
}
