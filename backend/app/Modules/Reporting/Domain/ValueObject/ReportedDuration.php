<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

/**
 * Una duracion tal y como se escribe en un informe: **`HH:MM`, nunca decimal**
 * (`/informe-nuevo`, paso 6; RL-06).
 *
 * ## Por que el decimal esta prohibido y no solo desaconsejado
 *
 * «7,75 horas» obliga a interpretar, y la mitad de la gente lee «siete horas y
 * setenta y cinco minutos». Peor aun: la coma decimal cambia de sitio segun la
 * configuracion regional del programa que abra el fichero, asi que `7.75` leido
 * con separador de miles español es setecientos setenta y cinco. `07:45` no se
 * interpreta y no depende de la configuracion de nadie.
 *
 * **Los minutos enteros siguen viajando en la respuesta**, al lado del texto:
 * quien calcula usa el numero y quien lee usa el texto. Lo que no sale por
 * ningun lado es una hora decimal.
 *
 * ## Las horas pasan de 24 y eso no es un error
 *
 * Esto no es una hora del reloj: es una cantidad de trabajo. El total mensual de
 * una persona son ciento sesenta y tantas horas y se escribe `168:00`.
 *
 * ## El signo, que aqui si hace falta
 *
 * La hermana de `Compliance` —`ExportedDuration`— no admite negativos porque un
 * tramo no dura menos que nada. Aqui si: la desviacion entre lo trabajado y lo
 * contratado es negativa cuando se ha trabajado de menos, y `-12:30` es la unica
 * forma honesta de escribirlo. El signo va delante y el resto se formatea sobre
 * el valor absoluto, para que no salga `-12:-30`.
 *
 * Escrita aqui y no reutilizada de `Compliance` porque el §1.6 no concede esa
 * arista: los dos modulos resuelven el mismo problema de presentacion y ninguno
 * puede importar al otro. La alternativa —subirla a `Shared`— convertiria un
 * detalle de formato en vocabulario compartido de todo el producto.
 */
final readonly class ReportedDuration
{
    private function __construct(public int $minutes) {}

    public static function ofMinutes(int $minutes): self
    {
        return new self($minutes);
    }

    public function toClockText(): string
    {
        $absolute = abs($this->minutes);
        $sign = $this->minutes < 0 ? '-' : '';

        return $sign.sprintf('%02d:%02d', intdiv($absolute, 60), $absolute % 60);
    }
}
