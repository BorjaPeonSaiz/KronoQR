#!/usr/bin/env bash
#
# KronoQR — release.yml: la etiqueta sigue apuntando al commit que `resolver` verifico (V6-DV-6).
#
# `resolver` comprueba que el commit etiquetado es ancestro de `main` y exporta su SHA; el resto
# de jobs hacen checkout de ESE SHA. Una etiqueta se puede mover (`git tag -f` + push forzado)
# mientras la CI de esta publicacion espera: sin esta comprobacion, un job posterior publicaria
# un commit distinto del verificado. Falla si `refs/tags/<tag>` ya no resuelve a ese commit, y
# tambien si no se puede preguntar (falla cerrado).
#
# Uso:   assert-tag-at-sha.sh ETIQUETA SHA
# Entorno: GH_TOKEN (github.token), GITHUB_REPOSITORY.

set -euo pipefail
IFS=$'\n\t'

if [ "$#" -ne 2 ]; then
  echo "Uso: assert-tag-at-sha.sh ETIQUETA SHA" >&2
  exit 1
fi
tag="$1"
esperado="$2"

actual="$(gh api "repos/${GITHUB_REPOSITORY}/commits/${tag}" --jq '.sha')" || {
  echo "::error title=No se puede comprobar la etiqueta::gh api no ha podido resolver ${tag}. No se publica nada; vuelve a lanzar el workflow." >&2
  exit 1
}

if [ "${actual}" != "${esperado}" ]; then
  echo "::error title=La etiqueta se ha movido::${tag} apuntaba a ${esperado:0:12} cuando se verifico y ahora apunta a ${actual:0:12}. No se publica nada: una version publicada tiene que salir del commit que paso por main." >&2
  exit 1
fi
echo "${tag} sigue en ${esperado:0:12}."
