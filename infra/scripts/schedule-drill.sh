#!/usr/bin/env bash
#
# KronoQR — programa el simulacro trimestral de restauracion en ESTE servidor
# (RNF-D-05, RQ-09; V5-DV-04).
#
# POR QUE EXISTE. El simulacro (`restore-drill.sh`) demuestra que LAS COPIAS DE ESTE
# CLIENTE se restauran, y RNF-D-05 lo exige cada trimestre. Hasta la 2.3.0 el runbook le pedia
# al IT que copiara una linea de cron a mano y la CAMBIARA en cada actualizacion (el directorio
# vigente lleva la version): nadie lo hacia, y la unica senal era una alerta que solo existe con
# el perfil de observabilidad. Este script deja la tarea puesta en una sola orden, y la deja
# puesta de forma que sobreviva a las actualizaciones.
#
# POR QUE UN CRON DEL ANFITRION Y NO EL PLANIFICADOR DE LARAVEL. El simulacro levanta un
# contenedor de PostgreSQL limpio, y para eso necesita hablar con Docker: ni el contenedor
# `scheduler` ni ningun otro de la aplicacion tiene (ni debe tener) el socket de Docker. Lo
# que SI hace la aplicacion es vigilarlo: `doctor.sh` avisa si el ultimo simulacro correcto
# tiene mas de 95 dias o fallo, y las alertas `SimulacroDeRestauracion*` (perfil de
# observabilidad) lo repiten.
#
# QUE INSTALA (dos ficheros, los dos de root):
#
#   /usr/local/sbin/kronoqr-restore-drill   un lanzador de unas lineas. Localiza la instalacion
#                                           vigente PREGUNTANDOLE A DOCKER (la misma tecnica que
#                                           doctor.sh y update.sh: las etiquetas del contenedor
#                                           `app`), entra en su directorio y ejecuta
#                                           `./restore-drill.sh`. Asi la entrada de cron no
#                                           lleva la version y una actualizacion no la deja
#                                           apuntando a un directorio retirado.
#   /etc/cron.d/kronoqr-restore-drill       la entrada de cron. Por defecto, el dia 1 de enero,
#                                           abril, julio y octubre a las 04:40 hora del servidor
#                                           (despues de la copia de las 03:15 y de las tareas de
#                                           04:05-04:30, y lejos del cambio de turno de las
#                                           06:00). Salida en /var/log/kronoqr-drill.log.
#
# QUE HACE EL SIMULACRO EN EL SERVIDOR (para decidir si programarlo): lee la ultima copia, la
# descifra y la restaura en un contenedor NUEVO de PostgreSQL sin red (`--network none`), comprueba
# claves ajenas, conteos y la cadena de hash de audit_log, y destruye el contenedor. NO toca la
# base de produccion, ni los contenedores de la aplicacion, ni las copias (las abre en lectura), y
# NO para nada. Consume CPU y disco de Docker mientras dura: del orden del tamano de la base de
# datos en disco, durante unos minutos (61 s con 3,65 M de filas en la medida de la 2.2.0). Necesita
# la imagen `postgres:17-alpine` del simulacro y la de la aplicacion descargadas: en un servidor
# sin salida a internet, cargalas antes (docs/runbooks/restaurar-backup.md §7).
#
# Uso:
#   schedule-drill.sh install [--schedule "M H D MES DS"]   lo programa (idempotente)
#   schedule-drill.sh remove                                 lo retira (y recuerda que no se quiere)
#   schedule-drill.sh ensure                                 lo programa salvo que se haya desactivado
#   schedule-drill.sh optout                                 recuerda que no se quiere programar
#   schedule-drill.sh status                                 dice si esta programado
#
# Necesita root para escribir en /etc/cron.d y /usr/local/sbin: `sudo ./schedule-drill.sh install`.
#
# install.sh y update.sh llaman a `ensure` (o a `optout` con --no-drill-schedule). La decision de no
# programarlo se recuerda en /etc/kronoqr/no-drill-schedule para que las actualizaciones no la
# deshagan; `install` la borra.
#
# Variables (solo para pruebas): KQ_DRILL_CRON_FILE, KQ_DRILL_LAUNCHER, KQ_DRILL_LOG, KQ_DRILL_OPTOUT.
#
# CODIGOS DE SALIDA (lib/exit-codes.sh):
#   0  Hecho (o ya estaba como se pedia).
#   1  Uso incorrecto. Nada tocado.
#   2  No se puede escribir en el destino (falta root) o falta Docker. Nada tocado.

set -euo pipefail
IFS=$'\n\t'

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIR
# shellcheck source-path=SCRIPTDIR
# shellcheck source=lib/exit-codes.sh disable=SC1091
. "${SCRIPT_DIR}/lib/exit-codes.sh"

readonly CRON_FILE="${KQ_DRILL_CRON_FILE:-/etc/cron.d/kronoqr-restore-drill}"
readonly LAUNCHER="${KQ_DRILL_LAUNCHER:-/usr/local/sbin/kronoqr-restore-drill}"
readonly LOG_FILE="${KQ_DRILL_LOG:-/var/log/kronoqr-drill.log}"
readonly OPTOUT_FILE="${KQ_DRILL_OPTOUT:-/etc/kronoqr/no-drill-schedule}"
readonly DEFAULT_SCHEDULE="40 4 1 1,4,7,10 *"
readonly MARK="# Gestionado por schedule-drill.sh (KronoQR, RNF-D-05). No lo edites a mano: schedule-drill.sh install | remove."

err() {
  printf 'schedule-drill: %s\n' "$*" >&2
}

die() {
  local code="$1"
  shift
  err "ERROR: $*"
  exit "$code"
}

usage() {
  sed -n '2,/^set -euo/p' "${BASH_SOURCE[0]}" | sed '$d' | sed 's/^# \{0,1\}//'
}

# El lanzador. Va entre comillas simples en el heredoc: nada se expande al escribirlo, todo al
# ejecutarlo en el momento del cron.
launcher_text() {
  cat <<'LAUNCHER_EOF'
#!/usr/bin/env bash
# KronoQR — lanza el simulacro de restauracion en la instalacion VIGENTE.
# Lo escribe schedule-drill.sh; no lo edites: vuelve a ejecutar 'schedule-drill.sh install'.
set -euo pipefail
IFS=$'\n\t'

if ! command -v docker >/dev/null 2>&1; then
  printf 'kronoqr-restore-drill: Docker no esta instalado o no esta en el PATH de cron. El simulacro necesita Docker.\n' >&2
  exit 2
fi

configs="$(docker ps -a --filter "label=com.docker.compose.project=kronoqr" \
  --filter "label=com.docker.compose.service=app" \
  --filter "label=com.docker.compose.oneoff=False" \
  --format '{{.Label "com.docker.compose.project.config_files"}}' |
  awk -F, 'NF { print $1 }' | sort -u)"

if [ "$(printf '%s\n' "$configs" | grep -c . || true)" != "1" ]; then
  printf 'kronoqr-restore-drill: no se localiza UNA instalacion de KronoQR (contenedores "app" del proyecto kronoqr: %s). Ejecuta el simulacro a mano desde el directorio de la instalacion: sudo ./restore-drill.sh\n' \
    "$(printf '%s' "$configs" | tr '\n' ' ')" >&2
  exit 2
fi

directorio="$(dirname -- "$configs")"
if [ ! -x "${directorio}/restore-drill.sh" ]; then
  printf 'kronoqr-restore-drill: %s/restore-drill.sh no existe o no es ejecutable. Reinstala el paquete.\n' "$directorio" >&2
  exit 2
fi

printf '%s kronoqr-restore-drill: simulacro en %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$directorio"
cd -- "$directorio"
exec ./restore-drill.sh "$@"
LAUNCHER_EOF
}

cron_text() {
  local schedule="$1"
  printf '%s\n' "$MARK"
  printf 'SHELL=/bin/bash\n'
  printf 'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin\n'
  printf '%s root %s >> %s 2>&1\n' "$schedule" "$LAUNCHER" "$LOG_FILE"
}

# El directorio de destino existe y se puede escribir; si no, termina diciendo que hacer.
ensure_writable_dir() {
  local dir
  dir="$(dirname -- "$1")"
  [ -d "$dir" ] || die "${KQ_EXIT_REQUIREMENTS}" "no existe el directorio '${dir}'. Este servidor no usa cron del sistema (/etc/cron.d)? Programa a mano 'cd <instalacion> && sudo ./restore-drill.sh' cada trimestre, o con un temporizador de systemd. No se ha tocado nada."
  [ -w "$dir" ] || die "${KQ_EXIT_REQUIREMENTS}" "no se puede escribir en '${dir}'. Ejecuta con sudo: 'sudo ./schedule-drill.sh install'. No se ha tocado nada."
}

# Escribe un fichero de forma atomica (temporal en el mismo directorio + mv) y solo si cambia.
# Devuelve 0 si lo ha escrito y 1 si ya estaba igual. El contenido va como argumento, NO por una
# tuberia: el `die` de aqui dentro tiene que terminar el script, no un subshell.
#   write_if_changed RUTA MODO CONTENIDO
write_if_changed() {
  local path="$1" mode="$2" content="$3" dir tmp
  ensure_writable_dir "$path"
  dir="$(dirname -- "$path")"
  tmp="$(mktemp "${dir}/.kronoqr-drill.XXXXXX")"
  printf '%s\n' "$content" >"$tmp"
  if [ -f "$path" ] && cmp -s "$tmp" "$path"; then
    rm -f -- "$tmp"
    chmod "$mode" "$path"
    return 1
  fi
  chmod "$mode" "$tmp"
  mv -f -- "$tmp" "$path"
  return 0
}

valid_schedule() {
  [[ "$1" =~ ^[0-9*/,-]+(\ [0-9*/,-]+){4}$ ]]
}

cmd_install() {
  local schedule="$DEFAULT_SCHEDULE" changed=0

  while [ $# -gt 0 ]; do
    case "$1" in
    --schedule)
      [ $# -ge 2 ] || die "${KQ_EXIT_USAGE}" "--schedule necesita un valor: cinco campos de cron entre comillas, por ejemplo \"${DEFAULT_SCHEDULE}\"."
      schedule="$2"
      shift 2
      ;;
    --schedule=*)
      schedule="${1#*=}"
      shift
      ;;
    *) die "${KQ_EXIT_USAGE}" "argumento desconocido '$1'. Ejecuta 'schedule-drill.sh --help'." ;;
    esac
  done
  valid_schedule "$schedule" ||
    die "${KQ_EXIT_USAGE}" "la planificacion '${schedule}' no son cinco campos de cron (minuto hora dia mes dia-de-semana; solo cifras, *, /, , y -). Ejemplo: \"${DEFAULT_SCHEDULE}\"."
  # Antes de escribir NADA: si un destino falla, el otro no se queda a medias.
  ensure_writable_dir "$LAUNCHER"
  ensure_writable_dir "$CRON_FILE"

  if write_if_changed "$LAUNCHER" 0755 "$(launcher_text)"; then
    changed=1
  fi
  if write_if_changed "$CRON_FILE" 0644 "$(cron_text "$schedule")"; then
    changed=1
  fi

  rm -f -- "$OPTOUT_FILE" 2>/dev/null || true

  if [ "$changed" -eq 1 ]; then
    printf 'Simulacro de restauracion programado: "%s" (hora del servidor).\n  Entrada de cron: %s\n  Lanzador:        %s\n  Salida:          %s\n' \
      "$schedule" "$CRON_FILE" "$LAUNCHER" "$LOG_FILE"
  else
    printf 'Ya estaba programado como se pide ("%s"). No se ha cambiado nada.\n' "$schedule"
  fi
  printf 'Para probarlo ahora, sin esperar al trimestre: sudo %s\nPara retirarlo: sudo ./schedule-drill.sh remove\n' "$LAUNCHER"

}

write_optout() {
  mkdir -p -- "$(dirname -- "$OPTOUT_FILE")" 2>/dev/null || true
  printf 'El operador ha decidido no programar el simulacro de restauracion (schedule-drill.sh remove u optout). Borra este fichero o ejecuta schedule-drill.sh install para volver a programarlo.\n' >"$OPTOUT_FILE" 2>/dev/null ||
    err "AVISO: no se ha podido recordar la decision en '${OPTOUT_FILE}': una actualizacion podria volver a programarlo."
}

cmd_optout() {
  write_optout
  printf 'El simulacro de restauracion NO se programara al instalar ni al actualizar (decision recordada en %s). ./doctor.sh seguira avisando. Para programarlo: sudo ./schedule-drill.sh install\n' "$OPTOUT_FILE"
}

# Lo que usan install.sh y update.sh: programa salvo que el operador haya dicho que no.
cmd_ensure() {
  if [ -e "$OPTOUT_FILE" ]; then
    printf 'El simulacro de restauracion NO se programa: el operador lo desactivo (%s). Para volver a programarlo: sudo ./schedule-drill.sh install\n' "$OPTOUT_FILE"
    return 0
  fi
  cmd_install
}

cmd_remove() {
  local removed=0
  if [ -e "$CRON_FILE" ]; then
    [ -w "$(dirname -- "$CRON_FILE")" ] || die "${KQ_EXIT_REQUIREMENTS}" "no se puede escribir en '$(dirname -- "$CRON_FILE")'. Ejecuta con sudo: 'sudo ./schedule-drill.sh remove'. No se ha tocado nada."
    rm -f -- "$CRON_FILE"
    removed=1
  fi
  if [ -e "$LAUNCHER" ]; then
    [ -w "$(dirname -- "$LAUNCHER")" ] || die "${KQ_EXIT_REQUIREMENTS}" "no se puede escribir en '$(dirname -- "$LAUNCHER")'. Ejecuta con sudo: 'sudo ./schedule-drill.sh remove'."
    rm -f -- "$LAUNCHER"
    removed=1
  fi
  if [ "$removed" -eq 1 ]; then
    write_optout
    printf 'Simulacro de restauracion RETIRADO del cron. Recuerda que RNF-D-05 lo exige cada trimestre: lanzalo a mano o programalo de otra forma.\n'
  else
    printf 'No estaba programado. No se ha cambiado nada.\n'
  fi
}

cmd_status() {
  local linea
  if [ -f "$CRON_FILE" ] && [ -x "$LAUNCHER" ]; then
    linea="$(grep -v '^#' "$CRON_FILE" | grep -F -- "$LAUNCHER" | head -n 1 || true)"
    printf 'PROGRAMADO: %s\n' "${linea:-$CRON_FILE}"
    printf 'Cuando corra, deja su informe en BACKUP_PATH/reports/ y su salida en %s. El ultimo resultado lo resume ./doctor.sh.\n' "$LOG_FILE"
    return 0
  fi
  printf 'NO PROGRAMADO. Para programarlo: sudo ./schedule-drill.sh install\n'
  return 0
}

main() {
  case "${1:-}" in
  install)
    shift
    cmd_install "$@"
    ;;
  ensure)
    shift
    cmd_ensure
    ;;
  optout)
    shift
    cmd_optout
    ;;
  remove)
    shift
    cmd_remove
    ;;
  status)
    shift
    cmd_status
    ;;
  -h | --help | help)
    usage
    ;;
  *) die "${KQ_EXIT_USAGE}" "uso: schedule-drill.sh install [--schedule \"M H D MES DS\"] | remove | status. Ejecuta 'schedule-drill.sh --help'." ;;
  esac
}

main "$@"
