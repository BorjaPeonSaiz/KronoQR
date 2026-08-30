<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

/**
 * El contrato que se intenta registrar no describe una vigencia posible
 * (**RF-GP-02**).
 *
 * Es un `422`: quien lo recibe tiene un campo que corregir en el formulario. Se
 * distingue de {@see OverlappingEmploymentContract}, que es un `409` porque lo
 * que no encaja no es la peticion sino el estado —ya hay otro contrato ahi— y no
 * se arregla reescribiendo el cuerpo.
 *
 * Las mismas tres afirmaciones estan declaradas en la migracion con `CHECK`. No
 * es duplicacion inutil: la del esquema protege de las escrituras que no pasan
 * por el dominio —una importacion de nomina, un `psql`—, y esta da un mensaje
 * con significado a quien esta dando de alta el contrato.
 */
final class InvalidEmploymentContract extends WorkforceDomainException
{
    public static function hoursMustBePositive(string $what, float $value): self
    {
        return new self('Un contrato no puede pactar '.$what.' en '.$value.': tiene que ser mayor que cero.');
    }

    public static function weeklyHoursExceedTheWeek(float $value): self
    {
        return new self('Un contrato no puede pactar '.$value.' horas semanales: una semana tiene 168.');
    }

    public static function periodIsInverted(string $validFrom, string $validTo): self
    {
        return new self(
            'La vigencia del contrato empieza en '.$validFrom.' y termina en '.$validTo
            .': la primera fecha no puede ser posterior a la ultima.',
        );
    }
}
