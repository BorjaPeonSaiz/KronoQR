<?php

declare(strict_types=1);

use App\Modules\Compliance\Infrastructure\Persistence\AuditLogSchema;
use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La zona horaria del centro queda fija **tambien en la base de datos** en
 * cuanto existe el primer tramo, y cada correccion deja una traza solo-append
 * (RN-23, ADR-056 con su enmienda del bloque 2 de la 2.3.0; residuo D2 de la
 * verificacion de la 2.2.1).
 *
 * ## Lo que cierra
 *
 * Desde la 2.2.1 la regla la aplica el caso de uso: `PATCH /site` responde `409`
 * con registro horario y la unica salida es `workforce:correct-site-timezone`,
 * con motivo, responsable y asiento. Pero la base no lo impedia: un
 * `UPDATE sites SET timezone = …` con la credencial del runtime pasaba, y
 * repintaba todo el historico con otra zona sin dejar asiento. Esta migracion lo
 * impide en el motor, con el mismo patron que ADR-057 §2 y §3: un *trigger* que
 * vigila y una funcion `SECURITY DEFINER` que es la unica puerta, y ademas una
 * tabla solo-append en la que esa puerta apunta cada paso.
 *
 * ## 1. `zz_sites_guard_timezone`
 *
 * `BEFORE UPDATE ON sites FOR EACH ROW`, sin `OF timezone`: la condicion
 * `IS DISTINCT FROM` ya filtra, y una lista de columnas solo se mira contra el
 * `SET` de la sentencia, no contra lo que la fila acaba teniendo. **El nombre
 * empieza por `zz_` a proposito**: PostgreSQL ejecuta los *triggers* `BEFORE`
 * de una tabla por orden alfabetico, y este tiene que ver la fila tal y como la
 * dejan los demas que alguien añada mañana, no como la pidio la sentencia.
 * Rechaza con `42501` cuando, a la vez:
 *
 * - la zona **cambia** (`IS DISTINCT FROM`): el panel manda el formulario
 *   entero y guardar el nombre con la zona de siempre tiene que seguir
 *   funcionando;
 * - el centro **tiene algun tramo** en `shift_entries`, en cualquier estado
 *   (`shift_entries_site_id_work_date_index`);
 * - y quien escribe **no es el propietario de la tabla**. Se compara
 *   `current_user` con `pg_class.relowner` de la tabla del *trigger*, sin
 *   nombres de rol en el SQL: dentro de la funcion de la seccion 2,
 *   `SECURITY DEFINER` y propiedad del rol de migracion, `current_user` es ese
 *   rol, y el *trigger* la deja pasar. El rol de migracion tambien puede soltar
 *   el *trigger*, porque es su propietario; esa credencial no llega a ningun
 *   contenedor de runtime (ADR-042).
 *
 * La funcion del *trigger* (`sites_guard_timezone()`) **no** es
 * `SECURITY DEFINER`: si lo fuera, `current_user` seria siempre el propietario
 * y no vigilaria nada. Lee `shift_entries` con los privilegios de quien
 * escribe, que la aplicacion tiene.
 *
 * El mensaje empieza por `sites_guard_timezone: <antes> -> <despues>:` para que
 * `EloquentSiteRepository::save()` lo reconozca y lo traduzca a
 * `SiteTimezoneLocked` (409), y no a un error de servidor.
 *
 * **No cierra la carrera con el primer fichaje**: un tramo sin confirmar no es
 * visible para el *trigger*. La cierran, como hasta ahora, el `FOR UPDATE` de
 * `UpdateSiteHandler` frente al `FOR KEY SHARE` del fichaje (ADR-056, nota del
 * 15-10-2026). El *trigger* es la barrera para quien escribe sin pasar por el
 * caso de uso.
 *
 * ## 2. `site_correct_timezone(p_site_id, p_from, p_to)`
 *
 * La correccion de consola (ADR-056 §3). `SECURITY DEFINER`, propiedad del rol
 * de migracion, `search_path = pg_catalog, pg_temp` y todo calificado con
 * `public.`; `EXECUTE` retirado a `PUBLIC` y concedido solo al rol de la
 * aplicacion, como `work_record_purge_batch`. Bloquea la fila del centro y:
 *
 * - `P0002` si el centro no existe o su zona actual **no es `p_from`**: la
 *   correccion se decidio sobre una zona que ya no es la del centro;
 * - `42501` si el centro **no tiene tramos**: entonces la zona se cambia por el
 *   camino normal, con su asiento, y esta puerta no se usa;
 * - `22023` si falta un argumento;
 * - si `p_from` y `p_to` son la misma zona no hace nada (ni traza);
 * - si no, cambia la zona **y apunta el paso en `site_timezone_changes`**, en
 *   la misma sentencia de la misma transaccion.
 *
 * **No valida que `p_to` sea una zona IANA**: lo hace el dominio
 * (`SiteTimezone`) con la base de zonas de PHP, que es con la que se calculan
 * las jornadas. Repetirlo aqui con la de PostgreSQL ataria la correccion a que
 * las dos versiones de `tzdata` coincidan.
 *
 * ## 3. `site_timezone_changes`, la traza solo-append
 *
 * `(id, site_id, from_tz, to_tz, changed_at, txid)`, propiedad del rol de
 * migracion. La escribe **solo la funcion** de la seccion 2: la aplicacion
 * tiene `SELECT` y nada mas (ni `INSERT`, `UPDATE`, `DELETE`, `TRUNCATE`,
 * `REFERENCES` o `TRIGGER`, ni la secuencia). Hay que retirarlo a mano porque
 * los privilegios por defecto de la provision (`2026_08_19_099000`) le darian
 * las cuatro operaciones a toda tabla nueva. Sin ella, un cambio transitorio
 * A→B→A con la funcion entre dos conciliaciones no dejaba rastro: la zona
 * volvia a coincidir con su asiento. Con ella, la conciliacion exige que cada
 * fila tenga su `site.updated` firmado con el mismo antes y despues.
 *
 * `changed_at` y `txid` los pone la base (`now()`, `txid_current()`), no quien
 * llama. `site_id` es clave ajena `RESTRICT`: la aplicacion ya no puede borrar
 * el centro (ADR-057), y asi tampoco el rol de migracion por descuido.
 *
 * ## Lo que esto no impide
 *
 * **Llamar a la funcion fuera de la orden de consola**, con registro horario y
 * con la credencial del runtime: la aplicacion tiene `EXECUTE` y la funcion no
 * escribe asiento (no puede firmar por nadie). Es el mismo residuo que la
 * purga (ADR-057, «Lo que esto no impide»). **Deja fila en la traza**, y la
 * conciliacion (`compliance:reconcile-work-record`,
 * `site_timezone_differs_from_audit`) la denuncia si no tiene su asiento
 * firmado.
 *
 * ## Despliegue y bloqueos
 *
 * Un solo paso, sin expand/contract: una tabla nueva y vacia, funciones y un
 * *trigger*; no lee ni reescribe filas. `CREATE TRIGGER` toma
 * `SHARE ROW EXCLUSIVE` sobre `sites` mientras escribe el catalogo; no choca con
 * el `FOR KEY SHARE` con que el fichaje lee la zona, y `lock_timeout` lo hace
 * rendirse en 3 s si una transaccion larga tuviera la tabla. El codigo anterior
 * no llama a la funcion y su `UPDATE` de la correccion lo rechazaria el
 * *trigger*, pero `update.sh` migra con la aplicacion parada y la correccion es
 * una orden manual.
 *
 * ## Reversible
 *
 * `down()` suelta el *trigger*, las dos funciones y la traza. No toca filas de
 * otras tablas ni sus privilegios. La traza se pierde con el `down()`: es lo
 * que se deshace.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public function up(): void
    {
        $this->limitLockWait();

        $application = $this->applicationRole();

        DB::statement(<<<'SQL'
            CREATE TABLE public.site_timezone_changes (
                id         bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                site_id    bigint      NOT NULL REFERENCES public.sites (id) ON DELETE RESTRICT,
                from_tz    text        NOT NULL,
                to_tz      text        NOT NULL,
                changed_at timestamptz NOT NULL DEFAULT pg_catalog.now(),
                txid       bigint      NOT NULL DEFAULT pg_catalog.txid_current(),
                CONSTRAINT site_timezone_changes_chk_changes CHECK (from_tz <> to_tz)
            )
            SQL);
        // Los privilegios por defecto de la provision le darian a la
        // aplicacion las cuatro operaciones: solo lectura.
        DB::statement('REVOKE ALL ON TABLE public.site_timezone_changes FROM PUBLIC');
        DB::statement('REVOKE ALL ON TABLE public.site_timezone_changes FROM '.$application);
        DB::statement('GRANT SELECT ON TABLE public.site_timezone_changes TO '.$application);
        DB::statement('REVOKE ALL ON SEQUENCE public.site_timezone_changes_id_seq FROM '.$application);

        DB::statement($this->guardFunction());
        DB::statement($this->correctionFunction());

        DB::statement('DROP TRIGGER IF EXISTS zz_sites_guard_timezone ON sites');
        DB::statement(
            'CREATE TRIGGER zz_sites_guard_timezone BEFORE UPDATE ON sites '
            .'FOR EACH ROW EXECUTE FUNCTION public.sites_guard_timezone()'
        );

        // `PUBLIC` recibe `EXECUTE` sobre toda funcion nueva si no se le
        // retira. Solo la correccion se concede, y solo a la aplicacion.
        DB::statement('REVOKE ALL ON FUNCTION public.sites_guard_timezone() FROM PUBLIC');
        DB::statement('REVOKE ALL ON FUNCTION public.site_correct_timezone(bigint, text, text) FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION public.site_correct_timezone(bigint, text, text) TO '.$application);
    }

    public function down(): void
    {
        $this->limitLockWait();

        DB::statement('DROP TRIGGER IF EXISTS zz_sites_guard_timezone ON sites');
        DB::statement('DROP FUNCTION IF EXISTS public.site_correct_timezone(bigint, text, text)');
        DB::statement('DROP FUNCTION IF EXISTS public.sites_guard_timezone()');
        DB::statement('DROP TABLE IF EXISTS public.site_timezone_changes');
    }

    private function guardFunction(): string
    {
        return <<<'SQL'
            CREATE OR REPLACE FUNCTION public.sites_guard_timezone()
            RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, pg_temp
            AS $kronoqr$
            BEGIN
                IF NEW.timezone IS DISTINCT FROM OLD.timezone
                   AND NOT EXISTS (
                       SELECT 1
                         FROM pg_catalog.pg_class c
                         JOIN pg_catalog.pg_roles r ON r.oid = c.relowner
                        WHERE c.oid = TG_RELID
                          AND r.rolname = current_user
                   )
                   AND EXISTS (SELECT 1 FROM public.shift_entries se WHERE se.site_id = OLD.id) THEN
                    RAISE EXCEPTION 'sites_guard_timezone: % -> %: la zona horaria del centro queda fija con el primer tramo; se corrige con workforce:correct-site-timezone (RN-23, ADR-056)',
                        OLD.timezone, NEW.timezone
                        USING ERRCODE = '42501';
                END IF;

                RETURN NEW;
            END;
            $kronoqr$
            SQL;
    }

    private function correctionFunction(): string
    {
        return <<<'SQL'
            CREATE OR REPLACE FUNCTION public.site_correct_timezone(p_site_id bigint, p_from text, p_to text)
            RETURNS void
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, pg_temp
            AS $kronoqr$
            DECLARE
                v_current text;
            BEGIN
                IF p_site_id IS NULL OR p_from IS NULL OR p_to IS NULL OR p_from = '' OR p_to = '' THEN
                    RAISE EXCEPTION 'site_correct_timezone: faltan el centro, la zona actual o la zona nueva'
                        USING ERRCODE = '22023';
                END IF;

                SELECT s.timezone INTO v_current FROM public.sites s WHERE s.id = p_site_id FOR UPDATE;

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'site_correct_timezone: no existe el centro %', p_site_id
                        USING ERRCODE = 'P0002';
                END IF;

                IF v_current IS DISTINCT FROM p_from THEN
                    RAISE EXCEPTION 'site_correct_timezone: la zona del centro % es %, no % (RN-23, ADR-056)', p_site_id, v_current, p_from
                        USING ERRCODE = 'P0002';
                END IF;

                IF NOT EXISTS (SELECT 1 FROM public.shift_entries se WHERE se.site_id = p_site_id) THEN
                    RAISE EXCEPTION 'site_correct_timezone: el centro % no tiene registro horario; la zona se cambia desde el panel (RN-23, ADR-056)', p_site_id
                        USING ERRCODE = '42501';
                END IF;

                IF p_from = p_to THEN
                    RETURN;
                END IF;

                UPDATE public.sites SET timezone = p_to WHERE id = p_site_id;

                INSERT INTO public.site_timezone_changes (site_id, from_tz, to_tz)
                VALUES (p_site_id, p_from, p_to);
            END;
            $kronoqr$
            SQL;
    }

    private function applicationRole(): string
    {
        return '"'.AuditLogSchema::applicationRole().'"';
    }
};
