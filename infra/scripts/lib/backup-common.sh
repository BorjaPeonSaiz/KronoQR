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
# shellcheck source=kqe.sh disable=SC1091
. "${BACKUP_COMMON_DIR}/kqe.sh"

# Cifrado en reposo de las copias (RL-12) y su autenticidad (R5-DV-04).
#
# FORMATO ACTUAL: KQE1 (lib/kqe.sh, ADR-049): AES-256-CBC con derivacion
# PBKDF2-SHA512 y sal aleatoria sobre `openssl` (que esta en cualquier servidor
# Linux), MAS una cabecera y un MAC SHA3-256 con clave derivada que se verifica
# antes de descifrar. Se descarto `age` (binario nuevo, identidad que custodiar,
# no autentica al emisor): ADR-049, «Alternativas descartadas».
#
# El `.sha256` sigue escribiendose, pero ya no es la defensa de integridad: lo es el
# MAC. Las copias HEREDADAS de la 2.1.0 (sin cabecera ni MAC) solo se leen
# (`kqe_decrypt_legacy_copy`); fabricarlas para las pruebas es cosa de
# `.github/scripts/forge-legacy-copy.sh`. El cifrado y sus parametros viven en
# kqe.sh (`KQE_ITER_DUMP`); aqui solo queda el nombre que declara el manifiesto.
# shellcheck disable=SC2034 # lo escribe backup.sh en el manifiesto.
readonly BACKUP_CIPHER="aes-256-cbc"

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

# Que fichero .env se lee cuando nadie ha dicho cual (N2.2-2).
#
# Las guias del cliente mandan ejecutar `bash ./restore-drill.sh` desde el
# directorio del paquete, y una entrada de cron con la ruta absoluta del script:
# en los dos casos el .env de la instalacion esta JUNTO AL PAQUETE y ninguna de
# las dos lleva `BACKUP_ENV_FILE`. Se busca respecto a la ubicacion del propio
# script, nunca respecto al directorio de trabajo (un cron no trabaja donde
# esta el paquete):
#
#   <paquete>/lib/backup-common.sh  ->  <paquete>/.env           (el paquete)
#                                       <paquete>/../.env        (scripts/ dentro
#                                                                 de la instalacion,
#                                                                 como en el runbook)
#
# El primero que exista. Imprime la ruta, o nada si no hay ninguno.
kq_default_env_file() {
  local candidate
  for candidate in "${BACKUP_COMMON_DIR}/../.env" "${BACKUP_COMMON_DIR}/../../.env"; do
    if [ -e "$candidate" ]; then
      # Sin `lib/..` en el mensaje que ve quien no puede leerlo.
      readlink -f -- "$candidate" 2>/dev/null || printf '%s' "$candidate"
      return 0
    fi
  done
  return 0
}

# Configuracion: entorno > fichero .env > valor por defecto.
#
# El fichero es BACKUP_ENV_FILE si esta definida (ahi manda quien la define, y
# `/dev/null` la desactiva) y, si no, el .env junto al paquete
# (`kq_default_env_file`). Lo que ya esta en el entorno GANA siempre al fichero.
#
# Regla dura 13: rutas, destinos y retencion son configuracion. Nada de lo que
# se lee aqui esta escrito en el codigo de ningun script.
load_backup_config() {
  local env_file="${BACKUP_ENV_FILE-}"

  if [ -z "${BACKUP_ENV_FILE+x}" ]; then
    env_file="$(kq_default_env_file)"
    # El .env de una instalacion es 0600 y de root (install.sh): quien no pueda
    # leerlo recibe el motivo verdadero y no un «falta la clave de cifrado» que
    # le mandaria a buscar en el sitio equivocado. Si la clave ya viene del
    # entorno, el fichero no hace falta y no se protesta.
    if [ -n "$env_file" ] && [ ! -r "$env_file" ]; then
      if [ -n "${BACKUP_ENCRYPTION_KEY:-}" ]; then
        env_file=""
      else
        die "${KQ_EXIT_REQUIREMENTS}" \
          "no se puede leer '${env_file}' (es de root y de modo 0600: contiene secretos). Ejecuta esto con sudo, o exporta BACKUP_ENCRYPTION_KEY y BACKUP_PATH en el entorno, o indica otro fichero con BACKUP_ENV_FILE=RUTA."
      fi
    fi
  fi

  load_env_file "${env_file:-/dev/null}"

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

  # Credenciales. El usuario de la copia NO es el de la aplicacion ni el de
  # migracion: es `fichaje_backup` (AUD-1), de solo lectura (pg_read_all_data +
  # REPLICATION; initdb/03-backup-role.sh). La aplicacion no tiene UPDATE ni
  # DELETE sobre audit_log (regla dura 6) y el volcado necesita leerlo entero;
  # el superusuario no hace falta para copiar y no debe estar en este entorno.
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
  # Necesario para el respaldo `-pass env:` de kqe.sh (_kqe_with_pass) cuando la
  # clave se ha leido de un fichero .env en vez de heredarla del entorno.
  export BACKUP_ENCRYPTION_KEY
  kqe_require || die "${KQ_EXIT_REQUIREMENTS}" \
    "este servidor tiene un openssl sin SHA3-256 (hace falta OpenSSL 1.1.1 o posterior) y el formato de las copias (KQE1, ADR-049) lo necesita. Actualiza el paquete openssl del servidor, o ejecuta este script dentro del contenedor (docker compose exec scheduler ...)."
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

# Comprueba el arbol de destino y crea lo que falte SI se puede. Idempotente: si
# ya existe no toca permisos de un directorio que el cliente pueda haber
# ajustado a su almacenamiento.
#
#   ensure_backup_tree [daily|base|metrics|reports ...]
#
# Los nombres son los subdirectorios que ESTE proceso necesita poder ESCRIBIR. Sin
# argumentos se exigen los cuatro (comportamiento de la 2.1.0). Desde la 2.2.0 la
# raiz de BACKUP_PATH se monta en SOLO LECTURA en el runtime (A3-R2): ya no se
# exige `-w` en la raiz, y cada proceso pide solo lo suyo (`backup.sh run`: daily,
# base y metrics; `verify`: metrics; `restore.sh`: reports...). Un subdirectorio
# que falta se crea como uid 1000 sin `-p` (lib/fs.sh) si la raiz lo permite; si
# no, el mensaje dice como crearlo sin `install -d` por ruta.
ensure_backup_tree() {
  local dir name rc
  local -a necesarios=("$@")

  [ -d "$BACKUP_PATH" ] || die "${KQ_EXIT_REQUIREMENTS}" \
    "el destino de copias '${BACKUP_PATH}' no existe. Montalo (si es un recurso de red) o corrige BACKUP_PATH en el .env. Ver docs/runbooks/restaurar-backup.md."
  [ "${#necesarios[@]}" -gt 0 ] || necesarios=(daily base metrics reports)

  for name in daily base metrics reports; do
    case "$name" in
    daily) dir="$BACKUP_DIR_DUMP" ;;
    base) dir="$BACKUP_DIR_BASE" ;;
    metrics) dir="$BACKUP_DIR_METRICS" ;;
    reports) dir="$BACKUP_DIR_REPORTS" ;;
    esac

    if [ ! -d "$dir" ]; then
      rc=0
      kq_ensure_app_dir "$dir" || rc=$?
      if [ "$rc" -ne 0 ] || [ ! -d "$dir" ]; then
        die "${KQ_EXIT_REQUIREMENTS}" \
          "falta el directorio '${dir}' y no se ha podido crear (en el servidor la raiz de copias esta montada en solo lectura para la aplicacion). Crealo en el servidor como el usuario 1000: sudo -u '#1000' mkdir -m 0750 -- '${dir}' (y su padre antes, si falta). Ver docs/runbooks/restaurar-backup.md."
      fi
    fi

    case " ${necesarios[*]} " in
    *" ${name} "*)
      [ -w "$dir" ] || die "${KQ_EXIT_REQUIREMENTS}" \
        "no se puede escribir en '${dir}'. Dentro del contenedor la copia corre como uid 1000 y cada servicio solo escribe en lo suyo (compose.prod.yaml): ¿lo lanzas desde el servicio correcto ('scheduler' para copiar, 'restore' para restaurar)? Si es un recurso de red, comprueba que permite escribir al usuario 1000. Ver docs/runbooks/restaurar-backup.md."
      ;;
    esac
  done
}

# Re-ejecuta el script COMO EL UID DE LA APLICACION si se ejecuta como root
# (A3-R2): root no debe crear ni renombrar por ruta ficheros de un arbol que
# escribe el runtime. Exporta lo que el proceso hijo necesita porque el `.env` es
# de root 0600 y el hijo ya no lo puede leer. Sin `setpriv` se niega a seguir.
#
#   kq_reexec_as_app "$0" "$@"
kq_reexec_as_app() {
  [ "$(id -u)" = "0" ] || return 0
  [ -z "${KQ_ALREADY_DROPPED:-}" ] || return 0
  command -v setpriv >/dev/null 2>&1 || die "${KQ_EXIT_REQUIREMENTS}" \
    "este script se ha lanzado como root y necesita 'setpriv' (paquete util-linux) para trabajar como el usuario 1000 de la aplicacion y no escribir por ruta como root en las copias. Instalalo, o lanza la orden dentro del contenedor ('docker compose exec scheduler ...')."
  export BACKUP_PATH BACKUP_RETENTION_DAYS BACKUP_MIN_COPIES BACKUP_ENCRYPTION_KEY
  [ -z "${BACKUP_ENCRYPTION_KEY_PREVIOUS:-}" ] || export BACKUP_ENCRYPTION_KEY_PREVIOUS
  export BACKUP_ENV_FILE=/dev/null KQ_ALREADY_DROPPED=1
  exec setpriv --reuid=1000 --regid=1000 --clear-groups -- bash "$@"
}

# Abre una copia (volcado o fisica) para verificarla, restaurarla o ensayar su
# restauracion (ADR-049): UNA lectura a un directorio privado y todo lo demas sobre
# esa copia (TOCTOU). UNA sola implementacion para `backup.sh verify`, `restore.sh` y
# `restore-drill.sh`: lo que se comprueba antes de fiarse de una copia no puede
# diferir de un script a otro.
#
#   kq_open_copy KIND FICHERO DIRECTORIO_PRIVADO ACEPTAR_HEREDADA CODIGO_DE_FALLO [GANCHO]
#
# KIND: `dump` (NOMBRE.dump.enc, con manifiesto autenticado) o `base` (copia fisica
# NOMBRE.tar.gz.enc, sin manifiesto). ACEPTAR_HEREDADA: 0 la rechaza, 1 la acepta
# porque quien llama paso `--accept-unauthenticated`, 2 la acepta solo para VERIFICAR
# (nunca se va a restaurar: `backup.sh verify`). GANCHO: nombre de una funcion sin
# argumentos que se ejecuta justo antes de cada `die` por fallo de la copia (verify
# publica ahi su metrica).
#
# Deja: INTEGRIDAD (authenticated|legacy_accepted), HUELLA_PRIVADA, MANIFIESTO (ruta
# de la COPIA del manifiesto, o vacio) y KQE_* (fecha, kid). Si algo no cuadra
# termina con die y CODIGO_DE_FALLO. Los mensajes dicen que hacer y que NO se ha
# tocado nada.
# shellcheck disable=SC2034 # INTEGRIDAD, HUELLA_PRIVADA y MANIFIESTO los leen los llamadores.
kq_open_copy() {
  local kind="$1" fichero="$2" trabajo="$3" aceptar="$4" fallo="$5" gancho="${6:-}"
  local nombre estado=0 guardada manifiesto_origen etiqueta ext

  case "$kind" in
  dump)
    ext=".dump.enc"
    etiqueta="volcado"
    ;;
  base)
    ext=".tar.gz.enc"
    etiqueta="copia fisica"
    ;;
  *) die "${KQ_EXIT_USAGE}" "kq_open_copy: tipo '${kind}' desconocido (dump o base)." ;;
  esac

  nombre="$(basename -- "$fichero")"
  nombre="${nombre%"$ext"}"
  kqe_open "$fichero" "$trabajo" "$kind" "$nombre" || estado=$?
  case "$estado" in
  0) INTEGRIDAD="authenticated" ;;
  10)
    if [ "$aceptar" -eq 0 ]; then
      [ -z "$gancho" ] || "$gancho"
      die "$fallo" "'${fichero}' es una ${etiqueta} de la 2.1.0: esta cifrada pero NO autenticada (solo la protege su .sha256, que quien escriba en el destino puede recalcular). Si es la que quieres, repite con --accept-unauthenticated (por invocacion, nunca en el .env). El .sha256 sigue siendo obligatorio. Procedimiento: docs/runbooks/restaurar-backup.md §6.8. No se ha tocado nada."
    fi
    INTEGRIDAD="legacy_accepted"
    ;;
  15)
    [ -z "$gancho" ] || "$gancho"
    die "${KQ_EXIT_REQUIREMENTS}" "${KQE_REASON}. Comprueba que el destino de copias esta montado y que la copia existe. No se ha tocado nada."
    ;;
  *)
    [ -z "$gancho" ] || "$gancho"
    die "$fallo" "'${fichero}' NO supera la comprobacion de autenticidad: ${KQE_REASON}. No la uses: prueba con la copia anterior ('backup.sh list'), y si no hay una averia de almacenamiento que lo explique, avisa al responsable de seguridad. Si se roto BACKUP_ENCRYPTION_KEY, usa la anterior en BACKUP_ENCRYPTION_KEY_PREVIOUS. No se ha tocado nada."
    ;;
  esac

  # La huella SHA-256 es OBLIGATORIA, y se compara con los bytes de la copia privada.
  # Si quien llama aporta una huella de CONFIANZA (EXPECT_SHA256: la vuelta atras de
  # update.sh, que la calculo el mismo y la guardo donde el runtime no llega, C13),
  # manda ella y el `.sha256` de BACKUP_PATH no cuenta.
  guardada="${EXPECT_SHA256:-}"
  [ -n "$guardada" ] || guardada="$(kq_sha256_stored "${fichero}.sha256")"
  HUELLA_PRIVADA="$(sha256_of "$KQE_COPY")"
  if [ -z "$guardada" ] || [ "$guardada" != "$HUELLA_PRIVADA" ]; then
    [ -z "$gancho" ] || "$gancho"
    die "$fallo" "la huella SHA-256 de '${fichero}' falta o no coincide con la registrada: esta corrupta o alguien la ha tocado. Prueba con la copia anterior ('backup.sh list') y avisa al responsable de seguridad. No se ha tocado nada."
  fi

  MANIFIESTO=""
  [ "$kind" = "dump" ] || return 0

  # El manifiesto decide que conteos cuadran: en una copia KQE1 se AUTENTICA tambien.
  manifiesto_origen="${fichero%.dump.enc}.manifest.json"
  if [ "$INTEGRIDAD" = "authenticated" ]; then
    if ! { [ -f "$manifiesto_origen" ] && [ -f "${manifiesto_origen%.json}.mac" ] &&
      cp -- "$manifiesto_origen" "${trabajo}/manifest.json" && cp -- "${manifiesto_origen%.json}.mac" "${trabajo}/manifest.mac" &&
      kqe_manifest_check "$nombre" "${trabajo}/manifest.json" "${trabajo}/manifest.mac"; }; then
      [ -z "$gancho" ] || "$gancho"
      die "$fallo" "el manifiesto de '${fichero}' falta, no tiene MAC o el MAC no cuadra: no se puede confiar en los conteos que declara. La copia se trata como inexistente: prueba con la anterior ('backup.sh list'). No se ha tocado nada."
    fi
    MANIFIESTO="${trabajo}/manifest.json"
  else
    if [ "$aceptar" -eq 2 ]; then
      err "AVISO: '${fichero}' es una copia de la 2.1.0: esta cifrada pero NO autenticada (solo la protege su .sha256). Caduca sola; para restaurarla hace falta --accept-unauthenticated."
    else
      err "AVISO: copia de la 2.1.0 aceptada por bandera explicita: sin autenticar. Su manifiesto tampoco lo esta."
    fi
    if [ -f "$manifiesto_origen" ] && cp -- "$manifiesto_origen" "${trabajo}/manifest.json" 2>/dev/null; then
      MANIFIESTO="${trabajo}/manifest.json"
    else
      err "AVISO: falta el manifiesto '${manifiesto_origen}'. El simulacro de restauracion no podra comparar conteos por tabla."
    fi
  fi
}

# Publica un informe de trabajo (directorio privado) en `reports/` como el uid de
# la aplicacion y sin sobrescribir (A3-R2). Si no se puede, lo CONSERVA aparte y lo
# dice: un informe perdido seria peor que uno publicado tarde.
#
#   kq_report_publish TRABAJO DESTINO
kq_report_publish() {
  local work="$1" final="$2" rc=0 rescate rescate_dir
  [ -n "$work" ] && [ -s "$work" ] || return 0
  kq_publish_as_app "$work" "$final" 027 2>/dev/null || rc=$?
  if [ "$rc" -ne 0 ]; then
    # Nunca un nombre predecible en /tmp (directorio con sticky, donde otro usuario
    # puede plantar un enlace: fs.sh ya lo dice): el directorio de registros de root
    # si existe y es de fiar, y si no uno privado 0700 recien creado.
    rescate_dir="${KRONOQR_LOG_DIR:-/var/log/kronoqr}"
    if ! { [ -d "$rescate_dir" ] && [ -w "$rescate_dir" ] && kq_path_trusted "$rescate_dir"; }; then
      rescate_dir="$(mktemp -d "${TMPDIR:-/tmp}/kronoqr-informe.XXXXXX" 2>/dev/null)" || rescate_dir=""
    fi
    rescate="${rescate_dir:-/nonexistent}/$(basename -- "$final")"
    (umask 077 && cp -- "$work" "$rescate") 2>/dev/null || true
    err "AVISO: no se ha podido publicar el informe en '${final}' (hace falta setpriv si se ejecuta como root, y que el nombre no exista). Queda en '${rescate}'. Adjuntalo al parte del incidente."
  fi
  return 0
}

# La subclave del WAL: la del entorno o, si no hay, la derivada de la maestra
# (quien restaura la tiene; `postgres` no necesita derivarla).
kq_wal_key_ensure() {
  if ! kqe_wal_key_valid "${BACKUP_WAL_KEY:-}"; then
    [ -n "${BACKUP_ENCRYPTION_KEY:-}" ] || return 1
    BACKUP_WAL_KEY="$(kqe_derive_wal_key "$BACKUP_ENCRYPTION_KEY")" || return 1
    kqe_wal_key_valid "$BACKUP_WAL_KEY" || return 1
  fi
  export BACKUP_WAL_KEY
  # La de la clave ANTERIOR (rotacion), si se ha dado: para abrir segmentos de antes.
  if [ -n "${BACKUP_ENCRYPTION_KEY_PREVIOUS:-}" ] && [ -z "${BACKUP_WAL_KEY_PREVIOUS:-}" ]; then
    BACKUP_WAL_KEY_PREVIOUS="$(kqe_derive_wal_key "$BACKUP_ENCRYPTION_KEY_PREVIOUS")" || BACKUP_WAL_KEY_PREVIOUS=""
  fi
  if kqe_wal_key_valid "${BACKUP_WAL_KEY_PREVIOUS:-}"; then
    export BACKUP_WAL_KEY_PREVIOUS
  fi
  return 0

}

# Huella esperada de un `.sha256`: 64 hex, o nada.
kq_sha256_stored() {
  local file="$1" value=""
  [ -f "$file" ] || return 0
  value="$(cut -d' ' -f1 <"$file" 2>/dev/null | head -n 1)"
  [[ "$value" =~ ^[0-9a-f]{64}$ ]] || value=""
  printf '%s' "$value"
}

# Escritura ATOMICA de un fichero de metricas para el colector textfile de
# node-exporter (doc 02 §8.2). Se escribe en un temporal del mismo directorio y
# se renombra: node-exporter jamas lee media metrica.
#
# Cada productor escribe SU fichero y sus propias metricas: dos ficheros con la
# misma metrica hacen que node-exporter descarte los dos.
#
# Delega en `kq_write_metrics_atomic` (lib/fs.sh): como root escribe COMO EL UID
# DE LA APLICACION (setpriv) y no por ruta, porque `metrics/` lo escribe el
# runtime y un enlace plantado ahi no debe llevar a root a tocar otro fichero
# (A3-R2, F1 del bloque 16). Si no se puede escribir, la metrica es de cortesia y
# no tumba la operacion.
write_metrics() {
  kq_write_metrics_atomic "$1" || return 0
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
#
# `{p;q;}` y NO `| head -n 1`, y no es cosmetico: con la tuberia, `head` cierra
# el descriptor tras la primera linea, `sed` sigue leyendo el manifiesto y muere
# por SIGPIPE, y con `pipefail` esta funcion devolveria 141 aunque hubiera
# encontrado el campo. Es la misma clase de fallo que rompio la primera
# ejecucion de la etapa ⑧ en `random_password` (ver install.sh). Aqui `sed` para
# solo y no hay tuberia que cortar.
manifest_field() {
  local manifest="$1" campo="$2"
  [ -f "$manifest" ] || return 1
  sed -n "s/^[[:space:]]*\"${campo}\"[[:space:]]*:[[:space:]]*\"\{0,1\}\([^\",]*\)\"\{0,1\},\{0,1\}$/\1/p;T;q" "$manifest"
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
      if grep -qxF "$tabla" <<<"$estables"; then
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

#------------------------------------------------------------------------------
# Guarda de roles (AUD-1, A3-01)
#------------------------------------------------------------------------------
#
# POR QUE EXISTE. Restaurar corre como el rol de migracion, que es SUPERUSUARIO:
# `pg_restore` ejecuta lo que traiga el archivo. Una copia manipulada —el
# runtime puede escribir en BACKUP_PATH y el `scheduler` tiene la clave de
# cifrado— podria llevar `ALTER ROLE fichaje_app SUPERUSER`, y los roles son del
# CLUSTER, no de la base: el intercambio de nombres no lo deshace. Por eso se
# toma una foto de los atributos y de las pertenencias de rol ANTES del
# `pg_restore` y otra DESPUES, y si difieren no se intercambia nada.
#
# QUE SE COMPARA, y que no: los siete atributos que gobiernan el poder de un
# rol (superusuario, crear roles, crear bases, saltarse RLS, replicacion,
# login, herencia) y TODAS las pertenencias con sus opciones. Nunca
# contraseñas: se lee `pg_roles`, que las oculta, y no `pg_authid`.
#
# LO QUE ESTO NO CUBRE, dicho para que nadie lo descubra despues: un archivo
# manipulado ejecutado por un superusuario puede hacer mucho mas que cambiar un
# rol (COPY ... PROGRAM, ALTER SYSTEM, funciones). Esta guarda cierra el
# vector concreto de la escalada de roles; la autenticidad de la copia frente a
# quien tiene la clave de cifrado (el scheduler) es otra cosa y la resuelve
# sacar la copia del runtime (ADR-042, «Alternativas descartadas»).

# Escribe en DESTINO la foto, ordenada en la coleccion `C` para que dos fotos
# iguales sean identicas byte a byte.
#
#   kq_roles_snapshot DESTINO
kq_roles_snapshot() {
  local dest="$1"

  {
    psql_q postgres "SELECT 'role|' || concat_ws('|', rolname, rolsuper, rolcreaterole, rolcreatedb, rolbypassrls, rolreplication, rolcanlogin, rolinherit) FROM pg_roles"
    psql_q postgres "SELECT 'member|' || concat_ws('|', g.rolname, m.rolname, gr.rolname, am.admin_option, am.inherit_option, am.set_option) FROM pg_auth_members am JOIN pg_roles g ON g.oid = am.roleid JOIN pg_roles m ON m.oid = am.member JOIN pg_roles gr ON gr.oid = am.grantor"
  } | LC_ALL=C sort >"$dest"
}

# Identificador SQL entre comillas dobles, con las comillas internas duplicadas.
kq_sql_ident() {
  local name="$1"
  printf '"%s"' "${name//\"/\"\"}"
}

# Un booleano de psql (`t`/`f`; con concat_ws sale asi, no como `true`/`false`)
# convertido en su palabra SQL: `t` -> SUPERUSER, `f` -> NOSUPERUSER. Cualquier
# otra cosa se trata como falsa, que es el lado seguro.
kq_role_keyword() {
  local value="$1" keyword="$2"
  if [ "$value" = "t" ]; then
    printf '%s' "$keyword"
  else
    printf 'NO%s' "$keyword"
  fi
}

# `t` -> TRUE, cualquier otra cosa -> FALSE.
kq_sql_bool() {
  if [ "$1" = "t" ]; then
    printf 'TRUE'
  else
    printf 'FALSE'
  fi
}

# Intenta devolver el cluster a la foto ANTERIOR: atributos de los roles que
# existian, pertenencias y roles que no existian (se borran; si poseen algo el
# DROP falla y el diff los sigue mostrando). Es un mejor esfuerzo, y por eso el
# llamador vuelve a fotografiar y compara: lo unico que vale es la segunda foto.
# El llamador elimina ANTES la base de trabajo: un rol nuevo puede ser su
# propietario.
#
#   kq_roles_revert ANTES AHORA
kq_roles_revert() {
  local antes="$1" ahora="$2" line kind a b c d e f g h sql

  # Roles cuya linea ya no es la de antes: se les reescriben los atributos.
  while IFS= read -r line; do
    [ -n "$line" ] || continue
    IFS='|' read -r kind a b c d e f g h <<<"$line"
    [ "$kind" = "role" ] || continue
    sql="ALTER ROLE $(kq_sql_ident "$a") WITH $(kq_role_keyword "$b" SUPERUSER) $(kq_role_keyword "$c" CREATEROLE) $(kq_role_keyword "$d" CREATEDB) $(kq_role_keyword "$e" BYPASSRLS) $(kq_role_keyword "$f" REPLICATION) $(kq_role_keyword "$g" LOGIN) $(kq_role_keyword "$h" INHERIT)"
    "${PSQL_CMD[@]}" -d postgres -Atqc "$sql" >/dev/null 2>&1 || true
  done < <(LC_ALL=C comm -23 "$antes" "$ahora")

  # Roles que no estaban: se borran.
  while IFS= read -r line; do
    [ -n "$line" ] || continue
    IFS='|' read -r kind a b <<<"$line"
    [ "$kind" = "role" ] || continue
    grep -q "^role|${a}|" "$antes" && continue
    "${PSQL_CMD[@]}" -d postgres -Atqc "DROP ROLE IF EXISTS $(kq_sql_ident "$a")" >/dev/null 2>&1 || true
  done < <(LC_ALL=C comm -13 "$antes" "$ahora")

  # Pertenencias que no estaban: se retiran, con su otorgante.
  while IFS= read -r line; do
    [ -n "$line" ] || continue
    IFS='|' read -r kind a b c d e f <<<"$line"
    [ "$kind" = "member" ] || continue
    sql="REVOKE $(kq_sql_ident "$a") FROM $(kq_sql_ident "$b") GRANTED BY $(kq_sql_ident "$c")"
    "${PSQL_CMD[@]}" -d postgres -Atqc "$sql" >/dev/null 2>&1 || true
  done < <(LC_ALL=C comm -13 "$antes" "$ahora")

  # Pertenencias que estaban y ya no (o con otras opciones): se devuelven.
  while IFS= read -r line; do
    [ -n "$line" ] || continue
    IFS='|' read -r kind a b c d e f <<<"$line"
    [ "$kind" = "member" ] || continue
    sql="GRANT $(kq_sql_ident "$a") TO $(kq_sql_ident "$b") WITH ADMIN $(kq_sql_bool "$d"), INHERIT $(kq_sql_bool "$e"), SET $(kq_sql_bool "$f") GRANTED BY $(kq_sql_ident "$c")"
    "${PSQL_CMD[@]}" -d postgres -Atqc "$sql" >/dev/null 2>&1 || true
  done < <(LC_ALL=C comm -23 "$antes" "$ahora")
}

# Compara la foto de ANTES con una nueva. Devuelve:
#   0  iguales.
#   1  difieren, y tras el intento de reversion la foto vuelve a ser la de antes.
#   2  difieren y NO se ha podido revertir: hay que intervenir a mano.
# Si difieren imprime en la salida de error que ha cambiado (roles y atributos,
# nada secreto).
#
#   kq_roles_unchanged ANTES [ORDEN ARGUMENTOS...]
#
# ORDEN, si se da, se ejecuta solo cuando hay cambios y ANTES de revertirlos
# (sirve para soltar la base de trabajo).
kq_roles_unchanged() {
  local antes="$1" ahora
  shift

  ahora="$(mktemp "${antes}.XXXXXX")"
  kq_roles_snapshot "$ahora"

  if cmp -s "$antes" "$ahora"; then
    rm -f "$ahora"
    return 0
  fi

  err "Cambios en los roles del cluster durante la restauracion (- antes, + despues):"
  {
    LC_ALL=C comm -23 "$antes" "$ahora" | sed 's/^/  - /'
    LC_ALL=C comm -13 "$antes" "$ahora" | sed 's/^/  + /'
  } >&2

  if [ "$#" -gt 0 ]; then
    "$@" >/dev/null 2>&1 || true
  fi

  kq_roles_revert "$antes" "$ahora"
  kq_roles_snapshot "$ahora"
  if cmp -s "$antes" "$ahora"; then
    rm -f "$ahora"
    return 1
  fi
  rm -f "$ahora"
  return 2
}
