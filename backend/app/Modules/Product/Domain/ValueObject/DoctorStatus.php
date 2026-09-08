<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * Veredicto de una comprobacion de `product:doctor` (**RF-PD-13**, contrato
 * `DoctorCheck.status`).
 *
 * ## Tres valores y no dos
 *
 * Porque quien lee esto tiene que poder distinguir «esto hay que arreglarlo
 * ahora» de «esto conviene mirarlo». Un doctor que solo dijera si o no acabaria
 * en rojo permanente por el certificado autofirmado de una instalacion interna,
 * y un rojo permanente no lo mira nadie.
 *
 * ## El codigo de salida sale de aqui y de ningun otro sitio
 *
 * `0` todo correcto, `1` solo avisos, `2` al menos un fallo. Lo consumen
 * `install.sh` (`phase_verify`) y `update.sh` (`phase_start_and_verify`), y solo
 * el `2` se traduce al `6` de su tabla comun: un aviso se enseña y no bloquea
 * una instalacion que por lo demas esta bien.
 */
enum DoctorStatus: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Failure = 'failure';

    /**
     * El peor de un conjunto. Sin comprobaciones, `ok`: un doctor sin sondas no
     * ha encontrado nada malo, y decir lo contrario seria mentir.
     *
     * @param  iterable<DoctorStatus>  $statuses
     */
    public static function worstOf(iterable $statuses): self
    {
        $worst = self::Ok;

        foreach ($statuses as $status) {
            if ($status->severity() > $worst->severity()) {
                $worst = $status;
            }
        }

        return $worst;
    }

    public function exitCode(): int
    {
        return $this->severity();
    }

    private function severity(): int
    {
        return match ($this) {
            self::Ok => 0,
            self::Warning => 1,
            self::Failure => 2,
        };
    }
}
