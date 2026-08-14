-- KronoQR — extensiones que el dominio necesita (doc 02 §3.2).
--
-- Se ejecuta una sola vez, al inicializar el cluster. Las migraciones de la
-- tarea 1.3 dan por hecho que estan disponibles.

-- RN-02: los tramos vigentes de un mismo empleado nunca se solapan.
-- La restriccion EXCLUDE USING gist necesita btree_gist para combinar la
-- igualdad de employee_id con el solape de tstzrange.
CREATE EXTENSION IF NOT EXISTS btree_gist;

-- Hash del DNI y cadena de hash de la auditoria (§7.4, RS-07).
CREATE EXTENSION IF NOT EXISTS pgcrypto;

-- employee_code es CITEXT UNIQUE (doc 01 §5.5): un codigo de empleado no puede
-- duplicarse por diferencia de mayusculas.
CREATE EXTENSION IF NOT EXISTS citext;

-- Comprobacion de la regla dura 3. Si esto no dice UTC, el registro horario
-- que se construya encima no es fiable.
DO $$
BEGIN
  IF current_setting('TimeZone') NOT IN ('UTC', 'Etc/UTC', 'GMT', 'Etc/GMT') THEN
    RAISE EXCEPTION 'El cluster no esta en UTC (TimeZone=%). Revisa TZ/PGTZ en la imagen de postgres antes de seguir.',
      current_setting('TimeZone');
  END IF;
END
$$;
