<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

/**
 * Lee los comentarios de un fichero PHP y busca en ellos lo que un comentario
 * de produccion no debe decir.
 *
 * Es el gemelo PHP de la regla ESLint `kronoqr-comments/no-plan-references`
 * (`packages/web-kit/eslint/no-plan-references.js`), con el mismo criterio para
 * que el backend y las SPA no discrepen sobre que es una referencia al plan:
 *
 * - «bloque N», «tarea N.N», «ficha N.N», «desde la N.N», «decision N»;
 * - hallazgos y revisiones («hallazgo B1», «revision de codigo», «segunda
 *   vuelta», «cierre de la Fase 5»);
 * - ids de verificacion (`V21-1-RV-5`, `R4-SC-05`, `KI7-01`, `PIN-03`) y los
 *   ids cortos cuando citan: dentro de un parentesis (`(C1)`, `(RF-QR-08,
 *   HC1)`) o al principio de una linea seguidos de dos puntos.
 *
 * Siguen permitidos los requisitos (`RF-*`, `RN-*`, `RL-*`, `RS-*`, `RQ-*`), los
 * ADR —con sus decisiones y condiciones numeradas: `ADR-048 decision 9`,
 * `ADR-045 C3`—, las reglas duras, las secciones de un documento y las versiones
 * publicadas, que llevan tres numeros (`desde la 2.2.0`).
 *
 * Ademas, y solo en el backend, busca comentarios que justifican el codigo con
 * un modelo de varios centros o con traslados de empleados entre centros, que
 * ADR-040 y ADR-056 descartan: hay un centro por instalacion.
 */
final class CommentReferences
{
    private const string SHORT_ID = '(?!(?:A[3-5]|MP[34]|FH\d|KQ[A-Z]?\d|SHA\d)(?![\w.-]))[A-Z]{1,3}\d{1,2}(?![\w.-])';

    /**
     * Los comentarios de un fichero PHP: linea de inicio y texto.
     *
     * @return list<array{line: int, text: string}>
     */
    public static function commentsOf(string $code): array
    {
        $comments = [];

        foreach (token_get_all($code) as $token) {
            if (\is_array($token) && \in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $comments[] = ['line' => $token[2], 'text' => $token[1]];
            }
        }

        return $comments;
    }

    /**
     * Cada comentario de un fichero que cae en la regla, como `linea «texto»`.
     *
     * @return list<string>
     */
    public static function findingsIn(string $code): array
    {
        $findings = [];

        foreach (self::commentsOf($code) as $comment) {
            $found = self::planReference($comment['text']) ?? self::multiSiteReference($comment['text']);

            if ($found !== null) {
                $findings[] = $comment['line'].' «'.$found.'»';
            }
        }

        return $findings;
    }

    /** La primera referencia al plan o a una revision que contiene el texto, o `null`. */
    public static function planReference(string $text): ?string
    {
        // Las decisiones numeradas de un ADR son el motivo, no la historia:
        // «ADR-048 decision 9» pasa; «decision 9» a secas, no.
        $text = (string) preg_replace('~\bADR-\d{3},?\s+(?:§\S+\s+)?decisi(?:o|ó)n(?:es)?\s+\d+(?:\s*(?:,|y)\s*\d+)*~iu', 'ADR', $text);
        // Y sus condiciones o apartados con letra y cifra: «ADR-045 C3»,
        // «ADR-045 §g, condicion C4», «ADR-061 A2» o «condiciones C1, C3 y C9».
        // Un id suelto entre parentesis, sin su ADR, sigue sin pasar.
        $label = '[A-Z]{1,2}\d{1,2}(?![\w.-])(?:\s*(?:,|y|/)\s*[A-Z]{1,2}\d{1,2}(?![\w.-]))*';
        $text = (string) preg_replace('~\bcondici(?:o|ó)n(?:es)?\s+'.$label.'~u', 'condicion', $text);
        $text = (string) preg_replace('~\bADR-\d{3}(?:\s+§[\w-]+)?,?\s+'.$label.'~u', 'ADR', $text);

        return self::firstMatch($text, self::planPatterns());
    }

    /**
     * La primera justificacion con varios centros o traslados, o `null`.
     *
     * Busca sobre el comentario normalizado ({@see self::prose()}): la frase
     * puede llegar partida entre dos lineas de `//`, o con una palabra en
     * negrita (`el plazo **mas largo de todos los centros**`).
     */
    public static function multiSiteReference(string $text): ?string
    {
        return self::firstMatch(self::prose($text), [
            self::phrase('\bvari(?:o|a)s centros\b'),
            self::phrase('\bm(?:a|á)s de un centro\b'),
            self::phrase('\botros? centros?\b'),
            self::phrase('\btod(?:o|a)s los centros\b'),
            self::phrase('\bcambi(?:a|o|e|en|ar|arle)? de centro\b'),
            // Un cambio de departamento si existe; el de centro, no.
            '~\btraslados?\b(?!\s+de\s+departamento)~iu',
        ]);
    }

    /**
     * @return list<string>
     */
    private static function planPatterns(): array
    {
        return [
            // Tambien en ingles: los comentarios de `lang/en/` hablan ese idioma.
            '~\b(?:bloques?|blocks?)\s+[A-Z]?\d+[a-z]?(?:\.\d+)?\b~iu',
            self::phrase('\b(?:tareas?|fichas?|tasks?) \d+\.\d+[a-z]?\b'),
            self::phrase('\b(?:de|desde|tras) la \d+\.\d+[a-z]?\b(?![.,]\w)'),
            // «decision 7» y «decision 13c» de una ficha.
            '~\bdecisi(?:o|ó)n(?:es)?\s+\d{1,2}[a-z]?\b(?!-\d)~iu',
            self::phrase('\bdecisi(?:o|ó)n(?:es)? de la (?:\d|ficha\b|tarea\b)'),
            '~\bhallazgos?\s+[A-Z]{1,3}\d*-?\d+\b~iu',
            self::phrase('\bhallazgos? (?:de|del) (?:la )?revisi(?:o|ó)n\b'),
            self::phrase('\brevisi(?:o|ó)n(?:es)? (?:de la \d|de UI/UX|de c(?:o|ó)digo|de seguridad|de QA)'),
            self::phrase('\bcierre de la Fase \d'),
            self::phrase('\b(?:primera|segunda|tercera|cuarta|quinta|ultima|última) vuelta\b'),
            '~\b[A-Z]{1,3}\d+(?:-\d+)?-[A-Z]{2,3}-\d+\b~u',
            '~\b(?!SHA)[A-Z]{2,3}\d-\d{2,3}\b~u',
            '~\b(?:PIN|AUD)-\d+\b~u',
            // Id corto dentro de un parentesis: justo tras `(` o tras `,` `;` `:` `y`.
            '~\((?:[^()]*?(?:[,;:]|\sy))?\s*\K'.self::SHORT_ID.'(?=\s*[,;:)]|\s+(?:y|de|del)\s)~u',
            // Id corto al principio de una linea, seguido de dos puntos (`// G1: ...`).
            '~(?:^|\n)[\s*\/#]*\K'.self::SHORT_ID.'(?=:)~u',
        ];
    }

    /**
     * El texto de un comentario como prosa corrida: sin los delimitadores
     * `/*` y `*\/`, sin el margen `*`, `//` o `#` de cada linea, sin la negrita
     * `**` y con los espacios colapsados.
     */
    private static function prose(string $comment): string
    {
        $text = (string) preg_replace('~/\*+|\*+/~u', ' ', $comment);
        $text = (string) preg_replace('~^[ \t]*(?:\*(?!\*)|//+|#)~mu', '', $text);

        return trim((string) preg_replace('~\s+~u', ' ', str_replace('**', '', $text)));
    }

    /**
     * Frase de varias palabras: cada espacio admite tambien el salto de linea
     * con el margen ` * ` de un docblock. Sin distinguir mayusculas.
     */
    private static function phrase(string $source): string
    {
        return '~'.str_replace(' ', '[\s*]+', $source).'~iu';
    }

    /**
     * @param  list<string>  $patterns
     */
    private static function firstMatch(string $text, array $patterns): ?string
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $found) === 1) {
                // En una sola linea, sin el margen ` * ` del bloque que la partia.
                return trim((string) preg_replace('/\s*\n[\s*]*/', ' ', $found[0]));
            }
        }

        return null;
    }
}
