#!/usr/bin/env bash
#
# KronoQR — copia de seguridad cifrada y verificada (RF-PR-04, RNF-D-02, RL-12).
#
# Una copia no verificada no es una copia. Por eso `run` termina verificando lo
# que acaba de escribir, y por eso `verify` descifra el fichero entero y se lo
# da a `pg_restore --list`: comprobar que el fichero existe y pesa algo no
# demuestra nada el dia que hace falta.
#
# Que produce, dentro de BACKUP_PATH (configuracion, regla dura 13):
#
#   daily/kronoqr-<UTC>.dump.enc         volcado logico cifrado (pg_dump -Fc)
#   daily/kronoqr-<UTC>.dump.enc.sha256  huella del fichero cifrado
#   daily/kronoqr-<UTC>.manifest.json    conteos por tabla, LSN y metadatos
#   daily/LATEST                         nombre de la ultima copia verificada
#   base/kronoqr-base-<UTC>.tar.gz.enc   copia FISICA (pg_basebackup), --mode base
#   metrics/kronoqr_backup_*.prom        resultado para Prometheus (§8.2)
#
# Por que hay dos tipos de copia y no una:
#
#   · El volcado logico restaura la base en cualquier servidor y es lo que
#     valida el simulacro trimestral (restore-drill.sh).
#   · El RPO de 15 minutos (RNF-D-02) NO lo da un volcado: solo se alcanza
#     reproduciendo el WAL archivado sobre una copia FISICA. Sin `--mode base`
#     periodico, el WAL archivado no sirve para recuperar a un punto en el
#     tiempo y la promesa de 15 minutos es falsa.
#
# La copia se queda en la infraestructura del cliente (regla dura 16, RL-14).
# Este script no envia nada a ninguna parte: escribe en un directorio local.
#
# Uso:
#   backup.sh run [--mode dump|base|full] [--skip-verify] [--keep-going]
#   backup.sh verify [--file RUTA]
#   backup.sh prune
#   backup.sh list
#
# Ejemplos:
#   backup.sh run                       copia diaria, cifrada y verificada
#   backup.sh run --mode full           volcado + copia fisica (semanal)
#   backup.sh verify                    verifica la ultima copia
#
# Configuracion (entorno o BACKUP_ENV_FILE; nada de esto vive en el codigo):
#   BACKUP_PATH               destino. Por defecto /var/backups/fichaje
#   BACKUP_ENCRYPTION_KEY     clave de cifrado. Obligatoria (RL-12)
#   BACKUP_RETENTION_DAYS     dias que se conservan las copias. Por defecto 30
#   BACKUP_MIN_COPIES         copias que nunca se borran. Por defecto 3
#   DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD
#   BACKUP_DB_USERNAME/BACKUP_DB_PASSWORD  usuario alternativo para el volcado
#   BACKUP_DUMP_COMMAND       orden que produce el volcado. Solo la cambian el
#                             simulacro y las pruebas; por defecto `pg_dump`
#
# Codigos de salida: 0 correcto · 1 la copia o su verificacion han fallado ·
# 2 error de uso · 3 falta una herramienta o precondicion (nada tocado) ·
# 4 destino no escribible o sin espacio (nada tocado) · 5 clave ausente o
# incorrecta.
#
# NINGUN SECRETO EN LA SALIDA: la clave no se imprime, no viaja por argv y no
# aparece en los informes ni en las metricas.

set -euo pipefail
IFS=$'\n\t'

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIR
# La biblioteca comun SI se analiza: `make sh-lint` llama a ShellCheck con -x y
# entra en ella. SC1091 se silencia porque, sin esa bandera, ShellCheck avisa de
# que no puede seguir un fichero que de todas formas esta bajo analisis.
# shellcheck source-path=SCRIPTDIR
# shellcheck source=lib/backup-common.sh disable=SC1091
. "${SCRIPT_DIR}/lib/backup-common.sh"

# Ficheros a medias que hay que barrer si algo falla. Fallo seguro: el destino
# queda como estaba y la copia anterior sigue siendo la buena.
declare -a TEMPORALES=()
# Vale 1 entre el principio y el final de una copia. Si el script muere por el
# camino, el trap publica el fallo como metrica: una copia que se interrumpe y
# no deja rastro es exactamente el fallo que descubre RRHH a fin de mes.
COPIA_EN_CURSO=0
COPIA_MODO="dump"

al_salir() {
  local codigo="$?" f
  for f in "${TEMPORALES[@]+"${TEMPORALES[@]}"}"; do
    rm -f "$f" 2>/dev/null || true
  done
  TEMPORALES=()
  if [ "$COPIA_EN_CURSO" -eq 1 ] && [ "$codigo" -ne 0 ]; then
    metricas_de_copia 0 "$(now_epoch)" 0 0 "$COPIA_MODO" 2>/dev/null || true
  fi
}

trap al_salir EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

uso() {
  sed -n '2,60p' "${BASH_SOURCE[0]}" | sed 's/^#\{1,2\} \{0,1\}//'
}

#------------------------------------------------------------------------------
# Precondiciones. Se comprueban TODAS antes de tocar nada.
#------------------------------------------------------------------------------

comprobar_herramientas() {
  require_cmd openssl openssl
  require_cmd awk awk
  require_cmd df coreutils
  require_cmd find findutils
}

comprobar_cliente_postgres() {
  require_cmd psql postgresql17-client
  require_cmd pg_dump postgresql17-client
  require_cmd pg_restore postgresql17-client
}

comprobar_conexion() {
  psql -Atqc 'SELECT 1' >/dev/null 2>&1 || die 3 \
    "no se puede conectar a la base de datos ${PGDATABASE} en ${PGHOST}:${PGPORT} como ${PGUSER}. Comprueba que el servicio 'postgres' esta levantado (docker compose ps) y que DB_* del .env son correctos. No se ha tocado ninguna copia."
}

# Sin margen no se empieza: una copia que llena el disco deja el servidor sin
# sitio para el WAL, y entonces se para PostgreSQL. Fallar aqui es barato.
comprobar_espacio() {
  local necesario libre
  necesario="${1:-0}"
  libre="$(free_bytes_at "$BACKUP_PATH")"
  if [ "$libre" -lt "$necesario" ]; then
    die 4 "quedan $((libre / 1024 / 1024)) MiB libres en '${BACKUP_PATH}' y la copia necesita al menos $((necesario / 1024 / 1024)) MiB. Libera espacio o baja BACKUP_RETENTION_DAYS. Sigue en pie la ultima copia: no se ha borrado ni sobrescrito nada. Ver docs/runbooks/restaurar-backup.md."
  fi
}

tamano_base_de_datos() {
  psql -Atqc "SELECT pg_database_size(current_database())" 2>/dev/null || echo 0
}

#------------------------------------------------------------------------------
# Metricas (doc 02 §8.2, seccion de respaldo)
#------------------------------------------------------------------------------

metricas_de_copia() {
  local resultado="$1" fin="$2" duracion="$3" tamano="$4" tipo="$5"
  local exito_ts=0
  [ "$resultado" -eq 1 ] && exito_ts="$fin"

  {
    cat <<EOF
# HELP kronoqr_backup_last_result Resultado de la ultima copia: 1 correcta, 0 fallida.
# TYPE kronoqr_backup_last_result gauge
kronoqr_backup_last_result{type="${tipo}"} ${resultado}
# HELP kronoqr_backup_last_success_timestamp_seconds Momento de la ultima copia correcta.
# TYPE kronoqr_backup_last_success_timestamp_seconds gauge
kronoqr_backup_last_success_timestamp_seconds{type="${tipo}"} ${exito_ts}
# HELP kronoqr_backup_last_duration_seconds Duracion de la ultima copia.
# TYPE kronoqr_backup_last_duration_seconds gauge
kronoqr_backup_last_duration_seconds{type="${tipo}"} ${duracion}
# HELP kronoqr_backup_last_size_bytes Tamano del ultimo fichero de copia cifrado.
# TYPE kronoqr_backup_last_size_bytes gauge
kronoqr_backup_last_size_bytes{type="${tipo}"} ${tamano}
# HELP kronoqr_backup_copies_total Copias conservadas ahora mismo en el destino.
# TYPE kronoqr_backup_copies_total gauge
kronoqr_backup_copies_total{type="dump"} $(contar_copias)
EOF
    emit_volume_metrics
    metricas_de_archivado_wal
  } | write_metrics "${BACKUP_DIR_METRICS}/kronoqr_backup_run.prom"
}

# El estado del archivado de WAL se pregunta a PostgreSQL, no al directorio:
# pg_stat_archiver es la fuente autorizada y no exige montar el archivo de WAL
# en el contenedor que hace la copia.
metricas_de_archivado_wal() {
  local fila edad fallos archivados
  fila="$(psql -Atq -F'|' -c "SELECT coalesce(extract(epoch from now() - last_archived_time)::bigint, -1), failed_count, archived_count FROM pg_stat_archiver" 2>/dev/null || true)"
  edad="${fila%%|*}"
  fallos="$(printf '%s' "$fila" | cut -d'|' -f2)"
  archivados="$(printf '%s' "$fila" | cut -d'|' -f3)"
  [ -n "${edad//[^0-9-]/}" ] || edad=-1
  [ -n "${fallos:-}" ] || fallos=0
  [ -n "${archivados:-}" ] || archivados=0

  cat <<EOF
# HELP kronoqr_backup_wal_last_archived_age_seconds Antiguedad del ultimo segmento de WAL archivado; -1 si aun no se ha archivado ninguno.
# TYPE kronoqr_backup_wal_last_archived_age_seconds gauge
kronoqr_backup_wal_last_archived_age_seconds ${edad}
# HELP kronoqr_backup_wal_archive_failures_total Intentos fallidos de archivado de WAL desde el ultimo reinicio de estadisticas.
# TYPE kronoqr_backup_wal_archive_failures_total counter
kronoqr_backup_wal_archive_failures_total ${fallos}
# HELP kronoqr_backup_wal_archived_total Segmentos de WAL archivados desde el ultimo reinicio de estadisticas.
# TYPE kronoqr_backup_wal_archived_total counter
kronoqr_backup_wal_archived_total ${archivados}
EOF
}

metricas_de_verificacion() {
  local resultado="$1" momento="$2" edad="$3"
  local exito_ts=0
  [ "$resultado" -eq 1 ] && exito_ts="$momento"

  write_metrics "${BACKUP_DIR_METRICS}/kronoqr_backup_verify.prom" <<EOF
# HELP kronoqr_backup_last_verify_result Resultado de la ultima verificacion: 1 correcta, 0 fallida.
# TYPE kronoqr_backup_last_verify_result gauge
kronoqr_backup_last_verify_result ${resultado}
# HELP kronoqr_backup_last_verified_timestamp_seconds Momento de la ultima verificacion correcta.
# TYPE kronoqr_backup_last_verified_timestamp_seconds gauge
kronoqr_backup_last_verified_timestamp_seconds ${exito_ts}
# HELP kronoqr_backup_verified_copy_age_seconds Antiguedad de la copia verificada; -1 si no hay copia.
# TYPE kronoqr_backup_verified_copy_age_seconds gauge
kronoqr_backup_verified_copy_age_seconds ${edad}
EOF
}

contar_copias() {
  find "$BACKUP_DIR_DUMP" -maxdepth 1 -type f -name "${BACKUP_PREFIX}-*.dump.enc" 2>/dev/null | wc -l | tr -d ' '
}

#------------------------------------------------------------------------------
# Manifiesto: que hay dentro de la copia, para poder comprobarlo al restaurar
#------------------------------------------------------------------------------

# Conteos exactos por tabla. Solo metadatos: nombres de tabla y numeros. Ni un
# dato personal (regla dura 21).
conteos_por_tabla() {
  local consulta
  consulta="$(psql -Atqc "
    SELECT coalesce(string_agg(
      format('SELECT %L::text AS t, count(*)::bigint AS c FROM %I.%I', n.nspname || '.' || c.relname, n.nspname, c.relname),
      ' UNION ALL ' ORDER BY n.nspname, c.relname), '')
    FROM pg_class c
    JOIN pg_namespace n ON n.oid = c.relnamespace
    WHERE c.relkind = 'r' AND n.nspname NOT IN ('pg_catalog', 'information_schema') AND n.nspname !~ '^pg_'")"

  [ -n "$consulta" ] || return 0
  psql -Atq -F'|' -c "$consulta"
}

# Tablas cuyo conteo no cambio mientras se hacia el volcado.
#
# Por que hace falta esta lista. El volcado se toma de una instantanea
# coherente, pero los conteos se preguntan fuera de ella, asi que en un hotel
# en marcha una diferencia no demuestra que la copia este mal: demuestra que
# alguien ficho mientras se copiaba. Comparando el conteo de ANTES con el de
# DESPUES, tabla por tabla, se sabe cuales no se movieron, y esas SI se pueden
# exigir exactas al restaurar. Las demas solo admiten la comprobacion util: que
# la copia restaurada no tenga MENOS filas de las que habia.
#
# Es la diferencia entre un simulacro que comprueba conteos de verdad y uno que
# los mira de lejos porque el sistema estaba en marcha.
tablas_estables() {
  local antes="$1" despues="$2" tabla filas
  local -A previo=()

  while IFS='|' read -r tabla filas; do
    [ -n "$tabla" ] || continue
    previo["$tabla"]="$filas"
  done <<<"$antes"

  while IFS='|' read -r tabla filas; do
    [ -n "$tabla" ] || continue
    if [ "${previo["$tabla"]-}" = "$filas" ]; then
      printf '%s\n' "$tabla"
    fi
  done <<<"$despues"

  return 0
}

escribir_manifiesto() {
  local destino="$1" fichero="$2" huella="$3" tamano="$4" duracion="$5"
  local lsn_antes="$6" lsn_despues="$7" conteos="$8" estables="$9"
  local consistente="false" tablas=0 primero=1 tmp

  [ "$lsn_antes" = "$lsn_despues" ] && consistente="true"
  tablas="$(printf '%s' "$conteos" | grep -c '|' || true)"

  tmp="${destino}.part"
  TEMPORALES+=("$tmp")
  {
    printf '{\n'
    printf '  "format": "kronoqr-backup/1",\n'
    printf '  "created_at": "%s",\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    printf '  "database": "%s",\n' "$PGDATABASE"
    printf '  "server_version": "%s",\n' "$(psql -Atqc 'SHOW server_version' 2>/dev/null || echo desconocida)"
    printf '  "dump_format": "custom",\n'
    printf '  "cipher": "%s/pbkdf2-sha512",\n' "$BACKUP_CIPHER"
    printf '  "file": "%s",\n' "$fichero"
    printf '  "sha256": "%s",\n' "$huella"
    printf '  "size_bytes": %s,\n' "$tamano"
    printf '  "duration_seconds": %s,\n' "$duracion"
    printf '  "lsn_before": "%s",\n' "$lsn_antes"
    printf '  "lsn_after": "%s",\n' "$lsn_despues"
    printf '  "counts_consistent": %s,\n' "$consistente"
    printf '  "table_count": %s,\n' "$tablas"
    printf '  "row_counts": {\n'
    while IFS='|' read -r tabla filas; do
      [ -n "$tabla" ] || continue
      [ "$primero" -eq 1 ] || printf ',\n'
      primero=0
      printf '    "%s": %s' "$tabla" "$filas"
    done <<<"$conteos"
    [ "$primero" -eq 1 ] || printf '\n'
    printf '  },\n'
    # Tablas que no cambiaron durante el volcado: son las que el simulacro
    # puede exigir EXACTAS. Las demas admiten crecimiento, nunca perdida.
    printf '  "stable_tables": [\n'
    primero=1
    while IFS= read -r tabla; do
      [ -n "$tabla" ] || continue
      [ "$primero" -eq 1 ] || printf ',\n'
      primero=0
      printf '    "%s"' "$tabla"
    done <<<"$estables"
    [ "$primero" -eq 1 ] || printf '\n'
    printf '  ]\n}\n'
  } >"$tmp"
  chmod 0640 "$tmp"
  mv -f "$tmp" "$destino"
}

#------------------------------------------------------------------------------
# run
#------------------------------------------------------------------------------

copia_logica() {
  local marca="$1"
  local fichero="${BACKUP_PREFIX}-${marca}.dump.enc"
  local destino="${BACKUP_DIR_DUMP}/${fichero}"
  local tmp="${destino}.part"
  local inicio fin lsn_antes lsn_despues huella tamano duracion
  local conteos_antes conteos estables

  [ -e "$destino" ] && die 1 \
    "ya existe '${destino}'. No se sobrescribe ninguna copia: espera un segundo y vuelve a lanzarla, o borra esa copia a mano si sabes que sobra."

  inicio="$(now_epoch)"
  lsn_antes="$(psql -Atqc 'SELECT pg_current_wal_lsn()' 2>/dev/null || echo desconocido)"
  # Conteos ANTES del volcado. Se pagan dos pasadas de `count(*)` a proposito:
  # es lo que permite saber, tabla por tabla, cual no se movio mientras se
  # copiaba, y por tanto cual se puede exigir exacta en el simulacro.
  conteos_antes="$(conteos_por_tabla || true)"

  TEMPORALES+=("$tmp")
  log "Volcando ${PGDATABASE} y cifrando en ${fichero}"
  if ! { "${BACKUP_DUMP_COMMAND:-pg_dump}" --format=custom --compress=6 --no-password |
    encrypt_stream; } >"$tmp"; then
    rm -f "$tmp"
    die 1 "ha fallado el volcado o el cifrado. No se ha escrito ninguna copia nueva y la anterior sigue intacta. Revisa el espacio libre en '${BACKUP_PATH}' y los permisos del usuario ${PGUSER} sobre la base ${PGDATABASE}. Ver docs/runbooks/restaurar-backup.md."
  fi

  [ -s "$tmp" ] || {
    rm -f "$tmp"
    die 1 "el volcado ha salido vacio. No se ha tocado la copia anterior. Comprueba que ${PGUSER} puede leer las tablas de ${PGDATABASE}."
  }

  lsn_despues="$(psql -Atqc 'SELECT pg_current_wal_lsn()' 2>/dev/null || echo desconocido)"
  conteos="$(conteos_por_tabla || true)"
  estables="$(tablas_estables "$conteos_antes" "$conteos")"

  # Durabilidad antes de anunciar la copia: sin esto, un corte de corriente
  # deja un fichero con nombre definitivo y contenido a medias.
  sync
  huella="$(sha256_of "$tmp")"
  tamano="$(wc -c <"$tmp" | tr -d ' ')"
  fin="$(now_epoch)"
  duracion="$((fin - inicio))"

  printf '%s  %s\n' "$huella" "$fichero" >"${destino}.sha256.part"
  TEMPORALES+=("${destino}.sha256.part")
  escribir_manifiesto "${BACKUP_DIR_DUMP}/${BACKUP_PREFIX}-${marca}.manifest.json" \
    "$fichero" "$huella" "$tamano" "$duracion" "$lsn_antes" "$lsn_despues" "$conteos" "$estables"

  chmod 0640 "$tmp"
  mv -f "$tmp" "$destino"
  mv -f "${destino}.sha256.part" "${destino}.sha256"
  TEMPORALES=()

  printf '%s\n' "$fichero" >"${BACKUP_LATEST_POINTER}.part"
  mv -f "${BACKUP_LATEST_POINTER}.part" "$BACKUP_LATEST_POINTER"

  log "Copia escrita: ${destino} ($((tamano / 1024)) KiB, ${duracion} s)"
  COPIA_TAMANO="$tamano"
  COPIA_DURACION="$duracion"
  COPIA_FICHERO="$destino"
}

# Copia FISICA. Es la unica que, junto al WAL archivado, permite recuperar a un
# punto en el tiempo y sostener el RPO de 15 minutos (RNF-D-02).
copia_fisica() {
  local marca="$1"
  local destino="${BACKUP_DIR_BASE}/${BACKUP_PREFIX}-base-${marca}.tar.gz.enc"
  local tmp="${destino}.part"

  require_cmd pg_basebackup postgresql17-client
  TEMPORALES+=("$tmp")
  log "Copia fisica con pg_basebackup (necesaria para el RPO de 15 min)"
  if ! { pg_basebackup --format=tar --gzip --compress=6 --wal-method=fetch \
    --checkpoint=fast --no-password --pgdata=- | encrypt_stream; } >"$tmp"; then
    rm -f "$tmp"
    die 1 "ha fallado pg_basebackup. Comprueba que el usuario ${PGUSER} tiene el atributo REPLICATION o es superusuario y que pg_hba.conf admite conexiones de replicacion (infra/docker/postgres/conf/pg_hba.conf). El volcado logico de esta ejecucion, si lo hubo, sigue siendo valido."
  fi

  sync
  printf '%s  %s\n' "$(sha256_of "$tmp")" "$(basename "$destino")" >"${destino}.sha256.part"
  TEMPORALES+=("${destino}.sha256.part")
  chmod 0640 "$tmp"
  mv -f "$tmp" "$destino"
  mv -f "${destino}.sha256.part" "${destino}.sha256"
  TEMPORALES=()
  log "Copia fisica escrita: ${destino}"
}

cmd_run() {
  local modo="dump" verificar=1 marca resultado=0

  while [ $# -gt 0 ]; do
    case "$1" in
    --mode)
      modo="${2:-}"
      shift 2
      ;;
    --mode=*)
      modo="${1#*=}"
      shift
      ;;
    --skip-verify)
      verificar=0
      shift
      ;;
    -h | --help)
      uso
      return 0
      ;;
    *) die 2 "argumento desconocido '$1'. Ejecuta 'backup.sh --help' para ver las opciones." ;;
    esac
  done

  case "$modo" in
  dump | base | full) ;;
  *) die 2 "modo '${modo}' desconocido. Usa --mode dump (diaria), --mode base (fisica) o --mode full (las dos)." ;;
  esac

  # A partir de aqui, cualquier salida distinta de cero publica la copia como
  # fallida (trap `al_salir`). Se marca ANTES de las precondiciones a
  # proposito: una copia que no llega ni a empezar —clave borrada, base
  # inalcanzable, destino sin montar— es exactamente igual de grave que una que
  # falla a medias, y sin esta linea solo se notaria 26 horas despues, cuando
  # caduca la ultima verificacion.
  COPIA_MODO="$modo"
  COPIA_EN_CURSO=1

  comprobar_herramientas
  comprobar_cliente_postgres
  require_encryption_key
  ensure_backup_tree
  comprobar_conexion
  # Margen: el tamano de la base sin comprimir. El volcado comprimido ocupa
  # bastante menos, asi que exigir esto es exigir holgura de verdad.
  comprobar_espacio "$(tamano_base_de_datos)"

  marca="$(timestamp_utc)"
  COPIA_TAMANO=0
  COPIA_DURACION=0
  COPIA_FICHERO=""

  if [ "$modo" = "dump" ] || [ "$modo" = "full" ]; then
    copia_logica "$marca"
  fi
  if [ "$modo" = "base" ] || [ "$modo" = "full" ]; then
    copia_fisica "$marca"
  fi

  cmd_prune

  if [ "$verificar" -eq 1 ] && [ -n "$COPIA_FICHERO" ]; then
    # En subshell a proposito: cmd_verify termina con `die` cuando la copia no
    # pasa, y aqui hace falta recuperar el control para publicar el fallo como
    # metrica antes de salir. Las metricas de verificacion ya las ha escrito.
    (cmd_verify --file "$COPIA_FICHERO") || resultado=$?
    if [ "$resultado" -ne 0 ]; then
      metricas_de_copia 0 "$(now_epoch)" "$COPIA_DURACION" "$COPIA_TAMANO" "$modo"
      COPIA_EN_CURSO=0
      die 1 "la copia se ha escrito pero NO ha superado la verificacion. Tratala como inexistente: la anterior sigue siendo la buena. Ver docs/runbooks/restaurar-backup.md."
    fi
  fi

  metricas_de_copia 1 "$(now_epoch)" "$COPIA_DURACION" "$COPIA_TAMANO" "$modo"
  COPIA_EN_CURSO=0
  log "Copia terminada correctamente (modo ${modo})."
}

#------------------------------------------------------------------------------
# verify — una copia no verificada no es una copia
#------------------------------------------------------------------------------

cmd_verify() {
  local fichero="" huella_guardada huella_actual entradas edad manifiesto

  while [ $# -gt 0 ]; do
    case "$1" in
    --file)
      fichero="${2:-}"
      shift 2
      ;;
    --file=*)
      fichero="${1#*=}"
      shift
      ;;
    -h | --help)
      uso
      return 0
      ;;
    *) die 2 "argumento desconocido '$1'. Uso: backup.sh verify [--file RUTA]." ;;
    esac
  done

  comprobar_herramientas
  comprobar_cliente_postgres
  require_encryption_key
  ensure_backup_tree

  [ -n "$fichero" ] || fichero="$(latest_dump_file)"
  if [ -z "$fichero" ] || [ ! -f "$fichero" ]; then
    metricas_de_verificacion 0 "$(now_epoch)" -1
    die 1 "no hay ninguna copia que verificar en '${BACKUP_DIR_DUMP}'. Lanza 'backup.sh run' o revisa BACKUP_PATH. Ver docs/runbooks/restaurar-backup.md."
  fi

  log "Verificando ${fichero}"

  # 1) Huella del fichero cifrado: detecta corrupcion en disco o en el destino
  #    de red sin necesidad de la clave.
  if [ -f "${fichero}.sha256" ]; then
    huella_guardada="$(cut -d' ' -f1 <"${fichero}.sha256")"
    huella_actual="$(sha256_of "$fichero")"
    if [ "$huella_guardada" != "$huella_actual" ]; then
      metricas_de_verificacion 0 "$(now_epoch)" -1
      die 1 "la huella SHA-256 de '${fichero}' no coincide con la registrada al crearla: el fichero esta corrupto o alguien lo ha modificado. NO lo uses para restaurar. Usa la copia anterior ('backup.sh list') y avisa al responsable de seguridad."
    fi
  else
    err "AVISO: '${fichero}' no tiene fichero .sha256. Se verifica igualmente descifrando, pero no se puede descartar corrupcion silenciosa."
  fi

  # 2) Descifrado completo y lectura del indice del volcado. Esto prueba tres
  #    cosas a la vez: que la clave es la correcta, que el texto cifrado esta
  #    entero y que dentro hay un volcado que pg_restore entiende.
  #
  #    El descifrado va a un temporal de un directorio privado y no al disco de
  #    copias: pg_restore necesita un fichero legible, y el texto en claro no
  #    debe quedar nunca junto a las copias. El trap lo borra pase lo que pase.
  local temporal_dir claro
  temporal_dir="$(mktemp -d "${TMPDIR:-/tmp}/kronoqr-verify.XXXXXX")"
  claro="${temporal_dir}/copia.dump"
  TEMPORALES+=("$claro" "$temporal_dir")

  if ! decrypt_stream <"$fichero" >"$claro" 2>/dev/null; then
    rm -rf "$temporal_dir"
    metricas_de_verificacion 0 "$(now_epoch)" -1
    die 5 "no se ha podido descifrar '${fichero}'. O BACKUP_ENCRYPTION_KEY no es la clave con la que se creo, o el fichero esta dañado. Comprueba la clave del .env; si se roto, la copia solo se abre con la clave anterior. Ver docs/runbooks/restaurar-backup.md."
  fi

  if ! entradas="$(pg_restore --list "$claro" 2>/dev/null | grep -cE '^[0-9]+;' || true)"; then
    entradas=0
  fi
  if ! pg_restore --list "$claro" >/dev/null 2>&1; then
    rm -rf "$temporal_dir"
    metricas_de_verificacion 0 "$(now_epoch)" -1
    die 1 "'${fichero}' se descifra pero no es un volcado que pg_restore pueda leer. La copia NO sirve para restaurar: usa la anterior ('backup.sh list') y lanza 'backup.sh run' en cuanto puedas. Ver docs/runbooks/restaurar-backup.md."
  fi
  rm -rf "$temporal_dir"

  manifiesto="${fichero%.dump.enc}.manifest.json"
  [ -f "$manifiesto" ] || err "AVISO: falta el manifiesto '${manifiesto}'. El simulacro de restauracion no podra comparar conteos por tabla."

  edad="$(($(now_epoch) - $(stat -c %Y "$fichero" 2>/dev/null || now_epoch)))"
  metricas_de_verificacion 1 "$(now_epoch)" "$edad"
  log "Verificacion correcta: ${entradas} objetos en el volcado, antiguedad $((edad / 60)) min."
}

#------------------------------------------------------------------------------
# prune — retencion. Nunca por debajo de BACKUP_MIN_COPIES
#------------------------------------------------------------------------------

cmd_prune() {
  local total caducados fichero borrados=0 ultimo

  ensure_backup_tree
  total="$(contar_copias)"
  ultimo="$(basename "$(latest_dump_file 2>/dev/null || true)" 2>/dev/null || true)"

  if [ "$total" -le "$BACKUP_MIN_COPIES" ]; then
    return 0
  fi

  caducados="$(find "$BACKUP_DIR_DUMP" -maxdepth 1 -type f -name "${BACKUP_PREFIX}-*.dump.enc" \
    -mtime +"$BACKUP_RETENTION_DAYS" 2>/dev/null | sort || true)"

  while IFS= read -r fichero; do
    [ -n "$fichero" ] || continue
    [ "$((total - borrados))" -gt "$BACKUP_MIN_COPIES" ] || break
    [ "$(basename "$fichero")" != "$ultimo" ] || continue
    rm -f "$fichero" "${fichero}.sha256" "${fichero%.dump.enc}.manifest.json"
    borrados="$((borrados + 1))"
  done <<<"$caducados"

  # Las copias fisicas caducan igual, pero por su cuenta: son mucho mas
  # grandes y su retencion util la marca el WAL que se conserva.
  find "$BACKUP_DIR_BASE" -maxdepth 1 -type f -name "${BACKUP_PREFIX}-base-*" \
    -mtime +"$BACKUP_RETENTION_DAYS" -delete 2>/dev/null || true

  [ "$borrados" -eq 0 ] || log "Retencion: ${borrados} copias de mas de ${BACKUP_RETENTION_DAYS} dias eliminadas."
}

#------------------------------------------------------------------------------
# list
#------------------------------------------------------------------------------

cmd_list() {
  local fichero
  ensure_backup_tree
  printf '%-46s %10s  %s\n' "COPIA" "TAMANO" "VERIFICABLE"
  while IFS= read -r fichero; do
    [ -n "$fichero" ] || continue
    printf '%-46s %9s K  %s\n' \
      "$(basename "$fichero")" \
      "$(($(wc -c <"$fichero") / 1024))" \
      "$([ -f "${fichero}.sha256" ] && echo "sha256 + manifiesto" || echo "sin huella")"
  done < <(find "$BACKUP_DIR_DUMP" -maxdepth 1 -type f -name "${BACKUP_PREFIX}-*.dump.enc" 2>/dev/null | sort)
}

#------------------------------------------------------------------------------

main() {
  local orden="${1:-run}"
  [ $# -gt 0 ] && shift || true

  load_backup_config

  case "$orden" in
  run) cmd_run "$@" ;;
  verify) cmd_verify "$@" ;;
  prune) cmd_prune "$@" ;;
  list) cmd_list "$@" ;;
  -h | --help | help) uso ;;
  *) die 2 "orden desconocida '${orden}'. Usa run, verify, prune o list. 'backup.sh --help' las explica." ;;
  esac
}

main "$@"
