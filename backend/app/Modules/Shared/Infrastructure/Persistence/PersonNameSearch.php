<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Persistence;

/**
 * La unica manera de buscar a una persona por su nombre en PostgreSQL: por
 * subcadena, sin distinguir mayusculas ni acentos y sin comodines.
 *
 * La usan el directorio de plantilla (`GET /employees`, RF-GP-01), la presencia
 * en vivo, el directorio de cuentas de gestion y la busqueda de la persona de la
 * exportacion legal (RF-IN-05). Antes cada uno escribia su propio
 * `ILIKE unaccent(?)`, y los cuatro tenian el mismo fallo.
 *
 * ## `unaccent()` a los dos lados
 *
 * `ILIKE` ignora las mayusculas pero no los diacriticos: `'García' ILIKE
 * '%garcia%'` es falso. Quien escribe en un cuadro de busqueda pone la tilde o
 * no segun le sale, y la persona es la misma. La extension la habilita la
 * migracion `2026_08_28_100000_enable_unaccent_extension`.
 *
 * ## El escape va DESPUES de `unaccent()`, dentro del SQL, y no es un detalle
 *
 * `%`, `_` y `\` se escapan para que se busquen como los caracteres que son:
 * sin eso, `%%%` casaria con la plantilla entera y el minimo de tres caracteres
 * de RF-IN-05 no valdria nada. Escaparlos en PHP, antes de enviar el termino,
 * **no basta**: `unaccent.rules` convierte el porcentaje y el subrayado de
 * ancho completo (U+FF05, U+FF3F), el porcentaje pequeño (U+FE6A) y las barras
 * invertidas U+FF3C, U+FE68 y U+2216 en `%`, `_` y `\` ASCII, y esos llegaban
 * activos al `ILIKE` despues del escape: `'Garcia' ILIKE unaccent('%％＿＿%')`
 * es verdadero. Por eso el termino viaja **en crudo** como parametro enlazado y
 * se escapa sobre el resultado de `unaccent()`, que es lo que de verdad compara
 * el `ILIKE`. La barra invertida se escapa la primera, para no duplicar las que
 * añaden los otros dos `replace()`. El caracter de escape es la barra invertida
 * por defecto de `LIKE` en PostgreSQL, con `standard_conforming_strings`, que
 * viene activo de serie desde la 9.1.
 *
 * ## Sin indice, y a proposito
 *
 * `ILIKE '%…%'` no usa un btree y `unaccent()` no es `IMMUTABLE`. Las tablas que
 * se buscan asi son de cientos de filas (doc 02, Anexo A). Si algun dia hiciera
 * falta, la palanca es `pg_trgm` con un indice GIN sobre una envoltura
 * `IMMUTABLE`, y cabe entera aqui.
 */
final class PersonNameSearch
{
    /**
     * El patron de `ILIKE`, con un marcador: el termino en crudo.
     *
     * @var literal-string
     */
    public const string PATTERN = <<<'SQL'
        ('%' || replace(replace(replace(unaccent(?), '\', '\\'), '%', '\%'), '_', '\_') || '%')
        SQL;

    private function __construct() {}

    /**
     * `(unaccent(a) ILIKE <patron> OR unaccent(b) ILIKE <patron> …)` y sus
     * parametros, uno por expresion y todos el mismo termino.
     *
     * `literal-string` en las expresiones: las columnas las escribe el adaptador
     * en su codigo y nunca llegan de una peticion, asi que el resultado puede ir
     * a `whereRaw()` o a un `select()` sin que PHPStan lo trate como SQL
     * construido con datos. El termino va siempre enlazado.
     *
     * @param  non-empty-list<literal-string>  $expressions
     * @return array{0: literal-string, 1: list<string>}
     */
    public static function anyOf(array $expressions, string $term): array
    {
        $sql = '';
        $bindings = [];

        foreach ($expressions as $expression) {
            $sql .= ($sql === '' ? '' : ' OR ').'unaccent('.$expression.') ILIKE '.self::PATTERN;
            $bindings[] = $term;
        }

        return ['('.$sql.')', $bindings];
    }
}
