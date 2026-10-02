<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Exception;

use App\Modules\Product\Domain\ValueObject\SettingKey;

/**
 * El acento nuevo no alcanza el contraste minimo sobre las superficies claras y nadie ha
 * confirmado que lo quiere asi (MB2, RF-PD-08).
 *
 * **No es un valor invalido**, y por eso no es {@see InvalidSettingValue}: el
 * color tiene la forma correcta y el producto sabe pintarlo —el panel y los PDF
 * lo oscurecen hasta leerse—. Lo que falta es que alguien diga que lo ha visto.
 * El borde HTTP lo traduce a un `422` con `type` propio
 * (`urn:kronoqr:problem:low-contrast-accent`) para que el panel pueda ofrecer
 * «guardar de todos modos» y repetir la peticion con `confirm_low_contrast`, en
 * vez de pintar un error junto al campo sin salida.
 *
 * El mensaje tecnico va en ingles (doc 02 §3.5); el de usuario sale de
 * `settings.errors.low_contrast_accent` en el idioma negociado.
 */
final class LowContrastAccentColor extends ProductDomainException
{
    public const string TRANSLATION_KEY = 'settings.errors.low_contrast_accent';

    private function __construct(
        public readonly SettingKey $key,
        public readonly string $color,
        public readonly float $ratio,
        public readonly float $minimum,
    ) {
        parent::__construct(sprintf(
            'Setting "%s" colour %s reaches %.2f:1 against the light surfaces, below the %.1f:1 minimum, and was not confirmed.',
            $key->value,
            $color,
            $ratio,
            $minimum,
        ));
    }

    public static function of(SettingKey $key, string $color, float $ratio, float $minimum): self
    {
        return new self($key, $color, $ratio, $minimum);
    }
}
