#!/usr/bin/env bash
#
# KronoQR — comprobacion rapida del borde HTTP, con la imagen sola.
#
# PROPOSITO. Arrancar `kronoqr/nginx:ci` sin Compose, sin base de datos y sin
# aplicacion, y comprobar las cinco respuestas que definen si el borde sirve
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

# Git Bash (MSYS) reescribe `-e TLS_CERT_FILE=/etc/nginx/...` a `C:/...` antes de
# llegar a Docker. En Linux no hace nada: la comprobacion es la misma en la CI y
# en una estacion Windows.
export MSYS_NO_PATHCONV=1

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

# cabecera RUTA NOMBRE PATRON [CABECERA_PETICION]: la respuesta a RUTA trae la
# cabecera NOMBRE y su linea casa con PATRON (grep -E).
cabecera() {
  local ruta="$1" nombre="$2" patron="$3" peticion="${4:-X-Kq-Smoke: 1}" linea

  linea="$(curl -s -k -o /dev/null -D - -H "${peticion}" --max-time 10 \
    "https://127.0.0.1:${PUERTO}${ruta}" | tr -d '\r' | grep -i "^${nombre}:" || true)"

  if ! grep -qEi -- "${patron}" <<<"${linea}"; then
    printf '  [FALLA] %-24s cabecera %s: "%s", se esperaba /%s/\n' "${ruta}" "${nombre}" "${linea}" "${patron}" >&2
    fallo=1
    return 0
  fi

  printf '  [ok]    %-24s %s\n' "${ruta}" "${nombre}"
}

# sin_cabecera RUTA NOMBRE PATRON: lo contrario; falla si la linea casa.
sin_cabecera() {
  local ruta="$1" nombre="$2" patron="$3" linea

  linea="$(curl -s -k -o /dev/null -D - --max-time 10 \
    "https://127.0.0.1:${PUERTO}${ruta}" | tr -d '\r' | grep -i "^${nombre}:" || true)"

  if grep -qEi -- "${patron}" <<<"${linea}"; then
    printf '  [FALLA] %-24s cabecera %s no deberia casar /%s/: "%s"\n' "${ruta}" "${nombre}" "${patron}" "${linea}" >&2
    fallo=1
    return 0
  fi

  printf '  [ok]    %-24s sin %s /%s/\n' "${ruta}" "${nombre}" "${patron}"
}

# sin_cuerpo RUTA PATRON: falla si el cuerpo de la respuesta casa con PATRON.
sin_cuerpo() {
  local ruta="$1" patron="$2" cuerpo

  cuerpo="$(curl -s -k --max-time 10 "https://127.0.0.1:${PUERTO}${ruta}" || true)"

  if grep -qE -- "${patron}" <<<"${cuerpo}"; then
    printf '  [FALLA] %-24s el cuerpo revela la red (/%s/)\n' "${ruta}" "${patron}" >&2
    fallo=1
    return 0
  fi

  printf '  [ok]    %-24s sin IP ni CIDR en el cuerpo\n' "${ruta}"
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

  # Sin Docker ni imagen: la validacion de las tres redes (PP-06).
  bash "${SCRIPT_DIR}/nginx-entrypoint-test.sh" || exit "${KQ_EXIT_VERIFY_FAILED}"

  printf 'Arrancando %s sola, sin aplicacion detras.\n' "${IMAGEN}"

  # `--add-host`: el template proxya a `app` y a `reverb`, que aqui no existen.
  # Apuntandolos al propio contenedor, nginx arranca y las rutas de la API dan
  # 502 -que es correcto y no se comprueba-, en vez de morir al resolver.
  #
  # EL ENTORNO ES EL DE compose.prod.yaml (PIN-10, AUD-1) Y NO MAS: las tres redes
  # obligatorias, y las cuatro opcionales tal como las entrega Compose desde el
  # .env.example. El borde no recibe el .env entero ni ninguna credencial; si un
  # dia necesita otra variable, se anade aqui Y en su bloque de compose.prod.yaml.
  CONTENEDOR="$(docker run -d --name "${NOMBRE}" \
    -e KIOSK_VLAN_CIDR=10.92.0.0/24 \
    -e PORTAL_INTERNAL_CIDR="${CIDR_PORTAL}" \
    -e METRICS_ALLOW_CIDR=10.91.0.5/32 \
    -e TLS_ALLOW_SELF_SIGNED=true \
    -e TLS_CERT_FILE=/etc/nginx/certs/tls.crt \
    -e TLS_KEY_FILE=/etc/nginx/certs/tls.key \
    -e NGINX_CLIENT_MAX_BODY_SIZE=8m \
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

  # 403 y no 502: METRICS_ALLOW_CIDR=10.91.0.5/32 no incluye a este anfitrion,
  # y el candado lo decide el `geo` de la IMAGEN SOLA, antes de intentar
  # hablar con PHP-FPM (que aqui ni existe). Si esto diera 502 en vez de 403,
  # el borde estaria dejando pasar la peticion hacia la aplicacion y
  # confiando en que ELLA la rechace -la segunda guarda de la tarea 3.1-, que
  # es justo el escenario que una plantilla de Nginx mal editada podria dejar
  # abierto sin que nadie lo notara hasta que alguien mirara desde fuera.
  comprobar /metrics 403

  # PIN-01: libsodium compila WebAssembly. Sin 'wasm-unsafe-eval' el fichaje por
  # PIN del quiosco falla siempre en produccion; 'unsafe-eval' sigue prohibido.
  # CORP: la misma cabecera en las tres SPA y en la API.
  local ruta activo
  for ruta in /kiosk/ /admin/ /portal/ /api/v1/ready; do
    cabecera "${ruta}" Content-Security-Policy "script-src 'self' 'wasm-unsafe-eval';"
    sin_cabecera "${ruta}" Content-Security-Policy "'unsafe-eval'|'unsafe-inline'"
    cabecera "${ruta}" Cross-Origin-Resource-Policy "^Cross-Origin-Resource-Policy: same-origin$"
  done

  # PT-P2: los estaticos de una SPA salen comprimidos.
  activo="$(curl -s -k --max-time 10 "https://127.0.0.1:${PUERTO}/kiosk/" | grep -oE 'assets/[^"]+\.js' | head -n 1 || true)"
  if [ -z "${activo}" ]; then
    printf '  [FALLA] /kiosk/ no referencia ningun assets/*.js: no se puede comprobar la compresion\n' >&2
    fallo=1
  else
    cabecera "/kiosk/${activo}" Content-Encoding "^Content-Encoding: gzip$" "Accept-Encoding: gzip"
  fi

  # PP-02 y PP-08: el 403 del portal sigue siendo 403 (RF-ID-08), con pagina
  # bilingue, y la API del portal responde problem+json; ni una ni otra dicen
  # la red permitida ni la IP del visitante.
  comprobar /portal/ 403 "Accede desde la red del hotel o consulta con RRHH"
  comprobar /portal/ 403 "Connect from the hotel network or contact HR"
  comprobar /api/v1/me/workdays 403 "urn:kronoqr:problem:forbidden"
  cabecera /api/v1/me/workdays Content-Type "application/problem\+json"
  sin_cuerpo /portal/ "${CIDR_PORTAL%%/*}|127\.0\.0\.1"
  sin_cuerpo /api/v1/me/workdays "${CIDR_PORTAL%%/*}|127\.0\.0\.1"
  comprobar /portal 301
  cabecera /portal Location "^Location: /portal/$"

  # T1: Horizon no llega a PHP-FPM (aqui daria 502, no 404).
  comprobar /horizon 404
  comprobar /horizon/dashboard 404

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
