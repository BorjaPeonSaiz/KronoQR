<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Policy;

use App\Modules\Product\Domain\Exception\LowContrastAccentColor;
use App\Modules\Product\Domain\ValueObject\SettingKey;
use App\Modules\Product\Domain\ValueObject\SettingValue;
use App\Modules\Shared\Domain\ValueObject\HexColor;

/**
 * Un acento sin contraste suficiente solo se guarda si alguien lo confirma (MB2).
 *
 * ## Que se mide y contra que: la MISMA regla que el panel
 *
 * El acento **tal cual**, a **4,5:1**, contra las dos superficies claras sobre
 * las que es texto: `surface` (`#fff7ed`, el fondo de pagina) y
 * `surface-raised` (`#ffffff`, tarjetas y dialogos), y cuenta el peor de los
 * dos. Es exactamente `accentContrast()` de `packages/web-kit/src/branding.ts`,
 * que es lo que la pantalla «Marca» enseña y lo que decide si pide la casilla de
 * confirmacion: si el servidor midiera otra cosa, el panel pediria confirmar un
 * color que el servidor acepta sin ella, o al reves. En el panel y el portal el
 * acento sustituye a `primary-strong`, texto de enlace y de marca sobre esas
 * dos superficies (doc 06 §2 y §7), y eso es texto normal (WCAG 1.4.3). El
 * valor de serie, `#b8542a`, da 4,56:1 sobre `surface`.
 *
 * Las dos superficies son tokens del tema base que **ningun cliente cambia**
 * (doc 06 §7: la marca solo toca la familia primaria). Estan copiadas aqui
 * porque el dominio no lee CSS, y `AccentContrastSurfacesTest` comprueba en
 * cada ejecucion que coinciden con `theme.css`.
 *
 * ## Confirmacion y no rechazo duro
 *
 * El doc 06 §7 lo dejo escrito en la 5.8 —«contraste avisado, no impuesto»— y
 * la 2.2.0 lo mantiene: el color es la marca del cliente, y desde MB1 y MB3 el
 * producto lo oscurece hasta leerse alli donde se usa como texto. Lo que no
 * puede volver a pasar es lo que encontro MB2: que un `PATCH` directo guarde un
 * acento ilegible **sin que nadie lo haya visto**. Sin la confirmacion, `422`;
 * con ella, se guarda y el asiento de `audit_log` deja constancia de quien.
 *
 * **Solo se mira lo que cambia.** Un acento ya guardado y confirmado no vuelve a
 * pedir confirmacion cada vez que se guarda el nombre de la aplicacion.
 */
final class AccentContrastPolicy
{
    public const float MINIMUM = HexColor::WCAG_TEXT_MINIMUM;

    /**
     * `--kq-color-surface` y `--kq-color-surface-raised` de `theme.css`.
     *
     * @var list<string>
     */
    public const array LIGHT_TEXT_SURFACES = ['#fff7ed', '#ffffff'];

    /**
     * @throws LowContrastAccentColor si un acento nuevo no llega y no esta confirmado
     */
    public static function assertAcceptable(bool $confirmed, SettingValue ...$changed): void
    {
        if ($confirmed) {
            return;
        }

        foreach ($changed as $value) {
            if ($value->key !== SettingKey::BRANDING_ACCENT_COLOR) {
                continue;
            }

            $color = HexColor::fromHex($value->asText());
            $ratio = self::worstContrast($color);

            if ($ratio < self::MINIMUM) {
                throw LowContrastAccentColor::of($value->key, $color->toHex(), $ratio, self::MINIMUM);
            }
        }
    }

    /** El peor contraste del color sobre las superficies claras con texto. */
    public static function worstContrast(HexColor $color): float
    {
        return min(array_map(
            static fn (string $surface): float => $color->contrastWith(HexColor::fromHex($surface)),
            self::LIGHT_TEXT_SURFACES,
        ));
    }
}
