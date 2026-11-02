<?php

declare(strict_types=1);

use App\Modules\Compliance\Infrastructure\Persistence\AuditLogSchema;
use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `error_event_resolutions` — cada vez que un grupo de `error_events` se dio por
 * resuelto (**RF-PD-15**, tarea 33.4 de la 2.3.0). **Tecnica, no legal.**
 *
 * ## Por que existe
 *
 * Reabrir un grupo —el error vuelve a ocurrir despues de darlo por arreglado—
 * vacia `error_events.resolved_at` y `resolved_by_user_id`, y con ellos se
 * perdia quien lo habia resuelto y cuando. Es justo lo que IT necesita al ver
 * reaparecer un fallo: «esto ya lo cerro alguien el martes». La fila de
 * `error_events` sigue siendo el estado **actual**; esta tabla es el
 * **historial**, y la reapertura no la toca.
 *
 * ## Las invariantes, en el esquema
 *
 * - **Solo-append** para el rol de la aplicacion: `SELECT` e `INSERT`, sin
 *   `UPDATE`, `DELETE` ni `TRUNCATE`. Una resolucion pasada no se corrige.
 * - **Se purga con su grupo** (`ON DELETE CASCADE`): la retencion de 90 dias
 *   del historico (RL-11) y la fusion de grupos de ADR-048 borran el grupo, y
 *   su historial va detras. La accion referencial la ejecuta PostgreSQL con
 *   los privilegios del propietario de esta tabla, asi que el rol de la
 *   aplicacion no necesita `DELETE` aqui para purgar `error_events`
 *   (`ErrorEventResolutionsSchemaTest` lo demuestra con ese rol).
 * - **Y por su propia fecha**, aunque el grupo siga vivo: un grupo que se
 *   repite no vence nunca, y la cascada sola dejaba para siempre quien lo
 *   resolvio cada vez. La misma pasada de 90 dias borra las resoluciones con
 *   `resolved_at` anterior al corte por la funcion `SECURITY DEFINER` de
 *   `2026_11_02_100000`, sin dar `DELETE` a la aplicacion.
 * - **`UNIQUE (error_event_id, resolved_at)`**: dos resoluciones del mismo
 *   grupo en el mismo instante son la misma. Es lo que hace idempotente la
 *   copia del historial al fusionar dos grupos.
 * - **El autor, `nullOnDelete`**, como `error_events.resolved_by_user_id`: una
 *   cuenta borrada no hace desaparecer la resolucion.
 *
 * No entra en `audit_log`: resolver un error tecnico no tiene relevancia legal
 * (regla dura 6, al reves). Tampoco en el paquete de diagnostico: lleva quien
 * resolvio, que es informacion de la organizacion del cliente (ADR-020). Si en
 * la exportacion integra del cliente (RF-PD-14, RL-20).
 *
 * Indices: el del `UNIQUE`, que empieza por `error_event_id`, sirve a la
 * cascada y a la lectura de la resolucion anterior; y uno parcial por el autor,
 * para el `SET NULL` de una cuenta borrada.
 *
 * ## Patron `/migracion-segura`: expand puro, con relleno acotado
 *
 * Tabla nueva. El relleno copia el estado actual de los grupos ya resueltos
 * —para que uno resuelto antes de la actualizacion y reabierto despues siga
 * mostrando su resolucion anterior— y es un `INSERT … SELECT` que **solo lee**
 * `error_events`: no toma bloqueo de fila sobre ella ni la reescribe. La tabla
 * esta acotada por la purga de 90 dias, por grupo y por fecha de resolucion
 * (`2026_11_02_100000`). La version anterior no conoce esta tabla y sigue
 * funcionando: si resuelve durante la actualizacion, esa resolucion solo falta
 * en el historial.
 *
 * ## `down()` verificado
 *
 * La borra **aunque tenga filas**: no es evidencia legal, y el estado actual de
 * cada grupo sigue en `error_events`. Lo que se pierde es solo el historial.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    private const string TABLE = 'error_event_resolutions';

    public function up(): void
    {
        $this->limitLockWait();

        DB::statement(<<<'SQL'
            CREATE TABLE error_event_resolutions (
                id bigserial PRIMARY KEY,
                error_event_id bigint NOT NULL,
                resolved_at timestamptz(6) NOT NULL,
                resolved_by_user_id bigint NULL,
                created_at timestamptz(6) NOT NULL,
                CONSTRAINT error_event_resolutions_error_event_id_foreign
                    FOREIGN KEY (error_event_id) REFERENCES error_events (id) ON DELETE CASCADE,
                CONSTRAINT error_event_resolutions_resolved_by_user_id_foreign
                    FOREIGN KEY (resolved_by_user_id) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT error_event_resolutions_error_event_id_resolved_at_unique
                    UNIQUE (error_event_id, resolved_at)
            )
        SQL);

        DB::statement(
            'CREATE INDEX error_event_resolutions_resolved_by_user_id_index ON error_event_resolutions '
            .'(resolved_by_user_id) WHERE resolved_by_user_id IS NOT NULL',
        );

        DB::statement(<<<'SQL'
            INSERT INTO error_event_resolutions (error_event_id, resolved_at, resolved_by_user_id, created_at)
            SELECT id, resolved_at, resolved_by_user_id, now()
              FROM error_events
             WHERE resolved_at IS NOT NULL
        SQL);

        // Solo-append: `SELECT` e `INSERT`, nada mas.
        DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON TABLE '.self::TABLE.' FROM '.$this->applicationRole());
    }

    public function down(): void
    {
        $this->limitLockWait();

        Schema::dropIfExists(self::TABLE);
    }

    private function applicationRole(): string
    {
        return '"'.AuditLogSchema::applicationRole().'"';
    }
};
