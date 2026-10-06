#!/usr/bin/env bash
#
# KronoQR — metricas del RPO, cada minuto (R5-DV-01, RNF-D-02).
#
# Lo lanza el `scheduler` (`php artisan backup:wal-metrics`, cada minuto) y escribe
# `BACKUP_PATH/metrics/kronoqr_wal.prom`, que sirve node-exporter (colector
# textfile). Hasta la 2.2.0 el estado del archivado se publicaba como una FOTO
# al terminar la copia nocturna: si el archivado se paraba a las 10:00 la alerta
# saltaba a las 03:15 del dia siguiente, y el segmento en curso, que es lo que
# separa «perdimos 15 minutos» de «perdimos el dia», no lo medía nadie.
#
# QUE SE MIDE. La edad del ULTIMO archivado no sirve sola: sin escrituras (una
# madrugada) PostgreSQL no cierra segmentos y esa edad crece legitimamente. Lo que
# importa es CUANTO LLEVA SIN PROTEGERSE EL DATO MAS ANTIGUO NO ARCHIVADO, incluido
# el segmento en curso:
#
#   kronoqr_wal_unarchived_age_seconds    edad de ese dato; 0 si no hay nada pendiente
#   kronoqr_wal_unarchived_bytes          bytes de WAL escritos y no archivados
#   kronoqr_wal_unarchived_segments       segmentos COMPLETOS sin archivar (un archivado
#                                         que avanza pero atrasado reinicia la edad y
#                                         taparia el atasco)
#   kronoqr_wal_last_archived_age_seconds solo informativa (-1 si ninguno)
#   kronoqr_wal_archive_failing           1 si el ultimo intento fallo y no se ha recuperado
#   kronoqr_wal_archive_failures_total / kronoqr_wal_archived_total   pg_stat_archiver
#   kronoqr_wal_archive_timeout_seconds   `archive_timeout` (0 = desactivado)
#   kronoqr_wal_exporter_last_run_timestamp_seconds   el latido de este exportador
#   kronoqr_wal_replication_slots_inactive / ..._retained_bytes   slots de replicacion
#     (A3-05; antes salian al terminar la copia diaria)
#
# `dirty_since` (desde cuando hay dato pendiente) vive en `metrics/.wal-exporter.state`
# —oculto: node-exporter solo lee `*.prom`—. `metrics/` lo escriben `app` y
# `horizon` ademas de este script: el fichero se lee con EXPRESIONES REGULARES
# DE ENTEROS, sin `source` ni `eval`, y un valor invalido se ignora (BAJO del
# dictamen de seguridad). Un proceso comprometido puede falsear estas metricas:
# residuo declarado en ADR-049.
#
# Privilegios: el rol `fichaje_backup` (solo lectura) SIN privilegios nuevos:
# `pg_stat_archiver`, `pg_current_wal_insert_lsn()` y `pg_settings` son publicos.
# No se concede `pg_monitor`.
#
# Codigos de salida: 0 medido y escrito · 2 no se puede (sin conexion, sin
# credencial o `metrics/` no escribible; el mensaje dice que hacer). Un fallo NO
# actualiza el fichero: la alerta `MedicionDeWalAusente` salta por el latido.
#
# NINGUN SECRETO NI DATO PERSONAL EN LA SALIDA: nombres de segmento y numeros.

set -euo pipefail
IFS=$'\n\t'

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIR
# shellcheck source-path=SCRIPTDIR
# shellcheck source=lib/backup-common.sh disable=SC1091
. "${SCRIPT_DIR}/lib/backup-common.sh"

# Margen que se descuenta para considerar que hay dato pendiente: tras un cambio de
# segmento el puntero de insercion queda tras la cabecera de pagina (40 bytes).
readonly UMBRAL_PENDIENTE=64

# «X/Y» (hexadecimal) -> bytes.
lsn_a_bytes() {
  local lsn="$1" alto bajo
  [[ "$lsn" =~ ^([0-9A-Fa-f]+)/([0-9A-Fa-f]+)$ ]] || return 1
  alto="${BASH_REMATCH[1]}"
  bajo="${BASH_REMATCH[2]}"
  printf '%s' "$(((16#$alto << 32) | 16#$bajo))"
}

main() {
  local fila slots estado ahora segmento_bytes
  local actividad archivado edad_ultimo archivados fallos fallando lsn_insercion timeout slot_inactivos slot_retenido
  local fin_archivado insercion pendientes_bytes pendientes_segmentos dirty_since dirty_lsn prev_actividad edad
  local linea ultimo_fin

  load_backup_config
  ensure_backup_tree metrics

  fila="$(psql -Atq -F'|' -c "
    SELECT coalesce(a.last_archived_wal, '-'),
           coalesce(extract(epoch FROM now() - a.last_archived_time)::bigint, -1),
           a.archived_count, a.failed_count,
           (a.last_failed_time IS NOT NULL AND (a.last_archived_time IS NULL OR a.last_failed_time > a.last_archived_time))::int,
           pg_current_wal_insert_lsn(),
           (SELECT setting::bigint FROM pg_settings WHERE name = 'archive_timeout'),
           (SELECT setting::bigint FROM pg_settings WHERE name = 'wal_segment_size'),
           (SELECT coalesce(sum(tup_inserted + tup_updated + tup_deleted), 0)::bigint FROM pg_stat_database)
    FROM pg_stat_archiver a" 2>/dev/null)" || fila=""
  [ -n "$fila" ] || die "${KQ_EXIT_REQUIREMENTS}" \
    "no se puede leer el estado del archivado de PostgreSQL (${PGHOST}:${PGPORT} como ${PGUSER}). Comprueba que 'postgres' esta en marcha y que BACKUP_DB_* del .env son los de fichaje_backup. Sin medida, la alerta MedicionDeWalAusente avisara. Ver docs/runbooks/restaurar-backup.md §4.3."

  IFS='|' read -r archivado edad_ultimo archivados fallos fallando lsn_insercion timeout segmento_bytes actividad <<<"$fila"
  [[ "$edad_ultimo" =~ ^-?[0-9]+$ ]] && [[ "$archivados" =~ ^[0-9]+$ ]] && [[ "$fallos" =~ ^[0-9]+$ ]] &&
    [[ "$fallando" =~ ^[01]$ ]] && [[ "$timeout" =~ ^[0-9]+$ ]] && [[ "$segmento_bytes" =~ ^[0-9]+$ ]] && [[ "$actividad" =~ ^[0-9]+$ ]] &&
    [ "$segmento_bytes" -gt 0 ] || die "${KQ_EXIT_REQUIREMENTS}" \
    "PostgreSQL ha devuelto un estado de archivado que no se entiende. Ver docs/runbooks/restaurar-backup.md §4.3."

  insercion="$(lsn_a_bytes "$lsn_insercion")" || die "${KQ_EXIT_REQUIREMENTS}" "LSN de PostgreSQL no valido."

  # Estado entre ejecuciones. Solo enteros y un nombre de segmento, validados.
  ahora="$(now_epoch)"
  dirty_since=0
  dirty_lsn=0
  prev_actividad=-1
  ultimo_fin=0
  estado="${BACKUP_DIR_METRICS}/.wal-exporter.state"
  if [ -f "$estado" ]; then
    while IFS= read -r linea; do
      if [[ "$linea" =~ ^dirty_since=([0-9]{1,12})$ ]]; then
        dirty_since="${BASH_REMATCH[1]}"
      elif [[ "$linea" =~ ^dirty_lsn=([0-9]{1,18})$ ]]; then
        dirty_lsn="${BASH_REMATCH[1]}"
      elif [[ "$linea" =~ ^last_segment_end=([0-9]{1,19})$ ]]; then
        ultimo_fin="${BASH_REMATCH[1]}"
      elif [[ "$linea" =~ ^activity=([0-9]{1,18})$ ]]; then
        prev_actividad="${BASH_REMATCH[1]}"
      fi
    done < <(head -n 12 "$estado" 2>/dev/null || true)
  fi
  # Un `dirty_since` del futuro (reloj, fichero manipulado) no vale.
  [ "$dirty_since" -le "$ahora" ] || {
    dirty_since=0
    dirty_lsn=0
  }

  # Fin del ultimo segmento archivado: nombre = linea temporal (8) + id (8) + segmento (8).
  pendientes_bytes=0
  pendientes_segmentos=0
  # Un `.backup` (copia fisica) lleva el nombre de un segmento que el archivador ya habia
  # archivado antes que el (va detras en el orden de nombres): su FIN es una cota que vale
  # aunque no haya estado anterior (primera ejecucion tras la copia fisica, o estado
  # reiniciado). Un `.partial` nombra el segmento EN CURSO al promocionar: lo archivado
  # llega, como mucho, hasta su INICIO. Se toma el mayor entre la cota y lo recordado.
  fin_nombre=0
  if [[ "$archivado" =~ ^[0-9A-F]{8}([0-9A-F]{8})([0-9A-F]{8})\.[0-9A-F]{8}\.backup$ ]]; then
    fin_nombre="$(((16#${BASH_REMATCH[1]} << 32) + (16#${BASH_REMATCH[2]} + 1) * segmento_bytes))"
  elif [[ "$archivado" =~ ^[0-9A-F]{8}([0-9A-F]{8})([0-9A-F]{8})\.partial$ ]]; then
    fin_nombre="$(((16#${BASH_REMATCH[1]} << 32) + 16#${BASH_REMATCH[2]} * segmento_bytes))"
  fi
  [ "$fin_nombre" -le "$ultimo_fin" ] || ultimo_fin="$fin_nombre"
  if [[ "$archivado" =~ ^[0-9A-F]{8}([0-9A-F]{8})([0-9A-F]{8})$ ]]; then
    fin_archivado="$(((16#${BASH_REMATCH[1]} << 32) + (16#${BASH_REMATCH[2]} + 1) * segmento_bytes))"
    if [ "$insercion" -gt "$fin_archivado" ]; then
      pendientes_bytes="$((insercion - fin_archivado))"
      pendientes_segmentos="$((insercion / segmento_bytes - fin_archivado / segmento_bytes))"
    fi
    ultimo_fin="$fin_archivado"
  elif [ "$archivado" != "-" ] && [ "$ultimo_fin" -gt 0 ] && [ "$ultimo_fin" -le "$insercion" ]; then
    # El ultimo archivado es un `.backup`, `.history` o `.partial` (tras una copia
    # fisica el ultimo es el `.backup`): no dice hasta donde llega el WAL archivado.
    # Se usa el fin del ultimo SEGMENTO real, guardado en la ejecucion anterior; sin
    # esto, una madrugada sin fichajes tras una copia fisica dejaria «pendiente» el
    # resto del segmento y subiria el RPO sin que haya dato en riesgo.
    fin_archivado="$ultimo_fin"
    if [ "$insercion" -gt "$fin_archivado" ]; then
      pendientes_bytes="$((insercion - fin_archivado))"
      pendientes_segmentos="$((insercion / segmento_bytes - fin_archivado / segmento_bytes))"
    fi
  else
    # Ninguno archivado aun (o el ultimo es un fichero de historia y no hay
    # segmento anterior conocido): se mide lo escrito en el segmento en curso.
    pendientes_bytes="$((insercion % segmento_bytes))"
  fi

  # POR QUE SE MIRA LA ACTIVIDAD Y NO SOLO LOS BYTES. PostgreSQL escribe registros
  # «no importantes» (instantaneas de transacciones en curso, cada 15 s) que NO
  # fuerzan el cambio de segmento por `archive_timeout`: un servidor inactivo
  # tiene siempre unos cientos de bytes sin archivar y no los archivara nunca. Eso
  # no es RPO. Hay dato PENDIENTE de verdad cuando ha habido ESCRITURAS (filas
  # insertadas, modificadas o borradas, sumadas de pg_stat_database) desde la
  # ultima vez que el archivo cubrio el WAL.
  #
  # 1) Si el archivo ya cubre lo que estaba pendiente, se limpia.
  if [ "$dirty_lsn" -ne 0 ] && [ "${fin_archivado:-0}" -ge "$dirty_lsn" ]; then
    dirty_since=0
    dirty_lsn=0
  fi
  # 2) Si ha habido escrituras desde la muestra anterior y queda WAL sin archivar,
  #    hay dato pendiente desde ahora (como mucho 60 s de error por defecto).
  if [ "$prev_actividad" -ge 0 ] && [ "$actividad" -ne "$prev_actividad" ] && [ "$pendientes_bytes" -gt "$UMBRAL_PENDIENTE" ]; then
    [ "$dirty_since" -ne 0 ] || dirty_since="$ahora"
    dirty_lsn="$insercion"
  fi

  if [ "$dirty_since" -ne 0 ]; then
    edad="$((ahora - dirty_since))"
  else
    edad=0
  fi

  # Slots de replicacion (A3-05): KronoQR no usa ninguno; uno parado retiene WAL.
  slots="$(psql -Atq -F'|' -c "SELECT count(*) FILTER (WHERE NOT active), coalesce(max(pg_wal_lsn_diff(pg_current_wal_lsn(), restart_lsn)), 0)::bigint FROM pg_replication_slots" 2>/dev/null || true)"
  slot_inactivos="${slots%%|*}"
  slot_retenido="$(printf '%s' "$slots" | cut -d'|' -f2)"
  [[ "$slot_inactivos" =~ ^[0-9]+$ ]] || slot_inactivos=0
  [[ "$slot_retenido" =~ ^[0-9]+$ ]] || slot_retenido=0

  printf 'archived_wal=%s\nlast_segment_end=%s\ndirty_since=%s\ndirty_lsn=%s\nactivity=%s\n' "$archivado" "$ultimo_fin" "$dirty_since" "$dirty_lsn" "$actividad" |
    kq_write_metrics_atomic "$estado" || err "AVISO: no se ha podido guardar el estado del exportador; la edad se medira desde ahora."

  cat <<EOF | kq_write_metrics_atomic "${BACKUP_DIR_METRICS}/kronoqr_wal.prom" ||
# HELP kronoqr_wal_unarchived_age_seconds Edad del dato de WAL mas antiguo sin archivar, incluido el segmento en curso; 0 si no hay nada pendiente. Es el RPO real.
# TYPE kronoqr_wal_unarchived_age_seconds gauge
kronoqr_wal_unarchived_age_seconds ${edad}
# HELP kronoqr_wal_unarchived_bytes Bytes de WAL escritos y aun no archivados.
# TYPE kronoqr_wal_unarchived_bytes gauge
kronoqr_wal_unarchived_bytes ${pendientes_bytes}
# HELP kronoqr_wal_unarchived_segments Segmentos de WAL completos sin archivar.
# TYPE kronoqr_wal_unarchived_segments gauge
kronoqr_wal_unarchived_segments ${pendientes_segmentos}
# HELP kronoqr_wal_last_archived_age_seconds Antiguedad del ultimo segmento archivado (solo informativa; -1 si ninguno).
# TYPE kronoqr_wal_last_archived_age_seconds gauge
kronoqr_wal_last_archived_age_seconds ${edad_ultimo}
# HELP kronoqr_wal_archive_failing 1 si el ultimo intento de archivado fallo y no se ha recuperado.
# TYPE kronoqr_wal_archive_failing gauge
kronoqr_wal_archive_failing ${fallando}
# HELP kronoqr_wal_archive_failures_total Intentos fallidos de archivado desde el ultimo reinicio de estadisticas.
# TYPE kronoqr_wal_archive_failures_total counter
kronoqr_wal_archive_failures_total ${fallos}
# HELP kronoqr_wal_archived_total Segmentos archivados desde el ultimo reinicio de estadisticas.
# TYPE kronoqr_wal_archived_total counter
kronoqr_wal_archived_total ${archivados}
# HELP kronoqr_wal_archive_timeout_seconds Valor de archive_timeout en segundos (0 = desactivado: el RPO deja de estar acotado).
# TYPE kronoqr_wal_archive_timeout_seconds gauge
kronoqr_wal_archive_timeout_seconds ${timeout}
# HELP kronoqr_wal_exporter_last_run_timestamp_seconds Momento de la ultima medida publicada (el latido del exportador).
# TYPE kronoqr_wal_exporter_last_run_timestamp_seconds gauge
kronoqr_wal_exporter_last_run_timestamp_seconds ${ahora}
# HELP kronoqr_wal_replication_slots_inactive Slots de replicacion sin consumidor (retienen WAL). KronoQR no usa ninguno: distinto de 0 es un incidente.
# TYPE kronoqr_wal_replication_slots_inactive gauge
kronoqr_wal_replication_slots_inactive ${slot_inactivos}
# HELP kronoqr_wal_replication_slot_retained_bytes WAL retenido por el slot que mas retiene, en bytes.
# TYPE kronoqr_wal_replication_slot_retained_bytes gauge
kronoqr_wal_replication_slot_retained_bytes ${slot_retenido}
EOF
    die "${KQ_EXIT_REQUIREMENTS}" "no se puede escribir '${BACKUP_DIR_METRICS}/kronoqr_wal.prom'. En el servidor, 'metrics/' tiene que existir y ser del usuario 1000 (sudo -u '#1000' mkdir -m 0750 -- '${BACKUP_DIR_METRICS}'). Ver docs/runbooks/restaurar-backup.md §4.3."
}

main "$@"
