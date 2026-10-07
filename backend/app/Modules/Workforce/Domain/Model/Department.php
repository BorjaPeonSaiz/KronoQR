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
 * El responsable (`manager_user_id`) **no esta aqui** sino en `DepartmentView`
 * (`Application/Port`): apunta a una cuenta de `users`, que es de otro modulo,
 * y ninguna regla de este agregado lo usa. Se lee con el departamento para el
 * panel y se escribe con su propio metodo del repositorio y su propio asiento
 * (RF-ID-10, ADR-051 §5).
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
