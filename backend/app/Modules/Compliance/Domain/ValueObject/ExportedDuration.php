<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

use App\Modules\Compliance\Domain\Exception\InvalidLegalExportRequest;

/**
 * Una duracion tal y como se escribe en la exportacion legal: **`HH:MM`, nunca
 * decimal** (RL-06, plan 1.17 paso 4).
 *
 * ## Por que el decimal esta prohibido y no solo desaconsejado
 *
 * «7,5 horas» obliga a interpretar. En una inspeccion eso significa discutir si
 * son siete horas y media o siete horas y cinco minutos, y significa ademas que
 * la coma decimal cambia de sitio segun la configuracion regional del programa
 * que abra el fichero: `7.5` leido con separador de miles español es setenta y
 * cinco. `07:30` no se interpreta y no depende de la configuracion de nadie.
 *
 * ## Las horas pasan de 24 y eso no es un error
 *
 * Esto no es una hora del reloj: es una cantidad de trabajo. El total mensual de
 * una persona son ciento sesenta y tantas horas, y se escribe `168:00`. Por eso
 * las horas **no se truncan a dos digitos** ni se envuelven a las 24: se
 * escriben las que sean, con un minimo de dos digitos para que la columna quede
 * alineada.
 *
 * ## Nulo y cero son cosas distintas
 *
 * Un tramo abierto —alguien que todavia esta trabajando— no tiene duracion, y no
 * tiene cero: escribir `00:00` afirmaria que no trabajo nada. `absent()` produce
 * cadena vacia, que en una tabla es «no consta», que es la verdad.
 */
final readonly class ExportedDuration
{
    private function __construct(public ?int $minutes) {}

    public static function ofMinutes(int $minutes): self
    {
        if ($minutes < 0) {
            throw InvalidLegalExportRequest::negativeDuration($minutes);
        }

        return new self($minutes);
    }

    /** No consta: un tramo todavia abierto o una fila que no describe duracion. */
    public static function absent(): self
    {
        return new self(null);
    }

    /** `null` se acepta aqui porque las columnas de la consulta lo son. */
    public static function ofNullableMinutes(?int $minutes): self
    {
        return $minutes === null ? self::absent() : self::ofMinutes($minutes);
    }

    public function toClockText(): string
    {
        if ($this->minutes === null) {
            return '';
        }

        return sprintf('%02d:%02d', intdiv($this->minutes, 60), $this->minutes % 60);
    }
}
