#!/usr/bin/env bash
# alerts-drill.sh — simulacro de alertas de KronoQR (V4-DV-3, `make alerts-drill`).
#
# Dispara A PROPOSITO, una a una, las alertas que nunca han saltado de verdad y
# comprueba que el Alertmanager las recibe. Una alerta que nunca ha saltado es
# una alerta de la que no se sabe si salta: el simulacro lo averigua en una pila
# efimera, no el dia del incidente.
#
# QUE HACE
#   1. Levanta una pila APARTE (proyecto `kq-drill`, sin puertos publicados, sin
#      volumenes): node-exporter con su colector textfile, el Prometheus de la
#      imagen de produccion con las reglas REALES, y el Alertmanager REAL con
#      sus rutas e inhibiciones (infra/drill/compose.drill.yaml).
#   2. Publica un estado sano (baseline.prom) y comprueba que ninguna de las
#      alertas del simulacro esta activa.
#   3. Para cada alerta, aplica su falla (faults.tsv) —cambiar una serie o
#      retirarla— y espera a que aparezca en la API de Alertmanager; despues
#      restaura el estado sano.
#   4. Lista las alertas que NO se pueden fabricar y por que (not-fabricable.tsv):
#      quedan cubiertas por `promtool test rules`.
#   5. Destruye la pila (salvo KQ_DRILL_KEEP=1).
#
# QUE COMPRUEBA Y QUE NO
#   Comprueba el camino serie -> regla -> Prometheus -> Alertmanager -> ruta. NO
#   comprueba que el codigo del producto produzca esas series: eso es de las
#   pruebas PHP. La unica desviacion de las reglas es `for:` a 0 s e `interval:`
#   a 5 s (esperar 24 h no es repetible); la persistencia la prueba promtool.
#
# QUE NO TOCA, NUNCA
#   Ni la pila `kronoqr` ni una instalacion de cliente. Todas las ordenes de
#   Docker llevan `-p kq-drill` y el fichero de esta carpeta. Y se niega a
#   arrancar si el entorno no es de desarrollo (guarda_de_entorno, abajo).
#
# USO
#   APP_ENV=local infra/drill/alerts-drill.sh [--only ALERTA] [--list] [--help]
#   make alerts-drill [ALERT=NombreDeLaAlerta]
#
# VARIABLES
#   KQ_DRILL_TIMEOUT  segundos que se espera a cada alerta (90 por omision)
#   KQ_DRILL_KEEP=1   no destruir la pila al terminar (para inspeccionarla)
#
# SALIDA: 0 si todas las alertas del simulacro llegaron a Alertmanager; 1 si
# alguna no; 2 si el uso o el entorno son incorrectos.

set -euo pipefail
IFS=$'\n\t'

DRILL_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd -- "${DRILL_DIR}/../.." && pwd)"
readonly DRILL_DIR REPO_ROOT
readonly PROJECT="kq-drill"
readonly FAULTS="${DRILL_DIR}/faults.tsv"
readonly NOT_FABRICABLE="${DRILL_DIR}/not-fabricable.tsv"
readonly BASELINE="${DRILL_DIR}/baseline.prom"
readonly TIMEOUT="${KQ_DRILL_TIMEOUT:-90}"
# Las que se comprueban "tal como llegan": la entrega fallida no tiene estado
# sano al que volver, porque depende de que haya notificaciones que fallen.
readonly NO_PRECHECK="EntregaDeAlertasFallando"

err() { printf 'alerts-drill: %s\n' "$*" >&2; }
info() { printf '%s\n' "$*"; }
die() {
  local code="$1"
  shift
  err "$@"
  exit "${code}"
}

usage() {
  sed -n '2,/^$/p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
}

#-------------------------------------------------------------------------------
# GUARDA DE ENTORNO (SC7-03). Misma idea que DevelopmentSeeder::refuseInProduction:
# lista de PERMITIDOS, no de prohibidos, y falla cerrada. Un APP_ENV inesperado
# (`production`, `staging`, uno mal escrito) o desconocido se niega.
#
# Segunda cerradura: este script NO viaja en el paquete de entrega (package.sh
# copia una lista cerrada de scripts y AlertsDrillTest lo vigila), asi que en
# una instalacion de cliente ni siquiera existe. Si alguien lo copia a mano, la
# estructura del directorio lo delata: una instalacion tiene `docker-compose.yml`
# y `observability.sha256` en la raiz; el repositorio, `infra/compose.prod.yaml`.
#-------------------------------------------------------------------------------
guarda_de_entorno() {
  local entorno="${APP_ENV:-}"
  if [ -z "${entorno}" ] && [ -f "${REPO_ROOT}/.env" ]; then
    entorno="$(grep -E '^APP_ENV=' "${REPO_ROOT}/.env" | tail -n 1 | cut -d= -f2- | sed -E 's/[[:space:]]*#.*$//' | tr -d "\"' \r" || true)"
  fi
  case "${entorno}" in
  local | testing) ;;
  *)
    die 2 "el simulacro de alertas solo corre en desarrollo o en la CI (APP_ENV=local o testing) y APP_ENV='${entorno}'. Nunca contra una instalacion de cliente. Si estas en tu maquina de desarrollo, ejecuta: APP_ENV=local make alerts-drill"
    ;;
  esac
  [ -f "${REPO_ROOT}/infra/compose.prod.yaml" ] ||
    die 2 "no encuentro infra/compose.prod.yaml junto a este script: esto no es el repositorio de KronoQR y el simulacro no se ejecuta."
  if [ -e "${REPO_ROOT}/docker-compose.yml" ] || [ -e "${REPO_ROOT}/observability.sha256" ]; then
    die 2 "esto tiene la forma de una instalacion de cliente (docker-compose.yml u observability.sha256 en la raiz). El simulacro no se ejecuta ahi: borra este script de la instalacion."
  fi
}

compose() {
  docker compose -p "${PROJECT}" -f "${DRILL_DIR}/compose.drill.yaml" "$@"
}

# La imagen de cada pieza, de compose.prod.yaml: una version escrita a mano aqui
# solo podria desincronizarse de la que de verdad se despliega.
imagen() {
  local nombre="$1" tag
  tag="$(grep -oE "prom/${nombre}:v[0-9.]+" "${REPO_ROOT}/infra/compose.prod.yaml" | head -n 1 || true)"
  [ -n "${tag}" ] || die 2 "no encuentro la imagen prom/${nombre} en infra/compose.prod.yaml."
  printf '%s' "${tag}"
}

#-------------------------------------------------------------------------------
# Lectura de las recetas.
#-------------------------------------------------------------------------------
alertas_del_simulacro() {
  grep -vE '^[[:space:]]*(#|$)' "${FAULTS}" | cut -f1 | awk '!visto[$0]++'
}

#-------------------------------------------------------------------------------
# Renderiza el .prom: baseline + las fallas de UNA alerta (o ninguna). Las marcas
# @NOW@, @NOW-N@ y @NOW+N@ se resuelven en cada publicacion para que las
# comparaciones con time() no caduquen.
#-------------------------------------------------------------------------------
renderizar() {
  local alerta="${1:-}" seleccion
  seleccion="$(mktemp)"
  if [ -n "${alerta}" ]; then
    grep -vE '^[[:space:]]*(#|$)' "${FAULTS}" | awk -F'\t' -v a="${alerta}" '$1 == a { print $2 "\t" $3 }' >"${seleccion}"
  fi
  awk -F'\t' -v now="$(date +%s)" '
    function clave(linea, k) { k = linea; sub(/ [^ ]+$/, "", k); return k }
    function nombre(k) { sub(/\{.*/, "", k); return k }
    function marcas(linea, ini, tok, desplazamiento) {
      while (match(linea, /@NOW([+-][0-9]+)?@/)) {
        tok = substr(linea, RSTART + 4, RLENGTH - 5)
        desplazamiento = (tok == "") ? 0 : tok + 0
        linea = substr(linea, 1, RSTART - 1) sprintf("%d", now + desplazamiento) substr(linea, RSTART + RLENGTH)
      }
      return linea
    }
    FNR == NR {
      if ($1 == "set") { conjunto[clave($2)] = $2; orden[++n] = clave($2) }
      if ($1 == "del") { borrar[$2] = 1 }
      next
    }
    /^[[:space:]]*(#|$)/ { next }
    {
      k = clave($0)
      if (k in conjunto) { print marcas(conjunto[k]); usada[k] = 1; next }
      for (sel in borrar) {
        if (index(sel, "{") ? (k == sel) : (nombre(k) == sel)) { next }
      }
      print marcas($0)
    }
    END { for (i = 1; i <= n; i++) if (!(orden[i] in usada)) print marcas(conjunto[orden[i]]) }
  ' "${seleccion}" "${BASELINE}"
  rm -f "${seleccion}"
}

# Escritura atomica: el colector textfile lee cualquier `*.prom` que vea y un
# fichero a medias es un error de lectura. Se escribe con otra extension y se
# renombra.
publicar() {
  renderizar "${1:-}" |
    compose exec -T node-exporter sh -c 'cat > /textfile/drill.tmp && mv /textfile/drill.tmp /textfile/drill.prom'
}

# "estado receptores" de la alerta en Alertmanager, o nada si no esta.
consultar() {
  local nombre="$1" respuesta
  respuesta="$(compose exec -T alertmanager wget -qO- http://127.0.0.1:9093/api/v2/alerts 2>/dev/null || true)"
  [ -n "${respuesta}" ] || return 0
  jq -r --arg a "${nombre}" '[.[] | select(.labels.alertname == $a)] | first | if . == null then empty else "\(.status.state) \([.receivers[].name] | join(","))" end' <<<"${respuesta}"
}

alertas_activas() {
  local respuesta
  respuesta="$(compose exec -T alertmanager wget -qO- http://127.0.0.1:9093/api/v2/alerts 2>/dev/null || true)"
  [ -n "${respuesta}" ] || return 0
  jq -r '.[] | .labels.alertname' <<<"${respuesta}" | sort -u
}

esperar_pila() {
  local intentos=0
  until compose exec -T prometheus wget -qO- http://127.0.0.1:9090/-/ready >/dev/null 2>&1 &&
    compose exec -T alertmanager wget -qO- http://127.0.0.1:9093/-/ready >/dev/null 2>&1; do
    intentos=$((intentos + 1))
    [ "${intentos}" -lt 40 ] ||
      die 1 "la pila del simulacro no llega a estar lista en 80 s. Repite con KQ_DRILL_KEEP=1 y mira los registros de la pila kq-drill."
    sleep 2
  done
}

destruir() {
  # Solo el proyecto del simulacro. `down` sobre otro nombre de proyecto esta
  # prohibido en este repositorio: aqui el nombre es una constante.
  compose down -v --remove-orphans >/dev/null 2>&1 || true
}

# Al salir con error se vuelcan los registros de la pila ANTES de destruirla: es
# lo unico que explica por que no arranco una imagen o un contenedor.
al_salir() {
  local codigo=$?
  if [ "${codigo}" -ne 0 ]; then
    compose logs --tail 20 >&2 || true
  fi
  destruir
  exit "${codigo}"
}

main() {
  local solo="" listar=0
  while [ "$#" -gt 0 ]; do
    case "$1" in
    -h | --help)
      usage
      exit 0
      ;;
    --list) listar=1 ;;
    --only)
      [ "$#" -ge 2 ] || die 2 "--only necesita el nombre de una alerta."
      solo="$2"
      shift
      ;;
    *) die 2 "opcion desconocida '$1'. Mira --help." ;;
    esac
    shift
  done

  if [ "${listar}" -eq 1 ]; then
    info "Alertas que el simulacro dispara:"
    alertas_del_simulacro | sed 's/^/  /'
    info "Alertas que NO dispara (cubiertas por promtool):"
    cut -f1 "${NOT_FABRICABLE}" | grep -vE '^[[:space:]]*(#|$)' | sed 's/^/  /'
    exit 0
  fi

  guarda_de_entorno
  command -v docker >/dev/null 2>&1 || die 2 "no encuentro docker. El simulacro levanta una pila efimera."
  command -v jq >/dev/null 2>&1 || die 2 "no encuentro jq (lee la respuesta de la API de Alertmanager). Instalalo: apt install jq / winget install jqlang.jq."

  local propias
  propias="$(alertas_del_simulacro)"
  if [ -n "${solo}" ] && ! grep -qxF -- "${solo}" <<<"${propias}"; then
    die 2 "'${solo}' no esta en infra/drill/faults.tsv. Alertas disponibles: ejecuta --list."
  fi

  KQ_DRILL_PROMETHEUS_IMAGE="$(imagen prometheus)"
  KQ_DRILL_ALERTMANAGER_IMAGE="$(imagen alertmanager)"
  KQ_DRILL_NODE_EXPORTER_IMAGE="$(imagen node-exporter)"
  export KQ_DRILL_PROMETHEUS_IMAGE KQ_DRILL_ALERTMANAGER_IMAGE KQ_DRILL_NODE_EXPORTER_IMAGE

  if [ "${KQ_DRILL_KEEP:-0}" != "1" ]; then
    trap al_salir EXIT
  fi
  # Una ejecucion anterior que murio a medias deja su pila: se parte de limpio.
  destruir
  info "Levantando la pila efimera '${PROJECT}' (Prometheus, Alertmanager y node-exporter reales)..."
  compose up -d --quiet-pull >/dev/null
  esperar_pila

  # Estado sano, publicado varias veces para que Prometheus tenga muestras
  # previas de los contadores (increase() necesita ver el salto).
  info "Publicando el estado sano y esperando a que se asiente..."
  for _ in 1 2 3 4 5 6; do
    publicar ""
    sleep 5
  done

  local fallos=0 total=0 alerta activas
  activas="$(alertas_activas)"
  for alerta in $(alertas_del_simulacro); do
    case " ${NO_PRECHECK} " in *" ${alerta} "*) continue ;; esac
    if grep -qxF -- "${alerta}" <<<"${activas}"; then
      err "ESTADO SANO NO SILENCIOSO: ${alerta} ya esta activa antes de aplicar su falla. El baseline (infra/drill/baseline.prom) no cubre alguna serie que esa regla lee."
      fallos=$((fallos + 1))
    fi
  done
  local ajenas
  ajenas="$(grep -vxF -f <(printf '%s\n' "${propias}") <<<"${activas}" | tr '\n' ' ' || true)"
  if [ -n "${ajenas// /}" ]; then
    info "Otras alertas activas en el estado sano (ajenas al simulacro, p. ej. sin API ni blackbox): ${ajenas}"
  fi

  info ""
  info "ALERTA | RESULTADO | ESTADO Y RECEPTORES EN ALERTMANAGER"
  for alerta in $(alertas_del_simulacro); do
    [ -z "${solo}" ] || [ "${solo}" = "${alerta}" ] || continue
    total=$((total + 1))
    local resultado="" inicio
    inicio="$(date +%s)"
    while [ $(($(date +%s) - inicio)) -lt "${TIMEOUT}" ]; do
      publicar "${alerta}"
      resultado="$(consultar "${alerta}")"
      [ -z "${resultado}" ] || break
      sleep 3
    done
    if [ -n "${resultado}" ]; then
      info "OK     ${alerta} | ${resultado} | $(($(date +%s) - inicio)) s"
    else
      info "FALLA  ${alerta} | no llego a Alertmanager en ${TIMEOUT} s"
      fallos=$((fallos + 1))
    fi
    publicar ""
  done

  if [ -z "${solo}" ]; then
    info ""
    info "No fabricables (cubiertas por promtool test rules):"
    grep -vE '^[[:space:]]*(#|$)' "${NOT_FABRICABLE}" | awk -F'\t' '{ printf "  %s: %s\n", $1, $2 }'
  fi

  info ""
  if [ "${fallos}" -eq 0 ]; then
    info "Simulacro de alertas: ${total} alertas disparadas y recibidas por Alertmanager."
  else
    die 1 "${fallos} problema(s) en el simulacro (ver arriba). Una alerta que no llega es una alerta muerta: revisa su expresion en infra/observability/prometheus/rules/ y la receta en infra/drill/faults.tsv. Para inspeccionar la pila: KQ_DRILL_KEEP=1."
  fi
}

main "$@"
