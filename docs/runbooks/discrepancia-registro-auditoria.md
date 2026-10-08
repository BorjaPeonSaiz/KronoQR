# Runbook — el registro horario no cuadra con su auditoría

**Esto es un posible incidente de seguridad, no una avería.** Alguien ha escrito
en el registro horario por fuera de la aplicación. Antes de tocar nada, lee la
§2: hay evidencia que se pierde sola. Si se confirma un acceso indebido, el
procedimiento de 72 h es [`brecha-de-seguridad.md`](brecha-de-seguridad.md).

**Alertas que llevan aquí** (ADR-057 §4), definidas en
[`infra/observability/prometheus/rules/work-record.yml`](../../infra/observability/prometheus/rules/work-record.yml):

| Alerta | Umbral | Severidad | Destinatario | Sección |
| --- | --- | --- | --- | --- |
| `DiscrepanciaEntreRegistroYAuditoria` | cualquiera, `for: 1m` | Crítica (seguridad) | Responsable de seguridad | [§3](#3-las-cuatro-discrepancias) |
| `ConciliacionDelRegistroAusente` | > 26 h sin la pasada diaria, `for: 30m` | Crítica (seguridad) | Responsable de seguridad | [§5](#5-nadie-está-conciliando-el-silencio) |
| `ConciliacionCompletaDelRegistroAusente` | > 8 días sin la pasada completa, `for: 30m` | Alta | Responsable de seguridad | [§5](#5-nadie-está-conciliando-el-silencio) |

**Impacto en el fichaje: ninguno.** Nadie se queda sin fichar y no hay que parar
nada. Lo que está en juego es que el registro que se entrega a la Inspección
diga lo que de verdad pasó (RL-04). **Mientras no lo aclares, no generes
exportaciones legales del periodo afectado**: saldrían con lo manipulado.

---

## 1. Qué hay montado, en 30 segundos

La cadena de hash ([`rotura-cadena-auditoria.md`](rotura-cadena-auditoria.md))
protege `audit_log`, no `shift_entries`. La aplicación puede escribir en el
registro horario —tiene que poder, para fichar—, y con su credencial se puede
cambiar la hora de un tramo, borrarlo, inventarse uno o anularlo sin
corrección **sin tocar la cadena**, que sigue en verde.

Cada vez que la aplicación escribe un tramo —fichar entrada o salida, una
corrección, una anulación, un alta manual— escribe **en la misma transacción**
un asiento `shift_entry.*` en `audit_log` con las marcas, la persona, la jornada
y, si es una corrección, el antes y el después. Ese asiento no se puede
reescribir sin romper la cadena. La conciliación compara cada tramo con el
**último** asiento que habla de él.

| Pieza | Qué hace | Dónde |
| --- | --- | --- |
| `compliance:reconcile-work-record` | A diario, 04:15 UTC: los tramos de los últimos 7 días y todo lo que la auditoría apuntó en ese plazo | Contenedor `scheduler` |
| `compliance:reconcile-work-record --full` | Los domingos, 02:15 UTC: todo el registro y toda la auditoría viva. Es la única que ve un borrado o una edición de un tramo antiguo | Contenedor `scheduler` |
| `work_record_reconciliation_discrepancies{scope,kind}` | Discrepancias de la última pasada de cada alcance. **Debe estar siempre a cero** | `BACKUP_PATH/metrics/kronoqr_work_record_reconciliation_recent.prom` y `..._full.prom` |
| `work_record_reconciliation_last_run_timestamp_seconds{scope}` | Cuándo terminó la última pasada de cada alcance | Mismos ficheros |

**Solo lee**, en una instantánea `REPEATABLE READ, READ ONLY`, y no corrige
nada: cuál de los dos lados dice la verdad lo decides tú con este runbook. La
salida del comando y el log técnico (evento `work_record_reconciliation_mismatch`)
llevan el `uuid` del tramo, el tipo, **los nombres** de los campos que no
coinciden y el `id` del asiento. **Nunca valores ni nombres de personas.**

**Lo que no es una discrepancia** —y por eso la alerta, si suena, es de
verdad—: un tramo purgado por retención (la purga deja su asiento
`retention.purge_executed` con la fecha de corte, y lo anterior a ese corte se
da por purgado); un tramo cuyo asiento se fue con la partición anual de
`audit_log` ya sellada (ADR-027); y todo lo que hace el producto, que siempre
escribe su asiento. **No hay cierre automático de turnos** (RN-08): un turno
abierto sigue abierto hasta que alguien ficha o lo corrige.

---

## 2. Antes de tocar nada: preservar la evidencia

En este orden. Es el mismo procedimiento que el de la cadena, con la salida de
este comando en lugar de la de aquel.

```bash
# 0. Marca temporal del incidente, para nombrar todo lo demás.
INCIDENTE="registro-$(date -u +%Y%m%dT%H%M%SZ)"; echo "$INCIDENTE"

# 1. El hallazgo completo, sobre TODO el registro y no solo la ventana.
docker compose exec -T app php artisan compliance:reconcile-work-record --full \
  | tee "/tmp/${INCIDENTE}-conciliacion.txt"

# 2. ¿Está tocada también la cadena? Si sí, sigue ADEMÁS rotura-cadena-auditoria.md.
docker compose exec -T app php artisan compliance:verify-audit-chain \
  | tee "/tmp/${INCIDENTE}-cadena.txt"

# 3. Copia inmediata de la base. NO esperes a la nocturna: cualquier
#    corrección posterior se lleva el estado manipulado, que es la evidencia.
docker compose exec -T scheduler php artisan backup:run --mode=dump

# 4. Registro del motor de las últimas dos semanas.
docker compose logs --no-color --since 336h postgres \
  > "/tmp/${INCIDENTE}-postgres.log"

# 5. Quién está conectado ahora mismo, y desde dónde.
docker compose exec -T postgres psql -U fichaje_migrator -d fichaje -c \
  "SELECT usename, client_addr, backend_start, state, query
     FROM pg_stat_activity WHERE datname = current_database()"
```

Guarda los ficheros **fuera del servidor** antes de seguir. La salida de los
pasos 1 y 2 no lleva datos personales; la copia del paso 3 sí, y se custodia
como cualquier copia.

---

## 3. Las cuatro discrepancias

Cada línea de la salida dice el tramo, el tipo y, cuando lo hay, el asiento:

```text
shift_entries 0199a1b2-…-a001 · entry_differs_from_audit · clocked_in_at · audit_log #48211
```

Las consultas de abajo se lanzan con
`docker compose exec -T postgres psql -U fichaje_migrator -d fichaje`, y `U` es
el `uuid` de la línea. **Muestran datos personales**: no copies su salida en un
ticket ni en un correo al fabricante.

```sql
-- La fila tal y como está ahora.
SELECT * FROM shift_entries WHERE uuid = 'U';

-- Todo lo que la auditoría dice de ese tramo, en orden. El último es la referencia.
SELECT id, occurred_at, action, actor_type, actor_id, payload
  FROM audit_log
 WHERE action LIKE 'shift_entry.%'
   AND (payload @> '{"shift_entry_uuid": "U"}' OR payload @> '{"superseded_shift_entry_uuid": "U"}')
 ORDER BY id;

-- Sus correcciones (autor y motivo) y sus fichajes en el quiosco.
SELECT * FROM shift_corrections WHERE shift_entry_id = (SELECT id FROM shift_entries WHERE uuid = 'U');
SELECT scan_id, device_id, occurred_at, recorded_at, result
  FROM scan_events WHERE shift_entry_id = (SELECT id FROM shift_entries WHERE uuid = 'U');

-- Pista forense: la transacción que escribió la fila por última vez. Si es
-- distinta de la del último asiento, la fila se tocó DESPUÉS, en otra
-- transacción. Deja de servir si la tabla se ha congelado (VACUUM FREEZE).
SELECT xmin FROM shift_entries WHERE uuid = 'U';
SELECT xmin FROM audit_log WHERE id = <id del asiento>;
```

### `entry_differs_from_audit` — una marca, la persona o el estado no son los del asiento

Lo más habitual: un `UPDATE` sobre una hora. Los campos que no coinciden van en
la línea: `employee_uuid`, `site_id`, `work_date`, `clocked_in_at`,
`clocked_out_at`, `duration_minutes`, `status`, `version`, `superseded_by` o
`shift_corrections` (es una corrección y falta su fila con autor y motivo).

**Resolución.** El valor bueno es el del último asiento.

- **Una hora (`clocked_in_at`, `clocked_out_at`)**: corrige el tramo **desde
  el panel**, igual que cualquier corrección
  ([`guia-rrhh.md`](../cliente/guia-rrhh.md) §5), a las marcas del asiento,
  con el motivo `OTROS` y el número del incidente en el texto. Queda una
  corrección con autor, el valor manipulado como «antes» y el bueno como
  «después»: es la traza que quieres, y la conciliación vuelve a cuadrar
  porque el asiento nuevo describe las dos versiones.
- **Solo los minutos, la persona, el centro, la jornada, el estado, la versión
  o `superseded_by`**: el panel no los puede cambiar (una corrección no mueve
  un tramo de persona ni de día, y no admite una corrección que deje las horas
  como estaban). Devuelve la columna al valor del asiento con el rol de
  migración, en una intervención anotada en el registro del incidente; los
  minutos son `(salida − entrada)` en segundos enteros, divididos por 60 y
  truncados.

### `entry_without_audit` — un tramo que la aplicación nunca escribió

Un `INSERT` directo: horas que nadie fichó ni dio de alta. **Resolución:**
anúlalo **desde el panel** con el motivo `OTROS` y el número del incidente. La
anulación escribe su asiento con las marcas que tenía y el tramo deja de sumar.
No lo borres: la fila es parte de la evidencia.

### `retired_without_correction` — un tramo anulado o sustituido sin corrección

Un `UPDATE status` a `voided` o `superseded`: desaparecen horas de la
exportación sin que nadie firme. O una anulación cuya fila de
`shift_corrections` ha desaparecido. **Resolución:** devuelve `status` (y
`superseded_by_id`, si cambió) a lo que dice el último asiento con el rol de
migración; si lo que falta es la fila de corrección, recupérala de la copia de
anoche (§4).

### `audit_without_entry` — un asiento cuyo tramo ya no existe

Un `DELETE`. La conciliación ya ha descartado la purga de retención: el tramo
es posterior al último corte purgado. **Resolución:** recupera la fila de la
copia de anoche (§4) **con el mismo `uuid`** y vuelve a insertarla con el rol
de migración, junto con lo que el atacante borrase antes para poder borrarla
(sus `shift_corrections`, sus `scan_events`).

**En los cuatro casos**, al terminar:

```bash
docker compose exec -T app php artisan compliance:reconcile-work-record --full
docker compose exec -T app php artisan attendance:reconcile --from=<primer día> --to=<último día>
```

La primera tiene que salir en verde. La segunda recalcula `daily_totals` de
los días tocados: la proyección se construye desde `shift_entries` y estará
contando lo manipulado.

---

## 4. Recuperar una fila de la copia de anoche

Igual que en [`rotura-cadena-auditoria.md`](rotura-cadena-auditoria.md) §3:
restaura la copia **de antes de la manipulación** en un contenedor limpio, sin
red y nunca sobre `fichaje`, con el simulacro, y consulta allí la fila:

```bash
# 1. La copia de ANOCHE (la del paso 3 de la §2 ya está manipulada).
docker compose exec scheduler bash /opt/kronoqr/scripts/backup.sh list

# 2. Restáurala en un contenedor limpio y sin red. Desde el directorio de la
#    instalación; imprime «Contenedor conservado: kronoqr-drill-<marca>».
sudo bash ./restore-drill.sh --keep \
  --file "${BACKUP_PATH:-/var/backups/fichaje}/daily/<copia-de-anoche>.dump.enc"

# 3. La fila como estaba, y lo que la referenciaba.
docker exec -i kronoqr-drill-<marca> psql -U postgres -d drill -c \
  "SELECT * FROM shift_entries WHERE uuid = 'U'"

# 4. Destrúyelo al acabar: contiene el registro completo de la plantilla.
docker rm -f kronoqr-drill-<marca>
```

Si la manipulación es anterior a la copia más antigua que conservas, la fila
no se puede recuperar: el asiento es entonces la única constancia de lo que
decía, y así se hace constar en el registro del incidente.

---

## 5. Nadie está conciliando (el silencio)

Una edición del registro solo se detecta al día siguiente si la pasada diaria
se ejecuta; un borrado antiguo, si se ejecuta la del domingo. Sin estas dos
alertas, apagar el `scheduler` sería la forma más cómoda de que la de la §3 no
volviera a sonar.

```bash
docker compose ps scheduler
docker compose exec -T app php artisan schedule:list | grep reconcile-work-record
docker compose exec -T app php artisan compliance:reconcile-work-record          # diaria, a mano
docker compose exec -T app php artisan compliance:reconcile-work-record --full   # completa, a mano
cat "${BACKUP_PATH:-/var/backups/fichaje}/metrics/kronoqr_work_record_reconciliation_recent.prom"
```

Causas, de más a menos frecuente: el `scheduler` parado; el destino de
métricas (`BACKUP_PATH`) sin montar o sin permisos; `node-exporter` sin acceso
al volumen. Tras instalar o actualizar, la pasada completa no corre hasta el
primer domingo: su alerta de silencio no suena hasta que la diaria lleva una
semana publicando, precisamente para no avisar de eso.

---

## 6. Escalado

| Situación | A quién | En cuánto |
| --- | --- | --- |
| Cualquier discrepancia de la §3 | Responsable de seguridad del cliente **y** DPO | Inmediato |
| Discrepancia y además rotura de la cadena | Lo mismo, y [`rotura-cadena-auditoria.md`](rotura-cadena-auditoria.md) en paralelo | Inmediato |
| Se confirma un acceso indebido | [`brecha-de-seguridad.md`](brecha-de-seguridad.md): 72 h para la AEPD (RL-15) | Desde que se confirma |
| Silencio de la diaria | IT del cliente | Dentro de la jornada |
| Silencio de la completa | IT del cliente | Dentro de la semana |

**El fabricante no accede a los datos del cliente** (ADR-020, regla dura 16).
La salida de `compliance:reconcile-work-record` se puede compartir tal cual:
identificadores, tipos y nombres de campo, sin valores ni personas. El
contenido de `shift_entries` y de `audit_log` **no sale de la instalación**.
