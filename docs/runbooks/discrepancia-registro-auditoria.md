# Runbook — el registro horario no cuadra con su auditoría

**Esto es un posible incidente de seguridad, no una avería.** Alguien ha escrito
en el registro horario por fuera de la aplicación. Antes de tocar nada, lee la
§2: hay evidencia que se pierde sola. Si se confirma un acceso indebido, el
procedimiento de 72 h es [`brecha-de-seguridad.md`](brecha-de-seguridad.md).

**Alertas que llevan aquí** (ADR-057 §4), definidas en
[`infra/observability/prometheus/rules/work-record.yml`](../../infra/observability/prometheus/rules/work-record.yml):

| Alerta | Umbral | Severidad | Destinatario | Sección |
| --- | --- | --- | --- | --- |
| `DiscrepanciaEntreRegistroYAuditoria` | cualquiera, `for: 1m` | Crítica (seguridad) | Responsable de seguridad | [§3](#3-las-cinco-discrepancias) |
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
un asiento `shift_entry.*` en `audit_log` con las marcas, la persona, la
jornada, el origen del fichaje y, si es una corrección, el antes, el después, el
motivo y quién la firmó. Un asiento ya escrito no se puede cambiar sin romper la
cadena. La conciliación compara cada tramo con sus asientos:

- el **último** dice las marcas, la persona, el centro, la jornada, el estado y
  la versión;
- el **primero** dice de dónde vino la entrada (`qr_kiosk`, `pin_kiosk`, o
  `manual_admin` si la puso una persona desde el panel), y el **último que fijó
  las marcas**, de dónde vino la salida. Las dos cosas salen en la exportación
  legal;
- cada asiento de corrección exige su fila en `shift_corrections` con la
  **misma acción, el mismo motivo y el mismo autor**.

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
verdad—:

- un tramo purgado por retención: la purga deja su asiento
  `retention.purge_executed` con el corte y el centro, y lo anterior a ese corte
  se da por purgado **solo si el asiento es coherente** —corte con forma de
  fecha, no posterior a la fecha del propio asiento menos sus años de
  conservación, años no por debajo del mínimo del perfil, asiento no posterior a
  la pasada y con su centro—. Un asiento que no lo cumple no explica nada y
  sale él mismo como `purge_out_of_bounds`;
- el turno de la noche del 31 de diciembre cuyo asiento se fue con la partición
  anual de `audit_log` ya sellada (ADR-027): entrada el 31 en UTC, jornada del 1
  o el 2 de enero siguiente. Ningún otro tramo sin asiento se da por bueno;
- todo lo que hace el producto, que siempre escribe su asiento. **No hay cierre
  automático de turnos** (RN-08): un turno abierto sigue abierto hasta que
  alguien ficha o lo corrige.

**En un entorno de desarrollo la alerta suena**: la semilla de datos
(`DevelopmentSeeder`, solo en `local` y `testing`) escribe tramos directamente,
sin asiento, y salen como `entry_without_audit`. En una instalación de cliente
no hay semilla.

### 1.1 Lo que la conciliación no ve

Dicho con exactitud, porque es lo que hay que tener presente al investigar:

- **Una escritura acompañada de su asiento falsificado.** La aplicación tiene
  `INSERT` sobre `audit_log` y la cadena de hash no lleva secreto: con la
  credencial de la aplicación se puede **añadir** un asiento `shift_entry.*` o
  `retention.purge_executed` bien encadenado que «explique» un tramo inventado,
  una hora cambiada o un borrado. La conciliación lo da por bueno; la cadena
  también. Lo único que lo delata es el historial del tramo (§3, «Antes de
  restaurar nada») y que el asiento añadido sea **nuevo**: su `id` y su
  `occurred_at` son de cuando se escribió, no de cuando dice que ocurrió el
  fichaje.
- **Un asiento de purga falsificado que respeta los límites** —borrar lo que ya
  se podía purgar—. Se distingue cotejándolo con el informe de retención
  ([`operacion.md`](../cliente/operacion.md) §3.1): una purga real deja los dos
  con el mismo token.
- **Asientos antiguos sin `anomalies`**: de esos no se sabe si el tramo tenía
  que quedar `closed` o `anomalous`, y se admiten los dos.
- **El origen de un lado que una corrección no cambió**: lo hereda de la
  versión anterior y el asiento de la corrección no lo dice. Tampoco el origen
  de la entrada de un tramo antiguo en la pasada diaria, cuyo primer asiento
  cae fuera de la ventana (la semanal sí lo ve).
- **Los minutos de un tramo anulado o sustituido**: no suman en ninguna parte y
  no se comparan.
- **Las métricas**: los ficheros `.prom` los escribe el propio runtime en
  `BACKUP_PATH/metrics`, y quien tenga su credencial puede escribir un cero.
  Ante la duda, lanza el comando a mano (§5): su salida no depende del fichero.

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

## 3. Las cinco discrepancias

Cada línea de la salida dice el tramo, el tipo y, cuando lo hay, el asiento:

```text
shift_entries 0199a1b2-…-a001 · entry_differs_from_audit · clocked_in_at · audit_log #48211
audit_log #48390 · purge_out_of_bounds · cutoff_date
```

Las consultas de abajo se lanzan con
`docker compose exec -T postgres psql -U fichaje_migrator -d fichaje`, y `U` es
el `uuid` de la línea. **Muestran datos personales**: no copies su salida en un
ticket ni en un correo al fabricante.

```sql
-- La fila tal y como está ahora.
SELECT * FROM shift_entries WHERE uuid = 'U';

-- Todo lo que la auditoría dice de ese tramo, en orden.
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

### Antes de restaurar nada: ¿el asiento es de fiar?

Las resoluciones de abajo devuelven la fila **a lo que dicen los asientos**. Eso
solo es correcto si los asientos son los que escribió la aplicación, y la §1.1
explica que con su credencial se pueden añadir asientos falsos bien
encadenados. Con la salida de la segunda consulta delante, busca:

- **Secuencias imposibles**: dos `shift_entry.closed` de fichaje (sin
  `reason_code`) para el mismo tramo; un `shift_entry.created` que no es el
  primero; un asiento cuyo `occurred_at` es muy anterior a su `id` vecino (el
  `id` crece con cada escritura: un asiento de un fichaje de hace semanas con un
  `id` de hoy se escribió hoy).
- **Asientos de fichaje sin su `scan_events`**: todo fichaje del quiosco deja su
  escaneo con `scan_id` y `device_id` (tercera consulta). Un asiento de entrada o
  salida sin escaneo que le corresponda, o con un dispositivo que no existe o
  estaba desvinculado, no lo escribió un quiosco.
- **Correcciones sin sesión de gestión**: el autor de una corrección
  (`performed_by_user_id`) tuvo que entrar en el panel. Busca sus
  `auth.login_succeeded` en `audit_log` (`actor_type = 'user'`,
  `actor_id = <autor>`) antes del `occurred_at` de la corrección, y su acceso en
  los registros del servidor web (`docker compose logs nginx`). Una corrección firmada por alguien que no tenía sesión no la
  hizo esa persona.

Si algo de esto aparece, **el asiento es parte del ataque**: no restaures a lo
que dice; ve a [`brecha-de-seguridad.md`](brecha-de-seguridad.md) y recupera el
estado de la copia de antes de la manipulación (§4).

### `entry_differs_from_audit` — algo del tramo no es lo que dicen sus asientos

Lo más habitual: un `UPDATE` sobre una hora. Los campos que no coinciden van en
la línea: `employee_uuid`, `site_id`, `work_date`, `clocked_in_at`,
`clocked_out_at`, `duration_minutes`, `clock_in_source`, `clock_out_source`,
`status`, `version`, `superseded_by` o `shift_corrections` (es una corrección y
falta su fila, o la que hay tiene otra acción, otro motivo u otro autor).

**Resolución**, tras la comprobación de arriba. El valor bueno es el del
asiento.

- **Una hora (`clocked_in_at`, `clocked_out_at`)**: corrige el tramo **desde
  el panel**, igual que cualquier corrección
  ([`guia-rrhh.md`](../cliente/guia-rrhh.md) §5), a las marcas del asiento,
  con el motivo `OTROS` y el número del incidente en el texto. Queda una
  corrección con autor, el valor manipulado como «antes» y el bueno como
  «después»: es la traza que quieres, y la conciliación vuelve a cuadrar
  porque el asiento nuevo describe las dos versiones.
- **Solo los minutos, el origen, la persona, el centro, la jornada, el estado,
  la versión o `superseded_by`**: el panel no los puede cambiar (una corrección
  no mueve un tramo de persona ni de día, y no admite una corrección que deje
  las horas como estaban). Devuelve la columna al valor del asiento con el rol
  de migración, en una intervención anotada en el registro del incidente; los
  minutos son `(salida − entrada)` en segundos enteros, divididos por 60 y
  truncados.
- **`shift_corrections`**: devuelve el motivo o el autor de la fila a los del
  asiento (`reason_code`, `performed_by_user_id`) con el rol de migración, o
  recupera la fila de la copia (§4) si ha desaparecido.

### `entry_without_audit` — un tramo que la aplicación nunca escribió

Un `INSERT` directo: horas que nadie fichó ni dio de alta. **Resolución:**
anúlalo **desde el panel** con el motivo `OTROS` y el número del incidente. La
anulación escribe su asiento con las marcas que tenía y el tramo deja de sumar.
No lo borres: la fila es parte de la evidencia.

### `retired_without_correction` — un tramo anulado o sustituido sin corrección

Un `UPDATE status` a `voided` o `superseded`: desaparecen horas de la
exportación sin que nadie firme. O una anulación o una sustitución cuya fila de
`shift_corrections` ha desaparecido o dice otra cosa. **Resolución:** devuelve
`status` (y `superseded_by_id`, si cambió) a lo que dice el último asiento con
el rol de migración; si lo que falta es la fila de corrección, recupérala de la
copia de anoche (§4).

### `audit_without_entry` — un asiento cuyo tramo ya no existe

Un `DELETE`. Ninguna purga **admisible** lo explica. Antes de nada, **coteja el
último `retention.purge_executed` con su informe de retención**
([`operacion.md`](../cliente/operacion.md) §3.1): si el asiento existe y no hay
informe con su mismo token, o el corte no es el del informe, ese asiento de
purga también es sospechoso.

```sql
SELECT id, occurred_at, actor_type, actor_id, payload
  FROM audit_log WHERE action = 'retention.purge_executed' ORDER BY id;
```

**Resolución:** recupera la fila de la copia de anoche (§4) **con el mismo
`uuid`** y vuelve a insertarla con el rol de migración, junto con lo que el
atacante borrase antes para poder borrarla (sus `shift_corrections`, sus
`scan_events`).

### `purge_out_of_bounds` — un asiento de purga que la purga real no pudo escribir

Un `retention.purge_executed` con un corte que no es una fecha, posterior a la
fecha del asiento menos sus años de conservación (un `9999-12-31`, un corte de
ayer), con menos años de los que admite el perfil, fechado después de la pasada
o sin centro. `compliance:apply-retention` no puede escribir uno así: **alguien
lo añadió** para que un borrado pareciera una purga. La línea dice el `id` del
asiento y qué límite incumple.

**Resolución:** cotéjalo con los informes de retención
([`operacion.md`](../cliente/operacion.md) §3.1) —no habrá ninguno con su
token—, trátalo como evidencia del ataque ([`brecha-de-seguridad.md`](brecha-de-seguridad.md))
y busca qué tramos se borraron al amparo del corte: salen en la misma pasada
como `audit_without_entry`. El asiento no se puede quitar (regla dura 6) y la
conciliación lo seguirá contando en cada pasada: **la alerta no se apaga sola**.
Hoy el producto no tiene forma de dar por revisado un asiento así; déjalo
anotado en el registro del incidente y comunícaselo al fabricante (sin datos:
basta con el `id` del asiento y la línea de la salida).

### Al terminar

```bash
docker compose exec -T app php artisan compliance:reconcile-work-record --full
docker compose exec -T app php artisan compliance:reconcile-work-record
docker compose exec -T app php artisan attendance:reconcile --from=<primer día> --to=<último día>
```

Las dos primeras tienen que salir en verde: la completa confirma el registro
entero, y la diaria reescribe la métrica `scope="recent"`, que si no seguiría
contando la discrepancia —y la alerta sonando— hasta la pasada de la noche
siguiente. La tercera recalcula `daily_totals` de los días tocados: la
proyección se construye desde `shift_entries` y estará contando lo manipulado.

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
| Un `purge_out_of_bounds`, o un asiento que la §3 señala como falsificado | Lo mismo, y [`brecha-de-seguridad.md`](brecha-de-seguridad.md): alguien escribió en `audit_log` con la credencial de la aplicación | Inmediato |
| Discrepancia y además rotura de la cadena | Lo mismo, y [`rotura-cadena-auditoria.md`](rotura-cadena-auditoria.md) en paralelo | Inmediato |
| Se confirma un acceso indebido | [`brecha-de-seguridad.md`](brecha-de-seguridad.md): 72 h para la AEPD (RL-15) | Desde que se confirma |
| Silencio de la diaria | IT del cliente | Dentro de la jornada |
| Silencio de la completa | IT del cliente | Dentro de la semana |

**El fabricante no accede a los datos del cliente** (ADR-020, regla dura 16).
La salida de `compliance:reconcile-work-record` se puede compartir tal cual:
identificadores, tipos y nombres de campo, sin valores ni personas. El
contenido de `shift_entries` y de `audit_log` **no sale de la instalación**.
