#!/usr/bin/env bash
#
# KronoQR — los tres roles de base de datos (regla dura 6, ADR-010, ADR-027).
#
# POR QUE HAY TRES Y NO UNO
#
# La regla dura 6 dice que "el usuario de base de datos de la aplicacion no
# tiene UPDATE ni DELETE sobre audit_log". Un GRANT o un REVOKE sobre un
# SUPERUSUARIO es DECORATIVO: PostgreSQL no comprueba privilegios para un
# superusuario, asi que la garantia seria falsa y solo se descubriria el dia que
# alguien borrase una fila del registro probatorio. Lo mismo vale, en menor
# grado, para el PROPIETARIO de una tabla: puede volver a otorgarse lo que se le
# revoque.
#
#   fichaje_migrator     Bootstrap del cluster (POSTGRES_USER). Propietario de
#                        la base y del esquema. Ejecuta las MIGRACIONES, y solo
#                        eso. Es el unico con DDL.
#   fichaje_app          Runtime: API, colas, scheduler, quiosco. Sin DDL, sin
#                        CREATEDB, sin CREATEROLE y, sobre audit_log, solo
#                        INSERT y SELECT. Es el DB_USERNAME del .env.
#   fichaje_maintenance  Retencion (tarea 2.10). Unico que podra soltar una
#                        particion de audit_log. NO aparece en el .env de la
#                        aplicacion (ADR-027).
#
# CUANDO SE EJECUTA
#
# Los scripts de /docker-entrypoint-initdb.d/ solo corren la PRIMERA vez, sobre
# un volumen vacio. Este ademas es idempotente y se puede lanzar a mano contra
# un cluster ya inicializado, que es la via de actualizacion de una instalacion
# existente:
#
#   docker compose --env-file .env -f infra/compose.dev.yaml exec postgres \
#     /docker-entrypoint-initdb.d/02-application-roles.sh
#
# Si el cluster se inicializo con fichaje_app como POSTGRES_USER —como ocurria
# antes de la tarea 1.14— el script crea el rol de migracion, le traslada la
# propiedad y le retira a fichaje_app los atributos de superusuario. Es la unica
# forma de que la regla dura 6 pase de aparente a real sin recrear el volumen.
# En ese caso hay que decirle con que rol conectarse, porque el nuevo todavia no
# existe:
#
#   docker compose --env-file .env -f infra/compose.dev.yaml exec \
#     -e PROVISION_AS_USER=fichaje_app postgres \
#     /docker-entrypoint-initdb.d/02-application-roles.sh
#
# LAS CONTRASEÑAS no pasan por argv ni por sustitucion del shell: entran como
# variables de psql, que las cita, y se leen desde una tabla temporal dentro del
# bloque PL/pgSQL. psql NO interpola dentro de una cadena entrecomillada con
# dolares, asi que la tabla temporal no es un rodeo: es la unica forma correcta.
#
# Sin contraseña configurada, el rol se crea igualmente pero sin credencial:
# existe y no se puede usar por TCP. Es justo lo que se quiere para
# fichaje_maintenance mientras la tarea 2.10 no defina su custodia.

set -euo pipefail
IFS=$'\n\t'

log() { printf '[roles] %s\n' "$1" >&2; }

MIGRATOR_ROLE="${DB_MIGRATION_USERNAME:-${POSTGRES_USER:-fichaje_migrator}}"
MIGRATOR_PASSWORD="${DB_MIGRATION_PASSWORD:-}"
# Rol con el que CONECTARSE. En un cluster nuevo es el de arranque, que ya es el
# de migracion. En uno antiguo hay que pasar el que exista (PROVISION_AS_USER).
BOOTSTRAP_ROLE="${PROVISION_AS_USER:-${POSTGRES_USER:-fichaje_migrator}}"
APP_ROLE="${DB_APP_USERNAME:-fichaje_app}"
APP_PASSWORD="${DB_PASSWORD:-}"
MAINTENANCE_ROLE="${DB_MAINTENANCE_USERNAME:-fichaje_maintenance}"
MAINTENANCE_PASSWORD="${DB_MAINTENANCE_PASSWORD:-}"
BOOTSTRAP_DATABASE="${POSTGRES_DB:-fichaje}"

if [[ "${APP_ROLE}" == "${MIGRATOR_ROLE}" ]]; then
  log "ERROR: DB_APP_USERNAME (${APP_ROLE}) coincide con el rol de migracion."
  log "       La aplicacion correria con el rol propietario y la regla dura 6 seria decorativa."
  exit 1
fi

# ---------------------------------------------------------------------------
# 0 · El rol de migracion, si todavia no existe. Es el caso de un cluster
#     inicializado antes de la tarea 1.14, donde el superusuario es fichaje_app.
# ---------------------------------------------------------------------------
if [[ "${MIGRATOR_ROLE}" != "${BOOTSTRAP_ROLE}" ]]; then
  log "Creando el rol de migracion ${MIGRATOR_ROLE} desde ${BOOTSTRAP_ROLE}."

  psql --username "${BOOTSTRAP_ROLE}" --dbname "${BOOTSTRAP_DATABASE}" \
    --no-password --set ON_ERROR_STOP=1 --quiet \
    --set migrator_role="${MIGRATOR_ROLE}" \
    --set migrator_password="${MIGRATOR_PASSWORD}" <<'SQL'
CREATE TEMP TABLE kronoqr_migrator_config AS
SELECT :'migrator_role'::text     AS migrator_role,
       :'migrator_password'::text AS migrator_password;

DO $$
DECLARE
  cfg record;
BEGIN
  SELECT * INTO cfg FROM kronoqr_migrator_config;

  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = cfg.migrator_role) THEN
    -- Superusuario: es el rol de arranque del cluster en una instalacion nueva
    -- y tiene que poder hacer exactamente lo mismo aqui.
    EXECUTE format('CREATE ROLE %I LOGIN SUPERUSER CREATEDB CREATEROLE REPLICATION', cfg.migrator_role);
  END IF;

  IF cfg.migrator_password <> '' THEN
    EXECUTE format('ALTER ROLE %I PASSWORD %L', cfg.migrator_role, cfg.migrator_password);
  END IF;
END
$$;
SQL
fi

# Conexion por socket local, como el resto del entrypoint de la imagen oficial.
psql_run() {
  psql --username "${MIGRATOR_ROLE}" --dbname "$1" \
    --no-password --set ON_ERROR_STOP=1 --quiet \
    --set app_role="${APP_ROLE}" \
    --set app_password="${APP_PASSWORD}" \
    --set maintenance_role="${MAINTENANCE_ROLE}" \
    --set maintenance_password="${MAINTENANCE_PASSWORD}" \
    --set migrator_role="${MIGRATOR_ROLE}"
}

# ---------------------------------------------------------------------------
# 1 · Los roles. Son del CLUSTER, no de una base: basta hacerlo una vez.
# ---------------------------------------------------------------------------
log "Provisionando ${APP_ROLE} y ${MAINTENANCE_ROLE}; propietario ${MIGRATOR_ROLE}."

psql_run "${BOOTSTRAP_DATABASE}" <<'SQL'
CREATE TEMP TABLE kronoqr_role_config AS
SELECT :'app_role'::text            AS app_role,
       :'app_password'::text        AS app_password,
       :'maintenance_role'::text    AS maintenance_role,
       :'maintenance_password'::text AS maintenance_password;

DO $$
DECLARE
  cfg record;
  existing record;
  -- Atributos que hacen que un GRANT signifique algo. NOINHERIT ademas impide
  -- que una futura pertenencia a un grupo se active sola.
  attributes constant text := 'LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS NOINHERIT';
BEGIN
  SELECT * INTO cfg FROM kronoqr_role_config;

  SELECT oid, rolsuper INTO existing FROM pg_roles WHERE rolname = cfg.app_role;

  IF existing IS NULL THEN
    EXECUTE format('CREATE ROLE %I WITH %s', cfg.app_role, attributes);

  ELSIF existing.rolsuper AND existing.oid = 10 THEN
    -- El caso de un cluster inicializado ANTES de la tarea 1.14, donde
    -- fichaje_app era POSTGRES_USER. PostgreSQL 16+ no deja degradar al
    -- superusuario de arranque («the bootstrap superuser must have the
    -- SUPERUSER attribute»), asi que no hay forma de arreglarlo en el sitio: se
    -- aparta con otro nombre y el rol de la aplicacion se crea de cero.
    --
    -- Es la unica via de actualizacion sin recrear el volumen. El rol apartado
    -- queda sin uso; su contraseña ya no vale para nada porque el .env apunta al
    -- nuevo.
    EXECUTE format('ALTER ROLE %I RENAME TO %I', cfg.app_role, cfg.app_role || '_bootstrap');
    EXECUTE format('CREATE ROLE %I WITH %s', cfg.app_role, attributes);

  ELSE
    -- Idempotencia con dientes: si el rol gano atributos por el camino, aqui se
    -- le retiran.
    EXECUTE format('ALTER ROLE %I WITH %s', cfg.app_role, attributes);
  END IF;

  IF cfg.app_password <> '' THEN
    EXECUTE format('ALTER ROLE %I PASSWORD %L', cfg.app_role, cfg.app_password);
  END IF;

  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = cfg.maintenance_role) THEN
    EXECUTE format('ALTER ROLE %I WITH %s', cfg.maintenance_role, attributes);
  ELSE
    EXECUTE format('CREATE ROLE %I WITH %s', cfg.maintenance_role, attributes);
  END IF;

  IF cfg.maintenance_password <> '' THEN
    EXECUTE format('ALTER ROLE %I PASSWORD %L', cfg.maintenance_role, cfg.maintenance_password);
  ELSE
    EXECUTE format('ALTER ROLE %I PASSWORD NULL', cfg.maintenance_role);
  END IF;
END
$$;
SQL

# ---------------------------------------------------------------------------
# 2 · Propiedad y acceso, base por base. `template1` no se toca.
# ---------------------------------------------------------------------------
databases="$(psql --username "${MIGRATOR_ROLE}" --dbname "${BOOTSTRAP_DATABASE}" \
  --no-password --tuples-only --no-align --set ON_ERROR_STOP=1 \
  --command "SELECT datname FROM pg_database WHERE datallowconn AND datname <> 'template1'")"

for database in ${databases}; do
  log "Ajustando propiedad y acceso en «${database}»."

  psql_run "${database}" <<'SQL'
CREATE TEMP TABLE kronoqr_role_config AS
SELECT :'app_role'::text         AS app_role,
       :'maintenance_role'::text AS maintenance_role,
       :'migrator_role'::text    AS migrator_role;

DO $$
DECLARE
  cfg record;
  candidate text;
  owned record;
BEGIN
  SELECT * INTO cfg FROM kronoqr_role_config;

  -- Todo lo del esquema `public` que estuviera a nombre del rol de aplicacion
  -- —o del rol de arranque apartado, si esto es una actualizacion— pasa al
  -- propietario. Un propietario puede volver a otorgarse lo que se le revoque:
  -- si fichaje_app siguiera siendo dueño de audit_log, el REVOKE de la
  -- migracion no valdria nada.
  --
  -- Objeto por objeto y NO con `REASSIGN OWNED`: el superusuario de arranque es
  -- ademas dueño de los catalogos del sistema, y `REASSIGN OWNED` se niega en
  -- bloque —«objects owned by role … are required by the database system»—. Lo
  -- que hay que mover es lo de `public`, que es donde vive el esquema del
  -- producto.
  FOREACH candidate IN ARRAY ARRAY[cfg.app_role, cfg.app_role || '_bootstrap'] LOOP
    -- Primero tablas y vistas. Las secuencias de sus columnas `serial` van
    -- detras de su tabla y cambian de dueño con ella.
    FOR owned IN
      SELECT c.relname, c.relkind
      FROM pg_class c
      JOIN pg_roles r ON r.oid = c.relowner
      JOIN pg_namespace n ON n.oid = c.relnamespace
      WHERE r.rolname = candidate
        AND n.nspname = 'public'
        AND c.relkind IN ('r', 'p', 'v', 'm')
    LOOP
      EXECUTE format(
        'ALTER %s %I OWNER TO %I',
        CASE owned.relkind
          WHEN 'v' THEN 'VIEW'
          WHEN 'm' THEN 'MATERIALIZED VIEW'
          ELSE 'TABLE'
        END,
        owned.relname,
        cfg.migrator_role
      );
    END LOOP;

    -- Y despues las secuencias SUELTAS, las que no dependen de ninguna tabla.
    -- Intentar mover una enlazada da «Sequence … is linked to table …», y la
    -- consulta se hace ahora, cuando las enlazadas ya han cambiado de dueño.
    FOR owned IN
      SELECT c.relname
      FROM pg_class c
      JOIN pg_roles r ON r.oid = c.relowner
      JOIN pg_namespace n ON n.oid = c.relnamespace
      WHERE r.rolname = candidate
        AND n.nspname = 'public'
        AND c.relkind = 'S'
    LOOP
      EXECUTE format('ALTER SEQUENCE %I OWNER TO %I', owned.relname, cfg.migrator_role);
    END LOOP;
  END LOOP;

  -- Conectar y ver el esquema. Crear objetos, no: en PostgreSQL 15+ PUBLIC ya
  -- no tiene CREATE sobre `public`, y no se le devuelve a nadie.
  EXECUTE format('GRANT CONNECT ON DATABASE %I TO %I, %I',
    current_database(), cfg.app_role, cfg.maintenance_role);
  EXECUTE format('GRANT USAGE ON SCHEMA public TO %I, %I',
    cfg.app_role, cfg.maintenance_role);
  EXECUTE format('REVOKE CREATE ON SCHEMA public FROM %I, %I',
    cfg.app_role, cfg.maintenance_role);
END
$$;
SQL
done

# ---------------------------------------------------------------------------
# 3 · Propiedad de cada base, al final y desde la de arranque.
# ---------------------------------------------------------------------------
for database in ${databases}; do
  psql --username "${MIGRATOR_ROLE}" --dbname "${BOOTSTRAP_DATABASE}" \
    --no-password --set ON_ERROR_STOP=1 --quiet \
    --command "ALTER DATABASE \"${database}\" OWNER TO \"${MIGRATOR_ROLE}\""
done

log "Listo. La aplicacion corre con ${APP_ROLE}, que ya no es superusuario ni propietario."
