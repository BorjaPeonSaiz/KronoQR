#!/usr/bin/env bash
#
# KronoQR — comprobacion rapida del borde HTTP, con la imagen sola.
#
# PROPOSITO. Arrancar `kronoqr/nginx:ci` sin Compose, sin base de datos y sin
# aplicacion, y comprobar las cuatro respuestas que definen si el borde sirve
# lo que tiene que servir.
#
# POR QUE EXISTE, SI LA ETAPA ⑧ YA LO CUBRE. Por dos razones que no cubre:
#
#   1. La etapa ⑧ tarda entre 20 y 30 minutos en llegar a esa comprobacion, y
#      este fallo -las tres SPA devolviendo 403 por una directiva `index` que
#      faltaba- se detecta en 30 segundos. Un ciclo de 25 minutos para un error
#      de configuracion estatica es el que hace que la gente deje de esperar la
#      puerta.
#   2. La etapa ⑧ NO se puede ejecutar en el portatil de quien programa. Esto
#      si: `make nginx-smoke`, y sale antes de empujar.
#
# No duplica la etapa ⑧: comprueba menos -no hay aplicacion detras- y por eso
# es barato. Lo que fija es que el borde sirve los tres frontends y respeta el
# candado del portal.
#
# Uso:  nginx-smoke.sh [IMAGEN]     (por defecto kronoqr/nginx:ci)
#
# Codigos de salida (tabla comun de lib/exit-codes.sh):
#   0  las cuatro respuestas son las esperadas
#   1  uso incorrecto
#   2  falta Docker o la imagen; nada comprobado
#   6  el borde no responde lo que debe; se dice que ruta y que devolvio

set -Eeuo pipefail
IFS=$'\n\t'

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIR
# shellcheck source-path=SCRIPTDIR
# shellcheck source=lib/exit-codes.sh disable=SC1091
. "${SCRIPT_DIR}/lib/exit-codes.sh"

readonly IMAGEN="${1:-kronoqr/nginx:ci}"
readonly PUERTO="${KRONOQR_SMOKE_PORT:-18443}"
readonly NOMBRE="kronoqr-nginx-smoke-$$"

# El portal se sirve solo desde su red (RF-ID-08). Se le da una a la que este
# anfitrion NO pertenece, para que el 403 esperado sea el del candado.
readonly CIDR_PORTAL="10.90.0.0/24"

CONTENEDOR=""

al_salir() {
  [ -n "${CONTENEDOR}" ] && docker rm -f "${CONTENEDOR}" >/dev/null 2>&1
  return 0
}

trap al_salir EXIT

fallo=0

comprobar() {
  local ruta="$1" esperado="$2" contiene="${3:-}" codigo cuerpo

  codigo="$(curl -s -k -o /dev/null -w '%{http_code}' --max-time 10 \
    "https://127.0.0.1:${PUERTO}${ruta}" || echo 000)"

  if [ "${codigo}" != "${esperado}" ]; then
    printf '  [FALLA] %-10s devolvio %s, se esperaba %s\n' "${ruta}" "${codigo}" "${esperado}" >&2
    fallo=1
    return 0
  fi

  if [ -n "${contiene}" ]; then
    cuerpo="$(curl -s -k --max-time 10 "https://127.0.0.1:${PUERTO}${ruta}" || true)"
    case "${cuerpo}" in
    *"${contiene}"*) ;;
    *)
      printf '  [FALLA] %-10s da %s pero su cuerpo no contiene "%s"\n' "${ruta}" "${codigo}" "${contiene}" >&2
      fallo=1
      return 0
      ;;
    esac
  fi

  printf '  [ok]    %-10s %s\n' "${ruta}" "${codigo}"
}

main() {
  [ "$#" -le 1 ] || {
    printf 'Uso: nginx-smoke.sh [IMAGEN]\n' >&2
    exit "${KQ_EXIT_USAGE}"
  }

  command -v docker >/dev/null 2>&1 || {
    printf 'Falta Docker. Instalalo o ejecuta esta comprobacion donde lo haya.\n' >&2
    exit "${KQ_EXIT_REQUIREMENTS}"
  }

  docker image inspect "${IMAGEN}" >/dev/null 2>&1 || {
    printf 'No existe la imagen %s. Construyela con: make build-ci-images IMAGES=nginx\n' "${IMAGEN}" >&2
    exit "${KQ_EXIT_REQUIREMENTS}"
  }

  printf 'Arrancando %s sola, sin aplicacion detras.\n' "${IMAGEN}"

  # `--add-host`: el template proxya a `app` y a `reverb`, que aqui no existen.
  # Apuntandolos al propio contenedor, nginx arranca y las rutas de la API dan
  # 502 -que es correcto y no se comprueba-, en vez de morir al resolver.
  CONTENEDOR="$(docker run -d --name "${NOMBRE}" \
    -e KIOSK_VLAN_CIDR=10.92.0.0/24 \
    -e PORTAL_INTERNAL_CIDR="${CIDR_PORTAL}" \
    -e METRICS_ALLOW_CIDR=10.91.0.5/32 \
    -e TLS_ALLOW_SELF_SIGNED=true \
    --add-host app:127.0.0.1 --add-host reverb:127.0.0.1 \
    -p "${PUERTO}:8443" "${IMAGEN}")"

  local _espera
  for _espera in $(seq 1 30); do
    [ "$(docker inspect -f '{{.State.Health.Status}}' "${CONTENEDOR}" 2>/dev/null || echo x)" = "healthy" ] && break
    if [ "$(docker inspect -f '{{.State.Running}}' "${CONTENEDOR}" 2>/dev/null || echo false)" != "true" ]; then
      printf 'El borde no ha arrancado. Su registro:\n' >&2
      docker logs "${CONTENEDOR}" 2>&1 | tail -20 >&2
      exit "${KQ_EXIT_VERIFY_FAILED}"
    fi
    sleep 1
  done

  comprobar /healthz 200
  comprobar /admin/ 200 '<!doctype html'
  comprobar /kiosk/ 200 '<!doctype html'
  # 403 y no 200: este anfitrion queda FUERA de CIDR_PORTAL. Que el portal se
  # sirva a cualquiera seria el fallo (RF-ID-08).
  comprobar /portal/ 403

  if [ "${fallo}" -ne 0 ]; then
    printf '\nEl borde no responde lo que debe. Registro de errores:\n' >&2
    docker logs "${CONTENEDOR}" 2>&1 | grep -i 'error\|forbidden\|emerg' | tail -10 >&2 || true
    exit "${KQ_EXIT_VERIFY_FAILED}"
  fi

  printf 'El borde sirve las tres SPA y respeta el candado del portal.\n'
}

if [ "${BASH_SOURCE[0]}" = "$0" ]; then
  main "$@"
fi
