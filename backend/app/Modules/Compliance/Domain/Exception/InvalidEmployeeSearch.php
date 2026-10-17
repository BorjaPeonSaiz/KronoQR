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
 *
 * Lleva el campo, la cota (`min` o `max`) y su valor para que la capa HTTP
 * traduzca el `422` sin volver a medir el termino: la regla vive en
 * `EmployeeSearch` y en ningun otro sitio.
 */
final class InvalidEmployeeSearch extends ComplianceDomainException
{
    /** Cota minima incumplida. */
    public const string MIN = 'min';

    /** Cota maxima incumplida. */
    public const string MAX = 'max';

    /**
     * @param  string  $field  el campo del cuerpo al que se refiere, para el `422`
     * @param  self::MIN|self::MAX  $bound  que cota se incumplio
     * @param  int  $limit  el valor de esa cota, en caracteres
     */
    public function __construct(
        string $message,
        public readonly string $field,
        public readonly string $bound,
        public readonly int $limit,
    ) {
        parent::__construct($message);
    }

    public static function textTooShort(int $minimum): self
    {
        return new self(\sprintf(
            'El termino de busqueda necesita al menos %d caracteres una vez recortado.',
            $minimum,
        ), 'q', self::MIN, $minimum);
    }

    public static function textTooLong(int $maximum): self
    {
        return new self(\sprintf(
            'El termino de busqueda admite como mucho %d caracteres.',
            $maximum,
        ), 'q', self::MAX, $maximum);
    }

    public static function nationalIdTooShort(int $minimum): self
    {
        return new self(\sprintf(
            'El documento de identidad necesita al menos %d caracteres una vez quitados espacios, puntos y guiones.',
            $minimum,
        ), 'national_id', self::MIN, $minimum);
    }
}
