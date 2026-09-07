<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Domain\ValueObject;

use App\Modules\Kiosk\Domain\Exception\InvalidDeviceName;

/**
 * El nombre de un quiosco: «Recepcion», «Cocina», «Entrada de personal»
 * (**RF-PD-06**, doc 01 §5.5).
 *
 * ## Es el nombre del SITIO, nunca el de una persona
 *
 * Regla dura 21. Un dispositivo es un aparato colgado de una pared: no tiene
 * titular. Y ademas es lo que identifica al quiosco en el panel de salud, en la
 * alerta de «sin latido > 10 min» y en el runbook de alta — «Recepcion» y
 * «Cocina» son la diferencia entre saber a que tablet hay que ir a mirar y tener
 * que recorrer el hotel.
 *
 * ## La unicidad no se comprueba aqui
 *
 * Es una invariante **entre filas**, y la declara el indice
 * `devices_site_id_name_unique` de la migracion. Un objeto de valor no puede
 * saber que otros nombres existen sin consultar, y consultar desde el dominio es
 * justo lo que la frontera prohibe.
 *
 * ## El limite es 120, como la columna
 *
 * Se escribe aqui **y** en `devices.name` **y** en el contrato. Que este en tres
 * sitios no es duplicacion gratuita: la columna es la ultima linea de defensa
 * (§3.2), el contrato lo declara para el cliente generado y esto lo hace cierto
 * tambien para la consola.
 */
final readonly class DeviceName
{
    public const int MAX_LENGTH = 120;

    private function __construct(public string $value) {}

    /**
     * @throws InvalidDeviceName si esta vacio o pasa del limite de la columna
     */
    public static function of(string $name): self
    {
        $trimmed = trim($name);

        if ($trimmed === '') {
            throw new InvalidDeviceName('El quiosco necesita un nombre.');
        }

        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw new InvalidDeviceName('El nombre del quiosco no puede pasar de '.self::MAX_LENGTH.' caracteres.');
        }

        return new self($trimmed);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
