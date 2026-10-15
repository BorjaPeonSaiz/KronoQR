#!/usr/bin/env bash
#
# KronoQR — verificacion de la FIRMA de las imagenes del producto con cosign
# (B18 de la 2.2.1, enmienda de ADR-053).
#
# NO SE EJECUTA SOLO: lo cargan install.sh y update.sh (y el job `firmar` de
# release.yml, para comprobar con ESTE mismo codigo lo que acaba de firmar).
#
# QUE GARANTIZA. El digest fija QUE bytes se instalan; la firma prueba QUIEN los
# publico: que salieron del workflow `release.yml` de este repositorio, por una
# etiqueta `vX.Y.Z` (o desde `main`, con `workflow_dispatch`), y no de alguien con
# permiso de escritura en el registro. La firma es *keyless* (Sigstore): no hay
# clave privada del fabricante que custodiar ni rotar (RS-08). La identidad es la
# del propio workflow, sellada en un certificado de vida corta que emite Fulcio
# y queda en el registro de transparencia Rekor.
#
# CUANDO SE VERIFICA. Solo las imagenes del PRODUCTO (las que cuelgan del
# IMAGE_REGISTRY) y solo cuando van FIJADAS POR DIGEST. Una referencia por
# etiqueta (desarrollo, CI de la etapa 8, o el opt-out de un servidor sin
# internet con IMAGE_DIGEST_*= vacias) no tiene firma que verificar: el aviso de
# digest que ya existe (check_image_digest_overrides) cubre ese caso. Redis y las
# imagenes de observabilidad son de terceros y no las firma este proyecto.
#
# TRES RESULTADOS (KQ_SIG_RESULT), que los scripts traducen a su mensaje:
#   verified    cosign esta instalado y TODAS las firmas son validas.
#   not-pinned  no habia ninguna imagen del producto fijada por digest.
#   no-cosign   hay imagenes que verificar pero cosign no esta instalado: sigue
#               valiendo el digest, con un AVISO. Instalarlo es opcional
#               (docs/cliente/instalacion.md §7.1).
#   skipped     el operador paso --skip-signature-check (KQ_SKIP_SIGNATURE_CHECK=1).
#   failed      cosign esta y alguna firma NO es valida (o no existe). Es un
#               FALLO: el script que llama sale con KQ_EXIT_SECURITY (7).
#
# POR QUE EL ATAJO ES UNA OPCION DE LA LINEA DE ORDENES Y NO UNA VARIABLE DEL .env.
# La etapa 8b de la CI instala desde un registro local con imagenes SIN firmar
# (las construye ella misma) y necesita saltarse la verificacion. Un interruptor
# en el `.env` persistiria en silencio meses; una variable de entorno se pierde
# con `sudo` (env_reset) y tampoco deja huella. `--skip-signature-check` hay que
# teclearla en cada ejecucion, queda en el historial del shell y en la lista de
# procesos, y ambos scripts la anotan con un aviso visible y en el informe de la
# actualizacion. No pretende impedir que quien es root en el servidor se salte la
# verificacion (podria desinstalar cosign): pretende que NO pueda hacerlo sin
# dejar rastro.

# Los tres KQ_SIG_* los leen los scripts que CARGAN este fichero, no el fichero en
# si: ShellCheck no lo puede ver. La supresion es de FICHERO, como en
# exit-codes.sh, porque aqui solo viven estas variables y tres funciones.
# shellcheck disable=SC2034

set -euo pipefail
IFS=$'\n\t'

# Emisor OIDC de los workflows de GitHub Actions.
readonly KQ_COSIGN_OIDC_ISSUER="https://token.actions.githubusercontent.com"

# Identidad del certificado de firma: el workflow de publicacion de ESTE
# repositorio. `refs/tags/v…` es la publicacion normal; `refs/heads/main` cubre el
# `workflow_dispatch` lanzado desde la rama principal. Cualquier otro workflow,
# rama o repositorio (un fork, una Action ajena) NO casa. Si el repositorio
# cambia de propietario o de nombre, esta constante y la enmienda de ADR-053 se
# actualizan en la misma version.
readonly KQ_COSIGN_IDENTITY_REGEXP='^https://github\.com/BorjaPeonSaiz/KronoQR/\.github/workflows/release\.yml@refs/(tags/v[0-9][0-9A-Za-z.+-]*|heads/main)$'

# Resultado de la ultima llamada a kq_verify_product_images.
KQ_SIG_RESULT=""
# Imagen que fallo y salida de cosign (solo con KQ_SIG_RESULT=failed).
KQ_SIG_FAILED_IMAGE=""
KQ_SIG_DETAIL=""

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

# kq_cosign_verify_ref REF: verifica UNA referencia con la identidad del
# fabricante. Devuelve 0 si la firma es valida; si no, deja la salida de cosign
# (ultimas lineas) en KQ_SIG_DETAIL.
kq_cosign_verify_ref() {
  local ref status=0 output

  ref="$(kq_cosign_ref "$1")"
  output="$(
    cosign verify \
      --certificate-oidc-issuer "${KQ_COSIGN_OIDC_ISSUER}" \
      --certificate-identity-regexp "${KQ_COSIGN_IDENTITY_REGEXP}" \
      "${ref}" 2>&1 >/dev/null
  )" || status=$?

  if [ "${status}" -ne 0 ]; then
    KQ_SIG_DETAIL="$(printf '%s\n' "${output}" | tail -n 4 | tr '\n' ' ')"
    return 1
  fi
  return 0
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

  if ! command -v cosign >/dev/null 2>&1; then
    KQ_SIG_RESULT="no-cosign"
    return 0
  fi

  for image in "${pinned[@]}"; do
    if ! kq_cosign_verify_ref "${image}"; then
      KQ_SIG_RESULT="failed"
      KQ_SIG_FAILED_IMAGE="${image}"
      return 1
    fi
  done

  KQ_SIG_RESULT="verified"
  return 0
}
