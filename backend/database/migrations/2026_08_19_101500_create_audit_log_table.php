<?php

declare(strict_types=1);

use App\Modules\Compliance\Infrastructure\Persistence\AuditLogSchema;
use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `audit_log` — solo-append, encadenada por hash y **particionada por año desde
 * el minuto cero** (doc 01 §5.5, ADR-010, ADR-027, RS-07, regla dura 6).
 *
 * **Por que no la crea el constructor de esquemas de Laravel.** `Schema::create`
 * no sabe emitir `PARTITION BY RANGE`, y el particionado no es un detalle que se
 * pueda añadir despues: convertir en particionada una tabla solo-append con
 * valor probatorio y millones de filas exige mover datos que, por definicion, no
 * se deben mover (ADR-027).
 *
 * **Por que particionada, en una linea.** La retencion de RL-02 obliga a purgar
 * a los cuatro años; la regla dura 6 no da `DELETE` a la aplicacion; y **borrar
 * filas rompe el eslabon**: la primera superviviente apuntaria a una fila
 * inexistente y `compliance:verify-audit-chain` denunciaria rotura **todos los
 * dias**. Una alerta critica que suena siempre se silencia, y con ella se pierde
 * lo unico que esta tabla aporta. La purga es `DROP PARTITION`.
 *
 * **La clave primaria es `(id, occurred_at)`** y no `id` a secas: PostgreSQL
 * exige que la clave de particion forme parte de toda restriccion unica.
 * Ninguna tabla apunta a `audit_log`, asi que no arrastra cambios.
 *
 * **Los permisos son parte de la tabla, no un añadido.** `INSERT` y `SELECT`
 * para la aplicacion; nunca `UPDATE` ni `DELETE`, ni sobre la tabla madre ni
 * sobre ninguna particion —los permisos **no se heredan al adjuntar**—. Y para
 * que eso signifique algo, el rol de aplicacion no es superusuario ni
 * propietario: lo garantiza la migracion `099000`, que se niega a correr si lo
 * fuera.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public function up(): void
    {
        $this->limitLockWait();

        // `occurred_at` con precision 6 y no la de serie de Laravel, que es 0.
        // La cadena de hash se calcula sobre el instante con microsegundos: si
        // la columna redondease al segundo, el verificador recalcularia manana
        // un hash distinto del escrito hoy y denunciaria una manipulacion que no
        // existe. Es el detalle que convierte RS-07 en una falsa alarma diaria.
        DB::statement(<<<'SQL'
            CREATE TABLE audit_log (
                id           BIGSERIAL,
                occurred_at  TIMESTAMPTZ(6) NOT NULL,
                actor_type   TEXT           NOT NULL,
                actor_id     BIGINT         NULL,
                action       TEXT           NOT NULL,
                subject_type TEXT           NULL,
                subject_id   BIGINT         NULL,
                payload      JSONB          NOT NULL DEFAULT '{}'::jsonb,
                prev_hash    TEXT           NULL,
                hash         TEXT           NOT NULL,
                ip           INET           NULL,
                user_agent   TEXT           NULL,
                PRIMARY KEY (id, occurred_at)
            ) PARTITION BY RANGE (occurred_at)
        SQL);

        // Los dos hashes son SHA-256 en hexadecimal: 64 caracteres exactos. Sin
        // esta comprobacion, una fila con el hash truncado pasaria por buena
        // hasta que el verificador la recorriera.
        DB::statement(<<<'SQL'
            ALTER TABLE audit_log
                ADD CONSTRAINT audit_log_chk_hash_format
                CHECK (hash ~ '^[0-9a-f]{64}$')
        SQL);

        // `prev_hash` es NULL solo en teoria: la primera entrada de la
        // instalacion usa SHA256('FICHAJE-HOTEL-GENESIS'), no NULL. La columna
        // admite NULL porque asi la declara el doc 01 §5.5, y la comprobacion
        // acota lo unico que puede haber si no lo es.
        DB::statement(<<<'SQL'
            ALTER TABLE audit_log
                ADD CONSTRAINT audit_log_chk_prev_hash_format
                CHECK (prev_hash IS NULL OR prev_hash ~ '^[0-9a-f]{64}$')
        SQL);

        // Regla dura 21: en `audit_log` van identificadores, nunca nombres. El
        // actor se resuelve contra `users` o `devices` cuando de verdad hace
        // falta. El enum acota quien puede figurar como actor.
        DB::statement(<<<'SQL'
            ALTER TABLE audit_log
                ADD CONSTRAINT audit_log_chk_actor_type
                CHECK (actor_type IN ('user', 'device', 'system', 'maintenance'))
        SQL);

        // Las particiones. La del año literal de ADR-027 mas, si la instalacion
        // es posterior, la del año en curso y la siguiente: un cliente que
        // instale en 2029 no puede quedarse sin particion de destino, que es un
        // fallo de escritura que tumbaria el primer fichaje.
        foreach ($this->yearsToCreate() as $year) {
            foreach (AuditLogSchema::createPartitionStatements($year) as $statement) {
                DB::statement($statement);
            }
        }

        // Permisos de la tabla madre. Los de cada particion los pone
        // `createPartitionStatements()`, porque adjuntar una particion NO
        // hereda permisos.
        foreach (AuditLogSchema::appendOnlyGrantStatements(AuditLogSchema::TABLE) as $statement) {
            DB::statement($statement);
        }

        // El verificador recorre la cadena en orden de `id` —el de escritura, no
        // el del hecho (regla dura 9)—, y para eso ya sirve la clave primaria,
        // que empieza por `id`. Lo que no cubre ningun indice es resolver un
        // eslabon por su `hash`, que es como se persigue una rotura y como se
        // busca el `last_hash` de un ancla. UNIQUE ademas: dos filas con el
        // mismo hash serian la misma entrada escrita dos veces.
        //
        // El indice unico de una tabla particionada tiene que incluir la clave
        // de particion; de ahi el `occurred_at`.
        DB::statement('CREATE UNIQUE INDEX audit_log_hash_index ON audit_log (hash, occurred_at)');

        // La consulta de una inspeccion y la de una brecha (RL-15) son la misma:
        // «que paso con este sujeto». JSONB con GIN para el payload, igual que
        // en `scan_events`.
        DB::statement(<<<'SQL'
            CREATE INDEX audit_log_subject_index
                ON audit_log (subject_type, subject_id, occurred_at DESC)
        SQL);
        DB::statement(<<<'SQL'
            CREATE INDEX audit_log_actor_index
                ON audit_log (actor_type, actor_id, occurred_at DESC)
        SQL);
        DB::statement('CREATE INDEX audit_log_action_index ON audit_log (action, occurred_at DESC)');
        DB::statement('CREATE INDEX audit_log_payload_gin ON audit_log USING gin (payload jsonb_path_ops)');
    }

    public function down(): void
    {
        $this->limitLockWait();

        // `CASCADE` no hace falta: soltar la tabla madre se lleva sus
        // particiones. Se nombra `DROP TABLE IF EXISTS` para que revertir sobre
        // una base a medio migrar no se atasque.
        DB::statement('DROP TABLE IF EXISTS audit_log');
    }

    /**
     * @return list<int>
     */
    private function yearsToCreate(): array
    {
        // `gmdate` y no el puerto `Clock`: esto es una migracion, no dominio, y
        // el año que importa es el del reloj del servidor en UTC (regla dura 3).
        $currentYear = (int) gmdate('Y');

        $years = [AuditLogSchema::FIRST_YEAR, $currentYear, $currentYear + 1];
        $years = array_values(array_unique($years));

        sort($years);

        return $years;
    }
};
