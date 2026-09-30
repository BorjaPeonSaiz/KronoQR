#!/usr/bin/env bash
#
# KronoQR — el rol de las COPIAS DE SEGURIDAD (AUD-1, regla dura 6, ADR-033).
#
# POR QUE EXISTE
#
# Hasta la 2.1.0 las copias se hacian con `fichaje_migrator`, que es
# SUPERUSUARIO del cluster, y su contraseña vivia en el entorno de los
# contenedores de runtime (BACKUP_DB_PASSWORD). Quien ejecutara codigo en la
# aplicacion podia reescribir `audit_log` y recalcular la cadena de hash.
#
# Copiar no necesita escribir. `fichaje_backup` es un rol de SOLO LECTURA:
#
#   · pg_dump      → SELECT sobre todo. Lo da la pertenencia a `pg_read_all_data`,
#                    que ademas cubre las particiones de `audit_log` que se
#                    creen despues, sin ALTER DEFAULT PRIVILEGES.
#   · pg_basebackup → el atributo REPLICATION. No da ninguna escritura.
#
# NOSUPERUSER NOCREATEDB NOCREATEROLE: no puede crear nada, ni bases ni roles, ni
# tocar una fila. Restaurar SI necesita CREATEDB y renombrar bases; eso lo hace
# el servicio `restore` con el rol de migracion, nunca este.
#
# LA MEMBRESIA LLEVA `WITH INHERIT TRUE`, y no es adorno: desde PostgreSQL 16 un
# rol NOINHERIT (que es lo que usan todos los roles de KronoQR) no hereda los
# privilegios del rol al que pertenece, y sin esto `pg_dump` falla con
# «permission denied» pese al GRANT.
#
# RIESGO RESIDUAL, dicho aqui para que nadie lo descubra despues: el rol LEE
# todo (proteger la confidencialidad de la copia es la clave de cifrado, no este
# rol) y una copia fisica incluye `pg_authid` con los verificadores SCRAM de los
# demas roles. Con contraseñas aleatorias de 32 caracteres no es explotable en
# la practica, pero no es cero.
#
# LA GUARDA. Una 2.1.0 trae `BACKUP_DB_USERNAME=fichaje_migrator` en el .env. Este
# script se niega a tocar un rol que coincida con el de migracion, el de
# aplicacion o el de mantenimiento: `ALTER ROLE ... NOSUPERUSER` sobre el
# migrador dejaria la instalacion sin quien pueda migrar, y sobre el de
# aplicacion sin quien pueda fichar. Se niega SIN error (sale 0 con un aviso):
# es el caso normal de una instalacion que todavia no ha migrado sus copias, y
# un fallo aqui pararia el arranque de PostgreSQL en el primer initdb.
#
# CUANDO SE EJECUTA
#
#   · initdb, la primera vez sobre un volumen vacio: la contraseña llega en
#     DB_BACKUP_PASSWORD (compose.*.yaml la toma de BACKUP_DB_PASSWORD).
#   · A mano o desde update.sh sobre un cluster ya inicializado. Es idempotente.
#     Para no dejar la contraseña en `argv` ni en `docker inspect`, se entrega
#     por la ENTRADA ESTANDAR:
#
#       printf '%s\n' "$clave" | docker compose exec -T \
#         -e DB_BACKUP_USERNAME=fichaje_backup postgres \
#         /docker-entrypoint-initdb.d/03-backup-role.sh --password-stdin
#
# Codigos de salida: 0 hecho (o rol protegido, con aviso) · 1 configuracion
# invalida · 2 uso incorrecto.

set -euo pipefail
IFS=$'\n\t'

log() { printf '[backup-role] %s\n' "$1" >&2; }

BACKUP_PASSWORD="${DB_BACKUP_PASSWORD:-}"

case "${1:-}" in
"") ;;
--password-stdin)
  IFS= read -r BACKUP_PASSWORD || true
  ;;
*)
  log "uso: 03-backup-role.sh [--password-stdin]"
  exit 2
  ;;
esac

BACKUP_ROLE="${DB_BACKUP_USERNAME:-fichaje_backup}"
MIGRATOR_ROLE="${DB_MIGRATION_USERNAME:-${POSTGRES_USER:-fichaje_migrator}}"
APP_ROLE="${DB_APP_USERNAME:-fichaje_app}"
MAINTENANCE_ROLE="${DB_MAINTENANCE_USERNAME:-fichaje_maintenance}"
DATABASE="${POSTGRES_DB:-fichaje}"

if ! [[ "${BACKUP_ROLE}" =~ ^[a-z_][a-z0-9_]{0,62}$ ]]; then
  log "ERROR: DB_BACKUP_USERNAME='${BACKUP_ROLE}' no es un nombre de rol valido (minusculas, digitos y guion bajo)."
  log "       Que hacer: corrige BACKUP_DB_USERNAME en el .env."
  exit 1
fi

for protected in "${MIGRATOR_ROLE}" "${APP_ROLE}" "${MAINTENANCE_ROLE}" postgres; do
  if [[ "${BACKUP_ROLE}" == "${protected}" ]]; then
    log "AVISO: el rol de copias (${BACKUP_ROLE}) coincide con un rol que NO se debe degradar (${protected}). No se toca nada."
    log "       Es lo normal en una instalacion que aun hace las copias con el rol de migracion (2.1.0 y anteriores)."
    log "       update.sh la pasa al rol de solo lectura; ver docs/runbooks/rotacion-secretos.md."
    exit 0
  fi
done

if [[ -z "${BACKUP_PASSWORD}" ]]; then
  log "AVISO: sin contraseña para ${BACKUP_ROLE}: el rol se crea pero no podra conectar por red hasta que se le asigne una."
fi

log "Provisionando ${BACKUP_ROLE} (solo lectura) en «${DATABASE}»."

# El nombre entra como variable de psql, que la cita; la contraseña, por el
# ENTORNO de ese proceso y `\getenv` (A3-04): nunca por la linea de ordenes
# (`ps` la veria), ni por sustitucion del shell dentro del SQL. Mismo patron que
# 02-application-roles.sh.
KQ_BACKUP_PASSWORD="${BACKUP_PASSWORD}" \
  psql --username "${MIGRATOR_ROLE}" --dbname "${DATABASE}" \
  --no-password --set ON_ERROR_STOP=1 --quiet \
  --set backup_role="${BACKUP_ROLE}" <<'SQL'
\getenv backup_password KQ_BACKUP_PASSWORD
CREATE TEMP TABLE kronoqr_backup_config AS
SELECT :'backup_role'::text     AS backup_role,
       :'backup_password'::text AS backup_password;

DO $$
DECLARE
  cfg record;
  membership record;
  attributes constant text := 'LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE REPLICATION NOBYPASSRLS NOINHERIT';
BEGIN
  SELECT * INTO cfg FROM kronoqr_backup_config;

  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = cfg.backup_role) THEN
    -- Idempotencia con dientes: si el rol gano atributos por el camino, se le
    -- retiran.
    EXECUTE format('ALTER ROLE %I WITH %s', cfg.backup_role, attributes);
  ELSE
    EXECUTE format('CREATE ROLE %I WITH %s', cfg.backup_role, attributes);
  END IF;

  IF cfg.backup_password <> '' THEN
    EXECUTE format('ALTER ROLE %I PASSWORD %L', cfg.backup_role, cfg.backup_password);
  END IF;

  -- A3-06. Un rol que ya existia pudo ganar pertenencias por el camino
  -- (pg_write_all_data, pg_execute_server_program, el propio migrador...). Se
  -- retira TODA pertenencia distinta de pg_read_all_data, con el otorgante con
  -- el que se concedio: desde PostgreSQL 16 un REVOKE sin `GRANTED BY` solo
  -- quita lo concedido por el rol que lo ejecuta y deja el resto en pie.
  FOR membership IN
    SELECT g.rolname AS group_role, gr.rolname AS grantor_role
    FROM pg_auth_members am
    JOIN pg_roles g ON g.oid = am.roleid
    JOIN pg_roles r ON r.oid = am.member
    JOIN pg_roles gr ON gr.oid = am.grantor
    WHERE r.rolname = cfg.backup_role
      AND g.rolname <> 'pg_read_all_data'
  LOOP
    EXECUTE format('REVOKE %I FROM %I GRANTED BY %I',
      membership.group_role, cfg.backup_role, membership.grantor_role);
  END LOOP;

  -- Repetible: si la pertenencia existe, PostgreSQL 17 actualiza las opciones.
  EXECUTE format('GRANT pg_read_all_data TO %I WITH INHERIT TRUE', cfg.backup_role);
  EXECUTE format('GRANT CONNECT ON DATABASE %I TO %I', current_database(), cfg.backup_role);
END
$$;
SQL

log "Listo. ${BACKUP_ROLE} puede leer y copiar, y nada mas."
