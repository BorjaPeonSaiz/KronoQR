#!/usr/bin/env bash
#
# KronoQR — simulacro de restauracion (RNF-D-05, RQ-09).
#
# Restaura la ULTIMA copia en un contenedor LIMPIO —uno que no ha visto nunca
# esta base de datos— y comprueba dos cosas que ningun `ls -l` demuestra:
#
#   · INTEGRIDAD REFERENCIAL. Cada clave ajena se vuelve a crear sobre los
#     datos restaurados. PostgreSQL la valida al crearla, asi que una sola fila
#     huerfana hace fallar el simulacro con el nombre de la restriccion. Es la
#     comprobacion generica: vale para claves compuestas y para las que aun no
#     existen, sin escribir una consulta por tabla.
#   · CONTEOS. Cada tabla del manifiesto tiene en la copia restaurada al menos
#     las filas que tenia al hacerla.
#
# No toca la instalacion: ni la base de produccion, ni los contenedores del
# producto, ni las copias, que se abren en modo lectura. Al terminar, el
# contenedor del simulacro se destruye con todo lo que contenia, incluido el
# volcado descifrado, que nunca llega a tocar el disco del servidor.
#
# CADENCIA: trimestral (RNF-D-05). Se automatiza de dos maneras y las dos
# valen: `.github/workflows/backup-drill.yml` en el repositorio del fabricante,
# y una entrada de cron trimestral en el servidor del cliente, que es la que
# demuestra que SUS copias se restauran. El runbook explica las dos.
#
# Uso:
#   restore-drill.sh                        simulacro sobre la ultima copia
#   restore-drill.sh --file RUTA            sobre una copia concreta
#   restore-drill.sh --mode database        sin Docker, en una base nueva
#   restore-drill.sh --keep                 no destruye el contenedor al acabar
#
# Opciones:
#   --file RUTA     copia a restaurar. Por defecto la del puntero LATEST
#   --image IMAGEN  imagen del contenedor limpio. Por defecto postgres:17-alpine
#   --mode MODO     container (por defecto) o database
#   --timeout SEG   espera maxima a que arranque el contenedor. Por defecto 90
#   --keep          conserva el contenedor para inspeccionarlo a mano
#
# El modo `database` restaura en una base NUEVA del PostgreSQL configurado y la
# elimina al terminar. Existe para la integracion continua, donde el runner ya
# ES un contenedor limpio y no hay Docker dentro de Docker. En el servidor de
# un cliente se usa el modo por defecto: no se crean bases en la instancia que
# sostiene el registro legal.
#
# Codigos de salida: 0 el simulacro pasa · 1 el simulacro falla (la copia no
# sirve) · 2 error de uso · 3 falta una herramienta o precondicion · 5 clave
# ausente o incorrecta.

set -euo pipefail
IFS=$'\n\t'

# En Git Bash (MSYS) sobre Windows, un argumento como `/tmp/copia.dump` se
# reescribe a `C:/Users/.../Temp/copia.dump` ANTES de llegar a `docker exec`,
# y pg_restore dentro del contenedor no lo encuentra. La variable desactiva
# esa conversion y en Linux no hace nada: el simulacro es el mismo en el
# servidor del cliente, en la CI y en una estacion de desarrollo Windows.
export MSYS_NO_PATHCONV=1

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIR
# La biblioteca comun SI se analiza: `make sh-lint` llama a ShellCheck con -x.
# shellcheck source-path=SCRIPTDIR
# shellcheck source=lib/backup-common.sh disable=SC1091
. "${SCRIPT_DIR}/lib/backup-common.sh"

FICHERO=""
IMAGEN="${DRILL_POSTGRES_IMAGE:-postgres:17-alpine}"
MODO="container"
ESPERA=90
CONSERVAR=0
CONTENEDOR=""
BASE_SIMULACRO=""
INFORME=""

al_salir() {
  if [ -n "$CONTENEDOR" ] && [ "$CONSERVAR" -eq 0 ]; then
    docker rm -f "$CONTENEDOR" >/dev/null 2>&1 || true
  elif [ -n "$CONTENEDOR" ]; then
    log "Contenedor conservado: ${CONTENEDOR}. Destruyelo con 'docker rm -f ${CONTENEDOR}' cuando acabes."
  fi
  if [ "$MODO" = "database" ] && [ -n "$BASE_SIMULACRO" ] && [ "$CONSERVAR" -eq 0 ]; then
    psql -d postgres -Atqc "DROP DATABASE IF EXISTS \"${BASE_SIMULACRO}\"" >/dev/null 2>&1 || true
  fi
  return 0
}

trap al_salir EXIT

uso() {
  sed -n '2,48p' "${BASH_SOURCE[0]}" | sed 's/^#\{1,2\} \{0,1\}//'
}

informar() {
  log "$*"
  [ -n "$INFORME" ] && printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*" >>"$INFORME"
  return 0
}

#------------------------------------------------------------------------------
# Metrica del simulacro (doc 02 §8.2, seccion de respaldo)
#------------------------------------------------------------------------------

metricas_del_simulacro() {
  local resultado="$1" duracion="$2" tablas="$3" filas="$4"
  local exito_ts=0
  [ "$resultado" -eq 1 ] && exito_ts="$(now_epoch)"

  write_metrics "${BACKUP_DIR_METRICS}/kronoqr_backup_drill.prom" <<EOF
# HELP kronoqr_backup_restore_drill_last_result Resultado del ultimo simulacro de restauracion: 1 correcto, 0 fallido.
# TYPE kronoqr_backup_restore_drill_last_result gauge
kronoqr_backup_restore_drill_last_result ${resultado}
# HELP kronoqr_backup_restore_drill_last_success_timestamp_seconds Momento del ultimo simulacro correcto (RNF-D-05: trimestral).
# TYPE kronoqr_backup_restore_drill_last_success_timestamp_seconds gauge
kronoqr_backup_restore_drill_last_success_timestamp_seconds ${exito_ts}
# HELP kronoqr_backup_restore_drill_duration_seconds Duracion del ultimo simulacro; alimenta la estimacion del RTO.
# TYPE kronoqr_backup_restore_drill_duration_seconds gauge
kronoqr_backup_restore_drill_duration_seconds ${duracion}
# HELP kronoqr_backup_restore_drill_tables Tablas comprobadas en el ultimo simulacro.
# TYPE kronoqr_backup_restore_drill_tables gauge
kronoqr_backup_restore_drill_tables ${tablas}
# HELP kronoqr_backup_restore_drill_rows Filas restauradas y contadas en el ultimo simulacro.
# TYPE kronoqr_backup_restore_drill_rows gauge
kronoqr_backup_restore_drill_rows ${filas}
EOF
}

#------------------------------------------------------------------------------
# Destino del simulacro
#------------------------------------------------------------------------------

levantar_contenedor_limpio() {
  local esperado=0 clave

  require_cmd docker docker
  docker info >/dev/null 2>&1 || die 3 \
    "Docker no responde. El simulacro necesita levantar un contenedor limpio. Si este equipo no tiene Docker, usa '--mode database' contra una instancia de pruebas. No se ha tocado nada."

  # Contraseña de usar y tirar para un contenedor que vive minutos y no publica
  # ningun puerto. No se imprime ni se guarda.
  clave="$(openssl rand -hex 16)"
  CONTENEDOR="kronoqr-drill-$(timestamp_utc)"
  BASE_SIMULACRO="drill"

  informar "Levantando contenedor limpio ${CONTENEDOR} (${IMAGEN})"
  docker run --detach --name "$CONTENEDOR" \
    --env POSTGRES_PASSWORD="$clave" \
    --env POSTGRES_DB="$BASE_SIMULACRO" \
    --env POSTGRES_INITDB_ARGS=--encoding=UTF8 \
    --env TZ=UTC --env PGTZ=UTC \
    --network none \
    "$IMAGEN" >/dev/null || die 3 \
    "no se ha podido crear el contenedor del simulacro con la imagen '${IMAGEN}'. Descargala antes ('docker pull ${IMAGEN}') si el servidor no tiene salida a internet."

  # `--network none`: el contenedor del simulacro no habla con nadie. Todo
  # entra y sale por `docker exec`.
  PSQL_CMD=(docker exec -i "$CONTENEDOR" psql -U postgres)

  while [ "$esperado" -lt "$ESPERA" ]; do
    if docker exec "$CONTENEDOR" pg_isready -U postgres -d "$BASE_SIMULACRO" >/dev/null 2>&1; then
      informar "Contenedor listo en ${esperado} s."
      return 0
    fi
    sleep 2
    esperado=$((esperado + 2))
  done

  die 3 "el contenedor del simulacro no ha arrancado en ${ESPERA} s. Mira 'docker logs ${CONTENEDOR}'. No se ha tocado la instalacion."
}

crear_base_de_simulacro() {
  require_cmd psql postgresql17-client
  require_cmd pg_restore postgresql17-client
  psql -Atqc 'SELECT 1' >/dev/null 2>&1 || die 3 \
    "no se puede conectar a PostgreSQL en ${PGHOST}:${PGPORT}. El modo 'database' necesita una instancia de pruebas donde crear la base del simulacro."

  BASE_SIMULACRO="kronoqr_drill_$(timestamp_utc)"
  informar "Creando base limpia ${BASE_SIMULACRO}"
  psql -d postgres -Atqc "CREATE DATABASE \"${BASE_SIMULACRO}\"" >/dev/null || die 3 \
    "no se ha podido crear la base del simulacro. El usuario ${PGUSER} necesita CREATEDB."
}

restaurar_en_destino() {
  if [ "$MODO" = "container" ]; then
    # El volcado descifrado entra por la entrada estandar del contenedor y
    # muere con el: el texto en claro no toca el disco del servidor.
    decrypt_stream <"$FICHERO" |
      docker exec -i "$CONTENEDOR" sh -c 'cat > /tmp/copia.dump' || die 5 \
      "no se ha podido descifrar '${FICHERO}' con la clave actual. Si la clave se roto, el simulacro debe usar la que corresponda a esta copia."
    docker exec "$CONTENEDOR" pg_restore --username=postgres --dbname="$BASE_SIMULACRO" \
      --no-owner --no-privileges --exit-on-error /tmp/copia.dump >>"${INFORME:-/dev/null}" 2>&1 || return 1
  else
    decrypt_stream <"$FICHERO" >"${TMPDIR:-/tmp}/kronoqr-drill.dump" || die 5 \
      "no se ha podido descifrar '${FICHERO}' con la clave actual."
    pg_restore --dbname="$BASE_SIMULACRO" --no-owner --no-privileges --exit-on-error \
      "${TMPDIR:-/tmp}/kronoqr-drill.dump" >>"${INFORME:-/dev/null}" 2>&1 || {
      rm -f "${TMPDIR:-/tmp}/kronoqr-drill.dump"
      return 1
    }
    rm -f "${TMPDIR:-/tmp}/kronoqr-drill.dump"
  fi
  return 0
}

#------------------------------------------------------------------------------
# Las dos comprobaciones que dan sentido al simulacro
#------------------------------------------------------------------------------

# Integridad referencial, de forma generica: se recrea cada clave ajena. Crear
# una clave ajena obliga a PostgreSQL a validarla contra los datos, asi que
# esto comprueba TODAS las relaciones de la copia sin conocer el esquema.
#
# Se ejecuta dentro de una transaccion que se deshace: no altera ni siquiera la
# copia de usar y tirar.
comprobar_integridad_referencial() {
  local sql claves salida

  claves="$(psql_q "$BASE_SIMULACRO" "SELECT count(*) FROM pg_constraint WHERE contype = 'f'" | tr -d '[:space:]')"
  informar "Claves ajenas a validar: ${claves:-0}"
  [ "${claves:-0}" -gt 0 ] || return 0

  # Se quita el `NOT VALID` de la definicion a proposito. Una clave ajena
  # marcada como no validada es una promesa que PostgreSQL no ha comprobado
  # nunca, y recrearla igual dejaria pasar precisamente las filas huerfanas que
  # este simulacro busca. Aqui se exige que los datos restaurados satisfagan
  # TODAS las relaciones declaradas, validadas o no.
  sql="$(psql_q "$BASE_SIMULACRO" "
    SELECT string_agg(
      format('ALTER TABLE %s DROP CONSTRAINT %I; ALTER TABLE %s ADD CONSTRAINT %I %s;',
             conrelid::regclass, conname, conrelid::regclass, conname,
             replace(pg_get_constraintdef(oid), ' NOT VALID', '')),
      E'\n' ORDER BY conname)
    FROM pg_constraint WHERE contype = 'f'")"

  if ! salida="$(printf 'BEGIN;\n%s\nROLLBACK;\n' "$sql" |
    "${PSQL_CMD[@]}" -d "$BASE_SIMULACRO" -v ON_ERROR_STOP=1 2>&1)"; then
    err "Integridad referencial: FALLA."
    err "$salida"
    return 1
  fi
  informar "Integridad referencial: correcta (${claves} claves ajenas validadas contra los datos)."
  return 0
}

comprobar_conteos() {
  local manifiesto estables tablas filas

  manifiesto="${FICHERO%.dump.enc}.manifest.json"
  tablas="$(psql_q "$BASE_SIMULACRO" "SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.relkind = 'r' AND n.nspname NOT IN ('pg_catalog', 'information_schema') AND n.nspname !~ '^pg_'" | tr -d '[:space:]')"
  informar "Tablas en la copia restaurada: ${tablas:-0}"
  DRILL_TABLAS="${tablas:-0}"

  # Cero tablas no es un fallo del simulacro —la copia reproduce fielmente lo
  # que habia— pero si es algo que hay que mirar antes de dar por buena una
  # instalacion en marcha.
  [ "${tablas:-0}" -gt 0 ] || err "AVISO: la copia no contiene ninguna tabla. Si esta instalacion ya esta en produccion, esto es un incidente: comprueba que backup.sh se conecta a la base correcta (DB_DATABASE)."

  if [ ! -f "$manifiesto" ]; then
    err "No hay manifiesto para '${FICHERO}': no se pueden comparar conteos. El simulacro NO puede darse por bueno."
    return 1
  fi

  filas="$(manifest_counts "$manifiesto" | awk -F'|' '{s += $2} END {print s + 0}')"
  DRILL_FILAS="$filas"

  estables="$(manifest_stable_tables "$manifiesto" | grep -c . || true)"
  informar "Conteos del manifiesto: ${filas} filas en ${tablas} tablas, ${estables} de ellas exigibles exactas."
  compare_table_counts "$BASE_SIMULACRO" "$manifiesto"
}

#------------------------------------------------------------------------------

main() {
  local inicio duracion resultado=0

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
    --image)
      IMAGEN="${2:-}"
      shift 2
      ;;
    --image=*)
      IMAGEN="${1#*=}"
      shift
      ;;
    --mode)
      MODO="${2:-}"
      shift 2
      ;;
    --mode=*)
      MODO="${1#*=}"
      shift
      ;;
    --timeout)
      ESPERA="${2:-90}"
      shift 2
      ;;
    --timeout=*)
      ESPERA="${1#*=}"
      shift
      ;;
    --keep)
      CONSERVAR=1
      shift
      ;;
    -h | --help)
      uso
      return 0
      ;;
    *) die 2 "argumento desconocido '$1'. Ejecuta 'restore-drill.sh --help'." ;;
    esac
  done

  case "$MODO" in
  container | database) ;;
  *) die 2 "modo '${MODO}' desconocido. Usa --mode container (por defecto) o --mode database." ;;
  esac

  load_backup_config
  require_cmd openssl openssl
  require_encryption_key
  ensure_backup_tree

  [ -n "$FICHERO" ] || FICHERO="$(latest_dump_file)"
  [ -n "$FICHERO" ] && [ -f "$FICHERO" ] || die 1 \
    "no hay ninguna copia sobre la que hacer el simulacro. Lanza 'backup.sh run' primero."

  if [ -f "${FICHERO}.sha256" ]; then
    [ "$(cut -d' ' -f1 <"${FICHERO}.sha256")" = "$(sha256_of "$FICHERO")" ] || die 1 \
      "la copia '${FICHERO}' esta corrupta (su huella no coincide). El simulacro se detiene aqui: eso ya es el hallazgo."
  fi

  INFORME="${BACKUP_DIR_REPORTS}/drill-$(timestamp_utc).log"
  : >"$INFORME"
  chmod 0640 "$INFORME"
  DRILL_TABLAS=0
  DRILL_FILAS=0

  informar "Simulacro de restauracion (RNF-D-05) sobre '${FICHERO}', modo ${MODO}."
  inicio="$(now_epoch)"

  if [ "$MODO" = "container" ]; then
    levantar_contenedor_limpio
  else
    crear_base_de_simulacro
  fi

  if ! restaurar_en_destino; then
    resultado=1
    err "La restauracion en el destino limpio ha fallado. Revisa '${INFORME}'."
  fi

  if [ "$resultado" -eq 0 ]; then
    comprobar_integridad_referencial || resultado=1
    comprobar_conteos || resultado=1
  fi

  duracion="$(($(now_epoch) - inicio))"

  if [ "$resultado" -eq 0 ]; then
    metricas_del_simulacro 1 "$duracion" "$DRILL_TABLAS" "$DRILL_FILAS"
    informar "SIMULACRO CORRECTO en ${duracion} s. La copia restaura y los datos cuadran."
    informar "Informe: ${INFORME}. Adjuntalo al registro trimestral (RNF-D-05, RQ-09)."
    return 0
  fi

  metricas_del_simulacro 0 "$duracion" "$DRILL_TABLAS" "$DRILL_FILAS"
  informar "SIMULACRO FALLIDO en ${duracion} s."
  die 1 "el simulacro ha fallado: la ultima copia NO se puede dar por buena. Revisa '${INFORME}', repitelo con la copia anterior ('--file') y sigue docs/runbooks/restaurar-backup.md."
}

main "$@"
