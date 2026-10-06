#!/usr/bin/env bash
#
# KronoQR — etapas ⑧ y ⑧b de la CI: copias, WAL cifrado, RPO y montajes de
# BACKUP_PATH, de extremo a extremo sobre una instalacion real (ADR-049, bloque 20
# de la 2.2.0: R5-DV-01, R5-DV-02, R5-DV-04, A3-R2).
#
# NO ES UN ENTREGABLE: vive en .github/scripts/ y solo lo ejecuta la CI.
#
# POR QUE EXISTE. Ni una prueba de Pest ni el simulacro con un PostgreSQL suelto
# ven lo que solo ocurre en la instalacion que deja el instalador: que el
# contenedor de PostgreSQL archiva CIFRADO con la clave que le llega por el .env,
# que la raiz de BACKUP_PATH es de solo lectura para el runtime y escribible solo
# donde debe, que parar el archivado se ve en la metrica sin esperar a ninguna
# copia, y que tras actualizar desde la 2.1.0 no queda un solo segmento en claro.
#
# MODOS
#
#   clean DIRECTORIO           (etapa ⑧, tras instalar)
#     1. WAL CIFRADO: dos `pg_switch_wal()`; el segmento archivado es
#        `<seg>.gz.enc`, NO empieza por 1f8b, no se descomprime y da 0 coincidencias
#        de `scan_id|employee|kiosk`; no hay ningun `.gz` en claro.
#     2. FALLO CERRADO: sin BACKUP_WAL_KEY el archivado devuelve 1 (no archiva en
#        claro) y con la clave de desarrollo, tambien.
#     3. MATRIZ DE MONTAJES (C18): horizon no escribe en la raiz, ni en daily/, ni en
#        reports/retention, ni borra una copia, y SI en metrics/; app no escribe en la
#        raiz, daily/ ni reports/ (fuera de retention/), no crea update.lock, y SI en
#        reports/retention y metrics/; scheduler no escribe en la raiz y SI en
#        reports/retention, metrics/ y daily/; restore no escribe en daily/ ni base/;
#        node-exporter sigue `:ro`.
#     4. COPIA AUTENTICADA: `backup:run --mode=full` desde scheduler da dump y base
#        KQE1; un bit cambiado, un `.sha256` borrado, un fichero renombrado o un
#        manifiesto alterado hacen fallar `restore.sh --dry-run` con 6 y no tocan nada.
#     5. RPO CONTINUO (C17): `wal-metrics.sh` con el rol REAL `fichaje_backup` (sin
#        pg_monitor) publica `kronoqr_wal.prom` en <= 90 s; parar el archivado
#        (chmod 000 del directorio de WAL) se ve en `kronoqr_wal_archive_failing` en
#        <= 120 s, SIN esperar a ninguna copia, y se apaga al devolverlo.
#     6. CLAVE: `doctor.sh` falla si BACKUP_WAL_KEY no deriva de la maestra, y ningun
#        contenedor salvo postgres lleva la clave del WAL en su entorno, ni aparece en
#        el log de postgres ni en la linea de ordenes de ningun proceso (C7).
#
#   upgrade-seed DIRECTORIO    (etapa ⑧b, con la 2.1.0 en pie, ANTES de actualizar)
#     deja >= 3 segmentos `.gz` en claro en el archivo y apunta nombres y fechas.
#
#   upgrade-check DIRECTORIO   (etapa ⑧b, DESPUES de actualizar)
#     1. en <= 120 s no queda ningun `.gz` en claro; cada heredado esta como
#        `.gz.enc` con `src=legacy` y CONSERVA su fecha (la purga no los retiene mas);
#     2. `restore.sh` sobre una copia de la 2.1.0: sin bandera sale 6; con
#        `--accept-unauthenticated` y su `.sha256` pasa; con la bandera y sin
#        `.sha256`, sale 6;
#     3. `kronoqr-restore-wal` lee un `.gz` heredado plantado aparte: sin bandera sale
#        200 y con ella 0; un segmento intermedio ausente con otro posterior, 200;
#     4. el candado de la actualizacion no queda en BACKUP_PATH ni en
#        /var/log/kronoqr; los montajes siguen siendo los de la matriz.
#
# Uso:
#   backup-wal-e2e.sh MODO DIRECTORIO_DEL_PAQUETE
#
# Variables:
#   KQ_E2E_SUDO   (sudo) prefijo para docker y el .env (0600 de root). Vacio si ya se
#                 es root, como en docker-in-docker.
#
# Necesita: docker con el plugin compose, y las herramientas del propio paquete.
# Codigos de salida: 0 todo comprobado · 1 algo no se cumple · 2 uso incorrecto.

set -euo pipefail
IFS=$'\n\t'

[ "$#" -eq 2 ] || {
  printf 'uso: backup-wal-e2e.sh (clean|upgrade-seed|upgrade-check) DIRECTORIO_DEL_PAQUETE\n' >&2
  exit 2
}

MODE="$1"
PKG="$(cd -- "$2" && pwd)"
# La semilla de ⑧b la escribe upgrade-seed con el paquete ANTERIOR y la lee upgrade-check
# con el NUEVO: vive junto a los dos paquetes, no dentro de uno.
SEED_FILE="$(dirname -- "${PKG}")/.wal-seed.txt"
SUDO="${KQ_E2E_SUDO-sudo}"
STEP=""

WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT

# --- utilidades --------------------------------------------------------------

step() {
  STEP="$1"
  printf '\n=== %s\n' "${STEP}"
}

fail() {
  printf '\nFALLO en «%s»: %s\n' "${STEP}" "$*" >&2
  exit 1
}

ok() {
  printf '  ok · %s\n' "$*"
}

as_root() {
  if [ -n "${SUDO}" ]; then
    "${SUDO}" "$@"
  else
    "$@"
  fi
}

dc() {
  as_root docker compose --env-file "${PKG}/.env" -f "${PKG}/docker-compose.yml" "$@"
}

env_value() {
  as_root sed -n "s/^$1=//p" "${PKG}/.env" | tail -n 1
}

BACKUP_PATH="$(env_value BACKUP_PATH)"
[ -n "${BACKUP_PATH}" ] || BACKUP_PATH="/var/backups/fichaje"
readonly BACKUP_PATH
readonly WAL_DIR="${BACKUP_PATH}/wal"

sql() {
  dc exec -T postgres psql -U fichaje_migrator -d fichaje -Atqc "$1"
}

# Espera por CONDICION (no por tiempo): comando, segundos maximos, descripcion.
wait_for() {
  local cmd="$1" seconds="$2" what="$3" waited=0
  while [ "${waited}" -lt "${seconds}" ]; do
    if eval "${cmd}" >/dev/null 2>&1; then
      return 0
    fi
    sleep 3
    waited=$((waited + 3))
  done
  fail "${what} (no ocurrio en ${seconds} s)"
}

# Escribe con `touch` desde un servicio; devuelve 0 si pudo.
can_write() {
  local service="$1" path="$2"
  # shellcheck disable=SC2016 # `$1` es del `sh -c`.
  dc exec -T "${service}" sh -c 'touch "$1" 2>/dev/null && rm -f "$1"' sh "${path}"
}

expect_readonly() {
  local service="$1" path="$2"
  if can_write "${service}" "${path}"; then
    fail "${service} PUEDE escribir en ${path} y no debe (A3-R2)"
  fi
  ok "${service} no escribe en ${path}"
}

expect_writable() {
  local service="$1" path="$2"
  can_write "${service}" "${path}" || fail "${service} NO puede escribir en ${path} y debe"
  ok "${service} escribe en ${path}"
}

switch_wal() {
  sql "SELECT pg_switch_wal()" >/dev/null
}

wal_enc_count() {
  as_root sh -c "ls '${WAL_DIR}'/*.gz.enc 2>/dev/null | wc -l" | tr -d '[:space:]'
}

wal_plain_count() {
  as_root sh -c "ls '${WAL_DIR}'/*.gz 2>/dev/null | wc -l" | tr -d '[:space:]'
}

# Valor de una metrica de BACKUP_PATH/metrics/<fichero>.prom.
metric() {
  as_root sed -n "s/^$2 //p" "${BACKUP_PATH}/metrics/$1" 2>/dev/null | head -n 1
}

# Valor del .env sin imprimir: ¿esta el valor de BACKUP_WAL_KEY en este texto?
leaks_wal_key() {
  local key
  key="$(env_value BACKUP_WAL_KEY)"
  [ -n "${key}" ] || fail "BACKUP_WAL_KEY no esta en el .env"
  grep -qF -- "${key}"
}

# --- ⑧ · instalacion limpia ---------------------------------------------------

mode_clean() {
  local seg first newest copia nombre kid_envhash

  step "1 · El archivo de WAL esta CIFRADO (R5-DV-02, RL-12)"
  sql "CREATE TABLE IF NOT EXISTS ci_wal_probe(id serial primary key, scan_id text, employee text, kiosk text)"
  sql "INSERT INTO ci_wal_probe(scan_id, employee, kiosk) SELECT 'scan_id-' || g, 'employee-' || g, 'kiosk-' || g FROM generate_series(1, 2000) g"
  switch_wal
  sleep 2
  switch_wal
  wait_for "[ \"\$(as_root sh -c \"ls '${WAL_DIR}'/*.gz.enc 2>/dev/null | wc -l\")\" -ge 2 ]" 90 "PostgreSQL archiva al menos dos segmentos"
  [ "$(wal_plain_count)" = "0" ] || fail "hay segmentos .gz en CLARO en ${WAL_DIR}"
  ok "no hay ningun .gz en claro"
  newest="$(as_root sh -c "ls -t '${WAL_DIR}'/*.gz.enc | head -n 1")"
  first="$(as_root head -c 2 "${newest}" | od -An -tx1 | tr -d ' \n')"
  [ "${first}" != "1f8b" ] || fail "el segmento archivado empieza por 1f8b: es un gzip legible"
  ok "el segmento no es un gzip (empieza por ${first})"
  as_root gunzip -c "${newest}" >/dev/null 2>&1 && fail "el segmento se descomprime sin la clave"
  ok "no se descomprime sin la clave"
  [ "$(as_root sh -c "cat '${WAL_DIR}'/*.gz.enc | strings | grep -c -E 'scan_id|employee|kiosk' || true" | tr -d '[:space:]')" = "0" ] ||
    fail "los segmentos archivados dejan ver scan_id/employee/kiosk (R5-DV-02)"
  ok "0 coincidencias de scan_id|employee|kiosk en todo el archivo"
  as_root head -n 1 "${newest}" | grep -qE '^KQE1 kind=wal kid=[0-9a-f]{8} iter=10000 created=[0-9T:Z-]+ name=[0-9A-F]{24}' ||
    fail "la cabecera del segmento no es KQE1"
  ok "cabecera KQE1 correcta"

  step "2 · Sin clave el archivado FALLA (no archiva en claro)"
  dc exec -T postgres sh -c 'head -c 4096 /dev/urandom > /tmp/seg-e2e'
  if dc exec -T -e BACKUP_WAL_KEY= postgres kronoqr-archive-wal /tmp/seg-e2e 0000000100000000000000FE; then
    fail "kronoqr-archive-wal archivo SIN clave"
  fi
  if dc exec -T -e BACKUP_WAL_KEY=4deec0e109388ff8c0ed3ca348abba952eef5a59c6e87906f052e8453eff69ec postgres kronoqr-archive-wal /tmp/seg-e2e 0000000100000000000000FE; then
    fail "kronoqr-archive-wal archivo con la clave de DESARROLLO"
  fi
  as_root test ! -e "${WAL_DIR}/0000000100000000000000FE.gz.enc" || fail "quedo un segmento escrito sin clave"
  ok "sin clave y con la clave de desarrollo, el archivado sale con 1"

  step "3 · Matriz de montajes de BACKUP_PATH (A3-R2, C18)"
  expect_readonly horizon "${BACKUP_PATH}/.e2e-probe"
  expect_readonly horizon "${BACKUP_PATH}/daily/.e2e-probe"
  expect_readonly horizon "${BACKUP_PATH}/reports/retention/.e2e-probe"
  expect_writable horizon "${BACKUP_PATH}/metrics/.e2e-probe"
  expect_readonly app "${BACKUP_PATH}/.e2e-probe"
  expect_readonly app "${BACKUP_PATH}/daily/.e2e-probe"
  expect_readonly app "${BACKUP_PATH}/reports/.e2e-probe"
  expect_readonly app "${BACKUP_PATH}/update.lock"
  expect_writable app "${BACKUP_PATH}/reports/retention/.e2e-probe"
  expect_writable app "${BACKUP_PATH}/metrics/.e2e-probe"
  expect_readonly scheduler "${BACKUP_PATH}/.e2e-probe"
  expect_writable scheduler "${BACKUP_PATH}/reports/retention/.e2e-probe"
  expect_writable scheduler "${BACKUP_PATH}/metrics/.e2e-probe"
  expect_writable scheduler "${BACKUP_PATH}/daily/.e2e-probe"
  # El servicio privilegiado tampoco toca las copias.
  # shellcheck disable=SC2016
  if dc run --rm --no-deps -T restore sh -c 'touch "$1" 2>/dev/null' sh "${BACKUP_PATH}/daily/.e2e-probe"; then
    fail "restore PUEDE escribir en daily/"
  fi
  # shellcheck disable=SC2016
  if dc run --rm --no-deps -T restore sh -c 'touch "$1" 2>/dev/null' sh "${BACKUP_PATH}/base/.e2e-probe"; then
    fail "restore PUEDE escribir en base/"
  fi
  ok "restore no escribe en daily/ ni base/"
  # `horizon` tampoco borra una copia existente.
  # shellcheck disable=SC2016
  dc exec -T scheduler sh -c 'echo x > "$1"' sh "${BACKUP_PATH}/daily/kronoqr-e2e-borrar.dump.enc"
  # shellcheck disable=SC2016
  if dc exec -T horizon sh -c 'rm -f "$1" 2>/dev/null; test ! -e "$1"' sh "${BACKUP_PATH}/daily/kronoqr-e2e-borrar.dump.enc"; then
    fail "horizon borro un fichero de daily/"
  fi
  dc exec -T scheduler rm -f "${BACKUP_PATH}/daily/kronoqr-e2e-borrar.dump.enc"
  ok "horizon no borra copias"
  # node-exporter (perfil observability) sigue `:ro` si esta levantado.
  if [ -n "$(dc ps -q node-exporter 2>/dev/null)" ]; then
    if dc exec -T node-exporter sh -c 'touch /var/backups/.e2e 2>/dev/null'; then
      fail "node-exporter PUEDE escribir en la raiz de las copias"
    fi
    ok "node-exporter sigue en solo lectura"
  fi

  step "4 · Copia autenticada: un bit cambiado, sin .sha256, renombrada o con el manifiesto alterado, NO restaura"
  dc exec -T scheduler php artisan backup:run --mode=full
  copia="$(as_root sh -c "ls -t '${BACKUP_PATH}'/daily/*.dump.enc | head -n 1")"
  nombre="$(basename "${copia}" .dump.enc)"
  as_root head -n 1 "${copia}" | grep -qE "^KQE1 kind=dump kid=[0-9a-f]{8} iter=600000 created=[0-9T:Z-]+ name=${nombre}\$" || fail "el volcado no es KQE1 con su nombre"
  as_root sh -c "head -n 1 '$(as_root sh -c "ls -t '${BACKUP_PATH}'/base/*.tar.gz.enc | head -n 1")'" | grep -q '^KQE1 kind=base ' || fail "la copia fisica no es KQE1"
  as_root test -s "${BACKUP_PATH}/daily/${nombre}.manifest.mac" || fail "falta el MAC del manifiesto"
  ok "volcado y copia fisica en KQE1, con cabecera y manifiesto autenticado"

  restore_dry() {
    dc run --rm --no-deps -T restore bash /opt/kronoqr/scripts/restore.sh --database kq_e2e --dry-run "$@"
  }
  restore_dry --file "${copia}" >/dev/null || fail "restore --dry-run de la copia buena fallo"
  ok "la copia buena pasa el dry-run"

  local rc
  # Un bit cambiado en el cuerpo.
  as_root cp -p "${copia}" "${copia}.bak"
  as_root sh -c "printf 'Z' | dd of='${copia}' bs=1 seek=300 conv=notrunc 2>/dev/null"
  rc=0
  restore_dry --file "${copia}" >/dev/null 2>&1 || rc=$?
  as_root mv -f "${copia}.bak" "${copia}"
  [ "${rc}" = "6" ] || fail "un bit cambiado dio salida ${rc} y se esperaba 6"
  ok "un bit cambiado -> salida 6"
  # .sha256 borrado y .enc modificado (el ataque del hallazgo).
  as_root cp -p "${copia}" "${copia}.bak"
  as_root mv "${copia}.sha256" "${copia}.sha256.bak"
  as_root sh -c "printf 'Z' | dd of='${copia}' bs=1 seek=300 conv=notrunc 2>/dev/null"
  rc=0
  restore_dry --file "${copia}" >/dev/null 2>&1 || rc=$?
  as_root mv -f "${copia}.bak" "${copia}"
  [ "${rc}" = "6" ] || fail "sin .sha256 y con el .enc modificado dio salida ${rc} y se esperaba 6"
  ok ".sha256 borrado + .enc modificado -> salida 6"
  rc=0
  restore_dry --file "${copia}" >/dev/null 2>&1 || rc=$?
  as_root mv -f "${copia}.sha256.bak" "${copia}.sha256"
  [ "${rc}" = "6" ] || fail "sin .sha256 dio salida ${rc} y se esperaba 6"
  ok "sin .sha256 -> salida 6"
  # Renombrada: la cabecera dice otro nombre.
  as_root sh -c "cp '${copia}' '${BACKUP_PATH}/daily/kronoqr-20240101T000000Z.dump.enc' && cp '${copia}.sha256' '${BACKUP_PATH}/daily/kronoqr-20240101T000000Z.dump.enc.sha256' && cp '${BACKUP_PATH}/daily/${nombre}.manifest.json' '${BACKUP_PATH}/daily/kronoqr-20240101T000000Z.manifest.json' && cp '${BACKUP_PATH}/daily/${nombre}.manifest.mac' '${BACKUP_PATH}/daily/kronoqr-20240101T000000Z.manifest.mac'"
  rc=0
  restore_dry --file "${BACKUP_PATH}/daily/kronoqr-20240101T000000Z.dump.enc" >/dev/null 2>&1 || rc=$?
  as_root sh -c "rm -f '${BACKUP_PATH}'/daily/kronoqr-20240101T000000Z.*"
  [ "${rc}" = "6" ] || fail "una copia renombrada dio salida ${rc} y se esperaba 6"
  ok "copia renombrada -> salida 6"
  # Manifiesto alterado.
  as_root cp -p "${BACKUP_PATH}/daily/${nombre}.manifest.json" "${WORK}/manifest.bak"
  as_root sh -c "echo ' ' >> '${BACKUP_PATH}/daily/${nombre}.manifest.json'"
  rc=0
  restore_dry --file "${copia}" >/dev/null 2>&1 || rc=$?
  as_root sh -c "cp '${WORK}/manifest.bak' '${BACKUP_PATH}/daily/${nombre}.manifest.json'"
  [ "${rc}" = "6" ] || fail "un manifiesto alterado dio salida ${rc} y se esperaba 6"
  ok "manifiesto alterado -> salida 6"
  # El gancho TOCTOU (KQE_TEST_HOOK_AFTER_VERIFY, que cambia un bit del origen despues de
  # verificar) se ejercita en backup-drill.yml, con un PostgreSQL de privilegios completos.

  step "5 · RPO continuo: la metrica llega y parar el archivado se ve sin esperar a la copia (R5-DV-01, C17)"
  # El rol REAL de las copias, sin pg_monitor: las consultas del exportador funcionan.
  [ "$(sql "SELECT count(*) FROM pg_auth_members m JOIN pg_roles r ON r.oid = m.roleid JOIN pg_roles u ON u.oid = m.member WHERE u.rolname = 'fichaje_backup' AND r.rolname = 'pg_monitor'")" = "0" ] ||
    fail "fichaje_backup es miembro de pg_monitor: no se conceden privilegios para medir el RPO"
  dc exec -T scheduler bash /opt/kronoqr/scripts/wal-metrics.sh || fail "wal-metrics.sh no funciona con el rol fichaje_backup"
  wait_for "as_root test -s '${BACKUP_PATH}/metrics/kronoqr_wal.prom'" 90 "kronoqr_wal.prom aparece en BACKUP_PATH/metrics"
  [ "$(metric kronoqr_wal.prom kronoqr_wal_archive_timeout_seconds)" = "900" ] || fail "archive_timeout no es 900 s en la metrica"
  ok "kronoqr_wal.prom publicado; archive_timeout=900"
  switch_wal
  wait_for "dc exec -T scheduler bash /opt/kronoqr/scripts/wal-metrics.sh && [ \"\$(metric kronoqr_wal.prom kronoqr_wal_unarchived_segments)\" = '0' ]" 60 "la exposicion vuelve a 0 tras archivar"
  ok "tras pg_switch_wal() sin segmentos pendientes"

  # PARAR EL ARCHIVADO: el directorio de WAL deja de ser escribible por postgres.
  as_root chmod 0000 "${WAL_DIR}"
  sql "INSERT INTO ci_wal_probe(scan_id, employee, kiosk) SELECT 'x-' || g, 'y-' || g, 'z-' || g FROM generate_series(1, 500) g"
  switch_wal
  wait_for "dc exec -T scheduler bash /opt/kronoqr/scripts/wal-metrics.sh && [ \"\$(metric kronoqr_wal.prom kronoqr_wal_archive_failing)\" = '1' ]" 120 "con el archivado parado, kronoqr_wal_archive_failing llega a 1 en <= 120 s (sin esperar a ninguna copia)"
  ok "archivado parado visto en la metrica en menos de 2 minutos"
  [ "$(metric kronoqr_wal.prom kronoqr_wal_unarchived_segments)" -ge 1 ] || fail "los segmentos sin archivar no se ven"
  as_root chmod 0750 "${WAL_DIR}"
  as_root chown 70:70 "${WAL_DIR}"
  wait_for "dc exec -T scheduler bash /opt/kronoqr/scripts/wal-metrics.sh && [ \"\$(metric kronoqr_wal.prom kronoqr_wal_archive_failing)\" = '0' ]" 120 "al devolver el permiso el archivado se recupera solo"
  ok "el archivado se recupera solo al volver el permiso"

  step "6 · La clave: derivada de la maestra, solo en postgres y fuera de las salidas (C7)"
  # doctor.sh falla si BACKUP_WAL_KEY no es la derivada.
  as_root cp -p "${PKG}/.env" "${WORK}/env.bak"
  as_root sh -c "sed -i 's/^BACKUP_WAL_KEY=.*/BACKUP_WAL_KEY=$(printf 'a%.0s' $(seq 64))/' '${PKG}/.env'"
  rc=0
  as_root bash "${PKG}/doctor.sh" >"${WORK}/doctor.out" 2>&1 || rc=$?
  as_root cp -p "${WORK}/env.bak" "${PKG}/.env"
  [ "${rc}" != "0" ] || fail "doctor.sh dio verde con una BACKUP_WAL_KEY que no deriva de la maestra"
  grep -q 'BACKUP_WAL_KEY' "${WORK}/doctor.out" || fail "doctor.sh fallo pero no dice nada de BACKUP_WAL_KEY"
  ok "doctor.sh falla con una clave del WAL que no deriva de BACKUP_ENCRYPTION_KEY"
  # Ningun contenedor salvo postgres lleva la clave en su entorno.
  local key cid svc
  key="$(env_value BACKUP_WAL_KEY)"
  for svc in app horizon scheduler reverb nginx; do
    cid="$(dc ps -q "${svc}" 2>/dev/null | head -n 1)"
    [ -n "${cid}" ] || continue
    if as_root docker inspect --format '{{json .Config.Env}}' "${cid}" | grep -qF -- "${key}"; then
      fail "el contenedor ${svc} lleva BACKUP_WAL_KEY en su entorno"
    fi
  done
  ok "app, horizon, scheduler, reverb y nginx no reciben la clave del WAL"
  dc logs --no-color postgres 2>&1 | leaks_wal_key && fail "la clave del WAL aparece en el log de postgres"
  # shellcheck disable=SC2016
  if dc exec -T postgres sh -c 'cat /proc/[0-9]*/cmdline 2>/dev/null | tr "\0" " " | grep -qF -- "$BACKUP_WAL_KEY"'; then
    fail "la clave del WAL aparece en la linea de ordenes de un proceso de postgres"
  fi
  ok "la clave no esta en el log de postgres ni en ninguna linea de ordenes"
  kid_envhash="$(as_root sh -c "head -n 1 \"\$(ls -t '${WAL_DIR}'/*.gz.enc | head -n 1)\"" | sed -n 's/^KQE1 kind=wal kid=\([0-9a-f]\{8\}\) .*/\1/p')"
  [ -n "${kid_envhash}" ] || fail "no se pudo leer el kid del ultimo segmento"
  ok "el ultimo segmento lleva kid ${kid_envhash}"
  printf '\nbackup-wal-e2e clean: TODO COMPROBADO\n'
}

# --- ⑧b · actualizacion desde la 2.1.0 ----------------------------------------

mode_upgrade_seed() {
  step "Semilla (2.1.0 en pie): >= 3 segmentos .gz en claro"
  sql "CREATE TABLE IF NOT EXISTS ci_wal_probe(id serial primary key, v text)"
  for _ in 1 2 3; do
    sql "INSERT INTO ci_wal_probe(v) SELECT 'v-' || g FROM generate_series(1, 300) g"
    switch_wal
    sleep 2
  done
  wait_for "[ \"\$(as_root sh -c \"ls '${WAL_DIR}'/*.gz 2>/dev/null | wc -l\")\" -ge 3 ]" 90 "la 2.1.0 archiva >= 3 segmentos .gz"
  if [ "$(wal_enc_count)" != "0" ]; then
    # La version anterior ya archiva cifrado (no es una 2.1.0): no hay nada heredado.
    : >"${SEED_FILE}"
    ok "la version anterior ya archiva cifrado: nada heredado que sembrar"
    return 0
  fi
  as_root sh -c "ls -l --time-style=+%s '${WAL_DIR}'/*.gz | awk '{print \$6, \$7}'" >"${SEED_FILE}"
  as_root chmod 0644 "${SEED_FILE}"
  ok "$(wal_plain_count) segmentos en claro sembrados"
}

mode_upgrade_check() {
  local nombre copia rc seg heredada

  step "1 · Tras actualizar no queda ningun .gz en claro (<= 120 s) y los heredados conservan su fecha"
  wait_for "[ \"\$(as_root sh -c \"ls '${WAL_DIR}'/*.gz 2>/dev/null | wc -l\")\" = '0' ]" 120 "los .gz heredados se cifran en sitio"
  ok "0 segmentos en claro"
  [ -f "${SEED_FILE}" ] || fail "falta ${SEED_FILE} (modo upgrade-seed)"
  # La semilla es «epoca ruta» separado por espacio, y el IFS de este script no lo lleva.
  while IFS=" " read -r _ nombre; do
    nombre="$(basename "${nombre}" .gz)"
    as_root test -f "${WAL_DIR}/${nombre}.gz.enc" || fail "falta ${nombre}.gz.enc"
    as_root head -n 1 "${WAL_DIR}/${nombre}.gz.enc" | grep -qE ' src=legacy$' || fail "${nombre}.gz.enc no lleva src=legacy"
  done <"${SEED_FILE}"
  ok "los heredados estan cifrados con src=legacy"
  while IFS=" " read -r epoch nombre; do
    nombre="$(basename "${nombre}" .gz)"
    [ "$(as_root stat -c %Y "${WAL_DIR}/${nombre}.gz.enc")" = "${epoch}" ] || fail "${nombre}.gz.enc no conserva la fecha del .gz (la purga lo retendria mas)"
  done <"${SEED_FILE}"
  ok "la fecha (mtime) de cada heredado se conserva"
  # Y el archivado nuevo ya es cifrado.
  switch_wal
  wait_for "[ \"\$(as_root sh -c \"ls -t '${WAL_DIR}'/*.gz.enc | head -n 1 | xargs head -n 1\" | grep -c 'src=legacy')\" = '0' ]" 60 "el archivado nuevo escribe .gz.enc sin src=legacy"
  ok "el archivado nuevo es KQE1"

  step "2 · restore.sh sobre una copia de la 2.1.0: bandera explicita y .sha256 obligatorios"
  heredada=""
  for copia in $(as_root sh -c "ls -t '${BACKUP_PATH}'/daily/*.dump.enc"); do
    if [ "$(as_root head -c 8 "${copia}")" = "Salted__" ]; then
      heredada="${copia}"
      break
    fi
  done
  restore_dry() {
    dc run --rm --no-deps -T restore bash /opt/kronoqr/scripts/restore.sh --database kq_e2e --dry-run "$@"
  }
  if [ -z "${heredada}" ]; then
    ok "no hay copias heredadas en daily/ (la version anterior ya autenticaba): nada que comprobar aqui"
  else
    rc=0
    restore_dry --file "${heredada}" >/dev/null 2>&1 || rc=$?
    [ "${rc}" = "6" ] || fail "una copia de la 2.1.0 sin bandera dio salida ${rc} y se esperaba 6"
    ok "sin bandera -> salida 6"
    restore_dry --file "${heredada}" --accept-unauthenticated >/dev/null || fail "con --accept-unauthenticated y su .sha256 deberia pasar el dry-run"
    ok "con --accept-unauthenticated y su .sha256 -> correcto"
    as_root mv "${heredada}.sha256" "${heredada}.sha256.bak"
    rc=0
    restore_dry --file "${heredada}" --accept-unauthenticated >/dev/null 2>&1 || rc=$?
    as_root mv "${heredada}.sha256.bak" "${heredada}.sha256"
    [ "${rc}" = "6" ] || fail "con la bandera pero sin .sha256 dio salida ${rc} y se esperaba 6"
    ok "con la bandera y sin .sha256 -> salida 6"
  fi

  step "3 · kronoqr-restore-wal: heredado solo con bandera; hueco -> 200; final real -> 1"
  # shellcheck disable=SC2016
  dc exec -T postgres sh -c '
    set -e
    d=/tmp/walx; rm -rf "$d"; mkdir -m 0700 "$d"
    head -c 1024 /dev/urandom | gzip -c > "$d/000000010000000000000010.gz"
    export KRONOQR_WAL_ARCHIVE_DIR="$d"
    rc=0; kronoqr-restore-wal 000000010000000000000010 /tmp/walx-out || rc=$?
    [ "$rc" = 200 ] || { echo "heredado sin bandera: salida $rc (se esperaba 200)"; exit 1; }
    KRONOQR_ACCEPT_LEGACY_WAL=1 kronoqr-restore-wal 000000010000000000000010 /tmp/walx-out
    gzip -dc "$d/000000010000000000000010.gz" | cmp - /tmp/walx-out
    head -c 1024 /dev/urandom | gzip -c > "$d/000000010000000000000012.gz"
    rc=0; KRONOQR_ACCEPT_LEGACY_WAL=1 kronoqr-restore-wal 000000010000000000000011 /tmp/walx-out || rc=$?
    [ "$rc" = 200 ] || { echo "hueco con segmento posterior: salida $rc (se esperaba 200)"; exit 1; }
    rc=0; KRONOQR_ACCEPT_LEGACY_WAL=1 kronoqr-restore-wal 000000010000000000000099 /tmp/walx-out || rc=$?
    [ "$rc" = 1 ] || { echo "final real: salida $rc (se esperaba 1)"; exit 1; }
    rm -rf "$d" /tmp/walx-out
  ' || fail "kronoqr-restore-wal no se comporta como dice el ADR-049 (ver la salida)"
  ok "heredado sin bandera 200 · con bandera 0 · hueco 200 · final real 1"

  step "4 · El candado de la actualizacion ya no esta en BACKUP_PATH, y los montajes son los de la matriz"
  as_root test ! -e "${BACKUP_PATH}/update.lock" || fail "queda un update.lock en BACKUP_PATH"
  as_root test ! -e /var/log/kronoqr/update.lock || fail "queda el candado /var/log/kronoqr/update.lock tras terminar la actualizacion"
  ok "sin candado residual"
  seg="${BACKUP_PATH}"
  expect_readonly horizon "${seg}/.e2e-probe"
  expect_readonly horizon "${seg}/reports/retention/.e2e-probe"
  expect_writable scheduler "${seg}/daily/.e2e-probe"
  expect_writable app "${seg}/reports/retention/.e2e-probe"
  printf '\nbackup-wal-e2e upgrade-check: TODO COMPROBADO\n'
}

case "${MODE}" in
clean) mode_clean ;;
upgrade-seed) mode_upgrade_seed ;;
upgrade-check) mode_upgrade_check ;;
*)
  printf 'modo desconocido: %s (clean, upgrade-seed o upgrade-check)\n' "${MODE}" >&2
  exit 2
  ;;
esac
