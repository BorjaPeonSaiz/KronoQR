<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Persistence;

use App\Modules\Shared\Domain\ValueObject\NationalId;

/**
 * La expresion SQL con la que se calcula `employees.national_id_hash` (RL-08),
 * en un unico sitio.
 *
 * ## Por que existe
 *
 * El documento de identidad no se guarda nunca en claro: se convierte en
 * `digest(?, 'sha256')` —de `pgcrypto`— **dentro de la propia sentencia**, con
 * parametro enlazado, para que el valor no exista como cadena en memoria mas
 * alla de la llamada ni aparezca en el texto de la consulta, que puede acabar en
 * el registro de consultas lentas de PostgreSQL.
 *
 * Escriben o comparan con esa expresion el alta de `Workforce`, la importacion
 * masiva y la busqueda de la persona de la exportacion legal de `Compliance`
 * (RF-IN-05). Si una de las tres usara otro algoritmo, u otra normalizacion, la
 * comparacion no casaria nunca: la reimportacion crearia la plantilla de nuevo
 * (regla dura 5) y el auditor no encontraria a nadie por su DNI. Por eso los
 * tres la toman de aqui y el valor de entrada es siempre un {@see NationalId},
 * que ya viene normalizado.
 *
 * ## Por que el algoritmo va como parametro y no en el texto
 *
 * Es como lo escribian los adaptadores originales y no cambia el plan: PostgreSQL
 * evalua `digest($1, $2)` una sola vez por sentencia, porque es una funcion
 * `IMMUTABLE` sobre parametros, y la comparacion por igualdad sigue pudiendo usar
 * el indice unico parcial `employees_national_id_hash_unique`
 * (`NationalIdSearchIndexUsageTest`).
 */
final class NationalIdDigest
{
    /** El algoritmo de `pgcrypto` con el que se calcula la columna. */
    public const string ALGORITHM = 'sha256';

    /** La expresion, con sus dos marcadores: el documento y el algoritmo. */
    public const string EXPRESSION = 'digest(?, ?)';

    private function __construct() {}

    /**
     * `<columna> = digest(?, ?)`, para un `WHERE`.
     *
     * `literal-string` en los dos lados: la columna la escribe el adaptador en
     * su codigo, nunca llega de una peticion, y asi el resultado puede ir a
     * `whereRaw()` sin que PHPStan lo trate como SQL construido con datos.
     *
     * @param  literal-string  $column
     * @return literal-string
     */
    public static function matches(string $column = 'national_id_hash'): string
    {
        return $column.' = '.self::EXPRESSION;
    }

    /**
     * Los dos valores que enlazan los marcadores de {@see self::EXPRESSION}, en
     * su orden.
     *
     * @return array{0: string, 1: string}
     */
    public static function bindings(NationalId $nationalId): array
    {
        return [$nationalId->value, self::ALGORITHM];
    }
}
