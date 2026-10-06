#!/usr/bin/env bash
#
# KronoQR — prueba de la prueba: `assert-runtime-env.sh` TIENE QUE FALLAR ante cada
# uno de los incumplimientos que dice vigilar (A3-10, AUD-1, ADR-042).
#
# NO ES UN ENTREGABLE: vive en .github/scripts/ y solo lo ejecuta la CI (etapa ⑧).
# Una comprobacion de seguridad que nunca ha fallado no demuestra nada, y esta
# ya dio falsos negativos (un alias `FOO: ${BACKUP_ENCRYPTION_KEY}` en `app` pasaba;
# un `.env` con `CLAVE="valor" # nota` se buscaba con las comillas). Aqui se levanta
# una pila de JUGUETE —cinco servicios `alpine` con los nombres de los de runtime—
# y se le hace cada trampa por separado.
#
# Uso:
#   assert-runtime-env-selftest.sh
#
# Necesita Docker y `sudo` (el mismo requisito que assert-runtime-env.sh).
# Codigos de salida: 0 todos los casos se comportan como se espera · 1 alguno no.

# Las ${...} de los casos van SIN expandir a proposito: son lo que Compose interpola
# en la pila de juguete. La supresion es de FICHERO porque solo afecta a esos literales.
# shellcheck disable=SC2016

set -euo pipefail
IFS=$'\n\t'

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIR
readonly ASSERT="${SCRIPT_DIR}/assert-runtime-env.sh"

# El nombre del directorio es el del proyecto de Compose: distinto del de la
# instalacion real, para no tocarla.
BASE="$(mktemp -d)"
readonly BASE
readonly DIR="${BASE}/kq-assert-selftest"
mkdir -p "${DIR}/certs"

al_salir() {
  (cd "${DIR}" && sudo docker compose --env-file .env -f docker-compose.yml down -v --remove-orphans >/dev/null 2>&1) || true
  rm -rf "${BASE}"
}
trap al_salir EXIT

cat >"${DIR}/.env" <<'EOF'
APP_KEY=base64:claveclaveclaveclave
DB_PASSWORD=apppassword12345
DB_MIGRATION_USERNAME=fichaje_migrator
DB_MIGRATION_PASSWORD="migrator-secret-9999" # nota del operador
DB_MAINTENANCE_PASSWORD=
BACKUP_ENCRYPTION_KEY=clave-de-copias-abcdefgh
BACKUP_DB_USERNAME=fichaje_backup
BACKUP_DB_PASSWORD=backup-secret-77777
KIOSK_VLAN_CIDR=10.0.0.0/24
PORTAL_INTERNAL_CIDR=10.0.0.0/24
METRICS_ALLOW_CIDR=10.0.0.0/24
EOF
chmod 0600 "${DIR}/.env"

# Escribe el compose con las trampas de EXTRA_<servicio> (lineas de `environment:`)
# y VOL_<servicio> (lineas de `volumes:`), que fija quien llama.
escribir_compose() {
  cat >"${DIR}/docker-compose.yml" <<EOF
x-common: &common
  image: alpine:3.24
  command: ["sleep", "infinity"]
services:
  app:
    <<: *common
    environment:
      APP_KEY:
      DB_PASSWORD:
      DB_MIGRATION_USERNAME:
      METRICS_ALLOW_CIDR:
${EXTRA_app:-}
    volumes:
      - ./certs:/certs:ro
${VOL_app:-}
  horizon:
    <<: *common
    environment:
      APP_KEY:
${EXTRA_horizon:-}
    volumes:
      - ./certs:/certs:ro
${VOL_horizon:-}
  reverb:
    <<: *common
    environment:
      APP_KEY:
${EXTRA_reverb:-}
    volumes:
      - ./certs:/certs:ro
${VOL_reverb:-}
  scheduler:
    <<: *common
    environment:
      BACKUP_ENCRYPTION_KEY:
      BACKUP_DB_USERNAME:
      BACKUP_DB_PASSWORD:
    volumes:
      - ./certs:/certs:ro
  nginx:
    <<: *common
    environment:
      KIOSK_VLAN_CIDR:
      PORTAL_INTERNAL_CIDR:
      METRICS_ALLOW_CIDR:
${EXTRA_nginx:-}
    volumes:
      - ./certs:/certs:ro
EOF
}

fallos=0

# caso NOMBRE ESPERADO [VARIABLE=valor ...]
caso() {
  local nombre="$1" esperado="$2" salida="" codigo=0 variable arranque
  shift 2

  for variable in EXTRA_app EXTRA_horizon EXTRA_reverb EXTRA_nginx VOL_app VOL_horizon VOL_reverb; do
    unset "${variable}"
  done
  for variable in "$@"; do
    export "${variable?}"
  done

  escribir_compose
  # La salida de `up` se conserva: si la pila de juguete no arranca (imagen que no
  # se descarga, red), el motivo tiene que verse en el registro de la CI.
  arranque="$(cd "${DIR}" && sudo docker compose --env-file .env -f docker-compose.yml up -d --force-recreate 2>&1)" || {
    printf 'selftest: no se ha podido levantar la pila de juguete para «%s»:\n%s\n' "${nombre}" "${arranque}" >&2
    fallos=$((fallos + 1))
    return 0
  }

  salida="$(cd "${DIR}" && bash "${ASSERT}" .env docker-compose.yml 2>&1)" || codigo=$?

  if [ "${codigo}" -eq "${esperado}" ]; then
    printf 'ok      %-46s salida %s\n' "${nombre}" "${codigo}"
  else
    printf 'FALLA   %-46s salida %s (se esperaba %s)\n%s\n' "${nombre}" "${codigo}" "${esperado}" "${salida}" >&2
    fallos=$((fallos + 1))
  fi
}

caso "pila correcta" 0
caso "alias de BACKUP_ENCRYPTION_KEY en app" 1 'EXTRA_app=      FOO: ${BACKUP_ENCRYPTION_KEY}'
caso "alias de BACKUP_DB_PASSWORD en horizon" 1 'EXTRA_horizon=      BAR: ${BACKUP_DB_PASSWORD}'
caso "BACKUP_ENCRYPTION_KEY_PREVIOUS en app (solo el planificador puede llevarla, y no tiene por que)" 1 'EXTRA_app=      BACKUP_ENCRYPTION_KEY_PREVIOUS: clave-anterior-abcdefgh'
caso "alias del migrador (.env con comillas y nota)" 1 'EXTRA_horizon=      BAZ: ${DB_MIGRATION_PASSWORD}'
caso "PGPASSFILE en reverb" 1 'EXTRA_reverb=      PGPASSFILE: /x/pgpass'
caso "PGSERVICEFILE en app" 1 'EXTRA_app=      PGSERVICEFILE: /x/svc'
caso "DATABASE_URL en horizon" 1 'EXTRA_horizon=      DATABASE_URL: postgres://x'
caso "alias de DB_PASSWORD en nginx" 1 'EXTRA_nginx=      QQ: ${DB_PASSWORD}'
caso "monta el directorio de despliegue" 1 'VOL_app=      - ./:/despliegue:ro'
caso "monta el .env" 1 'VOL_horizon=      - ./.env:/app/.env:ro'
caso "monta el socket de Docker" 1 'VOL_reverb=      - /var/run/docker.sock:/var/run/docker.sock'
caso "monta / (ancestro del despliegue)" 1 'VOL_horizon=      - /:/host:ro'

if [ "${fallos}" -ne 0 ]; then
  printf 'selftest: %d caso(s) no se comportan como se espera: assert-runtime-env.sh dejaria pasar un incumplimiento.\n' "${fallos}" >&2
  exit 1
fi
printf 'selftest: assert-runtime-env.sh falla ante cada incumplimiento y pasa la pila correcta.\n'
