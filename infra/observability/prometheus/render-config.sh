#!/bin/sh
#
# KronoQR — renderiza prometheus.yml desde su plantilla (doc 02 §8.1, tarea 3.8,
# hallazgo H-05 de docs/seguridad/revision-interna-asvs-2026-09.md).
#
# POR QUE ESTE SCRIPT. Prometheus no expande variables de entorno en su
# configuracion (salvo `--enable-feature=expand-external-labels`, que solo
# alcanza a `global.external_labels`, no a `scrape_configs`), y `APP_URL` — el
# dominio publico por el que los clientes reales llegan al hotel — es
# especifico de cada instalacion (regla dura 13: nada de eso en el repositorio
# del fabricante). Sin este script, la unica forma de anadir la sonda TLS que
# SI verifica identidad (`http_2xx_tls_verified`, `blackbox/blackbox.yml`)
# seria escribir el dominio del cliente a mano en un fichero versionado.
#
# MISMO MECANISMO QUE `infra/observability/alertmanager/render-config.sh`, a
# proposito: una plantilla de solo lectura con marcas `@@TOKEN@@`, resuelta
# ANTES de que el binario real arranque, con el resultado escrito en el
# volumen de datos (nunca sobre la plantilla). Ver la cabecera de ese script
# para el razonamiento completo (POSIX puro, sustitucion literal sin `sed`
# para los valores, verificacion con la herramienta real antes de arrancar);
# aqui solo se documenta lo que es distinto.
#
# LA UNICA MARCA: `@@KRONOQR_UPTIME_TLS_VERIFIED_JOB@@`, en
# `prometheus.yml.template`. Se sustituye por el `job_name:
# kronoqr-uptime-tls-verified` completo, apuntando a `${APP_URL}/api/v1/health`,
# SOLO cuando `APP_URL` esta configurada y la instalacion NO declaro
# `TLS_ALLOW_SELF_SIGNED=true`. Con el autofirmado declarado a proposito, el
# job se OMITE por completo (no se scrapea, no hay serie `probe_success` para
# ese target): es el "target condicionado" de la decision de la tarea 3.8, la
# forma mas honesta de no hacer sonar una alerta que nunca podria dejar de
# sonar en una instalacion que eligio ese modo a conciencia — sondear con
# verificacion activada un certificado que se sabe no verificable no seria
# tolerarlo, seria fingir que se vigila algo que no se vigila. Sin `APP_URL`
# configurada tampoco hay job: no hay dominio publico que sondear todavia
# (por ejemplo, durante la instalacion inicial antes de `install.sh`).
#
# EJECUCION. Es el ENTRYPOINT del contenedor de Prometheus (compose.dev.yaml y
# compose.prod.yaml): renderiza y luego cede el proceso al binario real con
# `exec prometheus "$@"`, de modo que Prometheus arranca con el PID 1. Con
# PROMETHEUS_RENDER_ONLY=1 el script SOLO renderiza (y verifica con `promtool`
# si esta disponible) y sale con 0, sin arrancar nada — lo mismo que
# ALERTMANAGER_RENDER_ONLY hace para Alertmanager.
#
# Codigos de salida: 0 si renderizo (y arranco Prometheus, salvo modo solo
# renderizado); 1 si la plantilla no existe, `APP_URL` o
# `TLS_ALLOW_SELF_SIGNED` no tienen la forma que se les exige, o `promtool
# check config` rechaza el resultado. El mensaje de error dice siempre que
# corregir.

# POSIX PURO, sin `set -o pipefail` ni `IFS=$'\n\t'`: este script lo ejecuta el
# `/bin/sh` que haya — el de la imagen oficial `prom/prometheus` (basada en
# busybox) en produccion, dash en la CI y en cualquier Debian/Ubuntu, bash en
# Git Bash. dash rechaza `set -o pipefail` y no entiende el entrecomillado
# ANSI-C. La unica tuberia del script es un `printf | sed` cuyo productor no
# puede fallar, asi que `pipefail` no hace falta.
set -eu
IFS="$(printf '\n\t')"

TEMPLATE_FILE="${PROMETHEUS_TEMPLATE_FILE:-/etc/prometheus/prometheus.yml.template}"
STORAGE_PATH="${PROMETHEUS_STORAGE_PATH:-/prometheus}"
OUTPUT_FILE="${PROMETHEUS_OUTPUT_FILE:-${STORAGE_PATH}/prometheus.yml}"

err() {
  printf 'render-config.sh: %s\n' "$*" >&2
}

die() {
  err "$*"
  exit 1
}

[ -f "${TEMPLATE_FILE}" ] || die "no existe la plantilla '${TEMPLATE_FILE}'. Comprueba el volumen que monta infra/observability/prometheus en /etc/prometheus."

#-------------------------------------------------------------------------------
# Configuracion. Mismos nombres que .env.example y que backend/config/security.php
# (`tls_allow_self_signed`), para que "no he tocado el .env" signifique lo
# mismo aqui que en Laravel.
#-------------------------------------------------------------------------------
APP_URL="${APP_URL:-}"
TLS_ALLOW_SELF_SIGNED="${TLS_ALLOW_SELF_SIGNED:-false}"

#-------------------------------------------------------------------------------
# Ninguna de las dos puede traer un salto de linea ni un retorno de carro: ver
# el razonamiento completo en la cabecera del render-config.sh de Alertmanager
# (decision 17b de esa tarea). Aqui el riesgo es el mismo: una URL mal citada
# en el .env podria cerrar la marca e inyectar una clave de YAML que nadie
# autorizo.
#-------------------------------------------------------------------------------
NL='
'
CR="$(printf '\r')"

reject_newline() {
  # $1 = nombre de la variable (para el mensaje) · $2 = su valor
  case "$2" in
  *"${NL}"* | *"${CR}"*)
    die "${1} contiene un salto de linea o un retorno de carro. No se admite: rompe el YAML renderizado. Revisa el .env: seguramente la variable quedo mal citada."
    ;;
  esac
}

reject_newline "APP_URL" "${APP_URL}"
reject_newline "TLS_ALLOW_SELF_SIGNED" "${TLS_ALLOW_SELF_SIGNED}"

case "${TLS_ALLOW_SELF_SIGNED}" in
true | false) ;;
*) die "TLS_ALLOW_SELF_SIGNED='${TLS_ALLOW_SELF_SIGNED}' no es 'true' ni 'false'. Corrigelo en el .env (mismo valor que backend/config/security.php espera)." ;;
esac

# `APP_URL` vacia es valida (todavia no hay dominio publico que sondear, por
# ejemplo durante la instalacion): sencillamente no se anade el job. Si SI
# viene rellena, tiene que ser HTTPS: el modulo de blackbox exige
# `fail_if_not_ssl: true` y un `http://` aqui fallaria siempre, generando una
# alerta permanente por un error de configuracion, no por un certificado de
# verdad.
case "${APP_URL}" in
'' | https://*) ;;
*) die "APP_URL='${APP_URL}' no empieza por 'https://'. La sonda que verifica el certificado solo tiene sentido sobre HTTPS; corrige APP_URL en el .env." ;;
esac

#-------------------------------------------------------------------------------
# Escape de YAML dentro de un escalar entre comillas simples: duplica cada
# comilla simple. Mismo `yaml_squote()` que Alertmanager, por el mismo motivo:
# seguro frente a `/`, `&`, `|` o cualquier otro caracter que traiga la URL.
#-------------------------------------------------------------------------------
yaml_squote() {
  printf '%s' "$1" | sed "s/'/''/g"
}

mkdir -p "$(dirname -- "${OUTPUT_FILE}")"

#-------------------------------------------------------------------------------
# El job de la sonda verificada, o nada (target condicionado). Se decide una
# sola vez, fuera del bucle de renderizado linea a linea, porque el bloque no
# depende de nada mas que de estas dos variables.
#-------------------------------------------------------------------------------
render_verified_tls_job() {
  if [ -z "${APP_URL}" ]; then
    printf '  # kronoqr-uptime-tls-verified: omitido, APP_URL no esta configurada todavia.\n'
    return 0
  fi
  if [ "${TLS_ALLOW_SELF_SIGNED}" = "true" ]; then
    printf '  # kronoqr-uptime-tls-verified: omitido, esta instalacion declaro TLS_ALLOW_SELF_SIGNED=true\n'
    printf '  # (un certificado autofirmado elegido a proposito nunca pasaria esta verificacion).\n'
    return 0
  fi

  target="$(yaml_squote "${APP_URL%/}/api/v1/health")"

  printf '  - job_name: kronoqr-uptime-tls-verified\n'
  printf '    metrics_path: /probe\n'
  printf '    params:\n'
  printf '      module: [http_2xx_tls_verified]\n'
  printf '    scrape_interval: 30s\n'
  printf '    static_configs:\n'
  printf '      - targets:\n'
  printf "          - '%s'\n" "${target}"
  printf '    relabel_configs:\n'
  printf '      - source_labels: [__address__]\n'
  printf '        target_label: __param_target\n'
  printf '      - source_labels: [__param_target]\n'
  printf '        target_label: instance\n'
  printf '      - target_label: __address__\n'
  printf '        replacement: blackbox-exporter:9115\n'
}

render() {
  while IFS= read -r line || [ -n "${line}" ]; do
    case "${line}" in
    '@@KRONOQR_UPTIME_TLS_VERIFIED_JOB@@')
      render_verified_tls_job
      ;;
    *)
      printf '%s\n' "${line}"
      ;;
    esac
  done <"${TEMPLATE_FILE}"
}

render >"${OUTPUT_FILE}.tmp"
chmod 0644 "${OUTPUT_FILE}.tmp"
mv -f "${OUTPUT_FILE}.tmp" "${OUTPUT_FILE}"

err "prometheus.yml renderizado en ${OUTPUT_FILE}"

# Verifica con la MISMA herramienta que despues evalua la configuracion de
# verdad. Si `promtool` no esta en el PATH -este script corre fuera de la
# imagen de Prometheus, por ejemplo desde una comprobacion en un portatil sin
# la imagen descargada- se avisa y se continua: la ausencia de la herramienta
# de verificacion no es un motivo para fallar el renderizado.
if command -v promtool >/dev/null 2>&1; then
  promtool check config "${OUTPUT_FILE}" || die "promtool check config ha rechazado ${OUTPUT_FILE} (ver el motivo arriba). No se arranca Prometheus con una configuracion que la propia herramienta de verificacion no acepta."
else
  err "aviso: promtool no esta en el PATH; no se ha podido verificar ${OUTPUT_FILE} antes de continuar."
fi

# Modo de comprobacion: solo renderizar (y verificar con promtool si esta
# disponible), sin arrancar el binario real. Mismo patron que
# ALERTMANAGER_RENDER_ONLY.
if [ "${PROMETHEUS_RENDER_ONLY:-0}" = "1" ]; then
  exit 0
fi

exec prometheus "$@"
