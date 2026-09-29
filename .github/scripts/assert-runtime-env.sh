#!/usr/bin/env bash
#
# KronoQR — etapas ⑧ y ⑧b de la CI: el ENTORNO REAL de los cinco contenedores de
# runtime no lleva ninguna credencial que pueda alterar el registro (AUD-1,
# ADR-042, regla dura 6), y lleva todo lo demas.
#
# NO ES UN ENTREGABLE: vive en .github/scripts/ y solo lo ejecuta la CI. Se ejecuta
# contra la pila YA EN PIE (`docker compose exec <servicio> env`), que es lo unico
# honesto: el compose puede decir una cosa y el contenedor otra.
#
# Comprueba tres cosas:
#
#   1. Ningun runtime (app, horizon, reverb, scheduler, nginx) tiene la contrasena
#      del migrador ni la del rol de mantenimiento, ni POSTGRES_*, ni ningun DB_*_URL
#      —ni el NOMBRE de la variable ni su VALOR—.
#   2. La clave de cifrado de las copias y las credenciales de copia (rol de SOLO
#      LECTURA) las tiene el `scheduler` y ningun otro.
#   3. `app` recibe TODA la configuracion no privilegiada del .env: cada clave
#      activa del .env que no figure en EXCLUIDAS (con su motivo, mas abajo) esta
#      en su entorno. Es lo que detecta una variable nueva que se quedo fuera de
#      `x-runtime-env` de compose.prod.yaml.
#
# Uso:
#   assert-runtime-env.sh RUTA_ENV RUTA_COMPOSE      (Compose y el .env se leen con sudo)
#
# Codigos de salida: 0 correcto · 1 incumplimiento · 2 uso incorrecto.

set -euo pipefail
IFS=$'\n\t'

[ "$#" -eq 2 ] || {
  printf 'uso: assert-runtime-env.sh RUTA_ENV RUTA_COMPOSE\n' >&2
  exit 2
}

ENV_FILE="$1"
COMPOSE_FILE="$2"

compose() {
  sudo docker compose --env-file "${ENV_FILE}" -f "${COMPOSE_FILE}" "$@"
}

readonly -a RUNTIME=(app horizon reverb scheduler nginx)

# Nombres que NINGUN runtime puede tener.
readonly -a PROHIBIDAS=(
  DB_MIGRATION_PASSWORD
  DB_MAINTENANCE_PASSWORD
  POSTGRES_USER
  POSTGRES_PASSWORD
  DB_URL
  DB_MIGRATION_URL
  DB_MAINTENANCE_URL
  PGPASSWORD
  PGUSER
)

# Nombres que solo el planificador puede tener (copias con el rol de solo lectura).
readonly -a SOLO_SCHEDULER=(
  BACKUP_ENCRYPTION_KEY
  BACKUP_DB_USERNAME
  BACKUP_DB_PASSWORD
)

# Claves del .env que `app` NO recibe, cada una con su motivo. Esta lista es la
# misma que la prueba de completitud (RuntimeEnvironmentTest): si se toca una, se
# toca la otra.
#
#   alertmanager         ALERT_MAINTENANCE_WEEKDAY/START/END: solo el renderizador de
#                        Alertmanager.
#   scheduler            BACKUP_ENCRYPTION_KEY, BACKUP_DB_USERNAME, BACKUP_DB_PASSWORD:
#                        las copias las lanza el planificador (ADR-042).
#   migrate/restore/pg   DB_MIGRATION_PASSWORD, DB_MAINTENANCE_PASSWORD: solo los
#                        servicios puntuales y PostgreSQL.
#   compose              BRANDING_PATH, HTTP_PORT, HTTPS_PORT, IMAGE_REGISTRY,
#                        TLS_CERT_DIR: los consume Compose (montajes, puertos, imagen).
#   grafana              GRAFANA_ADMIN_USER, GRAFANA_ADMIN_PASSWORD.
#   nginx                KIOSK_VLAN_CIDR, PORTAL_INTERNAL_CIDR, NGINX_CLIENT_MAX_BODY_SIZE,
#                        TLS_CERT_FILE, TLS_KEY_FILE: el borde, no la aplicacion.
readonly -a EXCLUIDAS=(
  ALERT_MAINTENANCE_WEEKDAY ALERT_MAINTENANCE_START ALERT_MAINTENANCE_END
  BACKUP_ENCRYPTION_KEY BACKUP_DB_USERNAME BACKUP_DB_PASSWORD
  DB_MIGRATION_PASSWORD DB_MAINTENANCE_PASSWORD
  BRANDING_PATH HTTP_PORT HTTPS_PORT IMAGE_REGISTRY TLS_CERT_DIR
  GRAFANA_ADMIN_USER GRAFANA_ADMIN_PASSWORD
  KIOSK_VLAN_CIDR PORTAL_INTERNAL_CIDR NGINX_CLIENT_MAX_BODY_SIZE TLS_CERT_FILE TLS_KEY_FILE
)

status=0
fallo() {
  printf 'FALLO: %s\n' "$1" >&2
  status=1
}

valor_del_env() {
  sudo sed -n "s/^$1=//p" "${ENV_FILE}" | head -1
}

en_lista() {
  local aguja="$1" elemento
  shift
  for elemento in "$@"; do
    [ "${elemento}" = "${aguja}" ] && return 0
  done
  return 1
}

declare -A ENTORNO=()

for servicio in "${RUNTIME[@]}"; do
  # Se captura entero: `exec ... | grep -q` bajo pipefail responde NO cuando la
  # respuesta es SI (ver lib/app-commands.sh).
  entorno="$(compose exec -T "${servicio}" env)" ||
    {
      fallo "no se puede leer el entorno de ${servicio} (docker compose exec ${servicio} env)"
      continue
    }
  ENTORNO["${servicio}"]="${entorno}"

  # 1. Nada prohibido, ni por nombre ni por valor.
  for nombre in "${PROHIBIDAS[@]}"; do
    if printf '%s\n' "${entorno}" | grep -q "^${nombre}="; then
      fallo "${servicio} tiene ${nombre}: un contenedor de runtime no puede llevar credenciales del migrador ni del mantenimiento."
    fi
  done
  for nombre in DB_MIGRATION_PASSWORD DB_MAINTENANCE_PASSWORD; do
    valor="$(valor_del_env "${nombre}")"
    if [ -n "${valor}" ] && printf '%s\n' "${entorno}" | grep -qF -- "${valor}"; then
      fallo "el VALOR de ${nombre} aparece en el entorno de ${servicio}."
    fi
  done

  # 2. Copias: solo el planificador.
  for nombre in "${SOLO_SCHEDULER[@]}"; do
    tiene=0
    printf '%s\n' "${entorno}" | grep -q "^${nombre}=" && tiene=1
    if [ "${servicio}" = "scheduler" ] && [ "${tiene}" -eq 0 ]; then
      fallo "scheduler no tiene ${nombre}: las copias programadas fallarian."
    fi
    if [ "${servicio}" != "scheduler" ] && [ "${tiene}" -eq 1 ]; then
      fallo "${servicio} tiene ${nombre}: solo el planificador puede llevarla."
    fi
  done
done

# El rol de copias del planificador es el de solo lectura, no el del migrador.
if [ -n "${ENTORNO[scheduler]:-}" ]; then
  esperado="$(valor_del_env BACKUP_DB_USERNAME)"
  migrador="$(valor_del_env DB_MIGRATION_USERNAME)"
  if [ -n "${esperado}" ] && [ "${esperado}" = "${migrador}" ]; then
    fallo "BACKUP_DB_USERNAME (${esperado}) es el rol de migracion: las copias tienen que usar el rol de solo lectura."
  fi
  if [ "$(valor_del_env BACKUP_DB_PASSWORD)" = "$(valor_del_env DB_MIGRATION_PASSWORD)" ]; then
    fallo "BACKUP_DB_PASSWORD coincide con DB_MIGRATION_PASSWORD."
  fi
fi

# 3. `app` recibe todo lo no privilegiado del .env.
if [ -n "${ENTORNO[app]:-}" ]; then
  faltan=""
  while IFS= read -r clave; do
    [ -n "${clave}" ] || continue
    en_lista "${clave}" "${EXCLUIDAS[@]}" && continue
    printf '%s\n' "${ENTORNO[app]}" | grep -q "^${clave}=" || faltan="${faltan}${faltan:+ }${clave}"
  done < <(sudo sed -nE 's/^[[:space:]]*([A-Z][A-Z0-9_]*)=.*/\1/p' "${ENV_FILE}" | sort -u)
  if [ -n "${faltan}" ]; then
    fallo "claves del .env que app NO recibe y no estan excluidas: ${faltan}. Anadelas a x-runtime-env de compose.prod.yaml o, si no son de la aplicacion, a EXCLUIDAS (con su motivo) aqui y en RuntimeEnvironmentTest."
  fi
fi

# nginx: solo lo suyo, y lo obligatorio presente.
if [ -n "${ENTORNO[nginx]:-}" ]; then
  for nombre in KIOSK_VLAN_CIDR PORTAL_INTERNAL_CIDR METRICS_ALLOW_CIDR; do
    printf '%s\n' "${ENTORNO[nginx]}" | grep -q "^${nombre}=." || fallo "nginx no tiene ${nombre}, que exige 04-kronoqr-required-env.sh."
  done
  for nombre in APP_KEY QR_SIGNING_KEY_CURRENT IDENTITY_PIN_SEALING_SECRET_KEY DB_PASSWORD LICENSE_KEY REVERB_APP_SECRET MAIL_PASSWORD; do
    if printf '%s\n' "${ENTORNO[nginx]}" | grep -q "^${nombre}="; then
      fallo "nginx tiene ${nombre}: el borde no necesita ningun secreto de la aplicacion (PIN-10)."
    fi
  done
fi

if [ "${status}" -eq 0 ]; then
  printf 'assert-runtime-env: %d servicios de runtime sin credenciales privilegiadas y con la configuracion completa.\n' "${#RUNTIME[@]}"
fi
exit "${status}"
