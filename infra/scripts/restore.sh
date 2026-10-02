#!/usr/bin/env bash
#
# KronoQR — restauracion de una copia cifrada (RF-PR-04, RTO <= 4 h).
#
# Lo ejecuta el IT del hotel, normalmente con un incidente delante y sin haber
# tocado nunca el sistema. De ahi las tres decisiones que lo gobiernan:
#
#   1. TODAS las precondiciones se comprueban ANTES de tocar nada. Si algo
#      falla, la instalacion queda exactamente como estaba (fallo seguro).
#   2. NO se restaura encima de la base viva. La copia se restaura en una base
#      NUEVA y, solo cuando ha superado sus comprobaciones, se intercambian los
#      nombres. La base anterior se conserva con su marca temporal, asi que la
#      vuelta atras es otro intercambio de nombres y dura segundos.
#   3. Toda restauracion deja INFORME en BACKUP_PATH/reports/. Restaurar es una
#      accion con relevancia legal: quien, cuando, que copia y con que
#      resultado (regla dura 6). El informe se adjunta al parte del incidente.
#
# Uso:
#   restore.sh --dry-run                   comprueba precondiciones, no toca nada
#   restore.sh --yes                       restaura la ultima copia verificada
#   restore.sh --file RUTA --yes           restaura una copia concreta
#   restore.sh --list                      copias disponibles
#
# Opciones:
#   --file RUTA        copia a restaurar. Por defecto, la del puntero LATEST
#   --database NOMBRE  base de destino. Por defecto, la del .env (DB_DATABASE)
#   --dry-run          solo comprobaciones; no crea, no borra, no renombra
#   --yes              confirma. Sin esto no se restaura nada
#   --keep-previous N  dias que se conserva la base anterior. Por defecto 7
#   --audit-by-caller  NO escribe el asiento de auditoria: lo escribe quien
#                      llama. Solo lo usa update.sh en su vuelta atras, que
#                      escribe el suyo con el paso y el motivo del fallo
#
# EL ASIENTO DE AUDITORIA (PR1, regla dura 6, RL-04). Restaurar descarta un
# intervalo del registro horario, y eso ha de constar DENTRO del registro: tras
# el intercambio de bases, y solo cuando la base de destino es la de la
# instalacion (con --database hacia otra base no se descarta nada y no se
# escribe), se deja en audit_log un asiento `system.restored_from_backup` con la
# copia usada y la punta de la cadena descartada (`chain_before`), la unica
# prueba de que hubo un intervalo que ya no esta. Lo escribe ESTE servicio
# (`restore`) con el rol de migracion que ya tiene: el runtime no recibe ninguna
# credencial nueva (AUD-1, ADR-042). Si no se puede escribir, la restauracion NO
# se deshace —ya esta hecha y verificada— y se sale con 6, con el asiento listo
# para escribir a mano: docs/runbooks/restaurar-backup.md §6.7.
#
# QUE NO SE REPONE (ADR-045). Restaurar devuelve la BASE DE DATOS y nada mas:
#
#   · El volumen `app-storage` (exportaciones integras, informes en diferido,
#     paquete de diagnostico, estado de la telemetria) NO entra en la copia y
#     este script no lo toca. Todo lo que contiene caduca o se regenera desde
#     los datos que si estan en la copia cifrada; meterlo en ella alargaria la
#     vida de una copia completa de los datos personales. Tras restaurar, las
#     exportaciones cuyo fichero no existe pasan a `purged` en la primera
#     pasada de purga, con un asiento `data_export.file_missing` o
#     `report_export.file_missing` que es ESPERADO; se vuelve a pedir la
#     exportacion y se genera de los datos restaurados. El informe lo anuncia.
#   · Los informes de retencion (BACKUP_PATH/reports/retention) tampoco se
#     tocan: un informe de purga describe un hecho que ocurrio aunque la base
#     vuelva a un momento anterior.
#   · Restaurar en un servidor nuevo estrena un identificador de instalacion de
#     telemetria; solo lo usa la telemetria, no la licencia.
#
# ANTES DE RESTAURAR hay que parar lo que escribe en la base: app, horizon,
# scheduler y reverb. El procedimiento completo, con los tiempos que caben en
# el RTO de 4 h, esta en docs/runbooks/restaurar-backup.md. Este script se
# niega a intercambiar las bases si quedan conexiones abiertas, y dice como
# cerrarlas.
#
# CODIGOS DE SALIDA. La tabla es la MISMA de los cinco scripts de operacion y
# vive en lib/exit-codes.sh. Aqui significan:
#
#   0  Restaurado y verificado. La base anterior se conserva con su marca.
#   1  Uso incorrecto, o falta --yes. Nada tocado.
#   2  Requisitos no cumplidos: no hay copia, no conecta con PostgreSQL, la
#      huella no coincide, no hay espacio, la clave no descifra o el volcado no
#      es legible. NADA se ha tocado.
#   3  Estado previo incompatible: quedan conexiones abiertas contra la base de
#      destino. NADA se ha tocado; el mensaje dice como cerrarlas.
#   4  La restauracion ha fallado y se ha deshecho: la base de trabajo se ha
#      eliminado y la base de destino sigue exactamente como estaba.
#   5  Ha quedado algo a medias —tipicamente una base de trabajo con la copia
#      ya restaurada— y hay que terminar el intercambio a mano. El mensaje dice
#      que base es y que ordenes la activan.
#   6  Verificacion posterior fallida. En este script, UNA sola causa: la base
#      esta restaurada y en servicio pero el asiento `system.restored_from_backup`
#      NO se ha escrito (ASIENTO PENDIENTE). No se deshace nada y NO se repite la
#      restauracion: el mensaje y el informe traen la orden que lo escribe.
#   7  GARANTIA DE SEGURIDAD ROTA (AUD-1, A3-01): la copia, al restaurarse, ha
#      cambiado atributos o pertenencias de rol del cluster (por ejemplo
#      `ALTER ROLE fichaje_app SUPERUSER`). NO se han intercambiado las bases:
#      la de trabajo se ha eliminado y '<base>' sigue como estaba. Se ha
#      intentado devolver los roles a su estado anterior y el mensaje dice si lo
#      ha conseguido. La copia esta manipulada o no es de este producto: NO la
#      uses, avisa al responsable de seguridad y sigue
#      docs/runbooks/rotacion-secretos.md.
#
# Si tenias un cron escrito contra la tabla anterior, la equivalencia esta en
# lib/backup-common.sh y en docs/cliente/operacion.md.
#
# NINGUN SECRETO EN LA SALIDA NI EN EL INFORME.

set -euo pipefail
IFS=$'\n\t'

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIR
# La biblioteca comun SI se analiza: `make sh-lint` llama a ShellCheck con -x.
# shellcheck source-path=SCRIPTDIR
# shellcheck source=lib/backup-common.sh disable=SC1091
. "${SCRIPT_DIR}/lib/backup-common.sh"

FICHERO=""
BASE_DESTINO=""
SOLO_COMPROBAR=0
CONFIRMADO=0
SOLO_LISTAR=0
DIAS_ANTERIOR=7
TRABAJO=""
INFORME=""
ASIENTO_POR_LLAMADOR=0
AUDITAR=0
ASIENTO_PENDIENTE=0
ASIENTO_JSON=""
CADENA_DESCARTADA=""
# Donde esta `artisan` en la imagen de la aplicacion (el servicio `restore` usa
# la misma imagen que `app`).
ARTISAN_DIR="${KQ_ARTISAN_DIR:-/var/www/html}"

al_salir() {
  [ -n "$TRABAJO" ] && [ -d "$TRABAJO" ] && rm -rf "$TRABAJO"
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
  [ -n "$INFORME" ] && printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*" >>"$INFORME"
  return 0
}

# A3-01. Compara los roles del cluster con la foto tomada antes del
# `pg_restore`. Si algo ha cambiado, elimina la base de trabajo y sale con el
# codigo de seguridad SIN intercambiar nada. Se llama tambien cuando el
# `pg_restore` ha FALLADO: un archivo manipulado puede cambiar un rol y
# despues romperse a proposito para que nadie mire.
guardar_roles() {
  local base_nueva="$1" resultado=0 detalle

  # Si hay cambios, la base de trabajo sobra y se suelta ANTES de revertir: un
  # rol nuevo puede ser su propietario y no se dejaria borrar.
  kq_roles_unchanged "${TRABAJO}/roles-antes.txt" \
    psql -d postgres -Atqc "DROP DATABASE IF EXISTS \"${base_nueva}\"" || resultado=$?
  [ "$resultado" -ne 0 ] || return 0

  if [ "$resultado" -eq 1 ]; then
    detalle="Los atributos y las pertenencias de rol se han devuelto a su estado anterior y se ha comprobado."
  else
    detalle="NO se han podido devolver los roles a su estado anterior: revisa AHORA 'SELECT rolname, rolsuper, rolcreaterole, rolcreatedb, rolbypassrls, rolreplication FROM pg_roles' y corrige a mano los que difieran de los de arriba."
  fi
  informar "SEGURIDAD: la copia ha cambiado roles del cluster. ${detalle}"
  die "${KQ_EXIT_SECURITY}" "la copia '${FICHERO}' ha cambiado roles del cluster al restaurarse (arriba, lo que ha cambiado). NO se ha intercambiado ninguna base: '${BASE_DESTINO}' sigue como estaba y la base de trabajo se ha eliminado. ${detalle} La copia esta manipulada o no es de este producto: no la uses, prueba con una anterior, avisa al responsable de seguridad y sigue docs/runbooks/rotacion-secretos.md."
}

#------------------------------------------------------------------------------
# Asiento de auditoria de la restauracion (PR1)
#------------------------------------------------------------------------------

# Mensaje en el idioma de la instalacion: texto "es" "en". Mismo criterio que la
# tabla de codigos de salida (kq_exit_lang); un idioma desconocido cae a espanol.
texto() {
  if [ "$(kq_exit_lang)" = "en" ]; then
    printf '%s' "$2"
  else
    printf '%s' "$1"
  fi
}

# Ejecuta artisan CONTRA UNA BASE CONCRETA y con el rol de migracion que este
# servicio ya tiene (PGUSER/PGPASSWORD). La contrasena viaja en el entorno del
# proceso hijo, nunca en la linea de ordenes. No se introduce ninguna credencial
# nueva (AUD-1, ADR-042): el rol de la aplicacion no llega a este servicio.
artisan_migrador() {
  local base="$1"
  shift
  (
    cd -- "$ARTISAN_DIR" &&
      DB_CONNECTION=pgsql_migrator DB_DATABASE="$base" DB_HOST="$PGHOST" DB_PORT="$PGPORT" \
        DB_MIGRATION_USERNAME="$PGUSER" DB_MIGRATION_PASSWORD="${PGPASSWORD:-}" \
        php artisan "$@"
  )
}

# Como `audit_json_object` de update.sh: `clave=valor ...`, y una clave con valor
# vacio se OMITE (es lo que pide el payload del dominio para los opcionales). Los
# valores son versiones, huellas, un nombre de fichero y un instante UTC: nunca
# texto libre ni datos personales.
asiento_json() {
  local pair key value out="{" first=1
  for pair in "$@"; do
    key="${pair%%=*}"
    value="${pair#*=}"
    [ -n "$value" ] || continue
    value="${value//\\/\\\\}"
    value="${value//\"/\\\"}"
    [ "$first" -eq 1 ] || out+=","
    first=0
    out+="\"${key}\":\"${value}\""
  done
  out+="}"
  printf '%s' "$out"
}

# Punta de la cadena de la base que se va a DESCARTAR, leida antes del
# intercambio: despues ya no es la que sirve. Vacia si no se puede leer; el
# asiento se escribe igual sin ella (un asiento sin punta vale mas que ninguno).
punta_cadena_descartada() {
  local salida
  salida="$(artisan_migrador "$BASE_DESTINO" compliance:audit-chain-head 2>/dev/null || true)"
  printf '%s' "$salida" | sed -n 's/.*"hash"[[:space:]]*:[[:space:]]*"\([0-9a-f]\{64\}\)".*/\1/p' | head -n 1 || true
}

# Instante de la copia en UTC: el del manifiesto o, sin el, el de su nombre.
instante_de_la_copia() {
  local valor
  valor="$(manifest_field "${FICHERO%.dump.enc}.manifest.json" created_at 2>/dev/null || true)"
  if [ -z "$valor" ]; then
    valor="$(basename -- "$FICHERO" | sed -n 's/.*\([0-9]\{4\}\)\([0-9]\{2\}\)\([0-9]\{2\}\)T\([0-9]\{2\}\)\([0-9]\{2\}\)\([0-9]\{2\}\)Z.*/\1-\2-\3T\4:\5:\6Z/p' || true)"
  fi
  printf '%s' "$valor"
}

huella_de_la_copia() {
  local valor=""
  [ ! -f "${FICHERO}.sha256" ] || valor="$(cut -d' ' -f1 <"${FICHERO}.sha256" 2>/dev/null || true)"
  [[ "$valor" =~ ^[0-9a-f]{64}$ ]] || valor=""
  printf '%s' "$valor"
}

# Version del producto que ejecuta esta restauracion: la de la imagen.
version_instalada() {
  local valor="${APP_VERSION:-}"
  [ -n "$valor" ] || valor="$(head -n 1 "${ARTISAN_DIR}/VERSION" 2>/dev/null | tr -d '[:space:]' || true)"
  printf '%s' "$valor"
}

# Cuando hay que dejar asiento: la base de destino es la de la instalacion y
# quien llama no se encarga. Restaurar en otra base (simulacros, pruebas) no
# descarta ningun registro y no deja asiento.
decidir_asiento() {
  AUDITAR=0
  [ "$ASIENTO_POR_LLAMADOR" -eq 0 ] || return 0
  [ "$BASE_DESTINO" = "$PGDATABASE" ] || return 0
  AUDITAR=1
}

# Precondicion (ANTES de tocar nada): si hay que dejar asiento, debe poder
# escribirse. Si no, es mejor no empezar que restaurar sin poder documentarlo.
comprobar_asiento_posible() {
  [ "$AUDITAR" -eq 1 ] || return 0
  if ! command -v php >/dev/null 2>&1 || [ ! -f "${ARTISAN_DIR}/artisan" ]; then
    die "${KQ_EXIT_REQUIREMENTS}" "$(texto \
      "no se puede escribir el asiento de auditoria de la restauracion: falta 'php' o '${ARTISAN_DIR}/artisan' en este entorno. Restaura desde el servicio 'restore' ('docker compose run --rm --no-deps restore bash /opt/kronoqr/scripts/restore.sh ...'), que lleva la aplicacion. No se ha tocado nada." \
      "the restore audit entry cannot be written: 'php' or '${ARTISAN_DIR}/artisan' is missing in this environment. Restore from the 'restore' service ('docker compose run --rm --no-deps restore bash /opt/kronoqr/scripts/restore.sh ...'), which ships the application. Nothing has been touched.")"
  fi
}

# Escribe `system.restored_from_backup` en la base YA restaurada. Nunca deshace
# la restauracion por un fallo aqui (seria cambiar un problema de trazabilidad
# por una perdida de datos): lo deja pendiente y main() sale con 6.
escribir_asiento() {
  local version salida estado=0 orden

  version="$(version_instalada)"
  ASIENTO_JSON="$(asiento_json \
    "backup_file=$(basename -- "$FICHERO")" \
    "backup_taken_at=$(instante_de_la_copia)" \
    "failed_step=manual_restore" \
    "reason=manual_restore" \
    "from_version=${version}" \
    "to_version=${version}" \
    "backup_fingerprint=$(huella_de_la_copia)" \
    "chain_before=${CADENA_DESCARTADA}" \
    "report_id=$(basename -- "$INFORME" .log)")"

  informar "Escribiendo el asiento system.restored_from_backup en audit_log"
  salida="$(artisan_migrador "$BASE_DESTINO" compliance:record-system-event system.restored_from_backup --data=- 2>&1 <<<"$ASIENTO_JSON")" || estado=$?

  if [ "$estado" -eq 0 ]; then
    informar "Asiento escrito: ${salida}"
    return 0
  fi

  ASIENTO_PENDIENTE=1
  orden="docker compose run --rm --no-deps -T -e DB_CONNECTION=pgsql_migrator migrate php artisan compliance:record-system-event system.restored_from_backup --data='${ASIENTO_JSON}'"
  informar "ASIENTO PENDIENTE (artisan salio con ${estado}): $(printf '%s' "$salida" | head -c 400 | tr '\n' ' ')"
  informar "Asiento a escribir: ${ASIENTO_JSON}"
  informar "Para escribirlo: ${orden}"
}

#------------------------------------------------------------------------------
# Precondiciones: todas, antes de nada
#------------------------------------------------------------------------------

comprobar_precondiciones() {
  local libre tamano_copia

  require_cmd openssl openssl
  require_cmd psql postgresql17-client
  require_cmd pg_restore postgresql17-client
  require_cmd df coreutils
  require_encryption_key
  ensure_backup_tree

  [ -n "$FICHERO" ] || FICHERO="$(latest_dump_file)"
  [ -n "$FICHERO" ] && [ -f "$FICHERO" ] || die "${KQ_EXIT_REQUIREMENTS}" \
    "no hay ninguna copia que restaurar en '${BACKUP_DIR_DUMP}'. Comprueba BACKUP_PATH en el .env y que el almacenamiento de copias esta montado. Si el destino es un recurso de red, montalo antes. Ver docs/runbooks/restaurar-backup.md."

  psql -Atqc 'SELECT 1' >/dev/null 2>&1 || die "${KQ_EXIT_REQUIREMENTS}" \
    "no se puede conectar a PostgreSQL en ${PGHOST}:${PGPORT} como ${PGUSER}. Levanta el servicio ('docker compose up -d postgres') y vuelve a lanzar esto. No se ha tocado nada."

  # Huella: si no coincide, la copia esta corrupta y no se toca la instalacion.
  if [ -f "${FICHERO}.sha256" ]; then
    [ "$(cut -d' ' -f1 <"${FICHERO}.sha256")" = "$(sha256_of "$FICHERO")" ] || die "${KQ_EXIT_REQUIREMENTS}" \
      "la huella SHA-256 de '${FICHERO}' no coincide con la registrada: esta corrupta. Prueba con la copia anterior ('restore.sh --list') y avisa al responsable de seguridad. No se ha tocado nada."
  fi

  # Espacio: la copia descomprime a bastante mas de lo que ocupa cifrada. Se
  # exige cinco veces su tamano, que es el margen con el que un volcado
  # comprimido cabe holgado.
  tamano_copia="$(wc -c <"$FICHERO")"
  libre="$(free_bytes_at "$BACKUP_PATH")"
  [ "$libre" -ge "$((tamano_copia * 5))" ] || die "${KQ_EXIT_REQUIREMENTS}" \
    "quedan $((libre / 1024 / 1024)) MiB libres y la restauracion necesita al menos $((tamano_copia * 5 / 1024 / 1024)) MiB de margen. Libera espacio antes de empezar. No se ha tocado nada."

  [ -n "$BASE_DESTINO" ] || BASE_DESTINO="$PGDATABASE"

  decidir_asiento
  comprobar_asiento_posible
}

# Descifra a un directorio privado y comprueba que pg_restore lo entiende.
# Aqui todavia no se ha tocado la instalacion.
preparar_volcado() {
  TRABAJO="$(mktemp -d "${TMPDIR:-/tmp}/kronoqr-restore.XXXXXX")"
  chmod 0700 "$TRABAJO"

  decrypt_stream <"$FICHERO" >"${TRABAJO}/copia.dump" 2>/dev/null || die "${KQ_EXIT_REQUIREMENTS}" \
    "no se puede descifrar '${FICHERO}' con la BACKUP_ENCRYPTION_KEY actual. Si la clave se roto, usa la anterior: una copia solo se abre con la clave con la que se hizo. No se ha tocado nada."

  pg_restore --list "${TRABAJO}/copia.dump" >"${TRABAJO}/indice.txt" 2>/dev/null || die "${KQ_EXIT_REQUIREMENTS}" \
    "'${FICHERO}' se descifra pero no es un volcado legible. Usa la copia anterior ('restore.sh --list'). No se ha tocado nada."

  informar "Copia legible: $(grep -cE '^[0-9]+;' "${TRABAJO}/indice.txt" || true) objetos."
}

conexiones_abiertas() {
  psql -Atqc "SELECT count(*) FROM pg_stat_activity WHERE datname = '${BASE_DESTINO}' AND pid <> pg_backend_pid()" 2>/dev/null || echo 0
}

#------------------------------------------------------------------------------

restaurar() {
  local marca base_nueva base_anterior abiertas

  marca="$(timestamp_utc)"
  base_nueva="${BASE_DESTINO}_restore_${marca}"
  base_anterior="${BASE_DESTINO}_pre_restore_${marca}"

  abiertas="$(conexiones_abiertas)"
  if [ "$abiertas" -gt 0 ]; then
    die "${KQ_EXIT_STATE_CONFLICT}" "hay ${abiertas} conexiones abiertas contra '${BASE_DESTINO}'. Para primero lo que escribe: 'docker compose stop app horizon scheduler reverb'. El fichaje sigue funcionando en los quioscos, que encolan en local (regla dura 19). No se ha tocado nada."
  fi

  informar "Creando base de trabajo ${base_nueva}"
  psql -d postgres -Atqc "CREATE DATABASE \"${base_nueva}\"" >/dev/null || die "${KQ_EXIT_REQUIREMENTS}" \
    "no se ha podido crear la base de trabajo. El usuario ${PGUSER} necesita el permiso CREATEDB. Nada se ha tocado."

  # LOS PRIVILEGIOS DEL VOLCADO SE CONSERVAN si los roles del producto existen
  # en el cluster, que es el caso de toda instalacion. Son ellos los que
  # sostienen la regla dura 6: los GRANT al rol de la aplicacion y los REVOKE
  # sobre audit_log viven en las migraciones y pg_dump los incluye. Con
  # `--no-privileges` la base restaurada nacia sin ninguno: las sondas decian
  # «operativo» y ningun fichaje se podia escribir (hallazgo de la tarea 5.7,
  # en la vuelta atras automatica). Solo se descartan cuando el rol no existe
  # —un contenedor limpio de simulacro—, donde un GRANT a un rol ausente
  # aborta la restauracion entera.
  local privilegios=(--no-privileges)
  if [ "$(psql -Atqc "SELECT count(*) FROM pg_roles WHERE rolname = '${DB_USERNAME:-fichaje_app}'" 2>/dev/null | tr -d '[:space:]')" = "1" ]; then
    privilegios=()
  fi

  # Foto de los roles del cluster ANTES de ejecutar lo que traiga el archivo
  # (A3-01): pg_restore corre como superusuario.
  kq_roles_snapshot "${TRABAJO}/roles-antes.txt"

  informar "Restaurando el volcado (esto es lo que mas tarda)"
  if ! pg_restore --dbname="$base_nueva" --no-owner "${privilegios[@]}" --exit-on-error \
    "${TRABAJO}/copia.dump" >>"${INFORME:-/dev/null}" 2>&1; then
    guardar_roles "$base_nueva"
    psql -d postgres -Atqc "DROP DATABASE IF EXISTS \"${base_nueva}\"" >/dev/null || true
    die "${KQ_EXIT_ROLLED_BACK}" "la restauracion ha fallado; la base de trabajo se ha eliminado y '${BASE_DESTINO}' sigue como estaba. Revisa el informe '${INFORME}' y prueba con la copia anterior."
  fi

  guardar_roles "$base_nueva"

  informar "Comprobando la copia restaurada antes de darla por buena"
  if [ "${#privilegios[@]}" -eq 0 ]; then
    comprobar_privilegios "$base_nueva" || {
      psql -d postgres -Atqc "DROP DATABASE IF EXISTS \"${base_nueva}\"" >/dev/null || true
      die "${KQ_EXIT_ROLLED_BACK}" "la base restaurada no conserva los privilegios del rol de la aplicacion (${DB_USERNAME:-fichaje_app}): o no puede escribir fichajes, o puede alterar audit_log. NO se ha sustituido '${BASE_DESTINO}'. La copia es de una version que no volcaba privilegios: avisa al fabricante."
    }
  fi
  comprobar_restauracion "$base_nueva" || {
    psql -d postgres -Atqc "DROP DATABASE IF EXISTS \"${base_nueva}\"" >/dev/null || true
    die "${KQ_EXIT_ROLLED_BACK}" "la copia restaurada no supera las comprobaciones de integridad. NO se ha sustituido '${BASE_DESTINO}'. Prueba con la copia anterior y avisa al responsable del sistema."
  }

  # La punta de la cadena que se va a descartar, en el ultimo instante en que
  # todavia es la que sirve (PR1). Es lo que el asiento guarda como prueba.
  if [ "$AUDITAR" -eq 1 ]; then
    CADENA_DESCARTADA="$(punta_cadena_descartada)"
    informar "Punta de la cadena de auditoria que se descarta: ${CADENA_DESCARTADA:-no disponible}"
  fi

  # Intercambio de nombres. Es el unico momento en que la instalacion cambia, y
  # dura lo que dos ALTER DATABASE.
  informar "Intercambiando ${BASE_DESTINO} -> ${base_anterior} y ${base_nueva} -> ${BASE_DESTINO}"
  psql -d postgres -Atqc "ALTER DATABASE \"${BASE_DESTINO}\" RENAME TO \"${base_anterior}\"" >/dev/null || die "${KQ_EXIT_ROLLBACK_INCOMPLETE}" \
    "no se ha podido apartar la base actual: probablemente ha vuelto a haber conexiones. Para los servicios y repite. La base restaurada esta en '${base_nueva}' y no se ha perdido nada."
  if ! psql -d postgres -Atqc "ALTER DATABASE \"${base_nueva}\" RENAME TO \"${BASE_DESTINO}\"" >/dev/null; then
    psql -d postgres -Atqc "ALTER DATABASE \"${base_anterior}\" RENAME TO \"${BASE_DESTINO}\"" >/dev/null || true
    die "${KQ_EXIT_ROLLED_BACK}" "no se ha podido activar la base restaurada; se ha devuelto la anterior a su nombre. La instalacion queda como estaba."
  fi

  informar "Restauracion completada. Base anterior conservada como '${base_anterior}'."
  informar "VUELTA ATRAS (mientras exista esa base): pare los servicios y ejecute"
  informar "  ALTER DATABASE \"${BASE_DESTINO}\" RENAME TO \"${BASE_DESTINO}_descartada\";"
  informar "  ALTER DATABASE \"${base_anterior}\" RENAME TO \"${BASE_DESTINO}\";"

  if [ "$AUDITAR" -eq 1 ]; then
    escribir_asiento
  fi

  purgar_bases_anteriores
}

# Comprobacion de lo restaurado ANTES de sustituir la base viva: que estan
# todas las tablas del manifiesto y que ninguna ha perdido filas.
#
# La validacion exhaustiva de claves ajenas —recrear cada una para que
# PostgreSQL las verifique contra los datos— la hace el simulacro trimestral
# (restore-drill.sh) sobre una copia de usar y tirar. Aqui seria invasiva y
# cara justo en el momento en que el RTO corre.
comprobar_restauracion() {
  local base="$1" tablas manifiesto

  tablas="$(psql -d "$base" -Atqc "SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.relkind = 'r' AND n.nspname NOT IN ('pg_catalog', 'information_schema') AND n.nspname !~ '^pg_'")"
  informar "Tablas restauradas: ${tablas}"

  manifiesto="${FICHERO%.dump.enc}.manifest.json"
  if [ ! -f "$manifiesto" ]; then
    err "AVISO: sin manifiesto no se pueden comparar conteos. Se continua, pero anotalo en el parte."
    return 0
  fi

  compare_table_counts "$base" "$manifiesto"
}

# El rol de la aplicacion escribe fichajes y NO toca audit_log (regla dura 6).
# Es lo que demuestra que los privilegios del volcado han llegado enteros.
comprobar_privilegios() {
  local base="$1" rol="${DB_USERNAME:-fichaje_app}" escribe altera
  escribe="$(psql -d "$base" -Atqc "SELECT has_table_privilege('${rol}', 'shift_entries', 'INSERT')" 2>/dev/null | tr -d '[:space:]')"
  altera="$(psql -d "$base" -Atqc "SELECT has_table_privilege('${rol}', 'audit_log', 'UPDATE') OR has_table_privilege('${rol}', 'audit_log', 'DELETE')" 2>/dev/null | tr -d '[:space:]')"
  [ "$escribe" = "t" ] && [ "$altera" = "f" ]
}

# Las bases apartadas por restauraciones anteriores no se acumulan para
# siempre: ocupan tanto como la base viva. La fecha va en el propio nombre, asi
# que no hace falta preguntarle al sistema de ficheros.
purgar_bases_anteriores() {
  local base
  while IFS= read -r base; do
    [ -n "$base" ] || continue
    informar "Eliminando base de restauracion antigua: ${base}"
    psql -d postgres -Atqc "DROP DATABASE IF EXISTS \"${base}\"" >/dev/null || true
  done < <(psql -d postgres -Atqc "
    SELECT datname FROM pg_database
    WHERE datname ~ ('^${BASE_DESTINO}_pre_restore_[0-9]{8}T[0-9]{6}Z\$')
      AND to_timestamp(right(datname, 16), 'YYYYMMDD\"T\"HH24MISS\"Z\"') < now() - interval '${DIAS_ANTERIOR} days'" 2>/dev/null || true)
}

#------------------------------------------------------------------------------

resumen_dry_run() {
  local manifiesto
  manifiesto="${FICHERO%.dump.enc}.manifest.json"

  printf '\n'
  printf 'Precondiciones de la restauracion\n'
  printf '  copia .................. %s\n' "$FICHERO"
  printf '  creada ................. %s\n' "$(manifest_field "$manifiesto" created_at || echo "sin manifiesto")"
  printf '  tablas en la copia ..... %s\n' "$(manifest_field "$manifiesto" table_count || echo "sin manifiesto")"
  printf '  huella ................. verificada\n'
  printf '  descifrado ............. correcto\n'
  printf '  base de destino ........ %s en %s:%s\n' "$BASE_DESTINO" "$PGHOST" "$PGPORT"
  printf '  conexiones abiertas .... %s (deben ser 0 al restaurar)\n' "$(conexiones_abiertas)"
  printf '  espacio libre .......... %s MiB\n' "$(($(free_bytes_at "$BACKUP_PATH") / 1024 / 1024))"
  if [ "$AUDITAR" -eq 1 ]; then
    printf '  asiento de auditoria ... se escribira (system.restored_from_backup)\n'
  elif [ "$ASIENTO_POR_LLAMADOR" -eq 1 ]; then
    printf '  asiento de auditoria ... lo escribe quien llama (--audit-by-caller)\n'
  else
    printf '  asiento de auditoria ... no aplica (la base de destino no es la de la instalacion)\n'
  fi
  printf '\n'
  printf 'Nada se ha modificado. Para restaurar de verdad:\n'
  printf '  1. docker compose stop app horizon scheduler reverb\n'
  printf '  2. %s --file %s --yes\n' "${BASH_SOURCE[0]}" "$FICHERO"
  printf '  3. docker compose up -d\n'
  printf 'Procedimiento completo y tiempos: docs/runbooks/restaurar-backup.md\n'
}

main() {
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
    --database)
      BASE_DESTINO="${2:-}"
      shift 2
      ;;
    --database=*)
      BASE_DESTINO="${1#*=}"
      shift
      ;;
    --keep-previous)
      DIAS_ANTERIOR="${2:-7}"
      shift 2
      ;;
    --keep-previous=*)
      DIAS_ANTERIOR="${1#*=}"
      shift
      ;;
    --dry-run)
      SOLO_COMPROBAR=1
      shift
      ;;
    --audit-by-caller)
      ASIENTO_POR_LLAMADOR=1
      shift
      ;;
    --list)
      SOLO_LISTAR=1
      shift
      ;;
    --yes)
      CONFIRMADO=1
      shift
      ;;
    -h | --help)
      uso
      return 0
      ;;
    *) die "${KQ_EXIT_USAGE}" "argumento desconocido '$1'. Ejecuta 'restore.sh --help'." ;;
    esac
  done

  load_backup_config

  if [ "$SOLO_LISTAR" -eq 1 ]; then
    ensure_backup_tree
    "${SCRIPT_DIR}/backup.sh" list
    return 0
  fi

  comprobar_precondiciones
  preparar_volcado

  if [ "$SOLO_COMPROBAR" -eq 1 ]; then
    resumen_dry_run
    return 0
  fi

  if [ "$CONFIRMADO" -ne 1 ]; then
    die "${KQ_EXIT_USAGE}" "esto sustituye la base de datos de produccion. Repite la orden con --yes cuando hayas leido docs/runbooks/restaurar-backup.md y parado los servicios que escriben."
  fi

  # El informe se abre ANTES de tocar nada y se conserva aunque la
  # restauracion falle: es la prueba de que se restauro, quien y cuando.
  INFORME="${BACKUP_DIR_REPORTS}/restore-$(timestamp_utc).log"
  : >"$INFORME"
  chmod 0640 "$INFORME"
  informar "Restauracion iniciada por '$(id -un 2>/dev/null || echo desconocido)' desde '$(hostname 2>/dev/null || echo desconocido)'"
  informar "Copia: ${FICHERO}"
  informar "Destino: ${BASE_DESTINO} en ${PGHOST}:${PGPORT}"

  restaurar

  # ADR-045: el volumen de ficheros generados no se repone. Se anuncia en el
  # informe para que quien lea los asientos `*.file_missing` de la primera pasada
  # de purga sepa que son esperados y no una exfiltracion.
  if [ "$AUDITAR" -eq 1 ]; then
    informar "$(texto \
      "Aviso: el volumen de ficheros generados (app-storage) no forma parte de la copia y no se ha repuesto. Las exportaciones y los informes en diferido posteriores a la copia que se acaba de restaurar apareceran como 'purged' con un asiento data_export.file_missing o report_export.file_missing en la proxima pasada de purga: es lo ESPERADO tras una restauracion. Pide de nuevo la exportacion desde el panel. Los informes de retencion de BACKUP_PATH/reports/retention no se han tocado." \
      "Notice: the generated-files volume (app-storage) is not part of the backup and has not been restored. Exports and deferred reports created after the backup you have just restored will show as 'purged' with a data_export.file_missing or report_export.file_missing entry on the next purge pass: that is EXPECTED after a restore. Request the export again from the panel. Retention reports in BACKUP_PATH/reports/retention have not been touched.")"
  fi

  log "Informe de la restauracion: ${INFORME}"
  log "Adjuntalo al parte del incidente: una restauracion en produccion se documenta (regla dura 6)."

  # Asiento pendiente: la base esta restaurada y en servicio, pero el intervalo
  # descartado aun no consta en audit_log. Codigo 6, con la orden que lo arregla.
  if [ "$ASIENTO_PENDIENTE" -eq 1 ]; then
    die "${KQ_EXIT_VERIFY_FAILED}" "$(texto \
      "la base esta RESTAURADA y en servicio, pero el asiento 'system.restored_from_backup' de audit_log NO se ha escrito: el intervalo descartado no consta todavia en el registro (regla dura 6). NO repitas la restauracion. Escribelo ahora con: docker compose run --rm --no-deps -T -e DB_CONNECTION=pgsql_migrator migrate php artisan compliance:record-system-event system.restored_from_backup --data='${ASIENTO_JSON}' ; comprueba despues 'docker compose exec app php artisan compliance:verify-audit-chain'. El mismo asiento y el motivo del fallo estan en el informe '${INFORME}'. Procedimiento: docs/runbooks/restaurar-backup.md §6.7." \
      "the database is RESTORED and in service, but the 'system.restored_from_backup' entry in audit_log was NOT written: the discarded interval is not yet recorded in the ledger (hard rule 6). Do NOT restore again. Write it now with: docker compose run --rm --no-deps -T -e DB_CONNECTION=pgsql_migrator migrate php artisan compliance:record-system-event system.restored_from_backup --data='${ASIENTO_JSON}' ; then check 'docker compose exec app php artisan compliance:verify-audit-chain'. The same entry and the failure reason are in the report '${INFORME}'. Procedure: docs/runbooks/restaurar-backup.md §6.7 (in Spanish).")"
  fi
}

# Ejecutable, o cargable con `source` para probar sus funciones sin restaurar.
if [ "${BASH_SOURCE[0]}" = "$0" ]; then
  main "$@"
fi
