#!/usr/bin/env bash
#
# KronoQR — lleva al directorio nuevo los cambios que el cliente hizo en
# `observability/` (umbrales, cuadros de mando, reglas propias).
#
# NO SE EJECUTA SOLO: lo cargan update.sh, package.sh (que escribe la huella) y la
# etapa 8b de la CI (que la genera para la version anterior, porque esta aun no la
# entrega). Carga ELLA MISMA lib/fs.sh, de donde sale `sha256_of`: quien la cargue no
# tiene que saber de que depende. Hasta la 2.2.0 el paquete nuevo traia su propio
# `observability/` y `update.sh` no copiaba el del cliente: cada actualizacion le
# devolvia los umbrales de serie sin avisar, y la guia le pedia volver a aplicarlos
# a mano (operacion.md §10).
#
# EL PROBLEMA ES DE TRES VIAS. Para saber si un fichero lo toco el cliente o
# lo cambio el paquete hace falta el fichero tal como lo entrego el paquete
# ANTERIOR, y ese no esta en ninguna parte: el directorio anterior es justo el que
# el cliente edito. Por eso package.sh deja junto al paquete `observability.sha256`
# (la huella de cada fichero tal como se entrega) y esta biblioteca decide, por
# cada fichero:
#
#   B = su huella en el paquete anterior   C = la que tiene ahora en el cliente
#   N = la que trae el paquete nuevo
#
#   C = B            lo cambio nadie: manda el paquete nuevo (no se hace nada).
#   C = N            ya es igual: nada.
#   C != B, N = B    SOLO lo cambio el cliente: se lleva al directorio nuevo.
#   C != B, N != B   lo cambiaron los dos (o el paquete lo ha retirado): GANA EL
#                    PAQUETE, se avisa, y la copia del cliente sigue, intacta, en
#                    el directorio anterior.
#   sin B, sin N     un fichero que el cliente anadio (B ausente): se lleva.
#   B y no C         el cliente BORRO el fichero. Si el paquete no lo cambia (N = B)
#                    se borra tambien en el directorio nuevo, y se dice por su
#                    nombre; si el paquete lo cambia, gana el paquete (vuelve) y se
#                    avisa. Nada reaparece en silencio.
#   enlaces          los enlaces simbolicos del cliente NO se llevan (apuntan fuera
#                    del arbol): se avisa por su nombre.
#
# LISTA PROTEGIDA. En estos ficheros SIEMPRE gana el paquete, los haya cambiado o borrado
# el cliente (se avisa por su nombre y su copia sigue en el directorio anterior):
#   · TODAS las reglas de serie `prometheus/rules/*.yml` (registro horario, cadena de
#     auditoria, proyeccion, incidencias, autenticacion, cuentas, copias, amenazas, y la
#     que vigila que las alertas llegan), SALVO dos excepciones: las `local-*` (son del
#     cliente y viajan siempre) y `kiosk.yml`, que queda fuera a proposito porque la guia
#     pide al cliente ajustar su umbral (QuioscoSinLatido) junto a
#     KIOSK_HEALTH_SILENT_AFTER_SECONDS;
#   · prometheus/prometheus.yml.template (carga las reglas y apunta a Alertmanager);
#   · alertmanager/alertmanager.yml.template, los dos render-config.sh y retention-guard.sh
#     (el arranque);
#   · loki/loki.yaml y tempo/tempo.yaml (la retencion).
# El resto sigue la fusion normal. Toda regla que viaje desde prometheus/rules/ (kiosk.yml
# editado, `local-*`, o una anadida) se valida con `promtool check rules` (un YAML roto apaga
# TODAS las alertas): el de la IMAGEN DE PROMETHEUS ya fijada en el compose
# (KQ_OBS_PROMETHEUS_IMAGE, `docker run --entrypoint promtool`), no el del anfitrion; si
# docker no esta disponible, el promtool del anfitrion; y si tampoco, solo viajan las
# `local-*` y kiosk.yml, y las anadidas quedan en aviso. Si la validacion falla, gana el
# paquete y el fichero queda en conflicto.
#
# Sin `observability.sha256` en el paquete anterior (hasta la 2.2.0) no hay B: no se
# puede saber quien toco que. Se lleva NADA, gana el paquete en todo lo que difiere
# y se avisa una vez, sin falsa precision: «puede que lo cambiaras tu». Es el salto
# real 2.2.0 -> 2.2.1; solo lo cubren las pruebas de Pest (ObservabilityMergeTest),
# porque la etapa 8b del CI parte de una version anterior que ya trae la huella.
#
# NUNCA PIERDE NADA EN SILENCIO: lo que no se lleva, queda donde estaba y el aviso
# dice donde. Y NUNCA aborta una actualizacion: la configuracion de observabilidad
# no vale una vuelta atras.
#
# CONTRATO
#
#   kq_obs_manifest DIR              imprime `<sha256>  <ruta relativa>` por fichero.
#   kq_obs_is_protected RUTA         0 si es de la lista protegida.
#   kq_obs_merge ANTERIOR NUEVO HUELLA
#       ANTERIOR  `observability/` del cliente (directorio anterior)
#       NUEVO     `observability/` del paquete nuevo
#       HUELLA    `observability.sha256` del paquete anterior, o vacio
#     Rellena KQ_OBS_BASELINE (1 si hay huella) y las listas KQ_OBS_CARRIED
#     (llevados), KQ_OBS_DELETED (borrados tambien en el nuevo), KQ_OBS_CONFLICTS
#     (no llevados), KQ_OBS_PROTECTED (de la lista protegida que el cliente cambio
#     o borro) y KQ_OBS_LINKS (enlaces). Devuelve 0 salvo fallo de copia (1).

set -euo pipefail
IFS=$'\n\t'

_KQ_OBS_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/fs.sh disable=SC1091
. "${_KQ_OBS_DIR}/fs.sh"
if ! declare -F sha256_of >/dev/null; then
  printf 'observability-merge.sh: falta sha256_of (lib/fs.sh)\n' >&2
  # shellcheck disable=SC2317
  return 1 2>/dev/null || exit 1
fi

KQ_OBS_BASELINE=0
KQ_OBS_CARRIED=()
KQ_OBS_DELETED=()
KQ_OBS_CONFLICTS=()
KQ_OBS_PROTECTED=()
KQ_OBS_LINKS=()

kq_obs_manifest() {
  local dir="$1" file hash
  [ -d "${dir}" ] || return 0
  while IFS= read -r -d '' file; do
    # Un hash vacio es una huella corrupta: se falla, no se escribe.
    hash="$(sha256_of "${dir}/${file#./}")" && [ -n "${hash}" ] || return 1
    printf '%s  %s\n' "${hash}" "${file#./}"
  done < <(cd -- "${dir}" && find . -type f -print0 | LC_ALL=C sort -z)
}

kq_obs_is_protected() {
  case "$1" in
  prometheus/rules/local-* | prometheus/rules/kiosk.yml) return 1 ;;
  prometheus/rules/*.yml | prometheus/prometheus.yml.template | alertmanager/alertmanager.yml.template | alertmanager/render-config.sh | prometheus/render-config.sh | retention-guard.sh | loki/loki.yaml | loki/rules/*/*.yml | tempo/tempo.yaml) return 0 ;;
  esac
  return 1
}

# Valida un fichero de reglas con promtool. 0 valido, 1 invalido, 2 no se ha podido validar.
# Primero el promtool de la imagen de Prometheus fijada en el compose (el que correra), no el
# del anfitrion, que puede ser de otra version; sin docker, el del anfitrion.
KQ_OBS_PROMETHEUS_IMAGE="${KQ_OBS_PROMETHEUS_IMAGE:-}"
kq_obs_validate_rule() {
  local file="$1"
  if [ -n "${KQ_OBS_PROMETHEUS_IMAGE}" ] && command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
    if docker run --rm --network none --entrypoint promtool -v "$(dirname -- "${file}"):/rules:ro" \
      "${KQ_OBS_PROMETHEUS_IMAGE}" check rules "/rules/$(basename -- "${file}")" >/dev/null 2>&1; then
      return 0
    fi
    return 1
  fi
  if command -v promtool >/dev/null 2>&1; then
    promtool check rules "${file}" >/dev/null 2>&1 && return 0
    return 1
  fi
  return 2
}

kq_obs_merge() {
  local old_dir="$1" new_dir="$2" baseline_file="${3:-}"
  local -A baseline=()
  local line hash path file current theirs before validation status=0

  KQ_OBS_BASELINE=0
  KQ_OBS_CARRIED=()
  KQ_OBS_DELETED=()
  KQ_OBS_CONFLICTS=()
  KQ_OBS_PROTECTED=()
  KQ_OBS_LINKS=()

  [ -d "${old_dir}" ] && [ -d "${new_dir}" ] || return 0

  if [ -n "${baseline_file}" ] && [ -f "${baseline_file}" ]; then
    KQ_OBS_BASELINE=1
    while IFS= read -r line; do
      hash="${line%%  *}"
      path="${line#*  }"
      # Una huella corrupta no se interpreta: ni rutas absolutas o con `..`, ni un hash
      # que no sea SHA-256.
      case "${path}" in /* | *..*) continue ;; esac
      [[ "${hash}" =~ ^[0-9a-f]{64}$ ]] || continue
      if [ -n "${path}" ]; then baseline["${path}"]="${hash}"; fi
    done <"${baseline_file}"
  fi

  while IFS= read -r -d '' file; do
    KQ_OBS_LINKS+=("${file#./}")
  done < <(cd -- "${old_dir}" && find . -type l -print0 | LC_ALL=C sort -z)

  while IFS= read -r -d '' file; do
    file="${file#./}"
    current="$(sha256_of "${old_dir}/${file}")"
    if [ -z "${current}" ]; then
      KQ_OBS_CONFLICTS+=("${file}")
      status=1
      continue
    fi
    theirs=""
    [ ! -f "${new_dir}/${file}" ] || theirs="$(sha256_of "${new_dir}/${file}")"

    # Ya es igual: no hay nada que llevar ni que avisar.
    [ "${current}" != "${theirs}" ] || continue

    if [ "${KQ_OBS_BASELINE}" -eq 0 ]; then
      KQ_OBS_CONFLICTS+=("${file}")
      continue
    fi

    before="${baseline[${file}]:-}"
    # Sin cambios del cliente: manda el paquete nuevo.
    [ "${current}" != "${before}" ] || continue

    # Lista protegida: el cliente la cambio y gana el paquete, siempre.
    if kq_obs_is_protected "${file}" && [ -n "${before}" ]; then
      KQ_OBS_PROTECTED+=("${file}")
      continue
    fi

    # El cliente lo cambio (o lo anadio). Si el paquete no lo ha tocado, gana el
    # cliente; si lo ha tocado o lo ha retirado, gana el paquete.
    if { [ -z "${before}" ] && [ -z "${theirs}" ]; } || { [ -n "${theirs}" ] && [ "${theirs}" = "${before}" ]; }; then
      case "${file}" in
      prometheus/rules/*)
        validation=0
        kq_obs_validate_rule "${old_dir}/${file}" || validation=$?
        if [ "${validation}" -eq 1 ]; then
          KQ_OBS_CONFLICTS+=("${file} (promtool la rechaza)")
          continue
        elif [ "${validation}" -eq 2 ] && [ -z "${before}" ] && [ "${file#prometheus/rules/local-}" = "${file}" ]; then
          KQ_OBS_CONFLICTS+=("${file} (regla propia sin validar)")
          continue
        fi
        ;;
      esac
      if install -d "$(dirname -- "${new_dir}/${file}")" && cp -p -- "${old_dir}/${file}" "${new_dir}/${file}"; then
        KQ_OBS_CARRIED+=("${file}")
      else
        KQ_OBS_CONFLICTS+=("${file}")
        status=1
      fi
    else
      KQ_OBS_CONFLICTS+=("${file}")
    fi
  done < <(cd -- "${old_dir}" && find . -type f -print0 | LC_ALL=C sort -z)

  # Lo que el cliente BORRO: las claves de la huella que ya no estan en su directorio.
  if [ "${KQ_OBS_BASELINE}" -eq 1 ]; then
    while IFS= read -r file; do
      [ -n "${file}" ] || continue
      [ ! -e "${old_dir}/${file}" ] && [ ! -L "${old_dir}/${file}" ] || continue
      if kq_obs_is_protected "${file}"; then
        [ ! -f "${new_dir}/${file}" ] || KQ_OBS_PROTECTED+=("${file} (borrado)")
        continue
      fi
      [ -f "${new_dir}/${file}" ] || continue
      if [ "$(sha256_of "${new_dir}/${file}")" = "${baseline[${file}]}" ]; then
        if rm -f -- "${new_dir}/${file}"; then
          KQ_OBS_DELETED+=("${file}")
        else
          status=1
        fi
      else
        KQ_OBS_CONFLICTS+=("${file} (borrado)")
      fi
    done < <(printf '%s\n' "${!baseline[@]}" | LC_ALL=C sort)
  fi

  return "${status}"
}

# «a, b, c ...» con un tope, para que el aviso quepa en pantalla.
kq_obs_list() {
  local max="$1" shown="" count=0 item
  shift
  for item in "$@"; do
    count=$((count + 1))
    [ "${count}" -le "${max}" ] || continue
    shown="${shown}${shown:+, }${item}"
  done
  [ "${count}" -le "${max}" ] || shown="${shown}, ..."
  printf '%s' "${shown}"
}
