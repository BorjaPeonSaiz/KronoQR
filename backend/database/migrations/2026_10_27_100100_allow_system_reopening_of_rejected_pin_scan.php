<?php

declare(strict_types=1);

use App\Modules\Compliance\Infrastructure\Persistence\AuditLogSchema;
use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El sistema reabre la `rejected_pin_scan` que el sistema cerro (RN-19,
 * enmienda del 27-10-2026 de ADR-057 §2; reglas duras 5 y 6).
 *
 * ## Lo que cambia
 *
 * `incidents_guard_transition` admite **una sola transicion mas**: `resolved` →
 * `open`, solo si `type = 'rejected_pin_scan'`, `OLD.resolved_by_user_id IS
 * NULL` y `OLD.resolution_note IS NULL` —la cerro el sistema porque la jornada
 * quedo subsanada—. En esa transicion la resolucion queda nula y `context`
 * puede cambiar: el hallazgo nuevo trae los intentos sin subsanar de ahora. En
 * cualquier otra escritura `context` sigue inmutable, tambien para el
 * propietario. Lo que resolvio o descarto una persona no se reabre nunca, y
 * ningun otro tipo, porque ningun otro se cierra sin persona.
 *
 * `fichaje_app` recibe `UPDATE (context)`. Quien decide cuando puede cambiar
 * es el *trigger*, no el privilegio; con la funcion anterior el privilegio no
 * serviria de nada, porque `context` no estaba entre las columnas mutables.
 *
 * ## Despliegue y bloqueos
 *
 * Un solo paso, sin expand/contract: `CREATE OR REPLACE FUNCTION` conserva el
 * OID, asi que el *trigger* sigue apuntando a ella sin recrearse, y no toma
 * bloqueo sobre `incidents`. El `GRANT` de columna escribe el catalogo de la
 * tabla. `lock_timeout` lo hace rendirse en 3 s. Ninguna fila se lee ni se
 * reescribe. El codigo anterior nunca reabre, asi que convivir no rompe nada.
 *
 * ## Reversible, y sin dejar nada a medias
 *
 * `down()` devuelve la funcion del bloque 7 (`2026_10_14_100000`) y retira
 * `UPDATE (context)`, en la misma transaccion. **Una reapertura ya hecha queda
 * entera**: la fila esta `open`, sin resolucion y con su contexto nuevo, que es
 * una incidencia abierta valida tambien para la funcion anterior, y su asiento
 * `incident.reopened` sigue en `audit_log`. Una persona la puede resolver o
 * descartar como cualquier otra; lo unico que se pierde es la posibilidad de
 * reabrirla otra vez. `IncidentReopeningGuardTest` lo comprueba.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public function up(): void
    {
        $this->limitLockWait();

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.incidents_guard_transition()
            RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, pg_temp
            AS $kronoqr$
            DECLARE
                mutable text[] := ARRAY['status', 'resolved_at', 'resolved_by_user_id', 'resolution_note', 'notified_at', 'updated_at', 'context'];
                -- RN-19: la unica vuelta atras. La cerro el sistema (sin autor ni
                -- nota) y es del unico tipo que se cierra sin persona.
                reopening boolean := OLD.status = 'resolved'
                    AND NEW.status = 'open'
                    AND OLD.type = 'rejected_pin_scan'
                    AND OLD.resolved_by_user_id IS NULL
                    AND OLD.resolution_note IS NULL;
            BEGIN
                IF (pg_catalog.to_jsonb(NEW) - mutable) IS DISTINCT FROM (pg_catalog.to_jsonb(OLD) - mutable) THEN
                    RAISE EXCEPTION 'incidents %: solo cambian la resolucion y el aviso de una incidencia (ADR-057 §2)', OLD.id
                        USING ERRCODE = '42501';
                END IF;

                IF NEW.context IS DISTINCT FROM OLD.context AND NOT reopening THEN
                    RAISE EXCEPTION 'incidents %: el contexto solo cambia cuando el sistema reabre una rejected_pin_scan (ADR-057 §2, RN-19)', OLD.id
                        USING ERRCODE = '42501';
                END IF;

                IF reopening THEN
                    IF NEW.resolved_at IS NOT NULL OR NEW.resolved_by_user_id IS NOT NULL OR NEW.resolution_note IS NOT NULL THEN
                        RAISE EXCEPTION 'incidents %: una incidencia reabierta queda sin resolucion (ADR-057 §2, RN-19)', OLD.id
                            USING ERRCODE = '42501';
                    END IF;
                ELSIF ROW(NEW.status, NEW.resolved_at, NEW.resolved_by_user_id, NEW.resolution_note)
                          IS DISTINCT FROM ROW(OLD.status, OLD.resolved_at, OLD.resolved_by_user_id, OLD.resolution_note)
                      AND NOT (OLD.status = 'open' AND NEW.status IN ('resolved', 'dismissed')) THEN
                    RAISE EXCEPTION 'incidents %: la resolucion solo se escribe al cerrar una incidencia abierta (ADR-057 §2)', OLD.id
                        USING ERRCODE = '42501';
                END IF;

                IF NEW.notified_at IS DISTINCT FROM OLD.notified_at AND OLD.notified_at IS NOT NULL THEN
                    RAISE EXCEPTION 'incidents %: el primer aviso no se reescribe (ADR-057 §2)', OLD.id
                        USING ERRCODE = '42501';
                END IF;

                RETURN NEW;
            END;
            $kronoqr$
            SQL);

        // `CREATE OR REPLACE` conserva los privilegios de la funcion, pero se
        // repite por si alguien la hubiera recreado: nadie la ejecuta a mano.
        DB::statement('REVOKE ALL ON FUNCTION public.incidents_guard_transition() FROM PUBLIC');

        DB::statement('GRANT UPDATE (context) ON TABLE incidents TO '.$this->applicationRole());
    }

    public function down(): void
    {
        $this->limitLockWait();

        DB::statement('REVOKE UPDATE (context) ON TABLE incidents FROM '.$this->applicationRole());

        // La del bloque 7, tal cual (`2026_10_14_100000`).
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.incidents_guard_transition()
            RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, pg_temp
            AS $kronoqr$
            DECLARE
                mutable text[] := ARRAY['status', 'resolved_at', 'resolved_by_user_id', 'resolution_note', 'notified_at', 'updated_at'];
            BEGIN
                IF (pg_catalog.to_jsonb(NEW) - mutable) IS DISTINCT FROM (pg_catalog.to_jsonb(OLD) - mutable) THEN
                    RAISE EXCEPTION 'incidents %: solo cambian la resolucion y el aviso de una incidencia (ADR-057 §2)', OLD.id
                        USING ERRCODE = '42501';
                END IF;

                IF ROW(NEW.status, NEW.resolved_at, NEW.resolved_by_user_id, NEW.resolution_note)
                       IS DISTINCT FROM ROW(OLD.status, OLD.resolved_at, OLD.resolved_by_user_id, OLD.resolution_note)
                   AND NOT (OLD.status = 'open' AND NEW.status IN ('resolved', 'dismissed')) THEN
                    RAISE EXCEPTION 'incidents %: la resolucion solo se escribe al cerrar una incidencia abierta (ADR-057 §2)', OLD.id
                        USING ERRCODE = '42501';
                END IF;

                IF NEW.notified_at IS DISTINCT FROM OLD.notified_at AND OLD.notified_at IS NOT NULL THEN
                    RAISE EXCEPTION 'incidents %: el primer aviso no se reescribe (ADR-057 §2)', OLD.id
                        USING ERRCODE = '42501';
                END IF;

                RETURN NEW;
            END;
            $kronoqr$
            SQL);

        DB::statement('REVOKE ALL ON FUNCTION public.incidents_guard_transition() FROM PUBLIC');
    }

    private function applicationRole(): string
    {
        return '"'.AuditLogSchema::applicationRole().'"';
    }
};
