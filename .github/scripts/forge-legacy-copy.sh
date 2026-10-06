#!/usr/bin/env bash
#
# KronoQR — fabrica una copia HEREDADA de la 2.1.0 para las pruebas (ADR-049).
#
# NO ES UN ENTREGABLE: vive en .github/scripts/ y solo lo usan las pruebas de la suite
# (KqeFormatTest, BackupKeyRotationTest, BackupScriptsTest). Las copias de la 2.1.0
# eran la salida de `openssl enc` sin cabecera ni MAC (Salted__ + datos); el producto
# ya no las escribe, solo las lee, asi que el codigo que las fabricaba no vive en la
# biblioteca de operacion.
#
# Uso:
#   forge-legacy-copy.sh SALIDA < claro
#
# La clave es BACKUP_ENCRYPTION_KEY (del entorno). Los parametros de cifrado son los
# del formato KQE1 (`KQE_ITER_DUMP`): no se repiten aqui.
#
# Codigos de salida: 0 hecha · 1 falta algo · 2 uso incorrecto.

set -euo pipefail
IFS=$'\n\t'

[ "$#" -eq 1 ] || {
  printf 'uso: forge-legacy-copy.sh SALIDA < claro\n' >&2
  exit 2
}

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd -- "${SCRIPT_DIR}/../.." && pwd)"
# shellcheck source=../../infra/scripts/lib/kqe.sh disable=SC1091
. "${REPO_DIR}/infra/scripts/lib/kqe.sh"

[ -n "${BACKUP_ENCRYPTION_KEY:-}" ] || {
  printf 'forge-legacy-copy: falta BACKUP_ENCRYPTION_KEY en el entorno\n' >&2
  exit 1
}

_kqe_with_pass "${BACKUP_ENCRYPTION_KEY}" openssl enc -aes-256-cbc -md sha512 -pbkdf2 -iter "${KQE_ITER_DUMP}" -salt >"$1"
