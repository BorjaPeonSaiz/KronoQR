#!/usr/bin/env bash
#
# KronoQR — registro de imagenes de destino: UNA sola resolucion para release.yml y
# auditoria-semanal.yml (antes cada workflow tenia su copia).
#
# Imprime en stdout la ruta del registro, sin barra final. Si `IMAGE_REGISTRY_VAR`
# (la variable de repositorio `vars.IMAGE_REGISTRY`) esta puesta, esa; si no,
# `ghcr.io/<propietario-en-minusculas>/kronoqr`. Los avisos van a stderr para no
# ensuciar el valor.
#
# Entorno:  OWNER (obligatorio: `github.repository_owner`), IMAGE_REGISTRY_VAR (opcional).

set -euo pipefail
IFS=$'\n\t'

if [ -z "${OWNER:-}" ]; then
  echo "resolve-registry.sh: falta OWNER (github.repository_owner). Exportalo en el env: del paso." >&2
  exit 1
fi

propietario="$(printf '%s' "${OWNER}" | tr '[:upper:]' '[:lower:]')"
registro="${IMAGE_REGISTRY_VAR:-ghcr.io/${propietario}/kronoqr}"
# Una barra final en la variable produciria "ghcr.io/x//php:2.1.0".
registro="${registro%/}"

if [ -n "${IMAGE_REGISTRY_VAR:-}" ]; then
  echo "Registro ${registro} (vars.IMAGE_REGISTRY puesta explicitamente)." >&2
else
  echo "Registro ${registro} (vars.IMAGE_REGISTRY vacia; valor por defecto)." >&2
fi
printf '%s\n' "${registro}"
