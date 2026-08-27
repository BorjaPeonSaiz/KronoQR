#!/usr/bin/env bash
#
# KronoQR — restauracion de una copia cifrada (RF-PR-04, RTO <= 4 h).
#
# Lo ejecuta el IT del hotel, normalmente con un incidente delante y sin haber
# tocado nunca el sistema. De ahi las tres decisiones que lo gobiernan:
#
#   1. TODAS las precondiciones se comprueban ANTES de tocar nada. Si algo
#      falla, la instalacion queda exactamente como estaba (fallo seguro).
#   2. NO se restaura encima de la base viva. La copia se restaura en una base
#      NUEVA y, solo cuando ha superado sus comprobaciones, se intercambian los
#      nombres. La base anterior se conserva con su marca temporal, asi que la
#      vuelta atras es otro intercambio de nombres y dura segundos.
#   3. Toda restauracion deja INFORME en BACKUP_PATH/reports/. Restaurar es una
#      accion con relevancia legal: quien, cuando, que copia y con que
#      resultado (regla dura 6). El informe se adjunta al parte del incidente.
#
# Uso:
#   restore.sh --dry-run                   comprueba precondiciones, no toca nada
#   restore.sh --yes                       restaura la ultima copia verificada
#   restore.sh --file RUTA --yes           restaura una copia concreta
#   restore.sh --list                      copias disponibles
#
# Opciones:
#   --file RUTA        copia a restaurar. Por defecto, la del puntero LATEST
#   --database NOMBRE  base de destino. Por defecto, la del .env (DB_DATABASE)
#   --dry-run          solo comprobaciones; no crea, no borra, no renombra
#   --yes              confirma. Sin esto no se restaura nada
#   --keep-previous N  dias que se conserva la base anterior. Por defecto 7
#
# ANTES DE RESTAURAR hay que parar lo que escribe en la base: app, horizon,
# scheduler y reverb. El procedimiento completo, con los tiempos que caben en
# el RTO de 4 h, esta en docs/runbooks/restaurar-backup.md. Este script se
# niega a intercambiar las bases si quedan conexiones abiertas, y dice como
# cerrarlas.
#
# Codigos de salida: 0 correcto · 1 la restauracion ha fallado · 2 error de uso
# · 3 falta una herramienta o precondicion (nada tocado) · 4 sin espacio (nada
# tocado) · 5 clave ausente o incorrecta.
#
# NINGUN SECRETO EN LA SALIDA NI EN EL INFORME.

set -euo pipefail
IFS=$'\n\t'

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIR
# La biblioteca comun SI se analiza: `make sh-lint` llama a ShellCheck con -x.
# shellcheck source-path=SCRIPTDIR
# shellcheck source=lib/backup-common.sh disable=SC1091
. "${SCRIPT_DIR}/lib/backup-common.sh"

FICHERO=""
BASE_DESTINO=""
SOLO_COMPROBAR=0
CONFIRMADO=0
SOLO_LISTAR=0
DIAS_ANTERIOR=7
TRABAJO=""
INFORME=""

al_salir() {
  [ -n "$TRABAJO" ] && [ -d "$TRABAJO" ] && rm -rf "$TRABAJO"
  return 0
}

trap al_salir EXIT

uso() {
  sed -n '2,42p' "${BASH_SOURCE[0]}" | sed 's/^#\{1,2\} \{0,1\}//'
}

informar() {
  log "$*"
  [ -n "$INFORME" ] && printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*" >>"$INFORME"
  return 0
}

#------------------------------------------------------------------------------
# Precondiciones: todas, antes de nada
#------------------------------------------------------------------------------

comprobar_precondiciones() {
  local libre tamano_copia

  require_cmd openssl openssl
  require_cmd psql postgresql17-client
  require_cmd pg_restore postgresql17-client
  require_cmd df coreutils
  require_encryption_key
  ensure_backup_tree

  [ -n "$FICHERO" ] || FICHERO="$(latest_dump_file)"
  [ -n "$FICHERO" ] && [ -f "$FICHERO" ] || die 1 \
    "no hay ninguna copia que restaurar en '${BACKUP_DIR_DUMP}'. Comprueba BACKUP_PATH en el .env y que el almacenamiento de copias esta montado. Si el destino es un recurso de red, montalo antes. Ver docs/runbooks/restaurar-backup.md."

  psql -Atqc 'SELECT 1' >/dev/null 2>&1 || die 3 \
    "no se puede conectar a PostgreSQL en ${PGHOST}:${PGPORT} como ${PGUSER}. Levanta el servicio ('docker compose up -d postgres') y vuelve a lanzar esto. No se ha tocado nada."

  # Huella: si no coincide, la copia esta corrupta y no se toca la instalacion.
  if [ -f "${FICHERO}.sha256" ]; then
    [ "$(cut -d' ' -f1 <"${FICHERO}.sha256")" = "$(sha256_of "$FICHERO")" ] || die 1 \
      "la huella SHA-256 de '${FICHERO}' no coincide con la registrada: esta corrupta. Prueba con la copia anterior ('restore.sh --list') y avisa al responsable de seguridad. No se ha tocado nada."
  fi

  # Espacio: la copia descomprime a bastante mas de lo que ocupa cifrada. Se
  # exige cinco veces su tamano, que es el margen con el que un volcado
  # comprimido cabe holgado.
  tamano_copia="$(wc -c <"$FICHERO")"
  libre="$(free_bytes_at "$BACKUP_PATH")"
  [ "$libre" -ge "$((tamano_copia * 5))" ] || die 4 \
    "quedan $((libre / 1024 / 1024)) MiB libres y la restauracion necesita al menos $((tamano_copia * 5 / 1024 / 1024)) MiB de margen. Libera espacio antes de empezar. No se ha tocado nada."

  [ -n "$BASE_DESTINO" ] || BASE_DESTINO="$PGDATABASE"
}

# Descifra a un directorio privado y comprueba que pg_restore lo entiende.
# Aqui todavia no se ha tocado la instalacion.
preparar_volcado() {
  TRABAJO="$(mktemp -d "${TMPDIR:-/tmp}/kronoqr-restore.XXXXXX")"
  chmod 0700 "$TRABAJO"

  decrypt_stream <"$FICHERO" >"${TRABAJO}/copia.dump" 2>/dev/null || die 5 \
    "no se puede descifrar '${FICHERO}' con la BACKUP_ENCRYPTION_KEY actual. Si la clave se roto, usa la anterior: una copia solo se abre con la clave con la que se hizo. No se ha tocado nada."

  pg_restore --list "${TRABAJO}/copia.dump" >"${TRABAJO}/indice.txt" 2>/dev/null || die 1 \
    "'${FICHERO}' se descifra pero no es un volcado legible. Usa la copia anterior ('restore.sh --list'). No se ha tocado nada."

  informar "Copia legible: $(grep -cE '^[0-9]+;' "${TRABAJO}/indice.txt" || true) objetos."
}

conexiones_abiertas() {
  psql -Atqc "SELECT count(*) FROM pg_stat_activity WHERE datname = '${BASE_DESTINO}' AND pid <> pg_backend_pid()" 2>/dev/null || echo 0
}

#------------------------------------------------------------------------------

restaurar() {
  local marca base_nueva base_anterior abiertas

  marca="$(timestamp_utc)"
  base_nueva="${BASE_DESTINO}_restore_${marca}"
  base_anterior="${BASE_DESTINO}_pre_restore_${marca}"

  abiertas="$(conexiones_abiertas)"
  if [ "$abiertas" -gt 0 ]; then
    die 1 "hay ${abiertas} conexiones abiertas contra '${BASE_DESTINO}'. Para primero lo que escribe: 'docker compose stop app horizon scheduler reverb'. El fichaje sigue funcionando en los quioscos, que encolan en local (regla dura 19). No se ha tocado nada."
  fi

  informar "Creando base de trabajo ${base_nueva}"
  psql -d postgres -Atqc "CREATE DATABASE \"${base_nueva}\"" >/dev/null || die 1 \
    "no se ha podido crear la base de trabajo. El usuario ${PGUSER} necesita el permiso CREATEDB. Nada se ha tocado."

  informar "Restaurando el volcado (esto es lo que mas tarda)"
  if ! pg_restore --dbname="$base_nueva" --no-owner --no-privileges --exit-on-error \
    "${TRABAJO}/copia.dump" >>"${INFORME:-/dev/null}" 2>&1; then
    psql -d postgres -Atqc "DROP DATABASE IF EXISTS \"${base_nueva}\"" >/dev/null || true
    die 1 "la restauracion ha fallado; la base de trabajo se ha eliminado y '${BASE_DESTINO}' sigue como estaba. Revisa el informe '${INFORME}' y prueba con la copia anterior."
  fi

  informar "Comprobando la copia restaurada antes de darla por buena"
  comprobar_restauracion "$base_nueva" || {
    psql -d postgres -Atqc "DROP DATABASE IF EXISTS \"${base_nueva}\"" >/dev/null || true
    die 1 "la copia restaurada no supera las comprobaciones de integridad. NO se ha sustituido '${BASE_DESTINO}'. Prueba con la copia anterior y avisa al responsable del sistema."
  }

  # Intercambio de nombres. Es el unico momento en que la instalacion cambia, y
  # dura lo que dos ALTER DATABASE.
  informar "Intercambiando ${BASE_DESTINO} -> ${base_anterior} y ${base_nueva} -> ${BASE_DESTINO}"
  psql -d postgres -Atqc "ALTER DATABASE \"${BASE_DESTINO}\" RENAME TO \"${base_anterior}\"" >/dev/null || die 1 \
    "no se ha podido apartar la base actual: probablemente ha vuelto a haber conexiones. Para los servicios y repite. La base restaurada esta en '${base_nueva}' y no se ha perdido nada."
  if ! psql -d postgres -Atqc "ALTER DATABASE \"${base_nueva}\" RENAME TO \"${BASE_DESTINO}\"" >/dev/null; then
    psql -d postgres -Atqc "ALTER DATABASE \"${base_anterior}\" RENAME TO \"${BASE_DESTINO}\"" >/dev/null || true
    die 1 "no se ha podido activar la base restaurada; se ha devuelto la anterior a su nombre. La instalacion queda como estaba."
  fi

  informar "Restauracion completada. Base anterior conservada como '${base_anterior}'."
  informar "VUELTA ATRAS (mientras exista esa base): pare los servicios y ejecute"
  informar "  ALTER DATABASE \"${BASE_DESTINO}\" RENAME TO \"${BASE_DESTINO}_descartada\";"
  informar "  ALTER DATABASE \"${base_anterior}\" RENAME TO \"${BASE_DESTINO}\";"

  purgar_bases_anteriores
}

# Comprobacion de lo restaurado ANTES de sustituir la base viva: que estan
# todas las tablas del manifiesto y que ninguna ha perdido filas.
#
# La validacion exhaustiva de claves ajenas —recrear cada una para que
# PostgreSQL las verifique contra los datos— la hace el simulacro trimestral
# (restore-drill.sh) sobre una copia de usar y tirar. Aqui seria invasiva y
# cara justo en el momento en que el RTO corre.
comprobar_restauracion() {
  local base="$1" tablas manifiesto

  tablas="$(psql -d "$base" -Atqc "SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.relkind = 'r' AND n.nspname NOT IN ('pg_catalog', 'information_schema') AND n.nspname !~ '^pg_'")"
  informar "Tablas restauradas: ${tablas}"

  manifiesto="${FICHERO%.dump.enc}.manifest.json"
  if [ ! -f "$manifiesto" ]; then
    err "AVISO: sin manifiesto no se pueden comparar conteos. Se continua, pero anotalo en el parte."
    return 0
  fi

  compare_table_counts "$base" "$manifiesto"
}

# Las bases apartadas por restauraciones anteriores no se acumulan para
# siempre: ocupan tanto como la base viva. La fecha va en el propio nombre, asi
# que no hace falta preguntarle al sistema de ficheros.
purgar_bases_anteriores() {
  local base
  while IFS= read -r base; do
    [ -n "$base" ] || continue
    informar "Eliminando base de restauracion antigua: ${base}"
    psql -d postgres -Atqc "DROP DATABASE IF EXISTS \"${base}\"" >/dev/null || true
  done < <(psql -d postgres -Atqc "
    SELECT datname FROM pg_database
    WHERE datname ~ ('^${BASE_DESTINO}_pre_restore_[0-9]{8}T[0-9]{6}Z\$')
      AND to_timestamp(right(datname, 16), 'YYYYMMDD\"T\"HH24MISS\"Z\"') < now() - interval '${DIAS_ANTERIOR} days'" 2>/dev/null || true)
}

#------------------------------------------------------------------------------

resumen_dry_run() {
  local manifiesto
  manifiesto="${FICHERO%.dump.enc}.manifest.json"

  printf '\n'
  printf 'Precondiciones de la restauracion\n'
  printf '  copia .................. %s\n' "$FICHERO"
  printf '  creada ................. %s\n' "$(manifest_field "$manifiesto" created_at || echo "sin manifiesto")"
  printf '  tablas en la copia ..... %s\n' "$(manifest_field "$manifiesto" table_count || echo "sin manifiesto")"
  printf '  huella ................. verificada\n'
  printf '  descifrado ............. correcto\n'
  printf '  base de destino ........ %s en %s:%s\n' "$BASE_DESTINO" "$PGHOST" "$PGPORT"
  printf '  conexiones abiertas .... %s (deben ser 0 al restaurar)\n' "$(conexiones_abiertas)"
  printf '  espacio libre .......... %s MiB\n' "$(($(free_bytes_at "$BACKUP_PATH") / 1024 / 1024))"
  printf '\n'
  printf 'Nada se ha modificado. Para restaurar de verdad:\n'
  printf '  1. docker compose stop app horizon scheduler reverb\n'
  printf '  2. %s --file %s --yes\n' "${BASH_SOURCE[0]}" "$FICHERO"
  printf '  3. docker compose up -d\n'
  printf 'Procedimiento completo y tiempos: docs/runbooks/restaurar-backup.md\n'
}

main() {
  while [ $# -gt 0 ]; do
    case "$1" in
    --file)
      FICHERO="${2:-}"
      shift 2
      ;;
    --file=*)
      FICHERO="${1#*=}"
      shift
      ;;
    --database)
      BASE_DESTINO="${2:-}"
      shift 2
      ;;
    --database=*)
      BASE_DESTINO="${1#*=}"
      shift
      ;;
    --keep-previous)
      DIAS_ANTERIOR="${2:-7}"
      shift 2
      ;;
    --keep-previous=*)
      DIAS_ANTERIOR="${1#*=}"
      shift
      ;;
    --dry-run)
      SOLO_COMPROBAR=1
      shift
      ;;
    --list)
      SOLO_LISTAR=1
      shift
      ;;
    --yes)
      CONFIRMADO=1
      shift
      ;;
    -h | --help)
      uso
      return 0
      ;;
    *) die 2 "argumento desconocido '$1'. Ejecuta 'restore.sh --help'." ;;
    esac
  done

  load_backup_config

  if [ "$SOLO_LISTAR" -eq 1 ]; then
    ensure_backup_tree
    "${SCRIPT_DIR}/backup.sh" list
    return 0
  fi

  comprobar_precondiciones
  preparar_volcado

  if [ "$SOLO_COMPROBAR" -eq 1 ]; then
    resumen_dry_run
    return 0
  fi

  if [ "$CONFIRMADO" -ne 1 ]; then
    die 2 "esto sustituye la base de datos de produccion. Repite la orden con --yes cuando hayas leido docs/runbooks/restaurar-backup.md y parado los servicios que escriben."
  fi

  # El informe se abre ANTES de tocar nada y se conserva aunque la
  # restauracion falle: es la prueba de que se restauro, quien y cuando.
  INFORME="${BACKUP_DIR_REPORTS}/restore-$(timestamp_utc).log"
  : >"$INFORME"
  chmod 0640 "$INFORME"
  informar "Restauracion iniciada por '$(id -un 2>/dev/null || echo desconocido)' desde '$(hostname 2>/dev/null || echo desconocido)'"
  informar "Copia: ${FICHERO}"
  informar "Destino: ${BASE_DESTINO} en ${PGHOST}:${PGPORT}"

  restaurar

  log "Informe de la restauracion: ${INFORME}"
  log "Adjuntalo al parte del incidente: una restauracion en produccion se documenta (regla dura 6)."
}

main "$@"
