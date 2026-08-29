<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Hasta donde alcanza una cuenta de gestion (**RF-ID-03**).
 *
 * **En Shared y no en Identity, por lo mismo que {@see UserRole}**: lo emite
 * `Identity`, que es quien tiene las cuentas y la tabla `departments`, y lo
 * consumen las policies y las consultas de `Workforce`, `Reporting` y
 * `Attendance`, que viven en el modulo dueño de cada recurso. Deptrac prohibe
 * que esos tres importen nada de `Identity` (doc 02 §1.6), asi que el tipo tiene
 * que estar en el unico sitio que todos alcanzan.
 *
 * **Un solo eje: el departamento.** No hay eje por centro porque hay exactamente
 * un centro por instalacion (ADR-040). Si algun dia hubiera mas, este objeto es
 * el sitio donde crecerian los ejes, y no cada consulta.
 *
 * ## La distincion que sostiene el requisito
 *
 * `unrestricted()` y `forDepartments()` **no** son «lista completa» y «lista
 * parcial»: son dos cosas distintas. Un responsable al que todavia no se le ha
 * asignado ningun departamento tiene `forDepartments()` con la lista vacia, y eso
 * significa que **no alcanza a nadie**. Representarlo con una lista vacia
 * indistinguible de «sin restriccion» es el error que convierte a un responsable
 * recien creado en alguien que ve la plantilla entera, y es exactamente el fallo
 * que RF-ID-03 existe para impedir. Por eso el discriminante es un booleano
 * propio y no `$departmentIds === []`.
 *
 * ## Se aplica en la consulta, no despues
 *
 * {@see self::departmentIds()} esta pensado para entrar en un `WHERE ... IN`, no
 * para filtrar en memoria una pagina ya traida: si el filtro se aplicara despues,
 * `meta.total` describiria a personas que quien pregunta no puede ver, que es una
 * fuga por si misma, y la paginacion daria paginas vacias intercaladas.
 */
final readonly class AccessScope
{
    /**
     * @param  list<int>  $departmentIds  Vacio con `$unrestricted = false` significa «nadie».
     */
    private function __construct(
        private bool $unrestricted,
        private array $departmentIds,
    ) {
        foreach ($this->departmentIds as $id) {
            if ($id < 1) {
                throw new InvalidArgumentException('Un departamento del alcance es un identificador valido.');
            }
        }
    }

    /**
     * Toda la plantilla: `admin`, `rrhh` y `auditor` (Anexo B del doc 01).
     */
    public static function unrestricted(): self
    {
        return new self(true, []);
    }

    /**
     * Solo los departamentos indicados (`departments.manager_user_id`).
     */
    public static function forDepartments(int ...$departmentIds): self
    {
        $unique = array_values(array_unique($departmentIds));

        sort($unique);

        return new self(false, $unique);
    }

    public function isUnrestricted(): bool
    {
        return $this->unrestricted;
    }

    /**
     * Si no alcanza a nadie. Es un estado legitimo, no una averia: un
     * responsable existe antes de que se le asigne su primer departamento.
     */
    public function reachesNobody(): bool
    {
        return ! $this->unrestricted && $this->departmentIds === [];
    }

    /**
     * @return list<int> Vacia cuando no acota nada; quien la use tiene que
     *                   preguntar antes por {@see self::isUnrestricted()}.
     */
    public function departmentIds(): array
    {
        return $this->departmentIds;
    }

    /**
     * Si el alcance cubre a alguien adscrito a ese departamento.
     *
     * **Un empleado sin departamento (`null`) solo lo alcanza quien no tiene
     * restriccion.** Es la respuesta prudente: nadie dirige el departamento de
     * quien no tiene ninguno, asi que atribuirselo a un responsable cualquiera
     * seria inventar una jerarquia. RRHH sigue viendolo, que es quien tiene que
     * corregir esa ficha.
     */
    public function reaches(?int $departmentId): bool
    {
        if ($this->unrestricted) {
            return true;
        }

        return $departmentId !== null && \in_array($departmentId, $this->departmentIds, true);
    }
}
