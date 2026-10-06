#!/usr/bin/env bash
#
# KronoQR — simulacro de restauracion (RNF-D-05, RQ-09).
#
# Restaura la ULTIMA copia en un contenedor LIMPIO —uno que no ha visto nunca
# esta base de datos— y comprueba dos cosas que ningun `ls -l` demuestra:
#
#   · INTEGRIDAD REFERENCIAL. Cada clave ajena se vuelve a crear sobre los
#     datos restaurados. PostgreSQL la valida al crearla, asi que una sola fila
#     huerfana hace fallar el simulacro con el nombre de la restriccion. Es la
#     comprobacion generica: vale para claves compuestas y para las que aun no
#     existen, sin escribir una consulta por tabla.
#   · CONTEOS. Cada tabla del manifiesto tiene en la copia restaurada al menos
#     las filas que tenia al hacerla.
#
# Y, desde la 2.2.0 (ADR-049), con `--mode pitr`, lo que ninguna otra
# comprobacion ejercita: que la copia FISICA mas el WAL archivado y CIFRADO
# reconstruyen la base (RNF-D-02, «copias mas WAL»), con los segmentos recientes
# cifrados y, si los hay, los heredados de la 2.1.0.
#
# No toca la instalacion: ni la base de produccion, ni los contenedores del
# producto, ni las copias, que se abren en modo lectura. Al terminar, el
# contenedor del simulacro se destruye con todo lo que contenia, incluido el
# volcado descifrado, que en el modo `container` nunca llega a tocar el disco
# del servidor.
#
# INTEGRIDAD (ADR-049). Igual que restore.sh: la copia se LEE UNA SOLA VEZ a un
# directorio privado 0700 y el MAC, el `.sha256` (obligatorio), el manifiesto
# autenticado y el descifrado se hacen sobre ESA copia. Una copia alterada,
# renombrada, sin `.sha256` o de la 2.1.0 sin `--accept-unauthenticated` hace
# fallar el simulacro con salida 6: eso ya es el hallazgo.
#
# CADENCIA: trimestral (RNF-D-05). Se automatiza de dos maneras y las dos
# valen: `.github/workflows/backup-drill.yml` en el repositorio del fabricante,
# y una entrada de cron trimestral en el servidor del cliente, que es la que
# demuestra que SUS copias se restauran. El runbook explica las dos.
#
# Uso:
#   restore-drill.sh                        simulacro sobre la ultima copia
#   restore-drill.sh --file RUTA            sobre una copia concreta
#   restore-drill.sh --mode database        sin Docker, en una base nueva
#   restore-drill.sh --mode pitr            copia fisica + WAL cifrado, a un punto
#   restore-drill.sh --keep                 no destruye el contenedor al acabar
#
# Opciones:
#   --file RUTA     copia a restaurar. Por defecto la del puntero LATEST
#   --base RUTA     (pitr) copia fisica. Por defecto la ultima de base/
#   --wal-source X  (pitr) donde esta el archivo de WAL: una ruta o un volumen de
#                   Docker, que se monta en solo lectura. Por defecto BACKUP_PATH/wal
#   --image IMAGEN  imagen del contenedor limpio. Por defecto postgres:17-alpine
#                   (en pitr, la imagen de postgres DEL PRODUCTO: trae las
#                   herramientas que entienden el formato cifrado)
#   --mode MODO     container (por defecto), database o pitr
#   --timeout SEG   espera maxima a que arranque el contenedor. Por defecto 90
#   --keep          conserva el contenedor para inspeccionarlo a mano
#   --accept-unauthenticated
#                   acepta una copia (y segmentos de WAL) de la 2.1.0, sin MAC.
#                   El `.sha256` sigue siendo obligatorio. Por invocacion; el
#                   .env no cuenta (C12)
#
# El modo `database` restaura en una base NUEVA del PostgreSQL configurado y la
# elimina al terminar. Existe para la integracion continua, donde el runner ya
# ES un contenedor limpio y no hay Docker dentro de Docker. En el servidor de
# un cliente se usa el modo por defecto: no se crean bases en la instancia que
# sostiene el registro legal.
#
# CODIGOS DE SALIDA. Tabla comun de lib/exit-codes.sh. Aqui significan:
#
#   0  El simulacro pasa: la copia se restaura y supera las comprobaciones.
#   1  Uso incorrecto. Nada tocado.
#   2  Requisitos no cumplidos: falta Docker, la imagen, el contenedor no
#      arranca, o falta una herramienta. NADA de la instalacion se ha tocado.
#   3  No hay ninguna copia sobre la que ensayar.
#   7  GARANTIA DE SEGURIDAD ROTA (solo en `--mode database`, A3-01): la copia,
#      al restaurarse en el PostgreSQL configurado, ha cambiado atributos o
#      pertenencias de rol del cluster. Se han intentado revertir. La copia esta
#      manipulada: ver docs/runbooks/rotacion-secretos.md. En el modo por
#      defecto el contenedor es de usar y tirar y sus roles no importan.
#   6  EL SIMULACRO FALLA: la copia no supera la comprobacion de integridad (MAC,
#      huella, nombre, manifiesto), no se descifra o no se puede restaurar; o, en
#      pitr, la recuperacion se ha abortado por un segmento de WAL alterado o
#      ausente. Es el resultado que importa: significa que hoy no se podria
#      recuperar el registro horario. Ver docs/runbooks/restaurar-backup.md.

set -euo pipefail
IFS=$'\n\t'

# En Git Bash (MSYS) sobre Windows, un argumento como `/tmp/copia.dump` se
# reescribe a `C:/Users/.../Temp/copia.dump` ANTES de llegar a `docker exec`,
# y pg_restore dentro del contenedor no lo encuentra. La variable desactiva
# esa conversion y en Linux no hace nada: el simulacro es el mismo en el
# servidor del cliente, en la CI y en una estacion de desarrollo Windows.
export MSYS_NO_PATHCONV=1

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIR
# La biblioteca comun SI se analiza: `make sh-lint` llama a ShellCheck con -x.
# shellcheck source-path=SCRIPTDIR
# shellcheck source=lib/backup-common.sh disable=SC1091
. "${SCRIPT_DIR}/lib/backup-common.sh"

FICHERO=""
BASE_FISICA=""
FUENTE_WAL=""
IMAGEN="${DRILL_POSTGRES_IMAGE:-}"
MODO="container"
ESPERA=90
CONSERVAR=0
CONTENEDOR=""
BASE_SIMULACRO=""
TRABAJO=""
# INFORME es la ruta FINAL; INFORME_TRABAJO donde se escribe mientras corre (un
# directorio privado), y se PUBLICA al salir como uid 1000 (A3-R2).
INFORME=""
INFORME_TRABAJO=""
ROLES_ANTES=""
INTEGRIDAD=""
MANIFIESTO=""
HUELLA_PRIVADA=""
DRILL_WAL_ENC=0
DRILL_WAL_HEREDADOS=0
# Bandera de copias heredadas: SOLO de la linea de ordenes (--accept-unauthenticated). La
# variable de entorno se heredaria de un perfil de root o de la crontab del simulacro y
# dejaria la puerta abierta en cada ejecucion (C12): se ignora y se avisa.
ACEPTAR_HEREDADA=0
[ -z "${KRONOQR_ACCEPT_UNAUTHENTICATED:-}" ] || printf '%s AVISO: se ignora KRONOQR_ACCEPT_UNAUTHENTICATED; usa la opcion --accept-unauthenticated en esta orden.\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" >&2

al_salir() {
  if [ -n "$CONTENEDOR" ] && [ "$CONSERVAR" -eq 0 ]; then
    docker rm -f "$CONTENEDOR" >/dev/null 2>&1 || true
  elif [ -n "$CONTENEDOR" ]; then
    log "Contenedor conservado: ${CONTENEDOR}. Destruyelo con 'docker rm -f ${CONTENEDOR}' cuando acabes."
  fi
  if [ "$MODO" = "database" ] && [ -n "$BASE_SIMULACRO" ] && [ "$CONSERVAR" -eq 0 ]; then
    psql -d postgres -Atqc "DROP DATABASE IF EXISTS \"${BASE_SIMULACRO}\"" >/dev/null 2>&1 || true
  fi
  [ -z "$ROLES_ANTES" ] || rm -f "$ROLES_ANTES"
  if [ -n "$INFORME_TRABAJO" ]; then
    kq_report_publish "$INFORME_TRABAJO" "$INFORME"
    INFORME_TRABAJO=""
  fi
  kqe_forget
  [ -z "$TRABAJO" ] || [ ! -d "$TRABAJO" ] || rm -rf "$TRABAJO"
  return 0
}

trap al_salir EXIT

uso() {
  # La cabecera entera, sin numeros de linea que mantener sincronizados: se
  # imprime desde la segunda linea hasta la primera que no sea un comentario.
  # Un rango fijo se desajusta en cuanto alguien anade un parrafo -- paso de
  # verdad al reescribir las cabeceras en la tarea 5.4 -- y el sintoma es una
  # ayuda cortada a la mitad.
  awk 'NR > 1 && !/^#/ { exit } NR > 1' "${BASH_SOURCE[0]}" | sed 's/^#\{1,2\} \{0,1\}//'
}

informar() {
  log "$*"
  [ -z "$INFORME_TRABAJO" ] || printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*" >>"$INFORME_TRABAJO"
  return 0
}

#------------------------------------------------------------------------------
# Metrica del simulacro (doc 02 §8.2, seccion de respaldo)
#------------------------------------------------------------------------------

metricas_del_simulacro() {
  local resultado="$1" duracion="$2" tablas="$3" filas="$4"
  local exito_ts=0
  [ "$resultado" -eq 1 ] && exito_ts="$(now_epoch)"

  {
    cat <<EOF
# HELP kronoqr_backup_restore_drill_last_result Resultado del ultimo simulacro de restauracion: 1 correcto, 0 fallido.
# TYPE kronoqr_backup_restore_drill_last_result gauge
kronoqr_backup_restore_drill_last_result ${resultado}
# HELP kronoqr_backup_restore_drill_last_success_timestamp_seconds Momento del ultimo simulacro correcto (RNF-D-05: trimestral).
# TYPE kronoqr_backup_restore_drill_last_success_timestamp_seconds gauge
kronoqr_backup_restore_drill_last_success_timestamp_seconds ${exito_ts}
# HELP kronoqr_backup_restore_drill_duration_seconds Duracion del ultimo simulacro; alimenta la estimacion del RTO.
# TYPE kronoqr_backup_restore_drill_duration_seconds gauge
kronoqr_backup_restore_drill_duration_seconds ${duracion}
# HELP kronoqr_backup_restore_drill_tables Tablas comprobadas en el ultimo simulacro.
# TYPE kronoqr_backup_restore_drill_tables gauge
kronoqr_backup_restore_drill_tables ${tablas}
# HELP kronoqr_backup_restore_drill_rows Filas restauradas y contadas en el ultimo simulacro.
# TYPE kronoqr_backup_restore_drill_rows gauge
kronoqr_backup_restore_drill_rows ${filas}
EOF
    if [ "$MODO" = "pitr" ]; then
      cat <<EOF
# HELP kronoqr_backup_restore_drill_pitr_wal_segments Segmentos de WAL cifrados reproducidos por el ultimo simulacro pitr.
# TYPE kronoqr_backup_restore_drill_pitr_wal_segments gauge
kronoqr_backup_restore_drill_pitr_wal_segments ${DRILL_WAL_ENC}
# HELP kronoqr_backup_restore_drill_pitr_legacy_wal_segments Segmentos heredados (2.1.0, sin autenticar) reproducidos por el ultimo simulacro pitr.
# TYPE kronoqr_backup_restore_drill_pitr_legacy_wal_segments gauge
kronoqr_backup_restore_drill_pitr_legacy_wal_segments ${DRILL_WAL_HEREDADOS}
EOF
    fi
  } | write_metrics "${BACKUP_DIR_METRICS}/kronoqr_backup_drill.prom"
}

#------------------------------------------------------------------------------
# Destino del simulacro
#------------------------------------------------------------------------------

levantar_contenedor_limpio() {
  local esperado=0 clave

  require_cmd docker docker
  docker info >/dev/null 2>&1 || die "${KQ_EXIT_REQUIREMENTS}" \
    "Docker no responde. El simulacro necesita levantar un contenedor limpio. Si este equipo no tiene Docker, usa '--mode database' contra una instancia de pruebas. No se ha tocado nada."

  # Contraseña de usar y tirar para un contenedor que vive minutos y no publica
  # ningun puerto. No se imprime ni se guarda.
  clave="$(openssl rand -hex 16)"
  CONTENEDOR="kronoqr-drill-$(timestamp_utc)"
  BASE_SIMULACRO="drill"

  informar "Levantando contenedor limpio ${CONTENEDOR} (${IMAGEN})"
  docker run --detach --name "$CONTENEDOR" \
    --env POSTGRES_PASSWORD="$clave" \
    --env POSTGRES_DB="$BASE_SIMULACRO" \
    --env POSTGRES_INITDB_ARGS=--encoding=UTF8 \
    --env TZ=UTC --env PGTZ=UTC \
    --network none \
    "$IMAGEN" >/dev/null || die "${KQ_EXIT_REQUIREMENTS}" \
    "no se ha podido crear el contenedor del simulacro con la imagen '${IMAGEN}'. Descargala antes ('docker pull ${IMAGEN}') si el servidor no tiene salida a internet."

  # `--network none`: el contenedor del simulacro no habla con nadie. Todo
  # entra y sale por `docker exec`.
  PSQL_CMD=(docker exec -i "$CONTENEDOR" psql -U postgres)

  while [ "$esperado" -lt "$ESPERA" ]; do
    if docker exec "$CONTENEDOR" pg_isready -U postgres -d "$BASE_SIMULACRO" >/dev/null 2>&1; then
      informar "Contenedor listo en ${esperado} s."
      return 0
    fi
    sleep 2
    esperado=$((esperado + 2))
  done

  die "${KQ_EXIT_REQUIREMENTS}" "el contenedor del simulacro no ha arrancado en ${ESPERA} s. Mira 'docker logs ${CONTENEDOR}'. No se ha tocado la instalacion."
}

crear_base_de_simulacro() {
  require_cmd psql postgresql17-client
  require_cmd pg_restore postgresql17-client
  psql -Atqc 'SELECT 1' >/dev/null 2>&1 || die "${KQ_EXIT_REQUIREMENTS}" \
    "no se puede conectar a PostgreSQL en ${PGHOST}:${PGPORT}. El modo 'database' necesita una instancia de pruebas donde crear la base del simulacro."

  BASE_SIMULACRO="kronoqr_drill_$(timestamp_utc)"
  # Foto de los roles del cluster ANTES de restaurar (A3-01): en este modo
  # pg_restore corre contra un cluster REAL, como el rol que este configurado.
  ROLES_ANTES="$(mktemp "${TMPDIR:-/tmp}/kronoqr-drill-roles.XXXXXX")"
  kq_roles_snapshot "$ROLES_ANTES"
  informar "Creando base limpia ${BASE_SIMULACRO}"
  psql -d postgres -Atqc "CREATE DATABASE \"${BASE_SIMULACRO}\"" >/dev/null || die "${KQ_EXIT_REQUIREMENTS}" \
    "no se ha podido crear la base del simulacro. El usuario ${PGUSER} necesita CREATEDB."
}

# Descifra la COPIA PRIVADA (la que ya se ha verificado) segun su formato.
#   descifrar_copia dump|base
descifrar_copia() {
  if [ "$INTEGRIDAD" = "authenticated" ]; then
    kqe_decrypt_copy
  else
    kqe_decrypt_legacy_copy "$1"
  fi
}

restaurar_en_destino() {
  if [ "$MODO" = "container" ]; then
    # El volcado descifrado entra por la entrada estandar del contenedor y
    # muere con el: el texto en claro no toca el disco del servidor.
    descifrar_copia dump |
      docker exec -i "$CONTENEDOR" sh -c 'cat > /tmp/copia.dump' || die "${KQ_EXIT_VERIFY_FAILED}" \
      "no se ha podido descifrar '${FICHERO}' con la clave actual. Si la clave se roto, el simulacro debe usar la que corresponda a esta copia (BACKUP_ENCRYPTION_KEY_PREVIOUS)."
    docker exec "$CONTENEDOR" pg_restore --username=postgres --dbname="$BASE_SIMULACRO" \
      --no-owner --no-privileges --exit-on-error /tmp/copia.dump >>"${INFORME_TRABAJO:-/dev/null}" 2>&1 || return 1
  else
    # El volcado descifrado vive en el directorio privado 0700 y se borra al salir.
    descifrar_copia dump >"${TRABAJO}/drill.dump" || die "${KQ_EXIT_VERIFY_FAILED}" \
      "no se ha podido descifrar '${FICHERO}' con la clave actual."
    pg_restore --dbname="$BASE_SIMULACRO" --no-owner --no-privileges --exit-on-error \
      "${TRABAJO}/drill.dump" >>"${INFORME_TRABAJO:-/dev/null}" 2>&1 || {
      rm -f "${TRABAJO}/drill.dump"
      return 1
    }
    rm -f "${TRABAJO}/drill.dump"
  fi
  return 0
}

# A3-01. Si el `pg_restore` de `--mode database` ha cambiado roles del cluster,
# el simulacro FALLA con el codigo de seguridad: no es un fallo de la copia, es
# una copia que intenta escalar privilegios. Publica el resultado como fallido.
guardar_roles() {
  local resultado=0 detalle duracion

  kq_roles_unchanged "$ROLES_ANTES" \
    psql -d postgres -Atqc "DROP DATABASE IF EXISTS \"${BASE_SIMULACRO}\"" || resultado=$?
  [ "$resultado" -ne 0 ] || return 0

  if [ "$resultado" -eq 1 ]; then
    detalle="Se han devuelto a su estado anterior y se ha comprobado."
  else
    detalle="NO se han podido devolver a su estado anterior: revisa AHORA 'SELECT rolname, rolsuper, rolcreaterole, rolcreatedb, rolbypassrls, rolreplication FROM pg_roles' y corrige a mano los que difieran de los de arriba."
  fi
  duracion="$(($(now_epoch) - ${INICIO_SIMULACRO:-$(now_epoch)}))"
  metricas_del_simulacro 0 "$duracion" 0 0
  informar "SEGURIDAD: la copia ha cambiado roles del cluster. ${detalle}"
  die "${KQ_EXIT_SECURITY}" "la copia '${FICHERO}' ha cambiado roles del cluster al restaurarse en el modo 'database' (arriba, lo que ha cambiado). ${detalle} La copia esta manipulada o no es de este producto: no la uses, avisa al responsable de seguridad y sigue docs/runbooks/rotacion-secretos.md."
}

#------------------------------------------------------------------------------
# Las dos comprobaciones que dan sentido al simulacro
#------------------------------------------------------------------------------

# Integridad referencial, de forma generica: se recrea cada clave ajena. Crear
# una clave ajena obliga a PostgreSQL a validarla contra los datos, asi que
# esto comprueba TODAS las relaciones de la copia sin conocer el esquema.
#
# Se ejecuta dentro de una transaccion que se deshace: no altera ni siquiera la
# copia de usar y tirar.
comprobar_integridad_referencial() {
  local sql claves salida

  claves="$(psql_q "$BASE_SIMULACRO" "SELECT count(*) FROM pg_constraint WHERE contype = 'f'" | tr -d '[:space:]')"
  informar "Claves ajenas a validar: ${claves:-0}"
  [ "${claves:-0}" -gt 0 ] || return 0

  # Se quita el `NOT VALID` de la definicion a proposito. Una clave ajena
  # marcada como no validada es una promesa que PostgreSQL no ha comprobado
  # nunca, y recrearla igual dejaria pasar precisamente las filas huerfanas que
  # este simulacro busca. Aqui se exige que los datos restaurados satisfagan
  # TODAS las relaciones declaradas, validadas o no.
  sql="$(psql_q "$BASE_SIMULACRO" "
    SELECT string_agg(
      format('ALTER TABLE %s DROP CONSTRAINT %I; ALTER TABLE %s ADD CONSTRAINT %I %s;',
             conrelid::regclass, conname, conrelid::regclass, conname,
             replace(pg_get_constraintdef(oid), ' NOT VALID', '')),
      E'\n' ORDER BY conname)
    FROM pg_constraint WHERE contype = 'f'")"

  if ! salida="$(printf 'BEGIN;\n%s\nROLLBACK;\n' "$sql" |
    "${PSQL_CMD[@]}" -d "$BASE_SIMULACRO" -v ON_ERROR_STOP=1 2>&1)"; then
    err "Integridad referencial: FALLA."
    err "$salida"
    return 1
  fi
  informar "Integridad referencial: correcta (${claves} claves ajenas validadas contra los datos)."
  return 0
}

comprobar_conteos() {
  local manifiesto estables tablas filas

  manifiesto="$MANIFIESTO"
  tablas="$(psql_q "$BASE_SIMULACRO" "SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.relkind = 'r' AND n.nspname NOT IN ('pg_catalog', 'information_schema') AND n.nspname !~ '^pg_'" | tr -d '[:space:]')"
  informar "Tablas en la copia restaurada: ${tablas:-0}"
  DRILL_TABLAS="${tablas:-0}"

  # Cero tablas no es un fallo del simulacro —la copia reproduce fielmente lo
  # que habia— pero si es algo que hay que mirar antes de dar por buena una
  # instalacion en marcha.
  [ "${tablas:-0}" -gt 0 ] || err "AVISO: la copia no contiene ninguna tabla. Si esta instalacion ya esta en produccion, esto es un incidente: comprueba que backup.sh se conecta a la base correcta (DB_DATABASE)."

  if [ -z "$manifiesto" ] || [ ! -f "$manifiesto" ]; then
    err "No hay manifiesto para '${FICHERO}': no se pueden comparar conteos. El simulacro NO puede darse por bueno."
    return 1
  fi

  filas="$(manifest_counts "$manifiesto" | awk -F'|' '{s += $2} END {print s + 0}')"
  DRILL_FILAS="$filas"

  estables="$(manifest_stable_tables "$manifiesto" | grep -c . || true)"
  informar "Conteos del manifiesto: ${filas} filas en ${tablas} tablas, ${estables} de ellas exigibles exactas."
  compare_table_counts "$BASE_SIMULACRO" "$manifiesto"
}

#------------------------------------------------------------------------------
# --mode pitr: copia fisica + WAL archivado y CIFRADO (RNF-D-02, ADR-049)
#------------------------------------------------------------------------------

# La imagen de postgres DEL PRODUCTO: trae `kronoqr-restore-wal` y el formato KQE1.
imagen_pitr() {
  [ -z "$IMAGEN" ] || {
    printf '%s' "$IMAGEN"
    return 0
  }
  [ -n "${IMAGE_TAG:-}" ] || die "${KQ_EXIT_REQUIREMENTS}" \
    "el modo pitr usa la imagen de postgres del producto y no se sabe cual: pon IMAGE_TAG en el .env o pasa --image <imagen> (por ejemplo ghcr.io/kronoqr/postgres:<version>). No se ha tocado nada."
  printf '%s/postgres:%s' "${IMAGE_REGISTRY:-ghcr.io/kronoqr}" "$IMAGE_TAG"
}

simulacro_pitr() {
  local base esperado=0 recuperando="" usuario imagen log_pg segmento abortada=0

  require_cmd docker docker
  docker info >/dev/null 2>&1 || die "${KQ_EXIT_REQUIREMENTS}" \
    "Docker no responde. El simulacro pitr necesita levantar un contenedor limpio. No se ha tocado nada."
  imagen="$(imagen_pitr)"
  docker image inspect "$imagen" >/dev/null 2>&1 || die "${KQ_EXIT_REQUIREMENTS}" \
    "no esta la imagen '${imagen}'. Descargala ('docker pull ${imagen}') o indica otra con --image. No se ha tocado nada."
  kq_wal_key_ensure || die "${KQ_EXIT_REQUIREMENTS}" \
    "no se puede obtener la clave del WAL: define BACKUP_ENCRYPTION_KEY (de la que se deriva) o BACKUP_WAL_KEY. No se ha tocado nada."

  base="$BASE_FISICA"
  [ -n "$base" ] || base="$(find "$BACKUP_DIR_BASE" -maxdepth 1 -type f -name "${BACKUP_PREFIX}-base-*.tar.gz.enc" 2>/dev/null | sort | tail -n 1)"
  [ -n "$base" ] && [ -f "$base" ] || die "${KQ_EXIT_STATE_CONFLICT}" \
    "no hay ninguna copia fisica en '${BACKUP_DIR_BASE}': sin ella el WAL no reconstruye nada. Lanza 'backup.sh run --mode base'."
  [ -n "$FUENTE_WAL" ] || FUENTE_WAL="${BACKUP_PATH}/wal"
  case "$FUENTE_WAL" in
  /* | ?:*) [ -d "$FUENTE_WAL" ] || die "${KQ_EXIT_REQUIREMENTS}" "no existe el archivo de WAL '${FUENTE_WAL}'. Indica --wal-source." ;;
  esac

  # La copia fisica se abre IGUAL que un volcado (kq_open_copy): MAC, huella obligatoria, nombre.
  kq_open_copy base "$base" "$TRABAJO" "$ACEPTAR_HEREDADA" "${KQ_EXIT_VERIFY_FAILED}"
  if [ "$INTEGRIDAD" = "authenticated" ]; then
    informar "Copia fisica AUTENTICADA (KQE1, kid ${KQE_KID}), creada el ${KQE_CREATED} segun su cabecera."
  else
    informar "Copia fisica heredada de la 2.1.0, SIN autenticar (aceptada con --accept-unauthenticated)."
  fi

  CONTENEDOR="kronoqr-drill-pitr-$(timestamp_utc)"
  # La clave del WAL va por entorno, SIN valor en la linea de ordenes (C7). El WAL
  # entra en solo lectura. Red cerrada: todo entra y sale por `docker exec`.
  informar "Levantando contenedor limpio ${CONTENEDOR} (${imagen}) con el archivo de WAL en solo lectura"
  docker run --detach --name "$CONTENEDOR" --network none \
    -e BACKUP_WAL_KEY -e BACKUP_WAL_KEY_PREVIOUS -e KRONOQR_WAL_ARCHIVE_DIR=/wal -e KRONOQR_RESTORE_STATS=/tmp/stats \
    -e "KRONOQR_ACCEPT_LEGACY_WAL=${ACEPTAR_HEREDADA}" \
    -v "${FUENTE_WAL}:/wal:ro" \
    --entrypoint sleep "$imagen" infinity >/dev/null || die "${KQ_EXIT_REQUIREMENTS}" \
    "no se ha podido crear el contenedor del simulacro con la imagen '${imagen}'."
  docker exec "$CONTENEDOR" sh -c 'mkdir -m 0700 /tmp/pgdata /tmp/stats' ||
    die "${KQ_EXIT_REQUIREMENTS}" "no se ha podido preparar el contenedor del simulacro."

  informar "Desplegando la copia fisica verificada"
  if ! { descifrar_copia base | docker exec -i "$CONTENEDOR" tar -xzf - -C /tmp/pgdata; }; then
    die "${KQ_EXIT_VERIFY_FAILED}" "no se ha podido desplegar '${base}' (descifrado o tar). La copia fisica no sirve."
  fi
  kqe_forget
  docker exec "$CONTENEDOR" sh -c ': > /tmp/pgdata/recovery.signal'

  # `postgres` directamente, con cada opcion como argumento propio (sin pasar por
  # una cadena de shell). La clave del WAL NO esta en ninguna opcion.
  usuario="${DB_MIGRATION_USERNAME:-fichaje_migrator}"
  BASE_SIMULACRO="${DB_DATABASE:-fichaje}"
  informar "Arrancando en recuperacion: restore_command=kronoqr-restore-wal, hasta el final del WAL, y promocion"
  docker exec -d "$CONTENEDOR" sh -c 'exec "$@" >/tmp/pg.log 2>&1' sh \
    postgres -D /tmp/pgdata -c port=5432 -c listen_addresses= -c unix_socket_directories=/tmp \
    -c "restore_command=kronoqr-restore-wal %f %p" -c recovery_target_action=promote \
    -c archive_mode=off -c archive_command=

  while [ "$esperado" -lt "$ESPERA" ]; do
    recuperando="$(docker exec "$CONTENEDOR" psql -h /tmp -U "$usuario" -d postgres -Atqc 'SELECT pg_is_in_recovery()' 2>/dev/null | tr -d '[:space:]' || true)"
    [ "$recuperando" != "f" ] || break
    # Si el servidor ha MUERTO, la recuperacion se ha abortado (exit 200 del restore_command).
    if ! docker exec "$CONTENEDOR" sh -c '[ -f /tmp/pgdata/postmaster.pid ] && kill -0 "$(head -n 1 /tmp/pgdata/postmaster.pid)"' >/dev/null 2>&1; then
      abortada=1
      break
    fi
    sleep 2
    esperado=$((esperado + 2))
  done

  log_pg="$(docker exec "$CONTENEDOR" cat /tmp/pg.log 2>/dev/null || true)"
  DRILL_WAL_ENC="$(docker exec "$CONTENEDOR" sh -c 'ls /tmp/stats 2>/dev/null | grep -c "^enc\." || true' | tr -d '[:space:]')"
  DRILL_WAL_HEREDADOS="$(docker exec "$CONTENEDOR" sh -c 'ls /tmp/stats 2>/dev/null | grep -c "^legacy\." || true' | tr -d '[:space:]')"
  [[ "$DRILL_WAL_ENC" =~ ^[0-9]+$ ]] || DRILL_WAL_ENC=0
  [[ "$DRILL_WAL_HEREDADOS" =~ ^[0-9]+$ ]] || DRILL_WAL_HEREDADOS=0

  if [ "$recuperando" != "f" ]; then
    # Aborto o no promocion: se recogen SOLO las lineas del restore_command y los
    # FATAL (nombres de segmento y motivos; ni datos ni secretos) al informe.
    segmento="$(printf '%s\n' "$log_pg" | sed -n "s/.*kronoqr-restore-wal: ERROR en el segmento '\([^']*\)'.*/\1/p" | head -n 1)"
    printf '%s\n' "$log_pg" | grep -E "kronoqr-restore-wal|FATAL" | grep -v "is starting up" | head -n 20 | while IFS= read -r linea; do
      informar "postgres: ${linea}"
    done
    if [ -n "$segmento" ]; then
      informar "RECUPERACION ABORTADA en el segmento ${segmento}: wal_integrity=aborted_at:${segmento}"
      err "La recuperacion se ha detenido a proposito en el segmento ${segmento}: el WAL no es de fiar desde ahi. Ver docs/runbooks/restaurar-backup.md §4.1."
    elif [ "$abortada" -eq 1 ]; then
      informar "El servidor de recuperacion ha terminado sin promocionar (ver las lineas de arriba)."
    else
      informar "No se ha completado la recuperacion en ${ESPERA} s."
    fi
    return 1
  fi

  informar "Recuperacion completada: wal_integrity=authenticated, legacy_wal=${DRILL_WAL_HEREDADOS}, segmentos cifrados reproducidos=${DRILL_WAL_ENC}"
  if [ "$DRILL_WAL_HEREDADOS" -gt 0 ]; then
    err "AVISO: se han reproducido ${DRILL_WAL_HEREDADOS} segmentos heredados de la 2.1.0 (sin cifrar ni autenticar)."
  fi
  if [ "$((DRILL_WAL_ENC + DRILL_WAL_HEREDADOS))" -lt 1 ]; then
    err "El simulacro NO ha ejercitado el WAL: no hay segmentos posteriores a la copia fisica. Repite cuando haya WAL archivado."
    return 1
  fi

  PSQL_CMD=(docker exec -i "$CONTENEDOR" psql -h /tmp -U "$usuario")
  DRILL_TABLAS="$(psql_q "$BASE_SIMULACRO" "SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.relkind = 'r' AND n.nspname NOT IN ('pg_catalog', 'information_schema') AND n.nspname !~ '^pg_'" | tr -d '[:space:]')"
  informar "Tablas en la base recuperada: ${DRILL_TABLAS:-0}"
  comprobar_integridad_referencial
}

#------------------------------------------------------------------------------

main() {
  local inicio duracion resultado=0

  while [ $# -gt 0 ]; do
    case "$1" in
    --file)
      FICHERO="${2:-}"
      shift 2
      ;;
    --file=*)
      FICHERO="${1#*=}"
      shift
      ;;
    --base)
      BASE_FISICA="${2:-}"
      shift 2
      ;;
    --base=*)
      BASE_FISICA="${1#*=}"
      shift
      ;;
    --wal-source)
      FUENTE_WAL="${2:-}"
      shift 2
      ;;
    --wal-source=*)
      FUENTE_WAL="${1#*=}"
      shift
      ;;
    --image)
      IMAGEN="${2:-}"
      shift 2
      ;;
    --image=*)
      IMAGEN="${1#*=}"
      shift
      ;;
    --mode)
      MODO="${2:-}"
      shift 2
      ;;
    --mode=*)
      MODO="${1#*=}"
      shift
      ;;
    --timeout)
      ESPERA="${2:-90}"
      shift 2
      ;;
    --timeout=*)
      ESPERA="${1#*=}"
      shift
      ;;
    --keep)
      CONSERVAR=1
      shift
      ;;
    --accept-unauthenticated)
      ACEPTAR_HEREDADA=1
      shift
      ;;
    -h | --help)
      uso
      return 0
      ;;
    *) die "${KQ_EXIT_USAGE}" "argumento desconocido '$1'. Ejecuta 'restore-drill.sh --help'." ;;
    esac
  done

  case "$MODO" in
  container | database | pitr) ;;
  *) die "${KQ_EXIT_USAGE}" "modo '${MODO}' desconocido. Usa --mode container (por defecto), --mode database o --mode pitr." ;;
  esac

  load_backup_config
  require_cmd openssl openssl
  require_encryption_key
  ensure_backup_tree reports metrics
  [ -n "$IMAGEN" ] || [ "$MODO" = "pitr" ] || IMAGEN="postgres:17-alpine"

  TRABAJO="$(mktemp -d "${TMPDIR:-/tmp}/kronoqr-drill.XXXXXX")"
  chmod 0700 "$TRABAJO"
  DRILL_TABLAS=0
  DRILL_FILAS=0

  INFORME="${BACKUP_DIR_REPORTS}/drill-$(timestamp_utc).log"
  INFORME_TRABAJO="${TRABAJO}/informe.log"
  : >"$INFORME_TRABAJO"

  inicio="$(now_epoch)"
  INICIO_SIMULACRO="$inicio"

  if [ "$MODO" = "pitr" ]; then
    informar "Simulacro de restauracion (RNF-D-05), modo pitr: copia fisica + WAL archivado."
    simulacro_pitr || resultado=1
  else
    [ -n "$FICHERO" ] || FICHERO="$(latest_dump_file)"
    [ -n "$FICHERO" ] && [ -f "$FICHERO" ] || die "${KQ_EXIT_STATE_CONFLICT}" \
      "no hay ninguna copia sobre la que hacer el simulacro. Lanza 'backup.sh run' primero."

    # UNA lectura a un directorio privado; todo lo demas sobre esa copia (ADR-049).
    kq_open_copy dump "$FICHERO" "$TRABAJO" "$ACEPTAR_HEREDADA" "${KQ_EXIT_VERIFY_FAILED}"
    informar "Simulacro de restauracion (RNF-D-05) sobre '${FICHERO}', modo ${MODO}."
    if [ "$INTEGRIDAD" = "authenticated" ]; then
      informar "Copia AUTENTICADA (KQE1, kid ${KQE_KID}), creada el ${KQE_CREATED} segun su cabecera."
    else
      informar "Copia heredada de la 2.1.0, SIN autenticar (aceptada con --accept-unauthenticated)."
    fi

    if [ "$MODO" = "container" ]; then
      levantar_contenedor_limpio
    else
      crear_base_de_simulacro
    fi

    if ! restaurar_en_destino; then
      resultado=1
      err "La restauracion en el destino limpio ha fallado. Revisa '${INFORME}'."
    fi
    kqe_forget

    # A3-01, solo en `--mode database`: el archivo se ha ejecutado contra un
    # cluster real. Se mira aunque la restauracion haya fallado.
    if [ "$MODO" = "database" ]; then
      guardar_roles
    fi

    if [ "$resultado" -eq 0 ]; then
      comprobar_integridad_referencial || resultado=1
      comprobar_conteos || resultado=1
    fi
  fi

  duracion="$(($(now_epoch) - inicio))"

  if [ "$resultado" -eq 0 ]; then
    metricas_del_simulacro 1 "$duracion" "$DRILL_TABLAS" "$DRILL_FILAS"
    informar "SIMULACRO CORRECTO en ${duracion} s. La copia restaura y los datos cuadran."
    informar "Informe: ${INFORME}. Adjuntalo al registro trimestral (RNF-D-05, RQ-09)."
    return 0
  fi

  metricas_del_simulacro 0 "$duracion" "$DRILL_TABLAS" "$DRILL_FILAS"
  informar "SIMULACRO FALLIDO en ${duracion} s."
  die "${KQ_EXIT_VERIFY_FAILED}" "el simulacro ha fallado: la ultima copia NO se puede dar por buena. Revisa '${INFORME}', repitelo con la copia anterior ('--file') y sigue docs/runbooks/restaurar-backup.md."
}

main "$@"
