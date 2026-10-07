#!/usr/bin/env bash
#
# KronoQR — release.yml: una version publicada es INMUTABLE (A6-2, doc 02
# §11.6.1). Falla si alguna de las tres imagenes de entrega ya existe en el
# registro con la etiqueta de esa version.
#
# POR QUE. `docker push` sobre una etiqueta existente la reescribe sin avisar, y
# `workflow_dispatch` permite repetir una publicacion: dos clientes con la
# «2.1.0» podrian acabar con imagenes distintas. Para corregir una version ya
# publicada se publica OTRA (2.1.1); la etiqueta vieja no se toca.
#
# FALLA CERRADO. Solo una respuesta inequivoca de «no existe» (manifiesto
# desconocido / no encontrado) deja pasar. Un error de red, de permisos o de
# cualquier otro tipo tambien detiene la publicacion: no saber si la etiqueta
# existe no es lo mismo que saber que no existe.
#
# Uso:   assert-image-tags-free.sh REGISTRO VERSION
#   REGISTRO  p. ej. ghcr.io/propietario/kronoqr (sin barra final)
#   VERSION   p. ej. 2.1.0 (sin la `v`)
# Hace falta `docker` con buildx y la sesion del registro ya abierta
# (docker/login-action).
#
# Codigos de salida: 0 las tres etiquetas estan libres · 1 alguna existe o no se
# ha podido comprobar · 2 uso incorrecto.

set -euo pipefail
IFS=$'\n\t'

[ "$#" -eq 2 ] || {
  printf 'uso: assert-image-tags-free.sh REGISTRO VERSION\n' >&2
  exit 2
}
registro="${1%/}"
version="$2"

if [[ ! "${version}" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]]; then
  printf 'assert-image-tags-free.sh: «%s» no es una version SemVer (sin la v). No se ha comprobado nada.\n' "${version}" >&2
  exit 2
fi

status=0
for imagen in php nginx postgres; do
  ref="${registro}/${imagen}:${version}"
  if salida="$(docker buildx imagetools inspect "${ref}" 2>&1)"; then
    printf '::error title=Version ya publicada::%s ya existe en el registro. Una version publicada es inmutable: no se reescribe. Para corregirla, publica otra version (p. ej. un parche) con su propia etiqueta.\n' "${ref}"
    status=1
  elif printf '%s' "${salida}" | grep -qiE 'not found|no such manifest|manifest unknown|name unknown'; then
    printf 'Libre: %s\n' "${ref}"
  else
    printf '::error title=No se puede comprobar la etiqueta::No he podido saber si %s existe (no es una respuesta de «no existe»). Se detiene la publicacion en vez de arriesgarse a reescribirla. Respuesta del registro: %s\n' "${ref}" "$(printf '%s' "${salida}" | head -n 3 | tr '\n' ' ')"
    status=1
  fi
done
exit "${status}"
