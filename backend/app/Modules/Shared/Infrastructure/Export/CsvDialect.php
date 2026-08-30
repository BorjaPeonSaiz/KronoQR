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
 * ## Las seis decisiones, y ninguna es cosmetica
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
 * - **Neutralizacion de formulas.** Una celda que empieza por `=`, `+`, `-`,
 *   `@`, tabulador o retorno de carro es, para Excel, una formula que se
 *   ejecuta al abrir el fichero. El texto libre de estas celdas lo escriben
 *   personas —el motivo de una correccion, un apellido— y el fichero lo abre
 *   un tercero con el que no se comparte confianza: la Inspeccion de Trabajo,
 *   RRHH, el propio empleado. Se antepone una comilla simple, la marca de
 *   texto de Excel, que no se muestra en la celda. Un numero negativo puro
 *   (`-30`) se deja intacto: es un numero, no una formula, y anteponerle la
 *   comilla lo convertiria en texto y romperia las sumas de quien lo reciba.
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

    /**
     * El delimitador de los idiomas cuyo separador decimal es el punto.
     *
     * **La unica decision de esta clase que depende del idioma, y no es una
     * grieta en su premisa.** El punto y coma no es una preferencia estetica: es
     * lo que Excel espera **cuando la coma es el separador decimal**. En una
     * instalacion en ingles la coma no lo es, y ahi un fichero con `;` se abre
     * con todas las columnas metidas en la primera celda — exactamente el fallo
     * que el `;` existe para evitar en español.
     *
     * Se elige por {@see self::delimiterFor()}, con el idioma de la instalacion
     * (ADR-017, regla dura 13), y **no lo elige quien escribe el fichero**: sigue
     * sin haber forma de pedir «otro dialecto». Los dos ficheros con efectos
     * legales —la exportacion a la Inspeccion y el historico propio del portal—
     * no lo usan y se quedan con el `;` de siempre: aquellos se entregan a un
     * tercero español y su formato no puede depender del idioma de una pantalla.
     */
    public const string DELIMITER_DOT_DECIMAL = ',';

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
     * El delimitador que espera la hoja de calculo de quien habla ese idioma.
     *
     * Los idiomas de coma decimal —español, y con el la practica totalidad de
     * Europa continental— llevan `;`; el resto, `,`. Se decide por el idioma de
     * la instalacion y no por una cabecera `Accept-Language`, por dos razones: el
     * producto no negocia idioma por peticion (no hay middleware que lo haga) y,
     * sobre todo, **el idioma que importa es el del programa que abrira el
     * fichero**, no el del navegador que lo descargo.
     *
     * La lista es de idiomas y no de paises a proposito: el producto se configura
     * con `app.locale`, que es lo que hay.
     */
    public static function delimiterFor(string $locale): string
    {
        // El prefijo, para que `es_ES` y `es-ES` se comporten como `es`.
        $language = strtolower(substr($locale, 0, 2));

        return \in_array($language, self::COMMA_DECIMAL_LANGUAGES, true)
            ? self::DELIMITER
            : self::DELIMITER_DOT_DECIMAL;
    }

    /**
     * Idiomas cuyo separador decimal es la coma y que por tanto necesitan `;`.
     *
     * Solo los que el producto declara en `config/app.php` mas los vecinos
     * evidentes: una lista exhaustiva de locales seria una tabla de datos
     * disfrazada de constante, y el criterio de admision es que alguien pueda
     * instalar KronoQR en ese idioma.
     *
     * @var list<string>
     */
    private const array COMMA_DECIMAL_LANGUAGES = ['es', 'ca', 'gl', 'eu', 'pt', 'fr', 'de', 'it', 'nl'];

    /**
     * Primer caracter con el que Excel decide que la celda es una formula.
     * `-` esta en la lista aunque tambien empiece numeros: la excepcion para el
     * numero puro vive en `neutralized()`, no aqui.
     */
    private const array FORMULA_TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Una fila. Un array vacio escribe la linea en blanco que separa el bloque
     * de criterios de la tabla —la que permite que una hoja de calculo reconozca
     * la tabla al seleccionarla—.
     *
     * @param  resource  $handle
     * @param  array<array-key, string>  $cells
     * @param  string  $delimiter  Solo para que la exportacion de conveniencia (RF-IN-04)
     *                             pueda pedir el de {@see self::delimiterFor()}. Los ficheros
     *                             con efectos legales no lo pasan y se quedan con el `;` de
     *                             siempre. Entrecomillado, escapado y fin de linea siguen sin
     *                             ser negociables.
     */
    public static function writeRow($handle, array $cells, string $delimiter = self::DELIMITER): void
    {
        $written = fputcsv(
            $handle,
            array_map(self::neutralized(...), array_values($cells)),
            $delimiter,
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

    /**
     * La celda, con la marca de texto de Excel delante si su primer caracter
     * la convertiria en formula. Ver el docblock de la clase: el fichero lo
     * abre un tercero, y `=HYPERLINK(...)` en un motivo de correccion se
     * ejecutaria en su hoja de calculo.
     */
    private static function neutralized(string $cell): string
    {
        if ($cell === '' || ! \in_array($cell[0], self::FORMULA_TRIGGERS, true)) {
            return $cell;
        }

        // Un numero negativo puro es un numero: Excel no lo ejecuta y la
        // comilla si lo estropearia (dejaria de sumar en la hoja de quien lo
        // recibe). Cualquier otra cosa que empiece por `-` —`-2+3+cmd|...`—
        // sigue siendo formula y se neutraliza.
        //
        // Y una DURACION NEGATIVA en `HH:MM` tampoco es una formula. Es la
        // desviacion entre lo trabajado y lo contratado (RF-IN-03, RF-IN-04), y
        // aparece en casi todas las filas de un informe de horas: con la comilla
        // delante, quien abre el fichero lee literalmente «'-68:34» —el
        // apostrofo de Excel solo es invisible al TECLEAR en una celda, no al
        // importar un CSV— y una columna entera con basura delante de cada cifra
        // se lee como un fallo de la exportacion.
        //
        // El patron es deliberadamente estrecho —signo, digitos, dos puntos y
        // exactamente dos digitos— asi que no puede alojar ninguna carga: ni
        // `cmd|`, ni una referencia DDE, ni un nombre de funcion. Se comprueba en
        // `CsvDialectTest`.
        if (preg_match('/^-\d+(?:[.,]\d+)?$/', $cell) === 1
            || preg_match('/^-\d+:\d{2}$/', $cell) === 1) {
            return $cell;
        }

        return "'".$cell;
    }
}
