<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * Serializacion canonica del paquete de diagnostico y su huella
 * (contrato `DiagnosticsManifest.sha256`, **RF-PD-09**).
 *
 * ## Para que sirve la huella
 *
 * El paquete **no va cifrado a proposito**: el cliente tiene que poder abrirlo y
 * comprobar que no lleva nada que no quiera enviar antes de enviarlo (ADR-020, y
 * la ficha 5.9 exige esa inspeccion manual). La contrapartida es que un fichero
 * en claro se puede alterar o truncar por el camino —un cliente de correo que
 * corta a los 5 MB, un copiar y pegar incompleto—, y soporte se pasaria una hora
 * diagnosticando un paquete a medias. La huella lo detecta en un segundo:
 * `php artisan product:diagnostics --verify=fichero.json`.
 *
 * ## Por que canonico y no `json_encode` a secas
 *
 * Porque quien recalcula la huella la recalcula sobre el fichero **releido**, y
 * un `json_decode`/`json_encode` de ida y vuelta no conserva ni el orden de las
 * claves ni los espacios. Sin una forma canonica, la comprobacion fallaria
 * siempre y no serviria para nada. Aqui: claves ordenadas en profundidad, sin
 * espacios, barras y unicode sin escapar.
 *
 * **Las listas no se ordenan.** Solo los mapas. Ordenar una lista cambiaria el
 * dato: el orden de las comprobaciones de `doctor` es informacion.
 */
final readonly class CanonicalJson
{
    /**
     * @param  array<array-key, mixed>  $document
     */
    public static function encode(array $document): string
    {
        return json_encode(
            self::canonicalize($document),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    /**
     * SHA-256 en hexadecimal de la forma canonica.
     *
     * @param  array<array-key, mixed>  $document
     */
    public static function digest(array $document): string
    {
        return hash('sha256', self::encode($document));
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function canonicalize(array $value): array
    {
        $isList = array_is_list($value);

        /** @var array<array-key, mixed> $canonical */
        $canonical = [];

        foreach ($value as $key => $item) {
            $canonical[$key] = is_array($item) ? self::canonicalize($item) : $item;
        }

        if (! $isList) {
            ksort($canonical, SORT_STRING);
        }

        return $canonical;
    }
}
