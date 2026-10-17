<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\Exception;

/**
 * Una busqueda de la persona de la exportacion legal que no cumple los limites
 * de RF-IN-05.
 *
 * **El mensaje no lleva nunca el termino buscado**: esta excepcion puede acabar
 * en el registro tecnico, y el termino es un nombre o un DNI (regla dura 21,
 * RL-08). Dice que limite fallo y nada mas.
 */
final class InvalidEmployeeSearch extends ComplianceDomainException
{
    /** El campo del cuerpo al que se refiere, para el `422`. */
    public function __construct(string $message, public readonly string $field)
    {
        parent::__construct($message);
    }

    public static function textTooShort(int $minimum): self
    {
        return new self(\sprintf(
            'El termino de busqueda necesita al menos %d caracteres una vez recortado.',
            $minimum,
        ), 'q');
    }

    public static function textTooLong(int $maximum): self
    {
        return new self(\sprintf(
            'El termino de busqueda admite como mucho %d caracteres.',
            $maximum,
        ), 'q');
    }

    public static function nationalIdTooShort(int $minimum): self
    {
        return new self(\sprintf(
            'El documento de identidad necesita al menos %d caracteres una vez quitados espacios, puntos y guiones.',
            $minimum,
        ), 'national_id');
    }
}
