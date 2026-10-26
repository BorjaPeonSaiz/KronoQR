<?php

declare(strict_types=1);

namespace Tests\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * {@see RefreshDatabase}, pero **en la sesion del rol dueño de las tablas**:
 * para las pruebas de volumen que miden un plan o un tiempo y necesitan
 * estadisticas de lo que acaban de sembrar.
 *
 * ## El problema que resuelve
 *
 * El planificador elige el plan con las estadisticas, y para tenerlas de lo
 * sembrado hace falta un `ANALYZE` que lo vea. Eso exige dos cosas a la vez:
 *
 * - **el rol dueño.** Con el de la aplicacion PostgreSQL responde
 *   `WARNING: permission denied to analyze` y sigue sin hacer nada. Hasta la
 *   19a las pruebas de volumen lo hacian asi, y su plan dependia de lo que el
 *   autovacuum hubiera dejado de la prueba anterior: en la CI, `daily_totals`
 *   con `reltuples = 0` y miles de paginas (densidad cero, una fila estimada) y
 *   un `Nested Loop` que releia las 15.500 filas del mes 15.500 veces. El
 *   informe de RNF-P-05 pasaba de 0,07 s a 5,9 s segun cuando hubiera pasado el
 *   autovacuum.
 * - **la misma sesion que sembro**, porque las filas no estan confirmadas.
 *
 * ## Como
 *
 * La conexion de migracion pasa a ser la de por omision de la aplicacion de la
 * prueba, y es la que `RefreshDatabase` envuelve en su transaccion. Todo —la
 * siembra, {@see QueryPlans::analyze()} y lo que se mide— corre en esa sesion,
 * y al acabar se deshace. Tambien las estadisticas: `pg_statistic` es
 * transaccional, asi que la prueba siguiente no planifica con el histograma de
 * un historico que ya no existe (lo que si pasa con {@see CommittedDatabase}
 * y un `ANALYZE` de datos confirmados).
 *
 * El SQL es el mismo que en produccion; cambia el rol que lo ejecuta, que solo
 * afecta a la comprobacion de permisos. Por eso esto es para pruebas de plan y
 * de volumen, no para las de privilegios.
 */
/** @phpstan-ignore trait.unused (Pest lo compone con uses() en el fichero de prueba, no con un `use` dentro de una clase: PHPStan no puede ver esa composicion y lo da por muerto.) */
trait RefreshDatabaseAsOwner
{
    use RefreshDatabase {
        refreshDatabase as private refreshDatabaseAsApplication;
    }

    public function refreshDatabase(): void
    {
        DB::setDefaultConnection(config()->string('database.migrations.connection'));

        $this->refreshDatabaseAsApplication();
    }

    /**
     * @return list<string>
     */
    protected function connectionsToTransact(): array
    {
        return [config()->string('database.migrations.connection')];
    }
}
