<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

/**
 * Detector de patrones de migracion que exigen parada de servicio (**RNF-D-04**,
 * patron expand / migrate / contract).
 *
 * ## Que problema resuelve
 *
 * KronoQR se despliega en el servidor de cada cliente y se actualiza sin ventana
 * de mantenimiento: un hotel ficha a las 06:00 y a las 22:00, y una migracion que
 * tome `ACCESS EXCLUSIVE` sobre `shift_entries` deja sin fichar a quien este
 * pasando su tarjeta (regla dura 19). El coste no lo paga quien despliega: lo
 * paga quien esta en la puerta de servicio a las seis de la mañana.
 *
 * El error clasico —`ADD COLUMN ... NOT NULL` sin `DEFAULT`— no se ve en una
 * revision, porque en una base de datos de desarrollo vacia funciona. Solo falla
 * en la del cliente, que tiene doscientas mil filas.
 *
 * ## La distincion que hace util al detector: tabla nueva frente a tabla con datos
 *
 * **Ninguna de estas sentencias bloquea nada sobre una tabla que se acaba de crear
 * en la misma migracion**: esta vacia y nadie la esta leyendo todavia. Por eso una
 * migracion de creacion puede —y debe— declarar sus columnas obligatorias, sus
 * `CHECK` validados y sus tipos definitivos de una vez.
 *
 * Sin esta distincion el detector marcaria como infractoras a las once
 * migraciones de creacion del esquema, que es precisamente lo que hacen bien; y un
 * detector con once falsos positivos se silencia el primer dia. Asi que se
 * recogen las tablas que la propia migracion crea y las sentencias sobre ellas no
 * cuentan.
 *
 * ## Patrones conocidos, no exhaustividad
 *
 * Deliberado. Un analizador que intentara decidir si CUALQUIER sentencia bloquea
 * acabaria con falsos positivos que alguien acallaria, y una puerta acallada no
 * existe. Aqui estan los errores clasicos —los que se cometen escribiendo la
 * migracion de prisa— y cada uno lleva su alternativa segura en el mensaje.
 *
 * ## Solo se mira `up()`
 *
 * `down()` es una reversion: se ejecuta cuando el despliegue ya ha fallado y la
 * parada ya la hay. Exigirle lo mismo prohibiria escribir un `down()` correcto —no
 * se puede revertir un `ADD COLUMN` sin un `DROP COLUMN`—.
 *
 * ## Los comentarios se descartan antes de mirar
 *
 * Las migraciones de este repositorio explican en prosa por que hacen lo que
 * hacen, y esa prosa contiene las mismas palabras que se buscan: «la columna nace
 * `NULL` y solo despues pasa a `NOT NULL`». Buscar sobre el texto crudo marcaria
 * como infractora justo a la migracion que hace lo correcto. Se descartan con
 * `token_get_all`, que es el unico que sabe donde acaba un comentario.
 */
final class MigrationSafety
{
    /**
     * Cada patron: la expresion que lo encuentra, si la sentencia nombra la tabla
     * —y entonces se puede perdonar cuando esa tabla es nueva— y por que bloquea.
     *
     * @return array<string, array{pattern: string, names_table: bool, why: string}>
     */
    public static function blockingPatterns(): array
    {
        return [
            'ADD COLUMN NOT NULL sin DEFAULT' => [
                'pattern' => '/ALTER\s+TABLE\s+(?:ONLY\s+)?(?<table>[\w".]+)\s+ADD\s+COLUMN\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:(?!DEFAULT|;|\bADD\b).)*?\bNOT\s+NULL\b(?!(?:(?!;).)*?\bDEFAULT\b)/is',
                'names_table' => true,
                'why' => 'PostgreSQL rechaza la sentencia si la tabla tiene filas. Expand: añade la columna '
                    .'NULL, rellenala, y pon el NOT NULL en una migracion posterior.',
            ],
            'DROP COLUMN en up()' => [
                'pattern' => '/ALTER\s+TABLE\s+(?:ONLY\s+)?(?<table>[\w".]+)\s+DROP\s+COLUMN/i',
                'names_table' => true,
                'why' => 'La fase de contract va en una version POSTERIOR a la que dejo de usar la columna. '
                    .'Si va en la misma, el codigo viejo que aun corre la busca y falla.',
            ],
            'dropColumn de Blueprint en up()' => [
                'pattern' => '/->\s*dropColumn\s*\(/i',
                'names_table' => false,
                'why' => 'Mismo motivo que el DROP COLUMN en SQL: contract es otra version.',
            ],
            'ALTER COLUMN TYPE' => [
                'pattern' => '/ALTER\s+TABLE\s+(?:ONLY\s+)?(?<table>[\w".]+)\s+ALTER\s+(?:COLUMN\s+)?[\w"]+\s+(?:SET\s+DATA\s+)?TYPE\b/i',
                'names_table' => true,
                'why' => 'Reescribe la tabla entera con ACCESS EXCLUSIVE. Expand: columna nueva del tipo '
                    .'nuevo, doble escritura, copia, y contract en otra version.',
            ],
            'change() de Blueprint' => [
                'pattern' => '/->\s*change\s*\(\s*\)/i',
                'names_table' => false,
                'why' => 'Genera un ALTER COLUMN TYPE con el mismo bloqueo, y ademas sin que se vea.',
            ],
            'RENAME de tabla o de columna' => [
                'pattern' => '/ALTER\s+TABLE\s+(?:ONLY\s+)?(?<table>[\w".]+)\s+RENAME\b/i',
                'names_table' => true,
                'why' => 'Renombrar rompe al codigo viejo en el instante en que se aplica. Expand: nombre '
                    .'nuevo, doble escritura, y retirada del viejo en otra version.',
            ],
            'renameColumn de Blueprint' => [
                'pattern' => '/(?:->\s*renameColumn\s*\(|Schema::\s*rename\s*\()/i',
                'names_table' => false,
                'why' => 'Mismo motivo que el RENAME en SQL: el codigo viejo deja de encontrar la columna.',
            ],
            'ADD CONSTRAINT sin NOT VALID' => [
                'pattern' => '/ALTER\s+TABLE\s+(?:ONLY\s+)?(?<table>[\w".]+)\s+ADD\s+CONSTRAINT\s+(?:(?!NOT\s+VALID|;).)*?\b(?:CHECK|FOREIGN\s+KEY)\b(?:(?!NOT\s+VALID|;).)*?(?:;|$)/is',
                'names_table' => true,
                'why' => 'Validar la restriccion recorre la tabla entera con ACCESS EXCLUSIVE. Añadela '
                    .'NOT VALID y despues VALIDATE CONSTRAINT, que solo toma SHARE UPDATE EXCLUSIVE.',
            ],
        ];
    }

    /**
     * Los patrones bloqueantes que aparecen en ese `up()`.
     *
     * @return list<string>
     */
    public static function violationsIn(string $upCode): array
    {
        $fresh = self::tablesCreatedIn($upCode);

        $found = [];

        foreach (self::blockingPatterns() as $name => $rule) {
            if (self::applies($rule, $upCode, $fresh)) {
                $found[] = $name;
            }
        }

        return $found;
    }

    /**
     * @param  array{pattern: string, names_table: bool, why: string}  $rule
     * @param  list<string>  $freshTables
     */
    private static function applies(array $rule, string $upCode, array $freshTables): bool
    {
        if ($rule['names_table'] === false) {
            return preg_match($rule['pattern'], $upCode) === 1;
        }

        preg_match_all($rule['pattern'], $upCode, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $table = self::normalize(\is_string($match['table'] ?? null) ? $match['table'] : '');

            // Una tabla creada por esta misma migracion esta vacia y nadie la
            // lee todavia: sobre ella ninguna de estas sentencias bloquea.
            if (! \in_array($table, $freshTables, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Las tablas que esta migracion crea, para poder perdonarle lo que haga sobre
     * ellas.
     *
     * @return list<string>
     */
    public static function tablesCreatedIn(string $upCode): array
    {
        $tables = [];

        preg_match_all(
            '/(?:Schema::\s*create\s*\(\s*[\'"](?<name>[\w.]+)[\'"]|CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?<sql>[\w".]+))/i',
            $upCode,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $match) {
            // La alternancia del patron deja vacio el grupo que no participo, asi
            // que se toma el que traiga algo: `name` para `Schema::create()` y
            // `sql` para un `CREATE TABLE` escrito a mano.
            $blueprint = $match['name'] ?? '';
            $raw = $match['sql'] ?? '';

            $tables[] = self::normalize($blueprint !== '' ? $blueprint : $raw);
        }

        return array_values(array_unique(array_filter($tables, static fn (string $t): bool => $t !== '')));
    }

    /**
     * El codigo de `up()` de una migracion, sin comentarios.
     *
     * El corte por `function down` es suficiente y se declara como tal: en este
     * repositorio toda migracion tiene `up()` antes que `down()`, y una que no lo
     * tuviera se analizaria entera, que es el lado seguro del error.
     */
    public static function upCodeOf(string $source): string
    {
        $code = self::withoutComments($source);

        $downAt = mb_strpos($code, 'function down');

        return $downAt === false ? $code : mb_substr($code, 0, $downAt);
    }

    /** Sin comillas y en minusculas: `"employees"` y `employees` son la misma. */
    private static function normalize(string $table): string
    {
        return mb_strtolower(trim($table, '"'));
    }

    private static function withoutComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (\is_array($token) && \in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= \is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}
