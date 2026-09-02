<?php

declare(strict_types=1);

namespace Tests\Support\Database;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase as FrameworkRefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;

/**
 * Base de datos migrada pero **sin la transaccion envolvente** de
 * `RefreshDatabase`.
 *
 * **Existe por una sola razon y no debe usarse para nada mas.** Las pruebas de
 * concurrencia del §9.4 —diez peticiones paralelas con el mismo `scan_id`,
 * treinta empleados fichando a la vez— necesitan procesos de verdad, y un
 * proceso hijo abre su propia conexion a PostgreSQL: **no puede ver lo que la
 * transaccion del proceso padre todavia no ha confirmado**. Con
 * `RefreshDatabase`, los hijos no encontrarian ni el centro, ni el empleado, ni
 * el quiosco, y la prueba comprobaria una carrera entre diez peticiones que
 * fallan todas por lo mismo.
 *
 * Simular la concurrencia en un solo proceso no vale aqui. Lo que se quiere
 * demostrar es que la garantia la da **el UNIQUE de `scan_events.scan_id`** y no
 * el codigo PHP (regla dura 8): diez llamadas seguidas en el mismo proceso
 * pasarian igual con un `SELECT` previo, que es justo la implementacion que esta
 * prohibida por tener condicion de carrera.
 *
 * ## El aislamiento: vaciado con el rol propietario, a los dos lados
 *
 * Una prueba que **confirma** rompe el aislamiento que el resto de la suite da
 * por hecho: sus filas siguen ahi cuando termina. Se vacia antes —para no
 * heredar nada— y despues —para no dejarle nada a la siguiente—.
 *
 * **Con el rol de migracion y no con el de la aplicacion**, y es la misma
 * garantia funcionando: el rol de la aplicacion **no puede vaciar `audit_log`**
 * (regla dura 6). Sin esto, los asientos de los fichajes concurrentes se
 * quedarian y la prueba de la cadena de `Compliance` —que cuenta desde la
 * genesis— fallaria despues, sin relacion aparente con quien la rompio.
 *
 * **La lista de tablas se descubre, no se escribe.** Una lista a mano se queda
 * vieja en cuanto otra tarea anade una tabla, y el sintoma seria una prueba
 * ajena que falla solo al ejecutar la suite entera. Se preguntan a `pg_class`,
 * se excluyen las particiones —las vacia su tabla padre— y se conservan los
 * **catalogos que siembran las migraciones**: el perfil de cumplimiento, los
 * umbrales operativos y los roles no son dato de prueba, son dato de producto
 * (regla dura 14), y vaciarlos dejaria la instalacion sin con que calcular.
 *
 * **Conservar no es restaurar, y esa diferencia costo un fallo intermitente.**
 * Los catalogos no se vacian, pero estas pruebas **si los mutan** —cambiar
 * configuracion es justo lo que hacen— y lo confirman. Sin devolverlos a como los
 * dejo la migracion, el producto queda configurado de otra manera para todo lo que
 * venga despues en el proceso. Lo hace {@see ProductCatalogBaseline}; el fallo
 * concreto que evita esta contado alli.
 *
 * **No se vuelve a migrar.** `migrate:fresh` dos veces en el mismo proceso PHP
 * deja al migrador con estado obsoleto y produce fallos intermitentes en
 * pruebas posteriores; el esquema se crea una vez, como para todas las demas.
 */
/** @phpstan-ignore trait.unused (Pest lo compone con uses() en el fichero de prueba, no con un `use` dentro de una clase: PHPStan no puede ver esa composicion y lo da por muerto.) */
trait CommittedDatabase
{
    use FrameworkRefreshDatabase;

    /**
     * Catalogos que siembran las migraciones. Son dato de producto y sobreviven
     * al vaciado (regla dura 14, ADR-017).
     *
     * @var list<string>
     */
    private const array PRODUCT_CATALOGS = [
        'migrations',
        'compliance_profiles',
        'installation_settings',
        'roles',
        'permissions',
        'role_has_permissions',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        // Con el rol de MIGRACION, igual que {@see RefreshDatabase}: desde la
        // tarea 1.14 el de la aplicacion no tiene DDL (regla dura 6).
        return [
            '--database' => config()->string('database.migrations.connection'),
            '--drop-views' => $this->shouldDropViews(),
            '--drop-types' => $this->shouldDropTypes(),
        ];
    }

    /**
     * Igual que la del framework **menos** `beginDatabaseTransaction()`.
     */
    protected function refreshTestDatabase(): void
    {
        if (! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh', $this->migrateFreshUsing());

            $this->app?->make(Kernel::class)->setArtisan(null);

            RefreshDatabaseState::$migrated = true;
        }

        $this->emptyDatabase();

        $this->beforeApplicationDestroyed(function (): void {
            $this->emptyDatabase();
        });
    }

    /**
     * Vacia las tablas de trabajo sin tocar los catalogos de producto.
     *
     * **`DELETE` y no `TRUNCATE ... CASCADE`, y la diferencia costo una tarde.**
     * `CASCADE` arrastra a toda tabla que **referencie** a una de las vaciadas, y
     * `installation_settings` tiene una clave foranea hacia `sites` para el
     * ambito por centro. Vaciar `sites` con `CASCADE` se llevaba por delante los
     * cuatro umbrales operativos que siembra su migracion, y a partir de ahi
     * cualquier fichaje posterior de la suite moria con «la configuracion
     * operativa no tiene un entero en ATTENDANCE_MAX_SHIFT_HOURS» — a varios
     * ficheros de distancia de la causa.
     *
     * `DELETE` mira las **filas** y no las tablas: las de
     * `installation_settings` no apuntan a ningun centro, asi que no estorban.
     *
     * > **Enmienda 31-08-2026 (tarea 5.1).** Esa clave ajena ya no existe: la
     * > migracion de contraccion `2026_09_05_100000` retiro `scope` y `scope_id`
     * > (ADR-040). El parrafo se conserva porque explica por que este metodo usa
     * > `DELETE`, y la razon sigue valiendo para el resto del esquema. Lo que ya
     * > no puede ocurrir es ese fallo concreto — y ademas, desde la 5.1, una
     * > instalacion sin filas de configuracion arranca con los valores de serie
     * > del catalogo en lugar de romper.
     *
     * **El orden se resuelve reintentando y no con una lista.** Una lista
     * ordenada por claves foraneas es otra cosa que se queda vieja en cuanto
     * alguien anade una tabla. Cada pasada borra lo que puede y deja para la
     * siguiente lo que aun tiene hijos; con un grafo sin ciclos —el de este
     * esquema lo es— converge en tantas pasadas como profundidad tenga.
     */
    private function emptyDatabase(): void
    {
        $connection = DB::connection(config()->string('database.migrations.connection'));

        // La foto se toma AQUI y no en `refreshTestDatabase()`: este es el primer
        // punto del ciclo de vida en el que la base esta migrada y todavia no ha
        // confirmado nadie, y ademas es el unico por el que pasan por igual el
        // primer vaciado y el de despues de cada prueba.
        ProductCatalogBaseline::captureOnce($connection, $this->restorableCatalogs());

        $this->releaseCatalogReferences($connection);

        $pending = $this->emptiableTables($connection);

        // Cada `DELETE` va en su propia transaccion implicita, de modo que uno
        // que falle por una clave foranea no deja la sesion abortada.
        for ($pass = 0; $pending !== [] && $pass <= \count($pending); $pass++) {
            $blocked = [];

            foreach ($pending as $table) {
                try {
                    $connection->table($table)->delete();
                } catch (QueryException) {
                    $blocked[] = $table;
                }
            }

            $pending = $blocked;
        }

        // Lo ultimo, ya con las tablas de trabajo vacias: asi ninguna fila de
        // trabajo puede retener a un catalogo por una clave ajena mientras se
        // restaura.
        ProductCatalogBaseline::restore($connection);
    }

    /**
     * Los catalogos que se devuelven a su estado migrado.
     *
     * Todos menos `migrations`, que no es dato de producto sino el registro de lo
     * ya aplicado: ninguna prueba lo toca y borrarlo y reinsertarlo en cada
     * vaciado seria arriesgar el esquema entero a cambio de nada.
     *
     * @return list<string>
     */
    private function restorableCatalogs(): array
    {
        return array_values(array_filter(
            self::PRODUCT_CATALOGS,
            static fn (string $table): bool => $table !== 'migrations',
        ));
    }

    /**
     * Suelta las referencias que los **catalogos de producto** guardan hacia
     * tablas de trabajo, antes de vaciarlas.
     *
     * ## El fallo que esto arregla, y por que tardo en salir
     *
     * Los catalogos no se vacian —son dato de producto— pero **si guardan quien
     * los toco por ultima vez**: `installation_settings.updated_by_user_id`
     * apunta a `users`, que si se vacia. Resultado: `DELETE FROM users` fallaba
     * en todas las pasadas del bucle y **dos cuentas sobrevivian a la limpieza**,
     * quedando visibles para todas las pruebas posteriores del mismo proceso.
     *
     * Estuvo latente desde la tarea 5.1 porque ninguna prueba dependia de que
     * `users` estuviera vacia. Las del asistente de puesta en marcha (5.5) si
     * —«no hay ningun administrador todavia» es literalmente el estado que
     * describen— y por eso lo destaparon: en solitario pasaban y en la suite
     * completa fallaban cuatro, a dos ficheros de distancia de la causa.
     *
     * ## Se descubre, no se escribe
     *
     * Mismo criterio que {@see self::emptiableTables()}: se preguntan a
     * `pg_constraint` las claves ajenas cuyo ORIGEN es un catalogo y cuyo DESTINO
     * es una tabla que se vacia, y se anulan esas columnas. Una lista a mano se
     * quedaria vieja en cuanto otra tarea anadiera una columna de autoria a un
     * catalogo, y el sintoma volveria a ser una prueba ajena que solo falla en la
     * suite entera.
     *
     * **Se anulan y no se borran las filas**: son columnas de atribucion,
     * nullables por diseño, y el catalogo tiene que sobrevivir. La atribucion
     * real de quien cambio que vive en `audit_log`, que no se toca aqui.
     *
     * ## Solo cubre claves ajenas de UNA columna
     *
     * `conkey[1]` es la primera columna de la clave: una FK compuesta se veria
     * con una sola de sus columnas y anularla no soltaria la referencia.
     *
     * Basta hoy porque **ningun catalogo de producto tiene una FK compuesta**
     * —las columnas de autoria son siempre `..._by_user_id`, una y nullable— y
     * porque una FK compuesta hacia una tabla vaciable seria un dato de trabajo
     * dentro de un catalogo, que es justo lo que la lista `PRODUCT_CATALOGS`
     * niega. Si algun dia aparece, el sintoma es el conocido: una tabla que no se
     * vacia y una prueba ajena que solo falla en la suite completa. La consulta
     * tendria que recorrer `conkey` entero y anular todas sus columnas.
     */
    private function releaseCatalogReferences(ConnectionInterface $connection): void
    {
        $emptiable = $this->emptiableTables($connection);

        /** @var list<object{source: string, column: string}> $references */
        $references = $connection->select(<<<'SQL'
            SELECT source.relname AS source, attribute.attname AS column
              FROM pg_constraint constraint_
              JOIN pg_class source ON source.oid = constraint_.conrelid
              JOIN pg_class target ON target.oid = constraint_.confrelid
              JOIN pg_attribute attribute
                ON attribute.attrelid = constraint_.conrelid
               AND attribute.attnum = constraint_.conkey[1]
             WHERE constraint_.contype = 'f'
               AND source.relname = ANY(?)
               AND target.relname = ANY(?)
               AND NOT attribute.attnotnull
        SQL, ['{'.implode(',', self::PRODUCT_CATALOGS).'}', '{'.implode(',', $emptiable).'}']);

        foreach ($references as $reference) {
            $connection->table($reference->source)->update([$reference->column => null]);
        }
    }

    /**
     * @return list<string>
     */
    private function emptiableTables(ConnectionInterface $connection): array
    {
        /** @var list<object{relname: string}> $rows */
        $rows = $connection->select(<<<'SQL'
            SELECT c.relname
              FROM pg_class c
              JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = 'public'
               -- 'r' tabla normal, 'p' tabla particionada. Las particiones se
               -- vacian con su padre, asi que quedan fuera por `relispartition`.
               AND c.relkind IN ('r', 'p')
               AND NOT c.relispartition
        SQL);

        $tables = [];

        foreach ($rows as $row) {
            if (! \in_array($row->relname, self::PRODUCT_CATALOGS, true)) {
                $tables[] = $row->relname;
            }
        }

        sort($tables);

        return $tables;
    }
}
