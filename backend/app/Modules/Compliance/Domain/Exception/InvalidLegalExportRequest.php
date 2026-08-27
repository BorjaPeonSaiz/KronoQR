<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\Exception;

/**
 * Lo que se ha pedido exportar no describe un periodo defendible (RF-IN-05,
 * RL-06).
 *
 * **Por que rompe en vez de corregir.** Un periodo invertido —`to` anterior a
 * `from`— se podria «arreglar» dando la vuelta a las dos fechas, y es
 * exactamente lo que no se debe hacer: el fichero que se entrega a un inspector
 * llevaria escrito un periodo que nadie pidio, y quien lo genero seguiria
 * creyendo que pidio otro. En un documento con efectos legales, adivinar la
 * intencion es peor que negarse.
 */
final class InvalidLegalExportRequest extends ComplianceDomainException
{
    public static function invertedPeriod(string $from, string $to): self
    {
        return new self(sprintf(
            'El periodo de la exportacion legal va de «%s» a «%s», que termina antes de empezar.',
            $from,
            $to,
        ));
    }

    public static function malformedDate(string $field, string $value): self
    {
        return new self(sprintf(
            'El campo «%s» de la exportacion legal vale «%s», que no es una fecha en forma YYYY-MM-DD.',
            $field,
            $value,
        ));
    }

    public static function negativeDuration(int $minutes): self
    {
        return new self(sprintf(
            'Una duracion exportada no puede ser negativa, y se recibieron %d minutos.',
            $minutes,
        ));
    }
}
