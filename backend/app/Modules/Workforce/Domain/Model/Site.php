<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Model;

use App\Modules\Workforce\Domain\ValueObject\SiteTimezone;
use InvalidArgumentException;

/**
 * Un centro de trabajo (doc 01 §5.5).
 *
 * Lo unico que este modelo protege —y lo unico que hace falta— es que la zona
 * horaria sea una zona horaria de verdad: **es el dato del que depende RN-05** y
 * el unico de esta tabla cuyo error cambia el resultado de un calculo legal.
 *
 * `id` es `null` mientras el centro no se ha guardado. Se acepta esa nulabilidad
 * en lugar de inventar un identificador en el dominio porque `sites` no tiene
 * UUID publico: su clave la asigna la base de datos.
 */
final readonly class Site
{
    public function __construct(
        public ?int $id,
        public string $name,
        public SiteTimezone $timezone,
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Un centro necesita nombre.');
        }

        if ($id !== null && $id < 1) {
            throw new InvalidArgumentException('El identificador de un centro es positivo.');
        }
    }

    public static function create(string $name, SiteTimezone $timezone): self
    {
        return new self(null, $name, $timezone);
    }

    public function rename(string $name): self
    {
        return new self($this->id, $name, $this->timezone);
    }

    /**
     * Cambiar la zona horaria afecta al calculo de las jornadas **siguientes**
     * (RN-05). No reescribe el pasado: las jornadas ya cerradas conservan su
     * `work_date`, que es lo que hace que un registro con valor legal sea
     * estable. Es configuracion con efecto sobre el computo, asi que se audita.
     */
    public function relocateTo(SiteTimezone $timezone): self
    {
        return new self($this->id, $this->name, $timezone);
    }

    public function withId(int $id): self
    {
        return new self($id, $this->name, $this->timezone);
    }
}
