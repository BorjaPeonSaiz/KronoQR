#!/usr/bin/env bash
#
# KronoQR — verificacion de la FIRMA de las imagenes del producto y de las sumas
# del paquete con cosign (B18 de la 2.2.1, enmienda de ADR-053).
#
# NO SE EJECUTA SOLO: lo cargan install.sh y update.sh (y los jobs `firmar` y
# `firmar-sumas` de release.yml, para comprobar con ESTE mismo codigo lo que
# acaban de firmar).
#
# QUE GARANTIZA. El digest fija QUE bytes se instalan; la firma prueba QUIEN los
# publico: que salieron del workflow `release.yml` de este repositorio, por una
# etiqueta `vX.Y.Z` (o desde `main`, con `workflow_dispatch`), y no de alguien con
# permiso de escritura en el registro. La firma es *keyless* (Sigstore): no hay
# clave privada del fabricante que custodiar ni rotar (RS-08). La identidad es la
# del propio workflow, sellada en un certificado de vida corta que emite Fulcio
# y queda en el registro de transparencia Rekor.
#
# LIMITE QUE NO SE PUEDE OCULTAR. Esta identidad viaja DENTRO del paquete. Quien
# sustituyera el paquete sustituiria tambien este fichero. Lo que cierra ese hueco
# no es el codigo de aqui, sino la verificacion del bundle de `SHA256SUMS` ANTES
# de descomprimir, con la identidad copiada de la guia de instalacion y no del
# paquete nuevo (docs/cliente/instalacion.md §7.1).
#
# CUANDO SE VERIFICA. Solo las imagenes del PRODUCTO (las que cuelgan del
# IMAGE_REGISTRY) y solo cuando van FIJADAS POR DIGEST. Una referencia por
# etiqueta (desarrollo, CI de la etapa 8, o el opt-out de un servidor sin
# internet con IMAGE_DIGEST_*= vacias) no tiene firma que verificar: el aviso de
# digest que ya existe (check_image_digest_overrides) cubre ese caso. Redis y las
# imagenes de observabilidad son de terceros y no las firma este proyecto.
#
# SEIS RESULTADOS (KQ_SIG_RESULT), que los scripts traducen a su mensaje:
#   verified      cosign esta instalado y TODAS las firmas son validas.
#   not-pinned    no habia ninguna imagen del producto fijada por digest.
#   no-cosign     hay imagenes que verificar pero cosign no esta instalado, o es
#                 mas viejo que KQ_COSIGN_MIN_VERSION (no lee el formato de firma
#                 que escribe cosign 3): sigue valiendo el digest, con un AVISO.
#                 Instalarlo es opcional (docs/cliente/instalacion.md §7.1).
#   skipped       el operador paso --skip-signature-check (KQ_SKIP_SIGNATURE_CHECK=1).
#   unverifiable  cosign no pudo CONSULTAR (red, Sigstore o registro inaccesibles).
#                 NO es una firma invalida: aviso y sigue valiendo el digest. Es el
#                 mismo trato que «sin cosign», porque abortar una instalacion
#                 por una verificacion OPCIONAL que depende de un servicio externo
#                 convertiria una caida de Sigstore en una parada del cliente.
#   failed        cosign esta, pudo consultar y la firma NO es valida (o no
#                 existe). Es un FALLO: el script que llama sale con
#                 KQ_EXIT_SECURITY (7).
#
# POR QUE EL ATAJO ES UNA OPCION DE LA LINEA DE ORDENES Y NO UNA VARIABLE DEL .env.
# La etapa 8b de la CI instala desde un registro local con imagenes SIN firmar
# (las construye ella misma) y necesita saltarse la verificacion. Un interruptor
# en el `.env` persistiria en silencio meses; una variable de entorno se pierde
# con `sudo` (env_reset) y tampoco deja huella. `--skip-signature-check` hay que
# teclearla en cada ejecucion, queda en el historial del shell y en la lista de
# procesos, y ambos scripts la anotan con un aviso visible; `update.sh` la
# escribe en su informe y en el asiento `system.updated`, e `install.sh` en
# `.kronoqr-signature-check`, junto al `.env`. No pretende impedir que quien es
# root en el servidor se salte la verificacion (podria desinstalar cosign):
# pretende que no pueda hacerlo sin dejar constancia en la instalacion.

# Los KQ_SIG_* los leen los scripts que CARGAN este fichero, no el fichero en
# si: ShellCheck no lo puede ver. La supresion es de FICHERO, como en
# exit-codes.sh, porque aqui solo viven estas variables y unas funciones.
# shellcheck disable=SC2034

set -euo pipefail
IFS=$'\n\t'

# Emisor OIDC de los workflows de GitHub Actions.
readonly KQ_COSIGN_OIDC_ISSUER="https://token.actions.githubusercontent.com"

# Identidad del certificado de firma: el workflow de publicacion de ESTE
# repositorio. `refs/tags/v…` es la publicacion normal; `refs/heads/main` cubre el
# `workflow_dispatch` lanzado desde la rama principal. Cualquier otro workflow,
# rama o repositorio (un fork, una Action ajena) NO casa. Si el repositorio
# cambia de propietario o de nombre, esta constante, la guia de instalacion y la
# enmienda de ADR-053 se actualizan en la misma version.
readonly KQ_COSIGN_IDENTITY_REGEXP='^https://github\.com/BorjaPeonSaiz/KronoQR/\.github/workflows/release\.yml@refs/(tags/v[0-9][0-9A-Za-z.+-]*|heads/main)$'

# Version MINIMA de cosign. release.yml firma con cosign 3 (formato de bundle nuevo,
# como referrer OCI), que un cosign 2.x no garantiza leer. Elegida de forma
# conservadora: un cosign por debajo se trata como «no instalado», con su aviso,
# en vez de dar un falso «firma invalida».
readonly KQ_COSIGN_MIN_VERSION="3.0.0"

# Resultado de la ultima llamada a kq_verify_product_images.
KQ_SIG_RESULT=""
# Imagen que fallo y salida de cosign (solo con KQ_SIG_RESULT=failed|unverifiable).
KQ_SIG_FAILED_IMAGE=""
KQ_SIG_DETAIL=""
# Clase del ultimo fallo de cosign: invalid | unreachable.
KQ_SIG_KIND=""

# Version de cosign instalada (x.y.z), o nada si no esta o no se lee.
kq_cosign_version() {
  local raw

  command -v cosign >/dev/null 2>&1 || return 0
  raw="$(cosign version 2>&1 | awk '/GitVersion/ { print $2; exit }')" || true
  raw="${raw#v}"
  case "${raw}" in
  [0-9]*) printf '%s' "${raw%%[-+]*}" ;;
  esac
}

# 0 si la version instalada es >= KQ_COSIGN_MIN_VERSION.
kq_cosign_version_ok() {
  local found lowest

  found="$(kq_cosign_version)"
  [ -n "${found}" ] || return 1
  lowest="$(printf '%s\n%s\n' "${found}" "${KQ_COSIGN_MIN_VERSION}" | sort -V | head -n 1)"
  [ "${lowest}" = "${KQ_COSIGN_MIN_VERSION}" ]
}

# Clasifica la salida de un cosign que ha fallado: `invalid` (la firma no existe o
# no casa con la identidad) o `unreachable` (no pudo consultar: red, Sigstore o
# registro). Lo desconocido es `invalid`: se falla cerrado.
kq_cosign_classify() {
  local output="$1"

  # CUALQUIER respuesta HTTP del registro (401, 403, 429, 5xx, denied, unauthorized,
  # toomanyrequests...) es `invalid`: un registro comprometido puede servir la
  # imagen sustituida y negar solo la firma, y eso no puede degradar a un aviso.
  if grep -qiE 'no matching signatures|no signatures found|none of the expected identities|certificate identity|invalid signature|signature verification|does not match|no signature|denied|unauthorized|forbidden|toomanyrequests|too many requests|status code|unexpected status|\b(401|403|404|429|50[0-9])\b' <<<"${output}"; then
    printf 'invalid'
  elif grep -qiE 'dial tcp|no such host|i/o timeout|connection refused|connection reset|network is unreachable|context deadline exceeded|tls handshake' <<<"${output}"; then
    # Solo un fallo de RED sin respuesta de nadie (contra el registro o contra
    # Sigstore: Rekor, Fulcio, TUF).
    printf 'unreachable'
  else
    printf 'invalid'
  fi
}

# Convierte `registro/php:2.2.1@sha256:...` en `registro/php@sha256:...`: cosign
# verifica el digest, y una etiqueta delante solo produce un aviso de cosign.
kq_cosign_ref() {
  local ref="$1" name last

  name="${ref%@*}"
  last="${name##*/}"
  case "${last}" in
  *:*) name="${name%:*}" ;;
  esac
  printf '%s@%s' "${name}" "${ref#*@}"
}

# Ejecuta una verificacion de cosign (argumentos tras el comando) y deja
# KQ_SIG_DETAIL y KQ_SIG_KIND si falla. 0 = valida.
kq_cosign_run() {
  local status=0 output

  output="$(cosign "$@" 2>&1 >/dev/null)" || status=$?

  if [ "${status}" -ne 0 ]; then
    KQ_SIG_DETAIL="$(printf '%s\n' "${output}" | tail -n 4 | tr '\n' ' ')"
    KQ_SIG_KIND="$(kq_cosign_classify "${output}")"
    return 1
  fi
  KQ_SIG_KIND=""
  return 0
}

# kq_cosign_verify_ref REF: verifica UNA referencia con la identidad del
# fabricante. 0 si la firma es valida.
kq_cosign_verify_ref() {
  kq_cosign_run verify \
    --certificate-oidc-issuer "${KQ_COSIGN_OIDC_ISSUER}" \
    --certificate-identity-regexp "${KQ_COSIGN_IDENTITY_REGEXP}" \
    "$(kq_cosign_ref "$1")"
}

# kq_cosign_verify_blob FICHERO BUNDLE: verifica un fichero (SHA256SUMS) contra su
# bundle de cosign con la identidad del fabricante. 0 si es valido.
kq_cosign_verify_blob() {
  kq_cosign_run verify-blob \
    --bundle "$2" \
    --certificate-oidc-issuer "${KQ_COSIGN_OIDC_ISSUER}" \
    --certificate-identity-regexp "${KQ_COSIGN_IDENTITY_REGEXP}" \
    "$1"
}

# kq_verify_product_images REGISTRY IMAGE...
#
# REGISTRY es el IMAGE_REGISTRY de la instalacion; solo se miran las imagenes que
# cuelgan de el. Devuelve 0 en todo menos en `failed` (1). Lee
# KQ_SKIP_SIGNATURE_CHECK (1 = el operador paso --skip-signature-check).
kq_verify_product_images() {
  local registry="${1:-}" image
  local -a pinned=()
  shift || true

  KQ_SIG_RESULT=""
  KQ_SIG_FAILED_IMAGE=""
  KQ_SIG_DETAIL=""
  KQ_SIG_KIND=""

  for image in "$@"; do
    [ -n "${registry}" ] || continue
    case "${image}" in
    "${registry}"/*@sha256:*) pinned+=("${image}") ;;
    esac
  done

  if [ "${#pinned[@]}" -eq 0 ]; then
    KQ_SIG_RESULT="not-pinned"
    return 0
  fi

  if [ "${KQ_SKIP_SIGNATURE_CHECK:-0}" = "1" ]; then
    KQ_SIG_RESULT="skipped"
    return 0
  fi

  if ! kq_cosign_version_ok; then
    KQ_SIG_RESULT="no-cosign"
    KQ_SIG_DETAIL="$(kq_cosign_version)"
    return 0
  fi

  # Se verifican TODAS: el resultado agregado es el peor, y un `invalid` en
  # cualquiera manda sobre un `unreachable` anterior.
  local unreachable_image="" unreachable_detail=""
  for image in "${pinned[@]}"; do
    if ! kq_cosign_verify_ref "${image}"; then
      if [ "${KQ_SIG_KIND}" = "unreachable" ]; then
        [ -n "${unreachable_image}" ] || {
          unreachable_image="${image}"
          unreachable_detail="${KQ_SIG_DETAIL}"
        }
        continue
      fi
      KQ_SIG_FAILED_IMAGE="${image}"
      KQ_SIG_RESULT="failed"
      return 1
    fi
  done

  if [ -n "${unreachable_image}" ]; then
    KQ_SIG_FAILED_IMAGE="${unreachable_image}"
    KQ_SIG_DETAIL="${unreachable_detail}"
    KQ_SIG_KIND="unreachable"
    KQ_SIG_RESULT="unverifiable"
    return 0
  fi

  KQ_SIG_RESULT="verified"
  return 0
}
