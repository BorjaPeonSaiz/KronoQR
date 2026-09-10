#!/bin/sh
#
# KronoQR — renderiza alertmanager.yml desde su plantilla (doc 02 §8.4, tarea 3.2,
# decision 5; endurecido en la decision 17 tras la revision de seguridad del
# 10-09-2026).
#
# POR QUE ESTE SCRIPT Y NO UN `sed` PARA TODO. Alertmanager no expande
# variables de entorno en su configuracion, y los seis destinatarios de correo
# y webhook (ALERT_EMAIL_*, ALERT_WEBHOOK_*) nacen VACIOS en .env.example
# (marca [CLIENTE]): un destino vacio no genera esa entrega, porque
# Alertmanager RECHAZA un `to:` o un `url:` vacios al arrancar. Una
# sustitucion de texto no puede decidir si una clave del YAML existe o no
# segun si la variable tiene contenido; un script si.
#
# POSIX `sh`, no bash: este script se ejecuta con el `/bin/sh` (BusyBox ash) de
# la propia imagen `prom/alertmanager`, que no lleva bash. Nada de arrays ni
# `[[ ]]`. Comprobado contra la imagen fijada en los dos compose: este ash SI
# admite `set -o pipefail`, `IFS=$'\n\t'` y `local` (los tres son extensiones
# de BusyBox, no POSIX puro), asi que el script lleva el mismo preambulo que el
# resto de scripts del repositorio (doc 02 §3.5) en vez de una excepcion.
#
# COMO SE RENDERIZA. El fichero se lee LINEA A LINEA y cada marca `@@TOKEN@@`
# se sustituye con expansion de parametros de POSIX (`${var%%patron*}` /
# `${var#*patron}`), que trata el valor como texto literal, nunca como patron
# de sed/awk: un correo o una URL de webhook pueden traer `/`, `&` o `|`, que
# tendrian significado especial en una sustitucion de sed y obligarian a
# escapar cada valor antes de insertarlo. Las marcas de receptor
# (`@@RECEIVER_*@@`) ocupan una linea entera y se expanden a cero, una o dos
# claves YAML segun haya correo, webhook o ninguno de los dos configurados.
# `sed` SI se usa, pero solo dentro de `yaml_squote()` (mas abajo) para una
# unica sustitucion literal (`'` por `''`) sin caracteres especiales de por
# medio: es segura independientemente de lo que traiga el valor.
#
# BLOQUEANTE 1 DE LA REVISION (decision 17b), CORREGIDO: el renderizador NO
# escapaba la comilla simple ni rechazaba saltos de linea. Un correo legal en
# RFC 5322 como `o'brien@hotel.example` producia YAML invalido -Alertmanager
# moria en bucle sin decir por que-, y un valor con un salto de linea podia
# anadir una clave nueva a la configuracion (un receptor o un webhook que
# nadie autorizo). `yaml_squote()` duplica cada comilla simple antes de
# insertar CUALQUIER valor en un escalar entrecomillado, y `reject_newline()`
# rechaza con `die` -nombrando la variable- cualquiera de las quince que traiga
# `\n` o `\r`, antes de tocar la plantilla.
#
# BLOQUEANTE 3 DE LA REVISION (decision 17c): tras renderizar, y ANTES de
# `exec alertmanager`, este script ejecuta `amtool check-config` sobre su
# propia salida. `amtool` vive en la misma imagen que `alertmanager`
# (`/bin/amtool`), asi que dentro del contenedor real esta comprobacion
# siempre se ejecuta. Cuando este script corre FUERA de esa imagen -el host de
# pruebas de la suite de arquitectura, o un portatil sin la imagen descargada-
# `amtool` puede no estar en el PATH: se avisa y se sigue, nunca se falla por
# la ausencia de la propia herramienta de verificacion.
#
# EJECUCION. Es el ENTRYPOINT del contenedor (compose.dev.yaml y
# compose.prod.yaml): renderiza y luego cede el proceso al binario real con
# `exec alertmanager "$@"`, de modo que Alertmanager arranca con el PID 1 y
# recibe las señales de `docker stop` como siempre. Con
# ALERTMANAGER_RENDER_ONLY=1 (usado por `make observability-check` y por la
# prueba de arquitectura que ejercita el script con distintos entornos) el
# script SOLO renderiza (y verifica con `amtool` si esta disponible) y sale
# con 0, sin arrancar nada: es lo que permite comprobar el YAML resultante sin
# levantar el contenedor.
#
# Codigos de salida: 0 si renderizo (y arranco Alertmanager, salvo modo solo
# renderizado); 1 si la plantilla no existe, una variable de entorno no tiene
# la forma que se le exige, o `amtool check-config` rechaza el resultado. El
# mensaje de error dice siempre que corregir.

# ShellCheck analiza `#!/bin/sh` en modo POSIX estricto y marca `pipefail` y el
# entrecomillado ANSI-C como extensiones "no definidas en POSIX sh" (SC3040,
# SC3003). Las dos estan comprobadas contra el `/bin/sh` real de la imagen
# fijada en los dos compose (BusyBox ash 1.36.1): funcionan, y esta linea es la
# unica diferencia entre este script y el preambulo que exige doc 02 §3.5 para
# el resto de scripts del repositorio.
# shellcheck disable=SC3040,SC3003
set -euo pipefail
IFS=$'\n\t'

TEMPLATE_FILE="${ALERTMANAGER_TEMPLATE_FILE:-/etc/alertmanager/alertmanager.yml.template}"
STORAGE_PATH="${ALERTMANAGER_STORAGE_PATH:-/alertmanager}"
OUTPUT_FILE="${ALERTMANAGER_OUTPUT_FILE:-${STORAGE_PATH}/alertmanager.yml}"
SECRET_DIR="${ALERTMANAGER_SECRET_DIR:-${STORAGE_PATH}/secrets}"
SECRET_FILE="${SECRET_DIR}/smtp_password"

err() {
  printf 'render-config.sh: %s\n' "$*" >&2
}

die() {
  err "$*"
  exit 1
}

[ -f "${TEMPLATE_FILE}" ] || die "no existe la plantilla '${TEMPLATE_FILE}'. Comprueba el volumen que monta infra/observability/alertmanager en /etc/alertmanager."

#-------------------------------------------------------------------------------
# Configuracion. Los mismos nombres y los mismos valores por defecto que
# .env.example, para que "no he tocado el .env" signifique lo mismo aqui que en
# Laravel.
#-------------------------------------------------------------------------------
MAIL_HOST="${MAIL_HOST:-mailpit}"
MAIL_PORT="${MAIL_PORT:-1025}"
MAIL_USERNAME="${MAIL_USERNAME:-}"
MAIL_PASSWORD="${MAIL_PASSWORD:-}"
MAIL_FROM_ADDRESS="${MAIL_FROM_ADDRESS:-no-reply@kronoqr.local}"
MAIL_SCHEME="${MAIL_SCHEME:-}"

ALERT_EMAIL_IT="${ALERT_EMAIL_IT:-}"
ALERT_EMAIL_RRHH="${ALERT_EMAIL_RRHH:-}"
ALERT_EMAIL_SEGURIDAD="${ALERT_EMAIL_SEGURIDAD:-}"
ALERT_WEBHOOK_IT="${ALERT_WEBHOOK_IT:-}"
ALERT_WEBHOOK_RRHH="${ALERT_WEBHOOK_RRHH:-}"
ALERT_WEBHOOK_SEGURIDAD="${ALERT_WEBHOOK_SEGURIDAD:-}"

ALERT_MAINTENANCE_WEEKDAY="${ALERT_MAINTENANCE_WEEKDAY:-sunday}"
ALERT_MAINTENANCE_START="${ALERT_MAINTENANCE_START:-02:00}"
ALERT_MAINTENANCE_END="${ALERT_MAINTENANCE_END:-04:00}"

#-------------------------------------------------------------------------------
# Decision 17(b): ninguna de las quince variables puede traer un salto de
# linea ni un retorno de carro. Sin esto, un valor asi rompe el YAML
# renderizado -o, peor, cierra una clave e inyecta otra que nadie autorizo-.
# `NL`/`CR` son caracteres LITERALES (no `\n` de texto): la asignacion de `NL`
# ocupa dos lineas a proposito, es la unica forma POSIX de meter un salto de
# linea de verdad en una variable sin que la sustitucion de comandos se lo
# coma.
#-------------------------------------------------------------------------------
NL='
'
CR="$(printf '\r')"

reject_newline() {
  # $1 = nombre de la variable (para el mensaje) · $2 = su valor
  case "$2" in
  *"${NL}"* | *"${CR}"*)
    die "${1} contiene un salto de linea o un retorno de carro. No se admite: rompe el YAML renderizado y podria usarse para inyectar una clave que nadie autorizo. Revisa el .env: seguramente una variable quedo mal citada."
    ;;
  esac
}

# Quince llamadas explicitas y no un bucle sobre "NOMBRE:valor": una URL de
# webhook lleva un `:` de sobra (`https://...`) y partir por el primer `:`
# leeria el nombre de variable a medias.
reject_newline "MAIL_HOST" "${MAIL_HOST}"
reject_newline "MAIL_PORT" "${MAIL_PORT}"
reject_newline "MAIL_USERNAME" "${MAIL_USERNAME}"
reject_newline "MAIL_PASSWORD" "${MAIL_PASSWORD}"
reject_newline "MAIL_FROM_ADDRESS" "${MAIL_FROM_ADDRESS}"
reject_newline "MAIL_SCHEME" "${MAIL_SCHEME}"
reject_newline "ALERT_EMAIL_IT" "${ALERT_EMAIL_IT}"
reject_newline "ALERT_EMAIL_RRHH" "${ALERT_EMAIL_RRHH}"
reject_newline "ALERT_EMAIL_SEGURIDAD" "${ALERT_EMAIL_SEGURIDAD}"
reject_newline "ALERT_WEBHOOK_IT" "${ALERT_WEBHOOK_IT}"
reject_newline "ALERT_WEBHOOK_RRHH" "${ALERT_WEBHOOK_RRHH}"
reject_newline "ALERT_WEBHOOK_SEGURIDAD" "${ALERT_WEBHOOK_SEGURIDAD}"
reject_newline "ALERT_MAINTENANCE_WEEKDAY" "${ALERT_MAINTENANCE_WEEKDAY}"
reject_newline "ALERT_MAINTENANCE_START" "${ALERT_MAINTENANCE_START}"
reject_newline "ALERT_MAINTENANCE_END" "${ALERT_MAINTENANCE_END}"

case "${ALERT_MAINTENANCE_WEEKDAY}" in
monday | tuesday | wednesday | thursday | friday | saturday | sunday) ;;
*) die "ALERT_MAINTENANCE_WEEKDAY='${ALERT_MAINTENANCE_WEEKDAY}' no es un dia valido (monday..sunday, en ingles y en minusculas). Corrigelo en el .env." ;;
esac
# [01][0-9]|2[0-3] y no [0-2][0-9]: la version anterior admitia "29:59", que
# no es una hora (bloqueante corregido en la decision 17b).
case "${ALERT_MAINTENANCE_START}" in
[01][0-9]:[0-5][0-9] | 2[0-3]:[0-5][0-9]) ;;
*) die "ALERT_MAINTENANCE_START='${ALERT_MAINTENANCE_START}' no tiene forma HH:MM con HH entre 00 y 23. Corrigelo en el .env." ;;
esac
case "${ALERT_MAINTENANCE_END}" in
[01][0-9]:[0-5][0-9] | 2[0-3]:[0-5][0-9]) ;;
*) die "ALERT_MAINTENANCE_END='${ALERT_MAINTENANCE_END}' no tiene forma HH:MM con HH entre 00 y 23. Corrigelo en el .env." ;;
esac

# STARTTLS/TLS del transporte de correo. Alertmanager solo puede EXIGIR TLS o
# no exigirlo (`smtp_require_tls`); no distingue STARTTLS oportunista de TLS
# implicito como si lo hace MAIL_SCHEME de Laravel (doc .env.example). Mismo
# criterio que el relevo de desarrollo: vacio (Mailpit, sin TLS) no lo exige;
# cualquier otro valor ('smtp' o 'smtps', un relevo real) si lo exige.
case "${MAIL_SCHEME}" in
"") SMTP_REQUIRE_TLS="false" ;;
*) SMTP_REQUIRE_TLS="true" ;;
esac

#-------------------------------------------------------------------------------
# Decision 17(b): duplica cada comilla simple (el escape de YAML dentro de un
# escalar entre comillas simples). Segura frente a `/`, `&`, `|` o cualquier
# otro caracter del valor: al no ser delimitador de sed ni aparecer en el
# patron o el reemplazo, no tiene significado especial aqui.
#-------------------------------------------------------------------------------
yaml_squote() {
  printf '%s' "$1" | sed "s/'/''/g"
}

#-------------------------------------------------------------------------------
# Secreto SMTP: fichero 0600, nunca en el YAML ni en la salida de este script
# (doc 02 §7.7). Se escribe siempre, incluso vacio: si MAIL_USERNAME esta vacio
# Alertmanager no intenta autenticar y el fichero simplemente no se usa.
#-------------------------------------------------------------------------------
umask 0077
mkdir -p "${SECRET_DIR}"
printf '%s' "${MAIL_PASSWORD}" >"${SECRET_FILE}.tmp"
chmod 0600 "${SECRET_FILE}.tmp"
mv -f "${SECRET_FILE}.tmp" "${SECRET_FILE}"

mkdir -p "$(dirname -- "${OUTPUT_FILE}")"

#-------------------------------------------------------------------------------
# Bloque email/webhook de un receptor. Nada si el destino esta vacio: un
# receptor sin ninguna integracion es valido en Alertmanager y sencillamente no
# entrega nada (mismo comportamiento que el receptor `silencio`).
#
# DECISION 17(g): el webhook es tan secreto como la contraseña SMTP -quien lo
# conoce puede hacerse pasar por el sistema de tickets del cliente-, y hasta
# ahora se escribia en el YAML 0644 mientras la contraseña iba a un fichero
# 0600. `url_file` (Alertmanager v0.28) recibe el mismo trato que
# `smtp_auth_password_file`: un fichero 0600 por destinatario.
#-------------------------------------------------------------------------------
receiver_block() {
  # $1 = correo (o vacio) · $2 = webhook (o vacio) · $3 = slug del destinatario
  # (it|rrhh|seguridad, para el nombre del fichero del webhook)
  if [ -n "$1" ]; then
    printf '    email_configs:\n'
    printf "      - to: '%s'\n" "$(yaml_squote "$1")"
    printf '        send_resolved: true\n'
  fi
  if [ -n "$2" ]; then
    webhook_file="${SECRET_DIR}/webhook_${3}"
    printf '%s' "$2" >"${webhook_file}.tmp"
    chmod 0600 "${webhook_file}.tmp"
    mv -f "${webhook_file}.tmp" "${webhook_file}"
    printf '    webhook_configs:\n'
    printf "      - url_file: '%s'\n" "$(yaml_squote "${webhook_file}")"
    printf '        send_resolved: true\n'
  fi
}

#-------------------------------------------------------------------------------
# Renderizado linea a linea. Ver la cabecera: sin sed ni awk para la
# SUSTITUCION de marcas, solo para `yaml_squote()`.
#-------------------------------------------------------------------------------
render() {
  while IFS= read -r line || [ -n "${line}" ]; do
    case "${line}" in
    *'@@SMTP_SMARTHOST@@'*)
      value="$(yaml_squote "${MAIL_HOST}:${MAIL_PORT}")"
      printf '%s\n' "${line%%@@SMTP_SMARTHOST@@*}${value}${line#*@@SMTP_SMARTHOST@@}"
      ;;
    *'@@SMTP_FROM@@'*)
      value="$(yaml_squote "${MAIL_FROM_ADDRESS}")"
      printf '%s\n' "${line%%@@SMTP_FROM@@*}${value}${line#*@@SMTP_FROM@@}"
      ;;
    *'@@SMTP_AUTH_USERNAME@@'*)
      value="$(yaml_squote "${MAIL_USERNAME}")"
      printf '%s\n' "${line%%@@SMTP_AUTH_USERNAME@@*}${value}${line#*@@SMTP_AUTH_USERNAME@@}"
      ;;
    *'@@SMTP_AUTH_PASSWORD_FILE@@'*)
      value="$(yaml_squote "${SECRET_FILE}")"
      printf '%s\n' "${line%%@@SMTP_AUTH_PASSWORD_FILE@@*}${value}${line#*@@SMTP_AUTH_PASSWORD_FILE@@}"
      ;;
    *'@@SMTP_REQUIRE_TLS@@'*)
      printf '%s\n' "${line%%@@SMTP_REQUIRE_TLS@@*}${SMTP_REQUIRE_TLS}${line#*@@SMTP_REQUIRE_TLS@@}"
      ;;
    *'@@ALERT_MAINTENANCE_WEEKDAY@@'*)
      printf '%s\n' "${line%%@@ALERT_MAINTENANCE_WEEKDAY@@*}${ALERT_MAINTENANCE_WEEKDAY}${line#*@@ALERT_MAINTENANCE_WEEKDAY@@}"
      ;;
    *'@@ALERT_MAINTENANCE_START@@'*)
      printf '%s\n' "${line%%@@ALERT_MAINTENANCE_START@@*}${ALERT_MAINTENANCE_START}${line#*@@ALERT_MAINTENANCE_START@@}"
      ;;
    *'@@ALERT_MAINTENANCE_END@@'*)
      printf '%s\n' "${line%%@@ALERT_MAINTENANCE_END@@*}${ALERT_MAINTENANCE_END}${line#*@@ALERT_MAINTENANCE_END@@}"
      ;;
    '@@RECEIVER_IT_CLIENTE@@')
      receiver_block "${ALERT_EMAIL_IT}" "${ALERT_WEBHOOK_IT}" it
      ;;
    '@@RECEIVER_RRHH@@')
      receiver_block "${ALERT_EMAIL_RRHH}" "${ALERT_WEBHOOK_RRHH}" rrhh
      ;;
    '@@RECEIVER_SEGURIDAD@@')
      receiver_block "${ALERT_EMAIL_SEGURIDAD}" "${ALERT_WEBHOOK_SEGURIDAD}" seguridad
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

err "alertmanager.yml renderizado en ${OUTPUT_FILE}"

# Decision 17(c): verifica con la MISMA herramienta que despues evalua la
# configuracion de verdad. Si `amtool` no esta en el PATH -este script corre
# fuera de la imagen de Alertmanager, por ejemplo desde la suite de pruebas-
# se avisa y se continua: la ausencia de la herramienta de verificacion no es
# un motivo para fallar el renderizado.
if command -v amtool >/dev/null 2>&1; then
  amtool check-config "${OUTPUT_FILE}" || die "amtool check-config ha rechazado ${OUTPUT_FILE} (ver el motivo arriba). No se arranca Alertmanager con una configuracion que la propia herramienta de verificacion no acepta."
else
  err "aviso: amtool no esta en el PATH; no se ha podido verificar ${OUTPUT_FILE} antes de continuar."
fi

# Modo de comprobacion: solo renderizar (y verificar con amtool si esta
# disponible), sin arrancar el binario. Lo usan `make observability-check` y
# la prueba de arquitectura que ejercita el script con distintos entornos
# (ninguno de los dos quiere un Alertmanager real escuchando en un puerto).
if [ "${ALERTMANAGER_RENDER_ONLY:-0}" = "1" ]; then
  exit 0
fi

exec alertmanager "$@"
