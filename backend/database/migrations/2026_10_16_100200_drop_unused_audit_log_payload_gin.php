<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Retira `audit_log_payload_gin` y sus indices de particion, que no sirven a
 * ninguna consulta (hallazgo DB7 de la verificacion; RNF-P-02, ADR-027).
 *
 * `audit_log.payload` se filtra en cuatro sitios —el paquete de diagnostico ya
 * registrado, la revision de una purga y la conciliacion del registro—, pero
 * siempre con `->>` y despues de acotar por `action` o por `id`
 * (`audit_log_action_index`, la clave primaria). Un GIN `jsonb_path_ops` solo
 * sirve `@>`, `@?` y `@@`, que nadie usa: `idx_scan` es 0 en todas las
 * particiones. Mientras, se mantenia en cada asiento de la cadena.
 *
 * **Solo se retira un indice**: ni filas, ni columnas, ni privilegios. La tabla
 * sigue siendo solo-append para la aplicacion (regla dura 6).
 *
 * ## Por que sin `CONCURRENTLY`, y por que no hace falta
 *
 * `audit_log` esta particionada por año. PostgreSQL no admite `DROP INDEX
 * CONCURRENTLY` sobre un indice particionado, y tampoco deja retirar el de una
 * particion mientras cuelga del padre. El unico camino es `DROP INDEX` del padre,
 * que arrastra los de las particiones y toma `ACCESS EXCLUSIVE` sobre `audit_log`
 * y sobre cada particion. Dura milisegundos —solo toca el catalogo—, pero choca
 * con cualquier lectura o escritura de la tabla, asi que va en una transaccion
 * corta con `limitLockWait()`: si alguien la tiene ocupada, ese intento se rinde
 * a los 3 s con `55P03` en vez de encolar el fichaje detras.
 *
 * **`up()` lo intenta tres veces** ({@see ATTEMPTS}), cada vez en su propia
 * transaccion y con {@see SECONDS_BETWEEN_ATTEMPTS} s de espera entre una y otra:
 * una lectura corta que coincida con la migracion no la tumba. Solo se reintenta
 * ante `55P03`; cualquier otro error, y el `55P03` del ultimo intento, salen tal
 * cual. En el peor caso son unos 13 s.
 *
 * `update.sh` migra con el mantenimiento puesto y `horizon` y `scheduler`
 * parados, pero **no se excluye con la copia nocturna**: un `pg_dump` de
 * `backup.sh` (cron, 03:15 UTC) tiene `ACCESS SHARE` sobre `audit_log` durante
 * minutos. Si coinciden, se agotan los tres intentos, la migracion falla y
 * `update.sh` deshace la actualizacion entera (restaura la copia previa, nada
 * queda a medias); basta con repetirla cuando la copia haya terminado.
 *
 * Las particiones que se creen despues (`audit_log_create_partition`) heredan los
 * indices del padre: ya no tendran este.
 *
 * ## `down()`
 *
 * El patron de PostgreSQL para indexar una tabla particionada sin bloquear sus
 * escrituras: el indice del padre con `ON ONLY` (vacio, invalido hasta que tenga
 * a todas sus particiones), el de cada particion con `CREATE INDEX CONCURRENTLY`
 * y `ATTACH PARTITION` de cada uno, con lo que el padre pasa a valido. En
 * produccion la vuelta atras es restaurar la copia (`update.sh`); este `down()` lo
 * ejecuta `MigrationsRoundTripTest`.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    /** `down()` construye indices concurrentes, que no pueden ir en una transaccion. */
    public $withinTransaction = false;

    /** Intentos del `DROP INDEX` ante `55P03`; ver el docblock. */
    private const int ATTEMPTS = 3;

    /** Espera entre un intento rendido y el siguiente. */
    private const int SECONDS_BETWEEN_ATTEMPTS = 2;

    /** `lock_not_available`: el `SQLSTATE` con el que `lock_timeout` rinde una sentencia. */
    private const string LOCK_NOT_AVAILABLE = '55P03';

    public function up(): void
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                DB::transaction(function (): void {
                    $this->limitLockWait();

                    DB::statement('DROP INDEX IF EXISTS audit_log_payload_gin');
                });

                return;
            } catch (QueryException $failure) {
                if ($attempt >= self::ATTEMPTS || ($failure->errorInfo[0] ?? null) !== self::LOCK_NOT_AVAILABLE) {
                    throw $failure;
                }

                sleep(self::SECONDS_BETWEEN_ATTEMPTS);
            }
        }
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $this->limitLockWait();

            DB::statement('CREATE INDEX IF NOT EXISTS audit_log_payload_gin ON ONLY audit_log USING gin (payload jsonb_path_ops)');
        });

        /** @var list<object{partition: string}> $partitions */
        $partitions = DB::select(<<<'SQL'
            SELECT c.relname AS partition
              FROM pg_inherits i
              JOIN pg_class c ON c.oid = i.inhrelid
             WHERE i.inhparent = 'audit_log'::regclass
             ORDER BY c.relname
            SQL);

        foreach ($partitions as $row) {
            // El nombre sale del catalogo y va a un DDL sin parametros: se exige
            // un identificador simple, como a cualquier constante de migracion.
            $this->assertSimpleIdentifier($row->partition, 'down()');
            $index = $row->partition.'_payload_idx';

            $this->createIndexConcurrently($index, 'ON '.$row->partition.' USING gin (payload jsonb_path_ops)');

            DB::transaction(function () use ($index): void {
                $this->limitLockWait();

                DB::statement('ALTER INDEX audit_log_payload_gin ATTACH PARTITION '.$index);
            });
        }
    }
};
