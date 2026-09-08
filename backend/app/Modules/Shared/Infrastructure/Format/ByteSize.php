<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Format;

/**
 * Bytes en algo que se lee de un vistazo, escrito **una sola vez**.
 *
 * ## Por que existe
 *
 * KronoQR le enseña un tamaño a la misma persona por dos caminos: el espacio
 * libre en disco de `product:doctor` (`DiskProbe`) y el tamaño del ZIP de la
 * exportacion integra (`product:export-all`). Los dos los lee quien administra
 * el servidor, casi siempre con prisa y a veces en la misma sesion.
 *
 * Cuando cada uno tenia su propio formateador, **dejaron de coincidir sin que
 * nadie se enterara**: uno decia `1.2 GiB` y el otro `1,2 GiB`, uno llegaba a
 * `TiB` y el otro se paraba en `GiB` —de modo que un disco de dos teras salia
 * como «2048.0 GiB»—. Nada fallaba; simplemente el producto pasó a tener dos
 * formatos para el mismo dato. Por eso esto no es una funcion en un sitio comodo:
 * es el unico sitio donde se decide.
 *
 * ## Las decisiones, y ninguna es cosmetica
 *
 * - **Unidades binarias** (`KiB`, `MiB`, `GiB`, `TiB`) y no decimales. Es lo que
 *   dicen `df -h`, `ls -lh` y `docker system df`, que es contra lo que quien lee
 *   esto va a comparar. Un `GB` decimal al lado de un `df` en `GiB` invita a
 *   pensar que falta espacio que si esta.
 * - **Un decimal.** `1.2 GiB` decide igual de bien que `1.23 GiB` y se lee mas
 *   rapido; con cero, `1 GiB` y `1.9 GiB` serian el mismo texto.
 * - **Separador decimal el punto**, no la coma. Este texto lo lee quien
 *   administra el servidor junto a la salida de `df`, no un informe traducido: la
 *   coherencia que importa es con las herramientas del sistema. Los documentos
 *   que si dependen del idioma —el CSV, los informes— tienen su propio camino.
 * - **`TiB` como techo.** Por encima no hay disco de hotel, y una unidad mas
 *   seria una rama que nadie ejecutaria nunca.
 *
 * ## Lo que NO decide
 *
 * Ni si el numero es un aviso, ni de que fichero es, ni en que idioma va la frase
 * que lo rodea. Aqui solo esta el numero y su unidad.
 */
final class ByteSize
{
    /** Ver el docblock: binarias, porque es lo que dice `df -h`. */
    private const array UNITS = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];

    /**
     * No se instancia. Es una decision de formato, no un colaborador: un
     * formateador inyectable seria exactamente la puerta que esta clase cierra.
     */
    private function __construct() {}

    public static function human(int $bytes): string
    {
        $value = (float) $bytes;
        $unit = 0;

        while ($value >= 1024.0 && $unit < \count(self::UNITS) - 1) {
            $value /= 1024.0;
            $unit++;
        }

        return round($value, 1).' '.self::UNITS[$unit];
    }
}
