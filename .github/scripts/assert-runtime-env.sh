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
# Comprueba cuatro cosas:
#
#   1. Ningun runtime (app, horizon, reverb, scheduler, nginx) tiene la contrasena
#      del migrador ni la del rol de mantenimiento, ni POSTGRES_*, ni ningun DB_*_URL,
#      ni PGPASSFILE/PGSERVICEFILE/DATABASE_URL —ni el NOMBRE de la variable ni su
#      VALOR—. El valor se busca aunque la variable se llame de otra forma
#      (`FOO: ${DB_MIGRATION_PASSWORD}`): es lo que un alias esquivaria por nombre.
#   2. La clave de cifrado de las copias y las credenciales de copia (rol de SOLO
#      LECTURA) las tiene el `scheduler` y ningun otro, por nombre Y por valor.
#   3. `app` recibe TODA la configuracion no privilegiada del .env: cada clave
#      activa del .env que no figure en EXCLUIDAS (con su motivo, mas abajo) esta
#      en su entorno. Es lo que detecta una variable nueva que se quedo fuera de
#      `x-runtime-env` de compose.prod.yaml.
#   4. Ningun runtime monta el directorio de despliegue (ni uno que lo contenga),
#      ni el propio `.env`, ni el socket de Docker: una montura asi entrega el
#      `.env` entero sin pasar por el entorno (A3-10).
#
# LECTURA DEL .env: se copia una vez a un temporal privado y se interpreta con
# `kq_env_unquote`, la MISMA funcion que usan los scripts del producto
# (infra/scripts/lib/env-file.sh): comillas y comentarios finales quedan fuera del
# valor, y se consideran TODAS las lineas de una clave repetida, no solo la primera.
# Sin esto, `DB_MIGRATION_PASSWORD="abc" # nota` se buscaba con las comillas y el
# comentario, no aparecia en ningun sitio y la comprobacion salia en verde.
#
# SIN TUBERIAS EN LAS PREGUNTAS. `printf ... | grep -q` bajo `pipefail` responde NO
# cuando la respuesta es SI si el productor recibe SIGPIPE (ver lib/app-commands.sh).
# Aqui una pregunta que da un falso NO es un falso negativo de seguridad: se hace
# con coincidencia de patrones de bash, que no tiene productor.
#
# Uso:
#   assert-runtime-env.sh RUTA_ENV RUTA_COMPOSE      (Compose y el .env se leen con sudo)
#
# Codigos de salida: 0 correcto · 1 incumplimiento · 2 uso incorrecto.

set -euo pipefail
IFS=$'\n\t'

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIR
# shellcheck source=../../infra/scripts/lib/env-file.sh disable=SC1091
. "${SCRIPT_DIR}/../../infra/scripts/lib/env-file.sh"

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
  PGPASSFILE
  PGSERVICEFILE
  DATABASE_URL
)

# Secretos cuyo VALOR no puede aparecer en ningun runtime salvo donde se usan. Las
# dos primeras, en NINGUNO; las de copia, en todos menos el planificador.
readonly -a VALOR_NUNCA_EN_RUNTIME=(DB_MIGRATION_PASSWORD DB_MAINTENANCE_PASSWORD)
readonly -a VALOR_SOLO_SCHEDULER=(BACKUP_ENCRYPTION_KEY BACKUP_DB_PASSWORD)
# El borde no necesita ningun secreto de la aplicacion: ni por nombre ni por valor.
readonly -a VALOR_NUNCA_EN_NGINX=(APP_KEY QR_SIGNING_KEY_CURRENT IDENTITY_PIN_SEALING_SECRET_KEY DB_PASSWORD LICENSE_KEY REVERB_APP_SECRET MAIL_PASSWORD)
# Un valor mas corto que esto no es un secreto generado (`true`, `5432`, `1`): buscarlo
# daria falsos positivos con cualquier variable de configuracion.
readonly LONGITUD_MINIMA_DE_SECRETO=8

# Nombres que solo el planificador puede tener (copias con el rol de solo lectura).
readonly -a SOLO_SCHEDULER=(
  BACKUP_ENCRYPTION_KEY
  BACKUP_DB_USERNAME
  BACKUP_DB_PASSWORD
)
# Nombres que solo el planificador PUEDE tener, pero no tiene por que: la clave anterior
# de las copias existe solo durante una rotacion y se pasa con -e (ADR-049); fuera de
# ella, el planificador no la lleva y eso es lo correcto.
readonly -a SOLO_SCHEDULER_OPCIONAL=(
  BACKUP_ENCRYPTION_KEY_PREVIOUS
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
#   nginx                KIOSK_VLAN_CIDR, PORTAL_INTERNAL_CIDR, ADMIN_INTERNAL_CIDR,
#                        NGINX_CLIENT_MAX_BODY_SIZE,
#                        TLS_CERT_FILE, TLS_KEY_FILE: el borde, no la aplicacion.
readonly -a EXCLUIDAS=(
  ALERT_MAINTENANCE_WEEKDAY ALERT_MAINTENANCE_START ALERT_MAINTENANCE_END
  BACKUP_ENCRYPTION_KEY BACKUP_DB_USERNAME BACKUP_DB_PASSWORD
  DB_MIGRATION_PASSWORD DB_MAINTENANCE_PASSWORD
  BRANDING_PATH HTTP_PORT HTTPS_PORT IMAGE_REGISTRY TLS_CERT_DIR
  GRAFANA_ADMIN_USER GRAFANA_ADMIN_PASSWORD
  KIOSK_VLAN_CIDR PORTAL_INTERNAL_CIDR NGINX_CLIENT_MAX_BODY_SIZE TLS_CERT_FILE TLS_KEY_FILE
  # ADMIN_INTERNAL_CIDR (PP-10) tambien es del borde: la rinde 07-kronoqr-admin-net.envsh.
  ADMIN_INTERNAL_CIDR
  # TRUSTED_PROXY_CIDR es de nginx (set_real_ip_from); DB_MAX_SLOT_WAL_KEEP_GB la
  # interpola Compose en el `command:` de postgres: la aplicacion no las lee.
  TRUSTED_PROXY_CIDR DB_MAX_SLOT_WAL_KEEP_GB
  # BACKUP_WAL_KEY es la subclave del WAL derivada de BACKUP_ENCRYPTION_KEY (ADR-049):
  # solo la recibe postgres, para su archive_command. app no la lee ni debe tenerla.
  BACKUP_WAL_KEY
)

status=0
fallo() {
  printf 'FALLO: %s\n' "$1" >&2
  status=1
}

# El .env se copia UNA vez a un temporal privado (mktemp lo crea 0600): sudo cat,
# porque el del servidor es de root.
ENV_COPIA="$(mktemp)"
trap 'rm -f "${ENV_COPIA}"' EXIT
# La redireccion la hace a proposito el usuario del runner (el temporal es suyo).
# shellcheck disable=SC2024
sudo cat "${ENV_FILE}" >"${ENV_COPIA}"

# Todos los valores de una clave, ya sin comillas ni comentario final, uno por
# linea. Compose se queda con la ULTIMA linea de una clave repetida y el script
# del producto con la primera: se comprueban todas.
valores_del_env() {
  local clave="$1" linea
  while IFS= read -r linea || [ -n "${linea}" ]; do
    [[ "${linea}" =~ ^[[:space:]]*(export[[:space:]]+)?"${clave}"=(.*)$ ]] || continue
    kq_env_unquote "${BASH_REMATCH[2]}"
    printf '\n'
  done <"${ENV_COPIA}"
}

# El primero, para lo que solo necesita UN valor (el nombre de un rol).
valor_del_env() {
  kq_env_value "${ENV_COPIA}" "$1"
}

# ¿Hay en ENTORNO una linea NOMBRE=...? Sin tuberia.
tiene_variable() {
  local entorno="$1" nombre="$2"
  [[ $'\n'"${entorno}" == *$'\n'"${nombre}="* ]]
}

# ¿Aparece TEXTO, literal, en ENTORNO? Sin tuberia.
contiene_texto() {
  local entorno="$1" texto="$2"
  [[ "${entorno}" == *"${texto}"* ]]
}

# Falla si algun valor de la clave del .env aparece en el entorno del servicio.
comprobar_valor() {
  local servicio="$1" entorno="$2" clave="$3" valor
  while IFS= read -r valor; do
    [ "${#valor}" -ge "${LONGITUD_MINIMA_DE_SECRETO}" ] || continue
    if contiene_texto "${entorno}" "${valor}"; then
      fallo "el VALOR de ${clave} aparece en el entorno de ${servicio} (con ese u otro nombre de variable)."
    fi
  done < <(valores_del_env "${clave}")
}

# Ruta absoluta y sin enlaces simbolicos.
ruta_real() {
  readlink -f -- "$1" 2>/dev/null || printf '%s' "$1"
}

DIRECTORIO_DE_DESPLIEGUE="$(ruta_real "$(dirname -- "${COMPOSE_FILE}")")"
readonly DIRECTORIO_DE_DESPLIEGUE
ENV_ABSOLUTO="$(ruta_real "${ENV_FILE}")"
readonly ENV_ABSOLUTO

# A3-10 (d). Ningun montaje de un contenedor de runtime entrega el .env: ni el
# directorio de despliegue (que lo contiene), ni un ancestro suyo, ni el fichero,
# ni el socket de Docker. Se lee de `docker inspect`, que es lo que hay montado de
# verdad y no lo que el compose dice.
comprobar_montajes() {
  local servicio="$1" id montajes origen destino real
  id="$(compose ps -q "${servicio}")" || {
    fallo "no se puede localizar el contenedor de ${servicio} (docker compose ps -q ${servicio})"
    return 0
  }
  montajes="$(sudo docker inspect --format '{{range .Mounts}}{{.Source}}|{{.Destination}}{{"\n"}}{{end}}' "${id}")" || {
    fallo "no se pueden leer los montajes de ${servicio} (docker inspect)"
    return 0
  }

  while IFS='|' read -r origen destino; do
    [ -n "${origen}" ] || continue
    real="$(ruta_real "${origen}")"
    if [ "${real}" = "/" ] || [ "${real}" = "${DIRECTORIO_DE_DESPLIEGUE}" ] ||
      [[ "${DIRECTORIO_DE_DESPLIEGUE}" == "${real}"/* ]]; then
      fallo "${servicio} monta ${origen} en ${destino}: es el directorio de despliegue o uno que lo contiene, y con el va el .env."
    fi
    if [ "${real}" = "${ENV_ABSOLUTO}" ] || [ "$(basename -- "${origen}")" = ".env" ] || [ "$(basename -- "${destino}")" = ".env" ]; then
      fallo "${servicio} monta el .env (${origen} en ${destino}): un runtime no puede leer los secretos del despliegue."
    fi
    case "${real}" in
    /var/run/docker.sock | /run/docker.sock)
      fallo "${servicio} monta el socket de Docker (${origen}): quien ejecute codigo en el contenedor controla el servidor."
      ;;
    esac
  done <<<"${montajes}"
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
    if tiene_variable "${entorno}" "${nombre}"; then
      fallo "${servicio} tiene ${nombre}: un contenedor de runtime no puede llevar credenciales del migrador ni del mantenimiento."
    fi
  done
  for nombre in "${VALOR_NUNCA_EN_RUNTIME[@]}"; do
    comprobar_valor "${servicio}" "${entorno}" "${nombre}"
  done
  if [ "${servicio}" != "scheduler" ]; then
    for nombre in "${VALOR_SOLO_SCHEDULER[@]}"; do
      comprobar_valor "${servicio}" "${entorno}" "${nombre}"
    done
  fi
  if [ "${servicio}" = "nginx" ]; then
    for nombre in "${VALOR_NUNCA_EN_NGINX[@]}"; do
      comprobar_valor "${servicio}" "${entorno}" "${nombre}"
    done
  fi

  # 4. Montajes.
  comprobar_montajes "${servicio}"

  # 2. Copias: solo el planificador.
  for nombre in "${SOLO_SCHEDULER[@]}"; do
    tiene=0
    if tiene_variable "${entorno}" "${nombre}"; then
      tiene=1
    fi
    if [ "${servicio}" = "scheduler" ] && [ "${tiene}" -eq 0 ]; then
      fallo "scheduler no tiene ${nombre}: las copias programadas fallarian."
    fi
    if [ "${servicio}" != "scheduler" ] && [ "${tiene}" -eq 1 ]; then
      fallo "${servicio} tiene ${nombre}: solo el planificador puede llevarla."
    fi
  done
  for nombre in "${SOLO_SCHEDULER_OPCIONAL[@]}"; do
    if [ "${servicio}" != "scheduler" ] && tiene_variable "${entorno}" "${nombre}"; then
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
  if [ -n "$(valor_del_env BACKUP_DB_PASSWORD)" ] && [ "$(valor_del_env BACKUP_DB_PASSWORD)" = "$(valor_del_env DB_MIGRATION_PASSWORD)" ]; then
    fallo "BACKUP_DB_PASSWORD coincide con DB_MIGRATION_PASSWORD."
  fi
fi

# 3. `app` recibe todo lo no privilegiado del .env.
if [ -n "${ENTORNO[app]:-}" ]; then
  faltan=""
  while IFS= read -r clave; do
    [ -n "${clave}" ] || continue
    en_lista "${clave}" "${EXCLUIDAS[@]}" && continue
    tiene_variable "${ENTORNO[app]}" "${clave}" || faltan="${faltan}${faltan:+ }${clave}"
  done < <(sed -nE 's/^[[:space:]]*([A-Z][A-Z0-9_]*)=.*/\1/p' "${ENV_COPIA}" | sort -u)
  if [ -n "${faltan}" ]; then
    fallo "claves del .env que app NO recibe y no estan excluidas: ${faltan}. Anadelas a x-runtime-env de compose.prod.yaml o, si no son de la aplicacion, a EXCLUIDAS (con su motivo) aqui y en RuntimeEnvironmentTest."
  fi
fi

# nginx: solo lo suyo, y lo obligatorio presente.
if [ -n "${ENTORNO[nginx]:-}" ]; then
  for nombre in KIOSK_VLAN_CIDR PORTAL_INTERNAL_CIDR METRICS_ALLOW_CIDR; do
    [[ $'\n'"${ENTORNO[nginx]}" == *$'\n'"${nombre}="?* ]] || fallo "nginx no tiene ${nombre}, que exige 04-kronoqr-required-env.sh."
  done
  for nombre in APP_KEY QR_SIGNING_KEY_CURRENT IDENTITY_PIN_SEALING_SECRET_KEY DB_PASSWORD LICENSE_KEY REVERB_APP_SECRET MAIL_PASSWORD; do
    if tiene_variable "${ENTORNO[nginx]}" "${nombre}"; then
      fallo "nginx tiene ${nombre}: el borde no necesita ningun secreto de la aplicacion (PIN-10)."
    fi
  done
fi

if [ "${status}" -eq 0 ]; then
  printf 'assert-runtime-env: %d servicios de runtime sin credenciales privilegiadas y con la configuracion completa.\n' "${#RUNTIME[@]}"
fi
exit "${status}"
