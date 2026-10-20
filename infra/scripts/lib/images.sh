#!/usr/bin/env bash
#
# KronoQR — imagenes de la instalacion: lo que comparten install.sh y update.sh al
# descargarlas.
#
# NO SE EJECUTA SOLO: lo cargan install.sh y update.sh.
#
# UNA IMAGEN CARGADA SIN SU DIGEST (V1-PL-02). En un servidor sin internet el IT
# carga las imagenes con `docker load`. Con el almacen clasico de Docker (sin
# containerd), `docker load` NO conserva el digest de registro: la imagen queda
# con su etiqueta y con `RepoDigests` vacio, y el compose del paquete, que la pide
# fijada por digest (ADR-053), intenta descargarla. El mensaje de siempre mandaba
# a repetir el `docker load`, que da el mismo resultado: un bucle.
#
# Por eso, y SOLO despues de que la descarga por digest haya fallado, se mira si
# la etiqueta esta en local y si no tiene ningun digest de registro: esa es la
# huella de un `docker load`. Con internet la descarga funciona y no se llega
# aqui, asi que nunca se empuja a nadie a quitar la fijacion sin necesidad. Una
# etiqueta presente CON digest (otro proyecto con `redis:7-alpine`, una version
# anterior descargada de un registro) no casa: su contenido no es el fijado, y
# lo que corresponde es el error de descarga normal.

set -euo pipefail
IFS=$'\n\t'

# La variable del .env que anula la fijacion de la imagen REF, por su nombre:
# IMAGE_DIGEST_PHP, IMAGE_DIGEST_NGINX, IMAGE_DIGEST_POSTGRES o REDIS_IMAGE_DIGEST.
# Vacio si no es una imagen fijada por el paquete.
#   kq_image_digest_var REF
kq_image_digest_var() {
  local name="${1%@*}"
  name="${name##*/}"
  name="${name%%:*}"
  case "${name}" in
  php) printf 'IMAGE_DIGEST_PHP' ;;
  nginx) printf 'IMAGE_DIGEST_NGINX' ;;
  postgres) printf 'IMAGE_DIGEST_POSTGRES' ;;
  redis) printf 'REDIS_IMAGE_DIGEST' ;;
  esac
  return 0
}

# 0 si REF va fijada por digest, su etiqueta esta en local y esa imagen no tiene
# ningun digest de registro (`RepoDigests` vacio): la cargo un `docker load`.
# Llamalo SOLO cuando la descarga de REF ya ha fallado. Nunca falla por otra cosa.
#   kq_image_digest_lost REF
kq_image_digest_lost() {
  local ref="$1" count
  case "${ref}" in
  *@sha256:*) ;;
  *) return 1 ;;
  esac
  count="$(docker image inspect -f '{{len .RepoDigests}}' "${ref%@*}" 2>/dev/null)" || return 1
  [ "${count}" = "0" ]
}
