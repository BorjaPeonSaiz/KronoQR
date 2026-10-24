<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

/**
 * Una cantidad de minutos escrita como **`HH:MM`, nunca decimal** (RL-06): la
 * unica forma en que el producto escribe una duracion para que la lea una
 * persona.
 *
 * ## La regla
 *
 * - Dos digitos como minimo en cada mitad: `07:45`, no `7:45`.
 * - **Las horas pasan de 24**: esto no es una hora del reloj sino una cantidad
 *   de trabajo, y el total mensual de una persona se escribe `168:00`.
 * - **El signo va delante** y el resto se formatea sobre el valor absoluto:
 *   `-12:30` y nunca `-12:-30`. La desviacion de un informe de periodo es
 *   negativa cuando se trabaja de menos.
 *
 * ## Por que vive en `Shared`
 *
 * La escribian por separado la exportacion legal (`Compliance`,
 * `ExportedDuration`) y los informes (`Reporting`, `ReportedDuration`), con el
 * mismo `sprintf`. Ninguno de los dos modulos puede importar el dominio del
 * otro (doc 02 §1.6) y el texto tiene que ser identico en los dos: una hora
 * escrita de dos maneras en dos ficheros del mismo registro es una discusion en
 * una inspeccion. Cada modulo conserva su objeto de valor —el legal no admite
 * negativos, el del informe si— y los dos delegan aqui el texto.
 */
final readonly class HoursAndMinutes
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
