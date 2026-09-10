# Runbook — se queda sin espacio el disco del servidor

**Alertas que llevan aquí** (doc 01 §9.3, fila *«Espacio en disco | < 20 % |
Alta»*), definidas en
[`infra/observability/prometheus/rules/host.yml`](../../infra/observability/prometheus/rules/host.yml):

| Alerta | Umbral | Severidad | Destinatario | Sección |
| --- | --- | --- | --- | --- |
| `EspacioEnDiscoBajo` | menos del 20 % libre en `/`, `for: 15m` | Alta | IT del cliente | [§2](#2-qué-está-creciendo-y-cuánto) |
| `MetricasDelAnfitrionAusentes` | sin métricas del anfitrión, `for: 30m` | Media | IT del cliente | [§4](#4-nadie-está-informando-el-silencio) |

**A las 06:30, quien la reciba hace esto:** identifica qué está creciendo con
`docker system df` y `du` (§2), libera lo que se puede borrar **sin tocar el
registro legal** en el orden de §3, y si el disco ya está por debajo del
margen de una noche más, amplía el almacenamiento antes de seguir posponiendo.

---

## 1. Por qué esta alerta no espera al fin de mes

Un disco que se llena no avisa solo: PostgreSQL se detiene, el archivado de
WAL se para —con él, el RPO de 15 minutos deja de ser cierto— y ninguna copia
nueva se puede escribir. Es la misma familia de fallos que
[`restaurar-backup.md`](restaurar-backup.md) ya cubre para el **disco de
copias** (`DiscoDeCopiasCasiLleno`) y para el **archivado del WAL**
(`ArchivadoDeWalDetenido`): esta alerta es la que mira el **disco del sistema
operativo entero**, que es donde vive también lo que Docker gestiona por su
cuenta — las imágenes, los volúmenes con nombre de PostgreSQL, Prometheus,
Loki y Tempo — y que ninguna de las otras dos alertas ve.

**Impacto en el fichaje: ninguno mientras quede margen**, y creciente según
se agota. Un disco lleno del todo detiene PostgreSQL, y con él, todo lo que
escribe: el fichaje deja de confirmarse en el servidor — los quioscos siguen
encolando en local, regla dura 19, pero nada sincroniza hasta que hay sitio
otra vez. El 20 % de margen de la alerta está pensado para que nunca se
llegue a ese punto si se actúa el mismo día.

---

## 2. Qué está creciendo, y cuánto

```bash
df -h /
docker system df -v
du -sh "${BACKUP_PATH:-/var/backups/fichaje}"/{daily,base,wal,reports,metrics} 2>/dev/null
```

Lo que ocupa espacio en una instalación de KronoQR, y dónde:

| Qué | Dónde | Cuánto, y de qué depende |
| --- | --- | --- |
| **Base de datos** (`shift_entries`, `audit_log`, `daily_totals`…) | Volumen con nombre `postgres-data`, gestionado por Docker | Crece con la plantilla y con los años de retención legal — cuatro años de serie. Nunca se purga por espacio, solo por la retención del perfil de cumplimiento (`operacion.md` §2) |
| **WAL sin archivar todavía** | Dentro del propio volumen de PostgreSQL, hasta que `kronoqr-archive-wal` lo mueve a `BACKUP_PATH/wal` | Si el archivado se detiene, esto es lo que llena el disco y para la base entera — ver [`restaurar-backup.md`](restaurar-backup.md) §4 |
| **Copias diarias y semanales** | `BACKUP_PATH/daily` y `BACKUP_PATH/base` | `BACKUP_RETENTION_DAYS` (30 de serie), nunca menos de `BACKUP_MIN_COPIES` (3) — `configuracion.md` §6.22 |
| **WAL ya archivado** | `BACKUP_PATH/wal` | `BACKUP_WAL_RETENTION_DAYS` (8 de serie) |
| **Informes de copia, restauración y actualización** | `BACKUP_PATH/reports` | Pequeños, se acumulan indefinidamente — no hay purga automática, es la constancia de que la operación se hizo (`operacion.md` §6) |
| **Registro técnico y trazas** | Volúmenes con nombre `loki-data` y `tempo-data`, solo si el perfil `observability` está encendido | 90 días (`TECHNICAL_LOG_RETENTION_DAYS`), fijado además en `infra/observability/loki/loki.yaml` y `tempo.yaml`. Nulo si `LOKI_URL` y `OTEL_EXPORTER_OTLP_ENDPOINT` están vacíos |
| **Métricas de Prometheus** | Volumen con nombre `prometheus-data` | 90 días (`--storage.tsdb.retention.time=90d`, fijo en `infra/compose.prod.yaml`) |
| **Histórico de errores técnicos** (`error_events`) | Dentro de la propia base de datos | 90 días (`ERROR_HISTORY_RETENTION_DAYS`), purga sola a diario. Pesa poco frente al resto — no es el sospechoso habitual |
| **Imágenes de versiones anteriores** | Almacenamiento de imágenes de Docker | Cada `update.sh` descarga las imágenes de la versión nueva y **no borra** las de la anterior — es la forma en que la vuelta atrás sigue siendo posible sin volver a descargar nada |

---

## 3. Qué se puede liberar, en orden, y qué no se toca nunca

**Nunca, bajo ningún concepto** (regla dura 5, la propia razón de ser del
producto): `shift_entries`, `scan_events`, `audit_log`, `daily_totals`
—que además es una proyección reconstruible, no algo que "pese" de verdad—,
ni **una copia sin verificar todavía**, aunque parezca redundante con la de
al lado. Nada de esto se libera nunca para hacer sitio: es exactamente el
registro que la ley obliga a conservar.

En este orden, de lo más seguro a lo que exige más criterio:

### 3.1 Imágenes de Docker que ya no usa ninguna versión instalada

```bash
docker image ls
docker system df -v      # qué imagen pesa cuánto, y si esta en uso
```

**Conserva siempre** la imagen de la versión que está corriendo **y** la de
la versión anterior — es la que usaría una vuelta atrás manual
([`actualizacion-cliente.md`](actualizacion-cliente.md) §5). Todo lo más
antiguo que eso es candidato seguro:

```bash
docker image prune -a --filter "until=720h"   # imagenes sin usar de mas de 30 dias
```

`--filter "until="` y no un `prune -a` a secas: sin filtro, `prune -a` borra
**cualquier** imagen que no tenga un contenedor corriendo ahora mismo,
incluida la de la versión anterior que necesitarías para una vuelta atrás de
emergencia.

### 3.2 Contenedores parados y redes huérfanas

```bash
docker container prune --filter "until=168h"
docker network prune
```

Sin riesgo para los datos: ninguno de los dos toca un volumen.

### 3.3 Copias de seguridad, bajando la retención

Solo si lo anterior no fue suficiente, y **en este orden de preferencia**
(el mismo de [`restaurar-backup.md`](restaurar-backup.md) §5):

1. **Amplía el disco primero, si puedes.** La obligación de conservar cuatro
   años de registro (RL-05) no negocia con el tamaño del disco que había el
   día de la instalación.
2. Si de verdad no hay margen para ampliar hoy, baja `BACKUP_RETENTION_DAYS`
   en el `.env` — nunca por debajo de `BACKUP_MIN_COPIES`.
3. Baja `BACKUP_WAL_RETENTION_DAYS` en último lugar, y solo si sigue siendo
   mayor que el intervalo entre copias físicas: sin la copia completa
   anterior, ese WAL archivado no reconstruye nada.

```bash
docker compose exec app bash /opt/kronoqr/scripts/backup.sh prune
```

**No borres ficheros de `BACKUP_PATH/daily` ni `/base` a mano con `rm`.**
`backup.sh prune` respeta `BACKUP_MIN_COPIES` y deja constancia en su
informe; un `rm` directo no.

### 3.4 Retención de observabilidad, si el disco lo necesita antes de 90 días

Bajar `TECHNICAL_LOG_RETENTION_DAYS` no basta por sí solo — hay que editar
**a la vez** `infra/observability/loki/loki.yaml` y
`infra/observability/tempo/tempo.yaml` (`configuracion.md` §6.16) — así que
es la última palanca, no la primera: entre Loki, Tempo y Prometheus hay
retenciones ya acotadas a 90 días, frente a los cuatro años del registro
legal. Si el disco de observabilidad es el problema y no puedes ampliarlo,
valora apagar el perfil (`COMPOSE_PROFILES=` vacío, `operacion.md` §10) antes
que acortar por debajo de lo que necesites para investigar un incidente.

### 3.5 Lo que NUNCA se libera para hacer sitio

- **Ninguna tabla del registro horario** (`shift_entries`, `scan_events`,
  `audit_log`, `employment_contracts`…): la retención de esas la fija el
  perfil de cumplimiento (`operacion.md` §2), no el espacio en disco.
- **La copia más reciente**, aunque no la hayas verificado todavía. Trátala
  como si fuera la única que tienes hasta que `backup:verify` confirme lo
  contrario.
- **`audit_chain_anchors`**: son las anclas que sellan cada partición de
  auditoría al soltarla (ADR-027). Sin ellas, una purga legítima de
  particiones antiguas se vería como una rotura de cadena.

---

## 4. Nadie está informando (el silencio)

`MetricasDelAnfitrionAusentes` no dice que el disco esté mal: dice que **no
se sabe**, porque `node-exporter` ha dejado de publicar sus propias
métricas — y con ellas, la alerta de la §2 queda ciega.

```bash
docker compose ps node-exporter
docker compose logs --tail 50 node-exporter
curl -s http://node-exporter:9100/metrics | grep node_filesystem_size_bytes | head
```

Causas por frecuencia: el contenedor `node-exporter` parado, o sin acceso al
punto de montaje raíz del anfitrión (`--path.rootfs=/host`, que
`infra/compose.prod.yaml` ya declara).

**Las dos alertas de este documento se callan durante la ventana de
mantenimiento semanal declarada** (`ALERT_MAINTENANCE_WEEKDAY`,
`ALERT_MAINTENANCE_START`, `ALERT_MAINTENANCE_END`, domingo 02:00–04:00 de
serie) y durante la ventana automática que abre `update.sh` mientras dura la
actualización: las dos pertenecen al componente `host`, y esa es la categoría
—junto con `kiosk`, `api` y `tls`— que la norma anti-fatiga silencia a
propósito, porque un servidor que se reinicia en su propia ventana no tiene
que despertar a nadie. Lo que **nunca** se silencia es integridad, copia,
auditoría, autenticación e incidencias — si una de esas suena a la vez que
esta, no la descartes por estar «dentro de la ventana».

---

## 5. Escalado

| Situación | A quién | En cuánto |
| --- | --- | --- |
| Por debajo del 20 %, con margen para limpiar imágenes y ajustar retención | IT del cliente | Dentro de la jornada |
| Por debajo del 20 % y sin margen para ampliar el disco esta semana | IT del cliente, y avisar al responsable del contrato: hace falta más almacenamiento | Esta semana |
| El disco se llenó del todo y PostgreSQL se detuvo | IT del cliente, urgente — sigue liberando espacio (§3) y comprueba que la base vuelve a arrancar | Inmediato |
| Silencio de las métricas del anfitrión | IT del cliente | Dentro de la jornada |

**Relacionados:** [`restaurar-backup.md`](restaurar-backup.md) §4 y §5 ·
[`actualizacion-cliente.md`](actualizacion-cliente.md) ·
[`../cliente/operacion.md`](../cliente/operacion.md) §1, §2 y §10.
