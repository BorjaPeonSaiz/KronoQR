<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

/**
 * Un criterio de inclusion del informe: **una clave de traduccion y, si hace
 * falta, lo que se le sustituye dentro** (RF-IN-01, RF-IN-04).
 *
 * ## Por que dejo de bastar una lista de cadenas
 *
 * Hasta la tarea 3.10 los criterios eran claves sueltas: ocho frases fijas que
 * la capa de presentacion resolvia contra `lang/{es,en}/reports.php`. RF-GP-04
 * trajo el primero que **depende del propio informe**: «N festivos del perfil X
 * en el periodo no cuentan como absentismo». Con una lista de cadenas solo
 * quedaban dos salidas, y las dos malas: traducir en el dominio —que no tiene
 * idioma— o que cada formato recompusiera la frase por su cuenta, que es como se
 * acaba con el JSON diciendo una cosa y el PDF otra.
 *
 * Asi que el dominio sigue **sin traducir nada**: transporta la clave y los
 * valores, y quien sabe en que idioma esta hablando los junta.
 *
 * Las sustituciones son `string` y no `mixed` a proposito: quien las compone es
 * quien decide como se escribe un numero, y un `int` suelto llegaria al texto
 * formateado por PHP y no por la traduccion.
 */
final readonly class ReportCriterion
{
    /**
     * @param  string  $key  clave de `lang/*\/reports.php`, sin traducir
     * @param  array<string, string>  $replacements  valores de los `:marcadores` de esa linea
     */
    public function __construct(
        public string $key,
        public array $replacements = [],
    ) {}

    /**
     * Atajo para los criterios fijos, que son casi todos.
     *
     * @param  list<string>  $keys
     * @return list<self>
     */
    public static function listOf(array $keys): array
    {
        return array_map(static fn (string $key): self => new self($key), $keys);
    }
}
