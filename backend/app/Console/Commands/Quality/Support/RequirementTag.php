<?php

declare(strict_types=1);

namespace App\Console\Commands\Quality\Support;

/**
 * Que es una etiqueta de requisito en una prueba (RQ-13, doc 02 §9.6).
 *
 * Un solo criterio para quien construye la matriz ({@see TagScanner}) y para
 * la puerta que exige que toda prueba cite un requisito
 * (`tests/Architecture/Support/UntaggedTests`): con dos lectores, una prueba
 * podia pasar la puerta con una etiqueta que la matriz no cuenta —`RN-O5`, con
 * la letra O— y quedar fuera de la evidencia sin que nada avisara.
 *
 * Separa los identificadores (`RequirementRange::IDENTIFIER`) del resto de
 * grupos (`slow`, `performance`) y aparta los que lo parecen sin serlo. Si el
 * identificador existe en el catalogo lo dice el catalogo, no esta clase.
 */
final class RequirementTag
{
    /** Lo que alguien ha querido escribir como requisito, bien o mal. */
    private const string LOOKS_LIKE_REQUIREMENT = '/^@?R[A-Z]{0,3}-/u';

    /**
     * @param  list<string>  $values  Los valores tal como van en la prueba, con o sin `@`.
     * @return array{requirements: list<string>, malformed: list<string>}
     */
    public static function classify(array $values): array
    {
        $requirements = [];
        $malformed = [];

        foreach ($values as $value) {
            $candidate = ltrim($value, '@');

            if (preg_match(RequirementRange::IDENTIFIER, $candidate) === 1) {
                $requirements[] = $candidate;

                continue;
            }

            if (preg_match(self::LOOKS_LIKE_REQUIREMENT, $value) === 1) {
                $malformed[] = $value;
            }
        }

        return ['requirements' => array_values(array_unique($requirements)), 'malformed' => $malformed];
    }
}
