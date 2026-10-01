<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Un color sRGB y su contraste WCAG 2.2 (criterios 1.4.3 y 1.4.11) — MB2, MB3.
 *
 * ## La misma formula que el cliente, no una parecida
 *
 * Es la traduccion literal de `packages/web-kit/src/contrast.ts` (luminancia
 * relativa de la recomendacion, umbral de linealizacion `0.04045`) y de
 * `adjustUntil()` de `branding.ts` (mezcla hacia el negro en pasos de un 2,5 %).
 * Hacen falta las dos copias porque las dos fronteras deciden sobre el mismo
 * color sin poder llamarse: el panel avisa antes de guardar y oscurece en
 * pantalla, y el servidor rechaza un `PATCH` directo sin confirmar y oscurece el
 * acento de los PDF, que no pasan por ningun navegador del cliente. Si una de
 * las dos cambia la formula, `HexColorTest` y `contrast.spec.ts` comparten los
 * valores publicados (negro/blanco 21:1, `#767676` 4,54:1) para que se note.
 *
 * **Una diferencia deliberada con `adjustUntil()`**: aqui cada candidato se
 * redondea a enteros ANTES de medirlo. El cliente mide el tono en coma flotante
 * y redondea al escribir el hexadecimal, y en el limite eso puede devolver un
 * color que, ya redondeado, se queda en 4,49:1. En un documento sellado lo que
 * cuenta es el color que se imprime, asi que se mide ese.
 *
 * ## Por que en `Shared` y no en `Product`
 *
 * Lo usan dos modulos que no pueden importarse: `Product` para validar el ajuste
 * y `Reporting` para pintar el informe sellado. Es aritmetica, no regla de
 * negocio de ninguno (criterio de admision de ADR-021 y ADR-025).
 */
final readonly class HexColor
{
    /** WCAG 2.2 AA, texto normal (1.4.3). El `text` de `WCAG_AA_MINIMUM`. */
    public const float WCAG_TEXT_MINIMUM = 4.5;

    /** WCAG 2.2 AA, texto grande y componentes (1.4.3 y 1.4.11). El `large`. */
    public const float WCAG_LARGE_MINIMUM = 3.0;

    /** Los mismos 40 pasos de un 2,5 % que `adjustUntil()` en `branding.ts`. */
    private const int ADJUSTMENT_STEPS = 40;

    private const string SHAPE = '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/';

    private function __construct(
        public int $red,
        public int $green,
        public int $blue,
    ) {}

    /**
     * Acepta `#rgb` y `#rrggbb`, como `parseHexColor()` del cliente.
     *
     * @throws InvalidArgumentException si la cadena no es un color hexadecimal
     */
    public static function fromHex(string $hex): self
    {
        $value = trim($hex);

        if (preg_match(self::SHAPE, $value) !== 1) {
            throw new InvalidArgumentException('Not a hexadecimal colour: "'.$hex.'".');
        }

        $digits = substr($value, 1);

        if (strlen($digits) === 3) {
            $digits = $digits[0].$digits[0].$digits[1].$digits[1].$digits[2].$digits[2];
        }

        return new self(
            (int) hexdec(substr($digits, 0, 2)),
            (int) hexdec(substr($digits, 2, 2)),
            (int) hexdec(substr($digits, 4, 2)),
        );
    }

    public static function white(): self
    {
        return new self(255, 255, 255);
    }

    public static function black(): self
    {
        return new self(0, 0, 0);
    }

    /** `#rrggbb` en minusculas, que es como lo publica `GET /api/v1/branding`. */
    public function toHex(): string
    {
        return sprintf('#%02x%02x%02x', $this->red, $this->green, $this->blue);
    }

    /** Luminancia relativa segun WCAG 2.2, de 0 (negro) a 1 (blanco). */
    public function relativeLuminance(): float
    {
        return 0.2126 * $this->linearize($this->red)
            + 0.7152 * $this->linearize($this->green)
            + 0.0722 * $this->linearize($this->blue);
    }

    /** Relacion de contraste, de 1 a 21. El orden no importa. */
    public function contrastWith(self $other): float
    {
        $mine = $this->relativeLuminance();
        $theirs = $other->relativeLuminance();

        return (max($mine, $theirs) + 0.05) / (min($mine, $theirs) + 0.05);
    }

    public function meetsContrast(self $background, float $minimum): bool
    {
        return $this->contrastWith($background) >= $minimum;
    }

    /**
     * El primer tono, acercandose al negro en pasos de un 2,5 %, que alcanza
     * `$minimum` sobre `$background`.
     *
     * Si el color ya llega, se devuelve tal cual: la marca del cliente no se
     * toca cuando no hace falta. Si ni el negro llega —un fondo oscuro—, se
     * devuelve el negro, que es lo mas lejos que se puede ir.
     */
    public function darkenedUntil(self $background, float $minimum): self
    {
        $black = self::black();

        for ($step = 0; $step <= self::ADJUSTMENT_STEPS; $step++) {
            $candidate = $black->mixedWith($this, $step / self::ADJUSTMENT_STEPS);

            if ($candidate->meetsContrast($background, $minimum)) {
                return $candidate;
            }
        }

        return $black;
    }

    /** Mezcla lineal con redondeo: `$weight` = 1 devuelve este, 0 devuelve `$other`. */
    private function mixedWith(self $other, float $weight): self
    {
        $channel = static fn (int $mine, int $theirs): int => (int) round($mine * $weight + $theirs * (1 - $weight));

        return new self(
            $channel($this->red, $other->red),
            $channel($this->green, $other->green),
            $channel($this->blue, $other->blue),
        );
    }

    private function linearize(int $channel): float
    {
        $value = $channel / 255;

        return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
    }
}
