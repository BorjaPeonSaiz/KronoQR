<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Model;

use InvalidArgumentException;

/**
 * Un departamento dentro de un centro (doc 01 §5.5).
 *
 * El nombre es unico **dentro del centro** y no en la instalacion: dos hoteles
 * del mismo cliente tienen los dos una «Recepcion». Esa unicidad la garantiza el
 * indice `departments_site_id_name_unique`, no esta clase.
 *
 * **No se puede mover de centro** y por eso no hay metodo para hacerlo: sus
 * empleados estan adscritos al centro, y arrastrarlos con un cambio de
 * departamento les cambiaria la zona horaria con la que se calcula su jornada
 * (RN-05) sin que nadie lo pidiera.
 *
 * `manager_user_id` existe en el esquema y **no esta aqui**: solo tiene efecto
 * con el ambito por departamento de RF-ID-03, que es de la tarea 2.1, y ademas
 * apunta a `users`, que es una tabla de otro modulo.
 */
final readonly class Department
{
    public function __construct(
        public ?int $id,
        public int $siteId,
        public string $name,
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Un departamento necesita nombre.');
        }

        if ($siteId < 1) {
            throw new InvalidArgumentException('Un departamento pertenece a un centro.');
        }

        if ($id !== null && $id < 1) {
            throw new InvalidArgumentException('El identificador de un departamento es positivo.');
        }
    }

    public static function create(int $siteId, string $name): self
    {
        return new self(null, $siteId, $name);
    }

    public function rename(string $name): self
    {
        return new self($this->id, $this->siteId, $name);
    }

    public function withId(int $id): self
    {
        return new self($id, $this->siteId, $this->name);
    }
}
