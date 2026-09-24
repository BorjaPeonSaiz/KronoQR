<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

/**
 * El objetivo a tres meses de produccion de un indicador, tal como lo fija el
 * §1.3 del documento 01 (**RF-IN-08**).
 *
 * ## No es un umbral configurable, y esa es la diferencia que importa
 *
 * La regla dura 14 obliga a que los umbrales **legales** —descanso minimo,
 * jornada maxima— se lean del perfil de cumplimiento y nunca de una constante.
 * Esto no es uno de ellos: es la **ambicion declarada del producto** en su propia
 * especificacion, la misma para todos los clientes, y ningun cliente la negocia.
 * Cambiar «≥ 99 % de jornadas completas» seria cambiar lo que KronoQR promete,
 * no configurar una instalacion (ADR-017 no aplica).
 *
 * Por eso vive en el codigo, viaja en la respuesta y **no** se lee de
 * `installation_settings`. Y por eso viaja en la respuesta en lugar de estar
 * escrito en la pantalla: el panel, el CSV y el PDF tienen que decir el mismo
 * numero.
 */
final readonly class AdoptionTarget
{
    private function __construct(
        public AdoptionTargetComparison $comparison,
        /** En la unidad del indicador, salvo en `reduction`: ahi son puntos porcentuales. */
        public float $value,
    ) {}

    /** «Al menos el 99 %», «al menos el 99,9 %». */
    public static function atLeast(float $value): self
    {
        return new self(AdoptionTargetComparison::AtLeast, $value);
    }

    /** «Menos del 2 %», «menos de 24 h» (1440 minutos). El limite NO cumple. */
    public static function atMost(float $value): self
    {
        return new self(AdoptionTargetComparison::AtMost, $value);
    }

    /** «−80 % sobre la linea base declarada». Ver {@see AdoptionTargetComparison}. */
    public static function reductionOf(float $percent): self
    {
        return new self(AdoptionTargetComparison::Reduction, $percent);
    }

    /**
     * ¿El valor medido alcanza el objetivo?
     *
     * `null` cuando no hay valor —sin denominador no hay porcentaje— y tambien
     * cuando el objetivo no juzga nada ({@see AdoptionTargetComparison::Reduction}).
     * **`null` no es «no cumple»**: es «no se sabe», y confundir los dos es
     * exactamente como un cuadro honesto se convierte en un cuadro alarmista.
     */
    public function isMetBy(?float $value): ?bool
    {
        if ($value === null || ! $this->comparison->judgesCompliance()) {
            return null;
        }

        return match ($this->comparison) {
            AdoptionTargetComparison::AtLeast => $value >= $this->value,
            // ESTRICTO: el §1.3 dice «< 2 %» y «< 24 h». Ver el docblock del caso.
            AdoptionTargetComparison::AtMost => $value < $this->value,
            AdoptionTargetComparison::Reduction => null,
        };
    }
}
