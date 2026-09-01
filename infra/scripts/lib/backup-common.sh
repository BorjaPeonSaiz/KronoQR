#!/usr/bin/env bash
#
# KronoQR — funciones comunes de copia, verificacion y restauracion.
#
# NO SE EJECUTA SOLO: lo cargan backup.sh, restore.sh y restore-drill.sh, que
# son los tres entregables del §11.6.1. Vive aparte porque los tres comparten
# la lectura de configuracion, el cifrado y la escritura de metricas, y tener
# tres copias de eso garantiza que se corrijan dos de las tres.
#
# Reglas que gobiernan este fichero (doc 02 §3.5):
#   · `set -euo pipefail` e `IFS` tambien aqui: el fichero se comprueba solo en
#     `make sh-lint` y quien lo lea debe ver las mismas garantias que en los
#     scripts que lo cargan.
#   · NINGUN SECRETO EN LA SALIDA. La clave de cifrado no se imprime, no se
#     pasa por la linea de ordenes (seria visible en `ps`) y no aparece en los
#     informes. Se entrega a openssl por un descriptor de fichero.
#   · Regla dura 21: aqui no se imprime ni un nombre de empleado. Lo que sale
#     por pantalla son rutas, tamanos, conteos por tabla y codigos de error.
#
# CODIGOS DE SALIDA: la tabla es UNICA para los cinco scripts de operacion y
# vive en lib/exit-codes.sh, que se carga aqui abajo. Hasta la tarea 5.4 estos
# tres scripts tenian una tabla propia y el instalador iba a traer otra: el
# mismo `3` habria significado "falta una herramienta" en la copia y "hay una
# instalacion previa" en el instalador, para la misma persona y a veces en el
# mismo cron.
#
# La equivalencia con la tabla anterior, para quien tuviera un cron escrito
# contra ella (documentada tambien en docs/cliente/operacion.md):
#
#   antes                                    ahora
#   1 la operacion ha fallado          -->   4 si se deshizo lo hecho
#                                            5 si algo quedo a medias
#                                            6 si fallo la verificacion
#                                            3 si no habia nada sobre lo que operar
#   2 error de uso                     -->   1
#   3 falta herramienta o precondicion -->   2
#   4 destino no escribible o sin espacio -> 2
#   5 clave ausente o incorrecta       -->   2 al comprobar la precondicion
#                                            6 al fallar el descifrado de una copia

set -euo pipefail
IFS=$'\n\t'

BACKUP_COMMON_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly BACKUP_COMMON_DIR
# shellcheck source-path=SCRIPTDIR
# shellcheck source=exit-codes.sh disable=SC1091
. "${BACKUP_COMMON_DIR}/exit-codes.sh"
# shellcheck source=env-file.sh disable=SC1091
. "${BACKUP_COMMON_DIR}/env-file.sh"
# shellcheck source=fs.sh disable=SC1091
. "${BACKUP_COMMON_DIR}/fs.sh"

# Cifrado en reposo de las copias (RL-12).
#
# AES-256-CBC con derivacion PBKDF2-SHA512 y sal aleatoria, sobre `openssl`,
# que esta en cualquier servidor Linux. Se descarto `age` —mas moderno y
# autenticado— porque no viene de serie en las distribuciones que instala un
# hotel, y una copia que no se puede descifrar en el servidor del cliente con
# las herramientas del sistema es una copia inutil el dia que hace falta.
#
# CBC no autentica el texto cifrado, asi que la integridad NO se deja al modo
# de cifrado: cada copia lleva su SHA-256 en un fichero aparte y `backup.sh
# verify` descifra la copia entera y la pasa por `pg_restore --list`. Un byte
# cambiado se detecta por las dos vias.
readonly BACKUP_CIPHER="aes-256-cbc"
readonly BACKUP_PBKDF2_ITER=600000

# Prefijo de todos los ficheros de una instalacion. No lleva nada del cliente
# (regla dura 13): el nombre es igual en todas las instalaciones.
readonly BACKUP_PREFIX="kronoqr"

log() {
  printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"
}

err() {
  printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*" >&2
}

# Termina con un codigo documentado y un mensaje que dice QUE HACER.
die() {
  local code="$1"
  shift
  err "ERROR: $*"
  exit "$code"
}

require_cmd() {
  local cmd="$1" paquete="$2"
  command -v "$cmd" >/dev/null 2>&1 || die "${KQ_EXIT_REQUIREMENTS}" \
    "falta la orden '${cmd}'. Instala el paquete '${paquete}' en el servidor, o ejecuta este script dentro del contenedor 'app' (docker compose exec app ...)."
}

# Lee un fichero .env sin ejecutarlo.
#
# Delega en lib/env-file.sh, que es el UNICO lector del arbol desde la tarea
# 5.4. Se conserva el nombre porque lo llaman `load_backup_config` y las
# pruebas de integracion; lo que ya no hay es una segunda interpretacion de lo
# que significa una comilla o una almohadilla (ver el porque en env-file.sh).
load_env_file() {
  kq_env_load "$1"
}

# Configuracion: entorno > fichero .env > valor por defecto.
#
# Regla dura 13: rutas, destinos y retencion son configuracion. Nada de lo que
# se lee aqui esta escrito en el codigo de ningun script.
load_backup_config() {
  load_env_file "${BACKUP_ENV_FILE:-/dev/null}"

  BACKUP_PATH="${BACKUP_PATH:-/var/backups/fichaje}"
  BACKUP_RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-30}"
  # Nunca se borra por debajo de este numero de copias, aunque la retencion
  # diga que todas han caducado: un servidor apagado tres meses no debe
  # despertarse sin ninguna copia.
  BACKUP_MIN_COPIES="${BACKUP_MIN_COPIES:-3}"

  BACKUP_DIR_DUMP="${BACKUP_PATH}/daily"
  BACKUP_DIR_BASE="${BACKUP_PATH}/base"
  BACKUP_DIR_METRICS="${BACKUP_PATH}/metrics"
  BACKUP_DIR_REPORTS="${BACKUP_PATH}/reports"
  BACKUP_LATEST_POINTER="${BACKUP_DIR_DUMP}/LATEST"

  # Credenciales. El usuario de la copia puede ser distinto del de la
  # aplicacion: la aplicacion no tiene UPDATE ni DELETE sobre audit_log (regla
  # dura 6) y el volcado necesita leerlo entero.
  PGHOST="${PGHOST:-${DB_HOST:-postgres}}"
  PGPORT="${PGPORT:-${DB_PORT:-5432}}"
  PGDATABASE="${PGDATABASE:-${DB_DATABASE:-fichaje}}"
  PGUSER="${PGUSER:-${BACKUP_DB_USERNAME:-${DB_USERNAME:-fichaje_app}}}"
  PGPASSWORD="${PGPASSWORD:-${BACKUP_DB_PASSWORD:-${DB_PASSWORD:-}}}"
  # `PGPASSWORD` viaja por el entorno del proceso, nunca por la linea de
  # ordenes: `ps aux` de cualquier usuario del servidor veria lo segundo.
  export PGHOST PGPORT PGDATABASE PGUSER PGPASSWORD
  export PGCONNECT_TIMEOUT="${PGCONNECT_TIMEOUT:-10}"
  # Regla dura 3: tambien las herramientas de linea de ordenes hablan UTC.
  export PGTZ=UTC
}

require_encryption_key() {
  [ -n "${BACKUP_ENCRYPTION_KEY:-}" ] || die "${KQ_EXIT_REQUIREMENTS}" \
    "BACKUP_ENCRYPTION_KEY no esta definida. Sin ella no se puede cifrar ni descifrar ninguna copia (RL-12). Definela en el .env de la instalacion; install.sh la genera y NO se puede recuperar si se pierde."
  [ "${#BACKUP_ENCRYPTION_KEY}" -ge 16 ] || die "${KQ_EXIT_REQUIREMENTS}" \
    "BACKUP_ENCRYPTION_KEY tiene menos de 16 caracteres. Genera una nueva con 'openssl rand -base64 48' y guardala en el gestor de secretos del cliente antes de sustituirla: las copias anteriores solo se descifran con la clave con la que se hicieron."
  # Necesario para el respaldo `-pass env:` de openssl_pass_spec cuando la
  # clave se ha leido de un fichero .env en vez de heredarla del entorno.
  export BACKUP_ENCRYPTION_KEY
}

# Como se le entrega la clave a openssl.
#
# Nunca por la linea de ordenes: `ps aux` de cualquier usuario del servidor la
# veria. Nunca por un fichero temporal: quedaria en disco. Quedan dos vias, y
# se prefiere la primera:
#
#   fd:3   la clave viaja por un descriptor de fichero que solo existe durante
#          la llamada. Es lo que se usa en el servidor (Linux).
#   env:   la variable de entorno del proceso, que solo puede leer su propio
#          usuario (o root). No añade exposicion: la clave YA esta en el
#          entorno del proceso que llama, que es de donde se lee.
#
# La deteccion existe porque algunas compilaciones de openssl —la de Git Bash
# en Windows, sin ir mas lejos— rechazan `fd:`. Sin este respaldo, el simulacro
# de restauracion no se puede ensayar en la maquina de quien lo escribe, y una
# comprobacion que solo corre en produccion no la ejecuta nadie.
openssl_pass_spec() {
  if [ -n "${BACKUP_PASS_SPEC:-}" ]; then
    printf '%s' "$BACKUP_PASS_SPEC"
    return 0
  fi
  if printf 'x' | openssl enc -"${BACKUP_CIPHER}" -pbkdf2 -pass fd:3 3< <(printf 'k') >/dev/null 2>&1; then
    BACKUP_PASS_SPEC="fd:3"
  else
    BACKUP_PASS_SPEC="env:BACKUP_ENCRYPTION_KEY"
  fi
  printf '%s' "$BACKUP_PASS_SPEC"
}

encrypt_stream() {
  local spec
  spec="$(openssl_pass_spec)"
  openssl enc -"${BACKUP_CIPHER}" -md sha512 -pbkdf2 -iter "${BACKUP_PBKDF2_ITER}" \
    -salt -pass "$spec" 3< <(printf '%s' "${BACKUP_ENCRYPTION_KEY}")
}

decrypt_stream() {
  local spec
  spec="$(openssl_pass_spec)"
  openssl enc -d -"${BACKUP_CIPHER}" -md sha512 -pbkdf2 -iter "${BACKUP_PBKDF2_ITER}" \
    -pass "$spec" 3< <(printf '%s' "${BACKUP_ENCRYPTION_KEY}")
}

sha256_of() {
  local file="$1"
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$file" | cut -d' ' -f1
  else
    openssl dgst -sha256 "$file" | awk '{print $NF}'
  fi
}

# Espacio en disco. La implementacion vive en lib/fs.sh, compartida con el
# instalador.
free_bytes_at() {
  kq_free_bytes "$1"
}

total_bytes_at() {
  kq_total_bytes "$1"
}

timestamp_utc() {
  date -u +%Y%m%dT%H%M%SZ
}

now_epoch() {
  date -u +%s
}

# Crea el arbol de destino si falta. Idempotente: si ya existe no toca permisos
# de un directorio que el cliente pueda haber ajustado a su almacenamiento.
ensure_backup_tree() {
  local dir
  [ -d "$BACKUP_PATH" ] || die "${KQ_EXIT_REQUIREMENTS}" \
    "el destino de copias '${BACKUP_PATH}' no existe. Creala y dale permiso de escritura al usuario que ejecuta la copia (uid 1000 dentro del contenedor 'app'), o corrige BACKUP_PATH en el .env."
  [ -w "$BACKUP_PATH" ] || die "${KQ_EXIT_REQUIREMENTS}" \
    "no se puede escribir en '${BACKUP_PATH}'. Comprueba el propietario del directorio: dentro del contenedor la copia corre como uid 1000. Ver docs/runbooks/restaurar-backup.md."

  for dir in "$BACKUP_DIR_DUMP" "$BACKUP_DIR_BASE" "$BACKUP_DIR_METRICS" "$BACKUP_DIR_REPORTS"; do
    if [ ! -d "$dir" ]; then
      mkdir -p "$dir"
      # Las copias son datos personales cifrados: el directorio no es de
      # lectura publica. El de metricas TAMPOCO desde la tarea 5.4: node-exporter
      # corre con el uid 1000, el mismo que escribe las copias
      # (infra/compose.prod.yaml), asi que lo lee sin necesidad de que el
      # directorio sea legible para todo el mundo.
      chmod 0750 "$dir"
    fi
  done
}

# Escritura ATOMICA de un fichero de metricas para el colector textfile de
# node-exporter (doc 02 §8.2). Se escribe en un temporal del mismo directorio y
# se renombra: node-exporter jamas lee media metrica.
#
# Cada productor escribe SU fichero y sus propias metricas: dos ficheros con la
# misma metrica hacen que node-exporter descarte los dos.
write_metrics() {
  local file="$1" tmp
  tmp="${file}.$$.tmp"
  cat >"$tmp"
  chmod 0644 "$tmp"
  mv -f "$tmp" "$file"
}

# Espacio libre en el destino, publicado como metrica propia y no dejado a los
# colectores de node-exporter: asi la alerta de disco de copias funciona igual
# en un bind mount, en un NFS del cliente y en un volumen de Docker.
emit_volume_metrics() {
  local libre total ratio
  libre="$(free_bytes_at "$BACKUP_PATH")"
  total="$(total_bytes_at "$BACKUP_PATH")"
  ratio=0
  [ "$total" -gt 0 ] && ratio="$(awk -v l="$libre" -v t="$total" 'BEGIN {printf "%.4f", l / t}')"

  cat <<EOF
# HELP kronoqr_backup_volume_free_bytes Espacio libre en el destino de copias.
# TYPE kronoqr_backup_volume_free_bytes gauge
kronoqr_backup_volume_free_bytes ${libre}
# HELP kronoqr_backup_volume_free_ratio Fraccion libre del destino de copias.
# TYPE kronoqr_backup_volume_free_ratio gauge
kronoqr_backup_volume_free_ratio ${ratio}
EOF
}

# Ultima copia valida segun el puntero LATEST, con respaldo por orden
# alfabetico si el puntero no existe (el nombre lleva la marca temporal, asi
# que ordenar por nombre es ordenar por fecha).
latest_dump_file() {
  local nombre
  if [ -f "$BACKUP_LATEST_POINTER" ]; then
    nombre="$(head -n 1 "$BACKUP_LATEST_POINTER")"
    if [ -n "$nombre" ] && [ -f "${BACKUP_DIR_DUMP}/${nombre}" ]; then
      printf '%s\n' "${BACKUP_DIR_DUMP}/${nombre}"
      return 0
    fi
  fi
  find "$BACKUP_DIR_DUMP" -maxdepth 1 -type f -name "${BACKUP_PREFIX}-*.dump.enc" 2>/dev/null |
    sort |
    tail -n 1
}

# Lee un campo de texto del manifiesto sin depender de jq, que no esta en
# ningun servidor por defecto. El manifiesto lo escribe backup.sh con un
# formato fijo, de una clave por linea.
manifest_field() {
  local manifest="$1" campo="$2"
  [ -f "$manifest" ] || return 1
  sed -n "s/^[[:space:]]*\"${campo}\"[[:space:]]*:[[:space:]]*\"\{0,1\}\([^\",]*\)\"\{0,1\},\{0,1\}$/\1/p" "$manifest" |
    head -n 1
}

# Como se habla con la base que se esta comprobando.
#
# Por defecto, el `psql` de esta maquina. El simulacro de restauracion lo
# sustituye por `docker exec <contenedor> psql -U postgres` para interrogar al
# contenedor limpio sin abrirle un puerto ni instalar nada: la comprobacion de
# integridad es la misma en los dos casos, y eso es justo lo que se quiere.
if ! declare -p PSQL_CMD >/dev/null 2>&1; then
  declare -a PSQL_CMD=(psql)
fi

psql_q() {
  local base="$1" sql="$2"
  "${PSQL_CMD[@]}" -d "$base" -Atqc "$sql"
}

# Conteos del manifiesto, en lineas `esquema.tabla|filas`.
manifest_counts() {
  local manifest="$1"
  [ -f "$manifest" ] || return 1
  sed -n 's/^[[:space:]]\{4\}"\([^"]*\)"[[:space:]]*:[[:space:]]*\([0-9]*\),\{0,1\}$/\1|\2/p' "$manifest"
}

# Tablas que el manifiesto declara ESTABLES: su conteo no cambio mientras se
# hacia el volcado, asi que en la copia restaurada tiene que salir el mismo
# numero, ni uno mas ni uno menos.
manifest_stable_tables() {
  local manifest="$1"
  [ -f "$manifest" ] || return 1
  sed -n '/"stable_tables"[[:space:]]*:[[:space:]]*\[/,/\]/p' "$manifest" |
    sed -n 's/^[[:space:]]*"\([^"]*\)".*$/\1/p' |
    grep -v '^stable_tables$' || true
}

# Compara los conteos por tabla de una base restaurada con los del manifiesto.
#
# Devuelve 0 si cuadran, 1 si no. Imprime SOLO nombres de tabla y numeros
# (regla dura 21: aqui no sale ni un dato de nadie).
#
# Dos varas de medir, y la distincion es la que hace util la comprobacion en un
# hotel que no se para:
#
#   · Tabla ESTABLE (no cambio durante el volcado): igualdad exacta. Una fila
#     de mas o de menos es un fallo de la copia.
#   · Tabla que cambio: solo se exige que no haya PERDIDO filas. Una diferencia
#     hacia arriba es gente fichando mientras se copiaba, no un fallo.
#
# Que FALTE una tabla es siempre un fallo, cambiara o no.
compare_table_counts() {
  local base="$1" manifest="$2"
  local tabla esperadas reales fallos=0 avisos=0 estables

  estables="$(manifest_stable_tables "$manifest" || true)"

  while IFS='|' read -r tabla esperadas; do
    [ -n "$tabla" ] || continue
    reales="$(psql_q "$base" "SELECT count(*) FROM ${tabla}" 2>/dev/null || echo "ausente")"
    reales="$(printf '%s' "$reales" | tr -d '\r[:space:]')"
    [ -n "$reales" ] || reales="ausente"
    if [ "$reales" = "ausente" ]; then
      err "FALTA la tabla '${tabla}', que si estaba en el momento de la copia."
      fallos=$((fallos + 1))
      continue
    fi
    if [ "$reales" != "$esperadas" ]; then
      if printf '%s\n' "$estables" | grep -qxF "$tabla"; then
        err "CONTEO distinto en '${tabla}', que no cambio durante la copia: manifiesto ${esperadas}, restaurada ${reales}."
        fallos=$((fallos + 1))
      elif [ "$reales" -lt "$esperadas" ]; then
        err "FALTAN filas en '${tabla}': manifiesto ${esperadas}, restaurada ${reales}."
        fallos=$((fallos + 1))
      else
        avisos=$((avisos + 1))
      fi
    fi
  done < <(manifest_counts "$manifest")

  [ "$avisos" -eq 0 ] || log "${avisos} tablas con mas filas que el manifiesto: se copio con el sistema en marcha."
  [ "$fallos" -eq 0 ]
}
