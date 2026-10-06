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
CONTENEDOR_PROXY=""
CONTENEDOR_ADMIN=""

al_salir() {
  [ -n "${CONTENEDOR}" ] && docker rm -f "${CONTENEDOR}" >/dev/null 2>&1
  [ -n "${CONTENEDOR_PROXY}" ] && docker rm -f "${CONTENEDOR_PROXY}" >/dev/null 2>&1
  [ -n "${CONTENEDOR_ADMIN}" ] && docker rm -f "${CONTENEDOR_ADMIN}" >/dev/null 2>&1
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

# problema RUTA CODIGO TIPO [ARGS_CURL...]: la respuesta a RUTA es CODIGO, de tipo
# application/problem+json, con el cuerpo estatico del borde y las cabeceras de
# seguridad (F4a-2, CH4). Sin rutas internas en el cuerpo.
problema() {
  local ruta="$1" codigo="$2" tipo="$3" respuesta cabeceras cuerpo
  shift 3

  # Una sola peticion: el cuerpo que llega por stdin (--data-binary @-) solo se lee una vez.
  respuesta="$(curl -s -k -i --max-time 20 "$@" "https://127.0.0.1:${PUERTO}${ruta}" | tr -d '\r' || true)"
  cabeceras="${respuesta%%$'\n\n'*}"
  cuerpo="${respuesta#*$'\n\n'}"

  if ! grep -qE "^HTTP/[0-9.]+ ${codigo}" <<<"${cabeceras}" ||
    ! grep -qiE '^content-type: application/problem\+json' <<<"${cabeceras}" ||
    ! grep -qiE '^x-content-type-options: nosniff' <<<"${cabeceras}" ||
    ! grep -qiE '^content-security-policy:' <<<"${cabeceras}" ||
    ! grep -qF "\"type\":\"urn:kronoqr:problem:${tipo}\"" <<<"${cuerpo}" ||
    grep -qE '/var/www|php|nginx/[0-9]|127\.0\.0\.1|app:9000' <<<"${cuerpo}"; then
    printf '  [FALLA] %-24s se esperaba %s problem+json (%s). Recibi:\n%s\n%s\n' \
      "${ruta}" "${codigo}" "${tipo}" "${cabeceras}" "${cuerpo}" >&2
    fallo=1
    return 0
  fi

  printf '  [ok]    %-24s %s problem+json\n' "${ruta}" "${codigo}"
}

# con_xff PUERTO RUTA XFF ESPERADO DESCRIPCION: el codigo de RUTA cuando la
# peticion declara un X-Forwarded-For (PP-03).
con_xff() {
  local puerto="$1" ruta="$2" xff="$3" esperado="$4" descripcion="$5" codigo

  codigo="$(curl -s -k -o /dev/null -w '%{http_code}' -H "X-Forwarded-For: ${xff}" --max-time 10 \
    "https://127.0.0.1:${puerto}${ruta}" || echo 000)"

  if [ "${codigo}" != "${esperado}" ]; then
    printf '  [FALLA] %s: %s con X-Forwarded-For %s devolvio %s, se esperaba %s\n' "${descripcion}" "${ruta}" "${xff}" "${codigo}" "${esperado}" >&2
    fallo=1
    return 0
  fi

  printf '  [ok]    %-24s %s (%s)\n' "${ruta}" "${codigo}" "${descripcion}"
}

# PP-03. Segundo borde, este CON un proxy de confianza: el gateway de su red de
# Docker, que es de donde le llegan las peticiones de este anfitrion: la
# X-Forwarded-For de ese proxy SI cuenta. Una IP dentro de PORTAL_INTERNAL_CIDR
# abre el portal (200) y una de fuera no (403).
proxy_de_confianza() {
  local puerto="$((PUERTO + 1))" gateway contenedor _espera

  # El gateway es el de la red del primer borde (la de Docker por defecto).
  gateway="$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.Gateway}}{{end}}' "${CONTENEDOR}" 2>/dev/null || true)"
  if ! [[ "${gateway}" =~ ^[0-9.]+$ ]]; then
    printf '  [aviso] no se ha podido averiguar el gateway de Docker: se omite la comprobacion del proxy de confianza\n' >&2
    return 0
  fi

  contenedor="$(docker run -d --name "${NOMBRE}-proxy" \
    -e KIOSK_VLAN_CIDR=10.92.0.0/24 \
    -e PORTAL_INTERNAL_CIDR="${CIDR_PORTAL}" \
    -e METRICS_ALLOW_CIDR=10.91.0.5/32 \
    -e TLS_ALLOW_SELF_SIGNED=true \
    -e TLS_CERT_FILE=/etc/nginx/certs/tls.crt \
    -e TLS_KEY_FILE=/etc/nginx/certs/tls.key \
    -e NGINX_CLIENT_MAX_BODY_SIZE=8m \
    -e TRUSTED_PROXY_CIDR="${gateway}/32" \
    --add-host app:127.0.0.1 --add-host reverb:127.0.0.1 \
    -p "${puerto}:8443" "${IMAGEN}")"
  CONTENEDOR_PROXY="${contenedor}"

  for _espera in $(seq 1 30); do
    [ "$(docker inspect -f '{{.State.Health.Status}}' "${contenedor}" 2>/dev/null || echo x)" = "healthy" ] && break
    if [ "$(docker inspect -f '{{.State.Running}}' "${contenedor}" 2>/dev/null || echo false)" != "true" ]; then
      printf '  [FALLA] el borde con TRUSTED_PROXY_CIDR no ha arrancado. Su registro:\n' >&2
      docker logs "${contenedor}" 2>&1 | tail -20 >&2
      fallo=1
      return 0
    fi
    sleep 1
  done

  con_xff "${puerto}" /portal/ 10.90.0.9 200 "proxy de confianza: origen declarado dentro del portal"
  con_xff "${puerto}" /portal/ 203.0.113.9 403 "proxy de confianza: origen declarado fuera del portal"
}

# PP-10. Tercer borde, con ADMIN_INTERNAL_CIDR definida a un rango que no incluye
# a este anfitrion: /admin/ y /api/v1/auth/* dan 403 (problem+json en la API), y
# lo que no es del panel —el portal con su propio rango, /healthz— no cambia. El
# borde principal, con la variable vacia, ya ha comprobado arriba que /admin/
# sigue abierto (200).
panel_cerrado() {
  local puerto="$((PUERTO + 2))" contenedor _espera

  contenedor="$(docker run -d --name "${NOMBRE}-admin" \
    -e KIOSK_VLAN_CIDR=10.92.0.0/24 \
    -e PORTAL_INTERNAL_CIDR=0.0.0.0/0 \
    -e METRICS_ALLOW_CIDR=10.91.0.5/32 \
    -e ADMIN_INTERNAL_CIDR="${CIDR_PORTAL}" \
    -e TLS_ALLOW_SELF_SIGNED=true \
    -e TLS_CERT_FILE=/etc/nginx/certs/tls.crt \
    -e TLS_KEY_FILE=/etc/nginx/certs/tls.key \
    -e NGINX_CLIENT_MAX_BODY_SIZE=8m \
    --add-host app:127.0.0.1 --add-host reverb:127.0.0.1 \
    -p "${puerto}:8443" "${IMAGEN}")"
  CONTENEDOR_ADMIN="${contenedor}"

  for _espera in $(seq 1 30); do
    [ "$(docker inspect -f '{{.State.Health.Status}}' "${contenedor}" 2>/dev/null || echo x)" = "healthy" ] && break
    if [ "$(docker inspect -f '{{.State.Running}}' "${contenedor}" 2>/dev/null || echo false)" != "true" ]; then
      printf '  [FALLA] el borde con ADMIN_INTERNAL_CIDR no ha arrancado. Su registro:\n' >&2
      docker logs "${contenedor}" 2>&1 | tail -20 >&2
      fallo=1
      return 0
    fi
    sleep 1
  done

  con_xff "${puerto}" /admin/ 10.90.0.9 403 "panel cerrado: fuera del rango"
  con_xff "${puerto}" /api/v1/auth/login 10.90.0.9 403 "autenticacion cerrada: fuera del rango"
  con_xff "${puerto}" /portal/ 10.90.0.9 200 "con el panel cerrado, el portal sigue su propio rango"
  con_xff "${puerto}" /healthz 10.90.0.9 200 "con el panel cerrado, /healthz responde"
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

  # PP-03: sin TRUSTED_PROXY_CIDR, la X-Forwarded-For de un visitante no cuenta.
  # Si contara, cualquiera abriria el portal declarandose dentro de su red.
  con_xff "${PUERTO}" /portal/ 10.90.0.9 403 "sin proxy de confianza la cabecera se ignora"
  comprobar /portal 301
  cabecera /portal Location "^Location: /portal/$"

  # T1: Horizon no llega a PHP-FPM (aqui daria 502, no 404).
  comprobar /horizon 404
  comprobar /horizon/dashboard 404

  # F4a-2, CH4: los errores que genera nginx en la API salen como problem+json,
  # no como HTML. Sin aplicacion detras no hay PHP-FPM: 502. Un cuerpo de 9 MiB
  # supera NGINX_CLIENT_MAX_BODY_SIZE=8m: 413. Una rafaga sobre auth (5 r/m,
  # rafaga 5) acaba en 429. Las SPA siguen siendo HTML (arriba, y al final).
  local json='Content-Type: application/json'
  problema /api/v1/ready 502 bad-gateway
  problema /api/v1/scan 502 bad-gateway -X POST -H "${json}" -d '{}'
  problema /api/v1/auth/login 502 bad-gateway -X POST -H "${json}" -d '{}'
  problema /api/v1/scan 413 payload-too-large -X POST -H "${json}" \
    --data-binary @- < <(head -c 9437184 /dev/zero | tr '\0' 'a')
  # En paralelo: cada peticion que llega a la aplicacion espera el resolvedor.
  local _rafaga
  for _rafaga in $(seq 1 12); do
    curl -s -k -o /dev/null --max-time 20 -X POST \
      "https://127.0.0.1:${PUERTO}/api/v1/auth/login" &
  done
  wait
  problema /api/v1/auth/login 429 too-many-requests -X POST
  comprobar /admin/ 200 '<!doctype html'

  proxy_de_confianza
  panel_cerrado

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
