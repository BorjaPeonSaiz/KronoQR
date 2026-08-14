#!/usr/bin/env bash
#
# KronoQR — punto de entrada de los contenedores PHP.
#
# Uso: kronoqr-entrypoint <rol>
#   fpm        PHP-FPM, el que sirve la API detras de Nginx
#   horizon    worker de colas
#   reverb     WebSocket de presencia en vivo
#   scheduler  tareas programadas
#   <otro>     se ejecuta tal cual (util para depurar)
#
# Codigos de salida:
#   0  parada limpia
#   1  rol desconocido o aplicacion en estado no arrancable
#   2  falta una variable de entorno obligatoria
#
# Nota de la Fase 0: la aplicacion Laravel llega en la tarea 0.2. Mientras no
# exista backend/artisan, los roles que la necesitan se quedan a la espera en
# lugar de reiniciarse en bucle, que es ruido que esconde fallos reales.

set -euo pipefail
IFS=$'\n\t'

readonly APP_PATH="/var/www/html"

log() {
  printf '{"level":"%s","service":"entrypoint","role":"%s","message":"%s"}\n' \
    "$1" "${ROLE:-unknown}" "$2" >&2
}

# La configuracion canonica vive en el .env de la RAIZ del repositorio, que
# Compose inyecta en el entorno del contenedor (env_file). Laravel, en cambio,
# espera encontrar un fichero .env junto a artisan, y sin el `php artisan test`
# avisa en cada prueba de que no puede leerlo.
#
# Se crea vacio a proposito: las variables ya presentes en el entorno ganan
# siempre sobre las del fichero, asi que este no configura nada. Existe para
# que no haya dos fuentes de verdad y para que nadie caiga en la tentacion de
# rellenarlo. Idempotente: si ya existe, no se toca.
ensure_environment_file() {
  local env_file="${APP_PATH}/.env"

  if [[ -f "${env_file}" ]] || [[ ! -d "${APP_PATH}" ]]; then
    return 0
  fi

  cat >"${env_file}" <<'EOF'
# Vacio a proposito. NO configures nada aqui.
#
# La configuracion de KronoQR vive en el .env de la raiz del repositorio y
# llega al contenedor como variables de entorno (env_file de Compose). Las
# variables del entorno tienen prioridad sobre las de este fichero, asi que
# cualquier valor que escribas aqui sera ignorado en unos casos y no en otros:
# el peor de los fallos posibles en configuracion.
EOF
}

# vendor/ es un volumen nombrado (infra/compose.dev.yaml), no el del bind
# mount: leer 16.919 ficheros a traves de la frontera NTFS cuesta ~500 veces
# mas que desde el disco del contenedor. Un volumen nuevo nace vacio, asi que
# hay que poblarlo o nada arranca.
#
# Idempotente: si autoload.php ya esta, no se toca. Solo lo hace el rol fpm,
# para que cuatro contenedores que comparten el volumen no instalen a la vez.
ensure_dependencies() {
  if [[ ! -f "${APP_PATH}/composer.json" ]] || [[ -f "${APP_PATH}/vendor/autoload.php" ]]; then
    return 0
  fi

  log "warning" "vendor/ vacio: instalando dependencias en el volumen. La primera vez tarda unos minutos."

  if ! composer install --no-interaction --no-progress --working-dir="${APP_PATH}"; then
    log "error" "composer install fallo. Que hacer: revisa composer.json y composer.lock, y vuelve a ejecutar make up. Si persiste, borra el volumen con: docker volume rm kronoqr_backend-vendor"
    return 1
  fi

  log "info" "Dependencias instaladas en el volumen backend-vendor."
}

wait_for_application() {
  log "warning" "Sin ${APP_PATH}/artisan: la aplicacion Laravel llega en la tarea 0.2. El contenedor queda a la espera. Ejecuta make up de nuevo cuando exista."
  trap 'exit 0' TERM INT
  while true; do
    sleep 5
  done
}

main() {
  ROLE="${1:-fpm}"
  readonly ROLE
  shift || true

  if [[ -f "${APP_PATH}/artisan" ]]; then
    ensure_environment_file
  fi

  # Antes de cualquier rol: sin vendor/ no arranca ni artisan ni php-fpm con
  # aplicacion. Solo fpm instala; los demas esperan a que aparezca.
  if [[ "${ROLE}" == "fpm" ]]; then
    ensure_dependencies
  elif [[ -f "${APP_PATH}/composer.json" && ! -f "${APP_PATH}/vendor/autoload.php" ]]; then
    log "warning" "Esperando a que el rol fpm instale las dependencias en el volumen."
    while [[ ! -f "${APP_PATH}/vendor/autoload.php" ]]; do
      sleep 3
    done
  fi

  if [[ "${ROLE}" != "fpm" && ! -f "${APP_PATH}/artisan" ]]; then
    wait_for_application
  fi

  case "${ROLE}" in
  fpm)
    if [[ ! -f "${APP_PATH}/artisan" ]]; then
      log "warning" "PHP-FPM arranca sin aplicacion: /api/v1/health respondera 404 hasta la tarea 0.2."
    fi
    exec php-fpm --nodaemonize
    ;;
  horizon)
    exec php "${APP_PATH}/artisan" horizon
    ;;
  reverb)
    exec php "${APP_PATH}/artisan" reverb:start --host=0.0.0.0 --port=8080
    ;;
  scheduler)
    exec php "${APP_PATH}/artisan" schedule:work
    ;;
  *)
    exec "${ROLE}" "$@"
    ;;
  esac
}

main "$@"
