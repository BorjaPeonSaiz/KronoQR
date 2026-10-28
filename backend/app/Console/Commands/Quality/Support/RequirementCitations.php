<?php

declare(strict_types=1);

namespace App\Console\Commands\Quality\Support;

/**
 * Los identificadores de requisito que cita un texto: `RF-AT-01`, `RNF-M-04`,
 * `RN-25`, `RS-03`, `RL-19`, `RQ-13`.
 *
 * El patron es el de los identificadores del Anexo A y **nada mas**: dos cifras
 * exactas y el prefijo de su familia. Asi un ejemplo de documentacion como
 * `RF-XX` o un error tipografico como `RN-O5` no cuentan como cita, y tampoco
 * como cita de un requisito inexistente: no son un identificador. Un rango
 * `RF-ID-04..09` cuenta como cita de su primer extremo, que basta para las dos
 * preguntas que se le hacen (¿cita algo? ¿lo citado existe?).
 */
final class RequirementCitations
{
    public const string PATTERN = '/\b(?:RF-[A-Z]{2}|RNF-[A-Z]|RN|RS|RL|RQ)-\d{2}\b/';

    /**
     * @return list<string> Sin repetir, en orden de aparicion.
     */
    public static function in(string $text): array
    {
        preg_match_all(self::PATTERN, $text, $matches);

        return array_values(array_unique($matches[0]));
    }

    /**
     * Los citados que el catalogo no define.
     *
     * @param  list<string>  $cited
     * @param  array<string, int>  $catalog  Identificador -> fase, como {@see RequirementCatalog::phases()}.
     * @return list<string>
     */
    public static function unknown(array $cited, array $catalog): array
    {
        return array_values(array_filter(
            $cited,
            static fn (string $id): bool => ! \array_key_exists($id, $catalog),
        ));
    }
}
