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
