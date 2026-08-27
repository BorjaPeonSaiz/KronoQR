<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Export;

use RuntimeException;

/**
 * Las decisiones de codificacion del CSV **del producto**, escritas una vez.
 *
 * ## Por que existe
 *
 * KronoQR entrega dos ficheros CSV distintos —la exportacion legal que va a la
 * Inspeccion (RL-06, RF-IN-05) y el historico propio que se descarga del portal
 * (RF-ID-05, RL-05)— y su **contenido** es y debe ser distinto: alcance,
 * manifiesto, asiento de auditoria y destinatario no tienen nada que ver. Su
 * **codificacion**, en cambio, tiene que ser la misma: son dos salidas del mismo
 * producto y quien las abre es la misma persona con el mismo Excel.
 *
 * Cuando cada escritor declaraba sus propias constantes, dejaron de coincidir
 * sin que nadie se enterara: el historico propio salia con fin de linea `\r\n` y
 * el que se entrega a la Inspeccion con `\n`. Nada fallo; simplemente el
 * producto pasó a tener dos formatos. Por eso esto no es un puñado de constantes
 * en un sitio comodo: los dos metodos de escritura existen para que **no haya
 * forma** de escribir una fila con otro delimitador, otro entrecomillado u otro
 * fin de linea sin borrar antes esta clase.
 *
 * ## Las cinco decisiones, y ninguna es cosmetica
 *
 * - **BOM.** Sin marca de orden de bytes, Excel con configuracion regional
 *   española lee el fichero en Windows-1252 y «Duración» sale «DuraciÃ³n». Un
 *   documento con efectos legales donde los apellidos salen rotos no se entrega.
 * - **Punto y coma.** Donde la coma es el separador decimal, Excel espera `;`;
 *   con `,` mete todas las columnas en la primera celda.
 * - **Comilla doble como entrecomillado.** El del RFC 4180, que duplica la
 *   comilla dentro del campo.
 * - **Sin escapado propietario.** El mecanismo de PHP con barra invertida no es
 *   CSV estandar: con el, un motivo de correccion que contenga `\"` sale mal en
 *   cualquier hoja de calculo. Ademas, en PHP 8.4 dejar el valor por omision
 *   esta obsoleto, asi que se pasa explicito y vacio.
 * - **`\r\n` como fin de linea.** Es el que exige el RFC 4180 y el que espera
 *   Excel en Windows, que es donde se abre esto.
 *
 * ## Lo que NO decide esta clase
 *
 * Ni las columnas, ni los rotulos, ni el idioma, ni si las horas van en `HH:MM`.
 * Eso es contenido y pertenece a cada escritor. Aqui solo estan los bytes que
 * envuelven a las celdas.
 */
final class CsvDialect
{
    /**
     * UTF-8 en su forma de tres bytes: `EF BB BF`. Se escribe como punto de
     * codigo para que el fichero fuente no dependa de que nadie «limpie» unos
     * bytes invisibles al principio de una cadena.
     */
    public const string BYTE_ORDER_MARK = "\u{FEFF}";

    public const string DELIMITER = ';';

    public const string ENCLOSURE = '"';

    /** Ver el docblock de la clase: vacio a proposito, no por omision. */
    public const string NO_ESCAPE = '';

    public const string END_OF_LINE = "\r\n";

    /**
     * `charset=utf-8` **ademas** del BOM: el BOM es para el programa que abre el
     * fichero descargado y esto para el que lo consuma por HTTP.
     */
    public const string CONTENT_TYPE = 'text/csv; charset=utf-8';

    /**
     * No se instancia. Es un conjunto de decisiones, no un colaborador: un
     * escritor que pudiera recibir «otro dialecto» seria exactamente la puerta
     * que esta clase cierra.
     */
    private function __construct() {}

    /**
     * La marca de orden de bytes, antes que nada.
     *
     * @param  resource  $handle
     */
    public static function writeByteOrderMark($handle): void
    {
        fwrite($handle, self::BYTE_ORDER_MARK);
    }

    /**
     * Una fila. Un array vacio escribe la linea en blanco que separa el bloque
     * de criterios de la tabla —la que permite que una hoja de calculo reconozca
     * la tabla al seleccionarla—.
     *
     * @param  resource  $handle
     * @param  array<array-key, string>  $cells
     */
    public static function writeRow($handle, array $cells): void
    {
        $written = fputcsv(
            $handle,
            array_values($cells),
            self::DELIMITER,
            self::ENCLOSURE,
            self::NO_ESCAPE,
            self::END_OF_LINE,
        );

        if ($written === false) {
            // Un CSV a medias es peor que ninguno: quien lo reciba no tiene
            // forma de saber que le falta el final.
            throw new RuntimeException('No se ha podido escribir una fila del fichero CSV.');
        }
    }
}
