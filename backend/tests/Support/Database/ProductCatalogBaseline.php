<?php

declare(strict_types=1);

namespace Tests\Support\Database;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * El contenido que las migraciones dejan en los **catalogos de producto**, para
 * poder devolverlos a ese estado entre pruebas.
 *
 * ## Por que hace falta
 *
 * {@see CommittedDatabase} no vacia los catalogos —el perfil de cumplimiento, los
 * umbrales de instalacion, los roles y sus permisos son dato de producto, no dato
 * de prueba (regla dura 14)— pero **conservar no es restaurar**: una prueba que
 * confirma y ademas los MUTA deja el producto configurado de otra manera para
 * todo lo que venga despues en el mismo proceso, y la transaccion de
 * `RefreshDatabase` no puede revertir lo que confirmo otro proceso.
 *
 * Ese fallo se midio: `SettingsConcurrencyTest` deja
 * `ATTENDANCE_MAX_SHIFT_HOURS` en un valor entre 8 y 13 —el del ultimo escritor
 * que confirmo— y `installation_settings` sobrevivia al vaciado. Cuando ese valor
 * caia en 8, la correccion de nueve horas de
 * `Tests\Feature\Reporting\EmployeeWorkDaysTest` pasaba a clasificarse
 * `anomalous` en lugar de `closed` (RN-08): otro fichero, otro modulo, ninguna
 * pista. Un fallo cada seis ejecuciones, y solo cuando las dos suites corren
 * juntas.
 *
 * ## Por que aqui y no con un `afterEach` en cada prueba
 *
 * `ComplianceProfileConcurrencyTest` lo resolvia a mano, reescribiendo los
 * valores de la migracion en un `afterEach`. Funciona hasta que alguien escribe
 * la prueba siguiente y no se acuerda —que es lo que paso— y ademas envejece:
 * esos valores son una copia del contenido de una migracion, asi que el dia que
 * la migracion cambie el `afterEach` restaura el perfil equivocado sin decir
 * nada. La linea de defensa tiene que estar en el soporte de pruebas, donde no se
 * puede olvidar.
 *
 * ## El contenido se lee, no se escribe
 *
 * Se fotografia lo que haya **una vez por proceso**, en el primer vaciado, cuando
 * la base acaba de migrarse y nadie ha confirmado nada todavia. Asi el dia que
 * una migracion siembre otra fila o cambie un umbral, la linea base cambia con
 * ella y aqui no hay nada que tocar.
 *
 * El estado es estatico y de proceso a proposito: una propiedad estatica de trait
 * pertenece a **cada clase** que lo compone, y con Pest cada fichero de prueba es
 * una clase distinta, de modo que la foto se tomaria una vez por fichero —la
 * segunda ya con el producto mutado por la primera—.
 */
final class ProductCatalogBaseline
{
    /**
     * Filas por tabla, tal y como las dejo la migracion.
     *
     * @var array<string, list<array<string, mixed>>>
     */
    private static array $rows = [];

    private static bool $captured = false;

    /**
     * Fotografia los catalogos la primera vez y no vuelve a hacerlo.
     *
     * @param  list<string>  $tables
     */
    public static function captureOnce(ConnectionInterface $connection, array $tables): void
    {
        if (self::$captured) {
            return;
        }

        foreach ($tables as $table) {
            self::$rows[$table] = self::read($connection, $table);
        }

        self::$captured = true;
    }

    /**
     * Devuelve los catalogos a la foto, si alguno se ha movido.
     *
     * **Se comprueba antes de tocar nada** porque el caso normal es que nadie los
     * haya movido: la mayoria de las pruebas no escriben configuracion, y borrar y
     * reinsertar cinco tablas en cada vaciado seria pagar en todas por lo que hacen
     * dos.
     *
     * **Y si una se movio, se restauran todas.** El orden lo imponen las claves
     * ajenas entre catalogos —`permissions` antes que `role_has_permissions`— y
     * escribirlo a mano seria otra lista que envejece. Se resuelve con el mismo
     * bucle de reintentos que usa {@see CommittedDatabase::emptyDatabase()}, a los
     * dos lados: cada pasada hace lo que puede y deja para la siguiente lo que
     * todavia choca. Con un grafo sin ciclos converge en tantas pasadas como
     * profundidad tenga.
     *
     * **El orden inverso al de borrado no vale como orden de insercion**, y ese
     * fue el primer intento: las claves ajenas de `spatie/permission` son
     * `ON DELETE CASCADE`, asi que borrar `roles` se lleva por delante
     * `role_has_permissions` y las dos «se borran» en la misma pasada, sin que el
     * orden diga nada de sus dependencias.
     */
    public static function restore(ConnectionInterface $connection): void
    {
        if (! self::hasDrifted($connection)) {
            return;
        }

        self::converge(
            array_keys(self::$rows),
            static fn (string $table): mixed => $connection->table($table)->delete(),
            'vaciar',
        );

        self::converge(
            array_keys(array_filter(self::$rows, static fn (array $rows): bool => $rows !== [])),
            static fn (string $table): mixed => $connection->table($table)->insert(self::$rows[$table]),
            'restaurar',
        );
    }

    /**
     * Aplica `$operation` a cada tabla, reintentando las que chocan con una clave
     * ajena hasta que no queda ninguna.
     *
     * **Y si queda alguna, revienta.** Un catalogo a medio restaurar es
     * exactamente el estado que esta clase existe para evitar: seguir en silencio
     * dejaria la instalacion sin roles o sin umbrales y el fallo saldria mas
     * adelante, en otro fichero y sin decir de donde viene.
     *
     * @param  list<string>  $tables
     * @param  callable(string): mixed  $operation
     */
    private static function converge(array $tables, callable $operation, string $what): void
    {
        $pending = $tables;

        for ($pass = 0; $pending !== [] && $pass <= \count($tables); $pass++) {
            $blocked = [];

            foreach ($pending as $table) {
                try {
                    $operation($table);
                } catch (QueryException) {
                    $blocked[] = $table;
                }
            }

            $pending = $blocked;
        }

        if ($pending !== []) {
            throw new RuntimeException(
                'No se ha podido '.$what.' los catalogos de producto: '.implode(', ', $pending).'.',
            );
        }
    }

    private static function hasDrifted(ConnectionInterface $connection): bool
    {
        foreach (self::$rows as $table => $baseline) {
            if (self::signature(self::read($connection, $table)) !== self::signature($baseline)) {
                return true;
            }
        }

        return false;
    }

    /**
     * El contenido de una tabla, columna a columna y sin depender del orden fisico
     * de las filas.
     *
     * Los booleanos viajan como `t` y `f`, que es lo que PostgreSQL espera de un
     * parametro sin tipo: PDO enviaria `false` como cadena vacia y la reinsercion
     * moriria con «invalid input syntax for type boolean». El resto de los valores
     * —fechas, `json`, enteros— vuelven ya como texto y el servidor los convierte
     * al tipo de la columna.
     *
     * @return list<array<string, mixed>>
     */
    private static function read(ConnectionInterface $connection, string $table): array
    {
        $rows = [];

        /** @var object $row */
        foreach ($connection->table($table)->get() as $row) {
            $columns = [];

            /** @var mixed $value */
            foreach ((array) $row as $column => $value) {
                $columns[(string) $column] = \is_bool($value) ? ($value ? 't' : 'f') : $value;
            }

            ksort($columns);

            $rows[] = $columns;
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private static function signature(array $rows): string
    {
        $encoded = array_map(
            static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR),
            $rows,
        );

        sort($encoded);

        return implode('|', $encoded);
    }
}
