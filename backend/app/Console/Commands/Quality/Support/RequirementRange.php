<?php

declare(strict_types=1);

namespace App\Console\Commands\Quality\Support;

use InvalidArgumentException;

/**
 * Expansor de identificadores de requisito, con y sin rango.
 *
 * El Anexo A del doc 01 esta escrito para leerse, no para procesarse:
 * `RF-ID-04..09` son seis requisitos, `RNF-M-01..06` son otros seis y
 * `**RF-GP-05**` es uno solo con negritas. El modo de fallo peligroso de la
 * trazabilidad no es que el comando reviente, sino que **de verde por no saber
 * expandir un rango**: asi fue como RF-ID-09 se quedo sin tarea que lo
 * construyera hasta que lo encontro una auditoria manual.
 *
 * Por eso esta clase hace dos cosas y las dos son ruidosas:
 *
 *   - expande el rango, o falla diciendo por que;
 *   - devuelve el TEXTO QUE NO HA SABIDO LEER (`residue`), para que quien lo
 *     llame pueda enseñarlo. Una entrada del Anexo A como «§9 completo» o
 *     «Cuadrantes, vacaciones con aprobacion» no es un identificador y no puede
 *     desaparecer en silencio.
 */
final class RequirementRange
{
    /**
     * Prefijos posibles: RF-XX (modulo de dos letras), RNF-X (categoria de una)
     * y los cuatro sin modulo (RN, RL, RS, RQ). La alternancia esta ordenada a
     * proposito: `RNF-M-01` tiene que leerse como RNF-M y no como RN.
     */
    private const string PREFIX = '(?:R(?:NF|F)-[A-Z]{1,2}|R[NLSQ])';

    /** Un identificador o un rango: `RS-08`, `RF-ID-04..09`. */
    private const string TOKEN = '/\b(?<prefix>'.self::PREFIX.')-(?<from>\d{2})(?:\.\.(?<to>\d{2}))?(?![\w-])/u';

    /** Un identificador ya expandido, sin rango. Lo usa el catalogo para validar. */
    public const string IDENTIFIER = '/^'.self::PREFIX.'-\d{2}$/u';

    /**
     * Expande un token suelto: `RF-ID-04..09` -> seis identificadores.
     *
     * @return list<string>
     */
    public static function expand(string $token): array
    {
        $trimmed = trim($token);

        if (preg_match(self::TOKEN, $trimmed, $match, PREG_UNMATCHED_AS_NULL) !== 1 || $match[0] !== $trimmed) {
            throw new InvalidArgumentException('No es un identificador de requisito ni un rango: "'.$token.'".');
        }

        return self::series(
            (string) $match['prefix'],
            (int) $match['from'],
            $match['to'] === null ? (int) $match['from'] : (int) $match['to'],
        );
    }

    /**
     * Todos los identificadores citados en un texto, con los rangos expandidos y
     * en el orden en el que aparecen.
     *
     * @return list<string>
     */
    public static function findAll(string $text): array
    {
        preg_match_all(self::TOKEN, $text, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);

        $identifiers = [];

        foreach ($matches as $match) {
            foreach (self::expand((string) $match[0]) as $identifier) {
                $identifiers[] = $identifier;
            }
        }

        return $identifiers;
    }

    /**
     * Lo que queda del texto despues de quitar los identificadores, los rangos y
     * la puntuacion que los separa. Vacio significa «lo he leido todo».
     */
    public static function residue(string $text): string
    {
        $rest = (string) preg_replace(self::TOKEN, '', $text);
        $rest = (string) preg_replace('/[,;.\s]+/u', ' ', $rest);

        return trim($rest);
    }

    /**
     * @return list<string>
     */
    private static function series(string $prefix, int $from, int $to): array
    {
        if ($to < $from) {
            throw new InvalidArgumentException('Rango invertido: '.$prefix.'-'.sprintf('%02d..%02d', $from, $to).'.');
        }

        $identifiers = [];

        for ($number = $from; $number <= $to; $number++) {
            $identifiers[] = $prefix.'-'.sprintf('%02d', $number);
        }

        return $identifiers;
    }
}
