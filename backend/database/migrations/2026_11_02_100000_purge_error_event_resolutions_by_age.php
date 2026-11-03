<?php

declare(strict_types=1);

use App\Modules\Compliance\Infrastructure\Persistence\AuditLogSchema;
use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `error_event_resolutions_purge_batch()`: las resoluciones del historico de
 * errores vencen por su **propia** fecha, no solo con su grupo (RL-11,
 * RF-PD-15, RGPD art. 5.1.e; revision de seguridad de la 33.4 de la 2.3.0).
 *
 * ## El hueco que cierra
 *
 * `error_event_resolutions` (`2026_11_01_100000`) solo se vaciaba en cascada,
 * al purgar su grupo de `error_events` por `last_seen_at`. Un grupo que se
 * repite no vence nunca, y con el conservaba para siempre **todas** sus
 * resoluciones, cada una con `resolved_by_user_id`: quien atendio cada error,
 * y cuando, mas alla de los 90 dias del historico tecnico. Desde aqui la misma
 * pasada que purga los grupos vencidos purga tambien las resoluciones con
 * `resolved_at` anterior al mismo corte, aunque su grupo siga vivo.
 *
 * ## Por que una funcion `SECURITY DEFINER` y no `DELETE` para la aplicacion
 *
 * La tabla es solo-append para el rol de la aplicacion (`SELECT` e `INSERT`):
 * una resolucion pasada no se corrige ni se quita fila a fila. Devolverle
 * `DELETE` para purgar se lo devolveria para todo. La funcion, del propietario
 * del esquema, solo sabe borrar **por antiguedad y por lotes**, con
 * `search_path` fijado y `EXECUTE` solo para la aplicacion: el mismo patron
 * que la minimizacion de ADR-060 (`2026_10_21_100300`) y la purga del registro
 * (ADR-057 §3).
 *
 * El corte lo decide quien llama (`ERROR_HISTORY_RETENTION_DAYS`, por el
 * puerto `Clock`), y la funcion no lo acota contra el reloj de la base: la
 * aplicacion ya puede llevarse un grupo entero —y su historial, por la
 * cascada— con su `DELETE` sobre `error_events`, asi que un tope aqui no
 * protegeria nada y ataria la purga al reloj de PostgreSQL en vez de al del
 * producto. Lo que si garantiza es que no hay otra forma de quitar una
 * resolucion que esa: la antiguedad.
 *
 * Sin indice por `resolved_at`: la tabla crece al ritmo de una persona pulsando
 * «Resolver», unas pocas filas al dia, y el recorrido por la clave primaria
 * con `LIMIT` basta. Un indice costaria en cada resolucion para ahorrar
 * milisegundos una vez por noche.
 *
 * ## Por que una migracion aparte y no la del 01-11
 *
 * Las dos son de la 2.3.0 y ninguna esta publicada, asi que cualquiera valia.
 * Aparte, porque son dos objetos distintos con dos `down()` distintos: aquella
 * crea la tabla y su relleno; esta, la unica via de purgarla por edad. Leida
 * sola, cada una dice una cosa.
 *
 * ## Patron `/migracion-segura`: expand puro
 *
 * Solo crea una funcion: no toca ninguna tabla ni toma bloqueo sobre ninguna.
 * `CREATE OR REPLACE`, como las de ADR-060: `migrate:fresh` borra las tablas
 * pero no las funciones, y un `CREATE` a secas fallaria al reaplicarla.
 * La version anterior no la conoce y sigue funcionando; el codigo nuevo la
 * llama solo si existe (`to_regprocedure`), asi que tampoco depende del orden
 * entre migrar y desplegar. `infra/scripts/lib/work-record-privileges.sh`
 * vigila que siga `SECURITY DEFINER`, con `search_path` y sin `EXECUTE` para
 * `PUBLIC`, y que la aplicacion siga sin `DELETE` sobre la tabla.
 *
 * ## `down()` verificado
 *
 * Borra la funcion. Las resoluciones ya purgadas no vuelven, como no vuelven
 * las de un grupo purgado: es diagnostico tecnico, no evidencia legal.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    /** La firma, para `REVOKE`, `GRANT` y `DROP`. Publica para las pruebas. */
    public const string SIGNATURE = 'public.error_event_resolutions_purge_batch(timestamptz, integer)';

    public function up(): void
    {
        $this->limitLockWait();

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.error_event_resolutions_purge_batch(p_cutoff timestamptz, p_batch integer)
            RETURNS integer
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, pg_temp
            SET lock_timeout = '5s'
            AS $kronoqr$
            DECLARE
                v_removed integer;
            BEGIN
                IF p_cutoff IS NULL OR p_batch IS NULL OR p_batch < 1 OR p_batch > 10000 THEN
                    RAISE EXCEPTION 'error_event_resolutions_purge_batch: corte % o lote % fuera de rango', p_cutoff, p_batch
                        USING ERRCODE = '22023';
                END IF;

                -- El `SELECT ... LIMIT` dentro del `DELETE`, como la purga de
                -- `error_events`: cada lote es una sentencia corta.
                DELETE FROM public.error_event_resolutions r
                 WHERE r.id IN (
                     SELECT s.id
                       FROM public.error_event_resolutions s
                      WHERE s.resolved_at < p_cutoff
                      ORDER BY s.id
                      LIMIT p_batch
                 );
                GET DIAGNOSTICS v_removed = ROW_COUNT;

                RETURN v_removed;
            END;
            $kronoqr$
            SQL);

        DB::statement('REVOKE ALL ON FUNCTION '.self::SIGNATURE.' FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION '.self::SIGNATURE.' TO "'.AuditLogSchema::applicationRole().'"');
    }

    public function down(): void
    {
        $this->limitLockWait();

        DB::statement('DROP FUNCTION IF EXISTS '.self::SIGNATURE);
    }
};
