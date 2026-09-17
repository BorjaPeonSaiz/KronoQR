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

# Pool de PHP-FPM (RNF-P-06, tarea 3.6, doc 02 §3.4). pm.max_children ya no es
# un literal de www.conf: lo decide PHP_FPM_MAX_CHILDREN (20 por omisión, el
# servidor mínimo publicado; 40 recomendado con 4 núcleos/8 GB) y de ahí se
# derivan start_servers (20 %), min_spare_servers (10 %) y max_spare_servers
# (30 %), con un mínimo de 1 en cada uno y el orden que FPM exige:
# min_spare <= start <= max_spare <= max_children.
#
# POR QUE SE RENDERIZA A UN FICHERO APARTE Y NO SE SOBRESCRIBE www.conf. El
# proceso corre como el usuario `app` (sin root, infra/docker/php/Dockerfile) y
# /usr/local/etc/php-fpm.d/ es de root: `app` puede LEER www.conf pero no
# escribir en ese directorio (ni con `sed -i`, que necesita crear un fichero
# temporal ahí). El rendido se escribe en /var/www/.php-fpm-pool.conf —dentro
# del HOME de `app`, que sí es suyo— y arranca con `php-fpm -y <fichero>`, que
# sustituye por completo al php-fpm.conf por defecto: por eso el rendido
# incluye también las dos líneas de [global] que trae la imagen oficial
# (error_log a stderr, sin demonizar), no solo el pool.
#
# DEUDA CONOCIDA (revision de codigo de la tarea 3.6, M4, sin corregir a
# proposito): el [global] rendido repone SOLO esas dos lineas, no el
# php-fpm.conf.default completo de la imagen base (que trae ademas, todas
# comentadas por defecto: pid, process_control_timeout, emergency_restart_*,
# etc.). Si un dia una de esas opciones comentadas dejara de ser el valor por
# defecto de PHP-FPM -o la imagen base cambiara su plantilla-, este rendido no
# se enteraria. Hoy no hace falta ninguna: error_log y daemonize son las unicas
# que este arranque necesita de verdad (log a stderr; primer plano bajo
# `exec`). Si `infra/docker/php/Dockerfile` sube de version base, comparar
# `/usr/local/etc/php-fpm.conf.default` de la imagen nueva contra estas dos
# lineas antes de dar esto por sentado.
#
# Solo el rol fpm la llama: horizon, reverb y scheduler no sirven peticiones y
# no tienen pool que ajustar.
render_fpm_pool() {
  local pool_source="/usr/local/etc/php-fpm.d/www.conf"
  local rendered="/var/www/.php-fpm-pool.conf"
  local max="${PHP_FPM_MAX_CHILDREN:-20}"

  case "${max}" in
  '' | *[!0-9]*)
    log "error" "PHP_FPM_MAX_CHILDREN debe ser un entero entre 1 y 500 (valor recibido: '${max}'). Que hacer: corrige PHP_FPM_MAX_CHILDREN en el .env del servidor y reinicia el contenedor app."
    exit 2
    ;;
  esac
  if [[ "${max}" -lt 1 ]]; then
    log "error" "PHP_FPM_MAX_CHILDREN debe ser un entero entre 1 y 500 (valor recibido: '${max}'). Que hacer: corrige PHP_FPM_MAX_CHILDREN en el .env del servidor y reinicia el contenedor app."
    exit 2
  fi
  # Techo de cordura, no un dimensionado real (revision de seguridad de la
  # tarea 3.6): a ~60 MB por trabajador, 500 ya son 30 GB, muy por encima de
  # cualquier servidor publicado (doc 02 §11.6.2). Un valor asi es casi
  # siempre un cero de mas en el .env, y mejor que el contenedor no arranque a
  # que arranque e intente reservar mas memoria de la que tiene la maquina.
  if [[ "${max}" -gt 500 ]]; then
    log "error" "PHP_FPM_MAX_CHILDREN=${max} supera el techo de cordura de 500 (a ~60 MB por trabajador son 30 GB). Que hacer: revisa el valor en el .env del servidor -- probablemente sobra un cero."
    exit 2
  fi

  local start=$((max * 20 / 100))
  local min_spare=$((max * 10 / 100))
  local max_spare=$((max * 30 / 100))
  [[ "${start}" -lt 1 ]] && start=1
  [[ "${min_spare}" -lt 1 ]] && min_spare=1
  [[ "${max_spare}" -lt 1 ]] && max_spare=1
  # Orden que php-fpm exige entre los cuatro valores, con max_children como
  # techo: con valores pequeños (p. ej. max=1) las tres proporciones colapsan a
  # 1 y las tres comparaciones siguientes no mueven nada.
  [[ "${max_spare}" -gt "${max}" ]] && max_spare="${max}"
  [[ "${start}" -gt "${max_spare}" ]] && start="${max_spare}"
  [[ "${min_spare}" -gt "${start}" ]] && min_spare="${start}"

  {
    printf '[global]\nerror_log = /proc/self/fd/2\ndaemonize = no\n\n'
    sed \
      -e "s/^pm\.max_children = .*/pm.max_children = ${max}/" \
      -e "s/^pm\.start_servers = .*/pm.start_servers = ${start}/" \
      -e "s/^pm\.min_spare_servers = .*/pm.min_spare_servers = ${min_spare}/" \
      -e "s/^pm\.max_spare_servers = .*/pm.max_spare_servers = ${max_spare}/" \
      "${pool_source}"
  } >"${rendered}"

  # La salida de `php-fpm -t` se CAPTURA, nunca se deja salir por el stdout de
  # esta funcion: el llamador hace `rendered_pool="$(render_fpm_pool)"`, y
  # cualquier linea de mas ahi -el "NOTICE: ... test is successful" de
  # php-fpm- se cuela en el valor y `exec php-fpm -y "${rendered_pool}"`
  # intenta abrir una ruta multilinea que no existe. Se probo en caliente y es
  # exactamente el fallo que produjo.
  local prueba
  if ! prueba="$(php-fpm -t -y "${rendered}" 2>&1)"; then
    log "error" "El pool de PHP-FPM rendido con PHP_FPM_MAX_CHILDREN=${max} no es valido: ${prueba}. Que hacer: revisa ${rendered} dentro del contenedor, o baja PHP_FPM_MAX_CHILDREN en el .env del servidor."
    exit 2
  fi

  log "info" "Pool de PHP-FPM: max_children=${max} start_servers=${start} min_spare_servers=${min_spare} max_spare_servers=${max_spare}."
  printf '%s' "${rendered}"
}

# DB_LOCK_TIMEOUT / DB_IDLE_IN_TRANSACTION_TIMEOUT (RNF-P-06, RNF-D-01, tarea
# 3.6, revision de seguridad). Solo el rol fpm los usa: son los que
# compose.dev.yaml y compose.prod.yaml componen en PGOPTIONS SOLO para el
# servicio `app`.
#
# POR QUE VALIDARLOS AQUI SI ESTE ENTRYPOINT NO CONSTRUYE PGOPTIONS. `docker
# compose` ya lo compuso con `${DB_LOCK_TIMEOUT:-5s}` ANTES de que este
# contenedor arrancara -son variables de COMPOSE, no de la aplicacion-, pero
# `env_file: .env` trae tambien las dos variables SUELTAS al entorno de este
# proceso, sin que nadie las valide. Un valor con un espacio o sin unidad
# reconocible ("5 s") rompe la sintaxis de PGOPTIONS (un `-c` con un token
# suelto detras) y PostgreSQL RECHAZA la conexion entera -no solo el limite:
# el fichaje completo, con un motivo que no se parece en nada a "revisa
# DB_LOCK_TIMEOUT en el .env"-. Validar el formato aqui, antes de servir la
# primera peticion, cambia ese fallo por uno con "Que hacer".
validate_pgoptions_durations() {
  local pattern='^[0-9]+(ms|s|min|h)?$'
  local lock="${DB_LOCK_TIMEOUT:-5s}"
  local idle="${DB_IDLE_IN_TRANSACTION_TIMEOUT:-60s}"

  if ! [[ "${lock}" =~ ${pattern} ]]; then
    log "error" "DB_LOCK_TIMEOUT no es una duracion valida de PostgreSQL (valor recibido: '${lock}'). Que hacer: usa un entero seguido opcionalmente de ms, s, min o h -por ejemplo 5s- en el .env del servidor, y reinicia el contenedor app."
    exit 2
  fi

  if ! [[ "${idle}" =~ ${pattern} ]]; then
    log "error" "DB_IDLE_IN_TRANSACTION_TIMEOUT no es una duracion valida de PostgreSQL (valor recibido: '${idle}'). Que hacer: usa un entero seguido opcionalmente de ms, s, min o h -por ejemplo 60s- en el .env del servidor, y reinicia el contenedor app."
    exit 2
  fi
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
    validate_pgoptions_durations
    rendered_pool="$(render_fpm_pool)"
    exec php-fpm --nodaemonize -y "${rendered_pool}"
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

# Guarda de origen (mismo patron que infra/scripts/install.sh): permite cargar
# este fichero con `source` desde una prueba de integracion y llamar a
# render_fpm_pool() directamente, sin disparar main() ni exec'ar php-fpm.
if [ "${BASH_SOURCE[0]}" = "$0" ]; then
  main "$@"
fi
