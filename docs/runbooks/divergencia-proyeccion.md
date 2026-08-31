# Runbook — divergencia en la reconciliación nocturna

**Esto no debería poder pasar.** `daily_totals` se recalcula entero en la misma
transacción que la escritura que lo motiva (regla dura 7, RN-06,
[ADR-007](../adr/ADR-007-daily-totals-proyeccion-reconstruible.md)): si esa
garantía se cumple, la reconciliación no encuentra nada nunca. Que haya
encontrado algo significa que **alguien o algo escribió la proyección por un
camino que no es el recálculo**. El objetivo de este procedimiento no es arreglar
los números —el comando ya los arregló— sino averiguar cómo se desviaron.

**Alertas que llevan aquí** (doc 01 §9.3, fila *«Divergencia en reconciliación
nocturna | cualquiera | Crítica | IT del cliente»*), definidas en
[`infra/observability/prometheus/rules/projection.yml`](../../infra/observability/prometheus/rules/projection.yml):

| Alerta | Umbral | Severidad | Destinatario | Sección |
| --- | --- | --- | --- | --- |
| `DivergenciaEnReconciliacionNocturna` | cualquiera en 24 h, `for: 5m` | Crítica | IT del cliente | [§3](#3-hubo-divergencia) |
| `ReconciliacionDeProyeccionAusente` | > 26 h sin reconciliar, `for: 30m` | Alta | IT del cliente | [§5](#5-nadie-está-reconciliando-el-silencio) |

**Impacto en el fichaje, que es lo primero que hay que saber: ninguno.** Nadie se
ha quedado sin poder fichar y nadie se va a quedar. `daily_totals` es una
proyección de lectura; la fuente de verdad son `shift_entries` y `scan_events`, y
el registro legal que se entrega a Inspección se construye sobre ellas, nunca
sobre este agregado (ADR-007). Lo que sí estuvo mal hasta que el comando corrigió
es **lo que la gente mira**: el total del día en el quiosco, el panel de presencia
y los informes de horas.

---

## 1. Qué hay montado, en 30 segundos

| Pieza | Qué hace | Dónde |
| --- | --- | --- |
| `shift_entries` | **La fuente de verdad.** Un tramo por turno, versionado | PostgreSQL |
| `daily_totals` | Proyección por `(employee_id, work_date)`. Reconstruible | PostgreSQL |
| `DailyTotalsProjector` | El **único** camino de escritura de la proyección | `backend/app/Modules/Attendance/Infrastructure/Projection/` |
| `attendance:reconcile` | Contrasta las dos y corrige, 03:50 UTC | Contenedor `scheduler` |
| `projection_divergence_total` | Contador. **Debe estar siempre en cero** | `BACKUP_PATH/metrics/kronoqr_projection.prom` |
| `audit_log` | Guarda el antes y el después de cada fila corregida | PostgreSQL |

**La afirmación que se comprueba**, y que tiene que ser cierta siempre:

```
daily_totals.total_minutes
  ==
SUM(shift_entries.duration_minutes) WHERE status NOT IN ('voided','superseded')
```

…y lo mismo, campo a campo, con `shift_count`, `first_in_at`, `last_out_at`,
`has_open_shift` y `has_incident`. **Una fila que falta también es divergencia**:
una jornada con tramos que no aparece en la proyección es un día que el panel
muestra vacío.

---

## 2. Antes de tocar nada: qué conservar

La reconciliación ya reescribió las filas, así que **la única prueba de lo que la
proyección afirmaba** está en dos sitios. Consérvalos antes de investigar, porque
uno de los dos rota:

```bash
# 0. Marca temporal, para nombrar todo lo demás.
INC=$(date -u +%Y%m%dT%H%M%SZ); echo "$INC"

# 1. El log de la pasada: qué campos no cuadraban y cuánto valía cada uno.
#    Rota: cópialo YA. Nunca lleva nombres, solo employee_uuid (regla dura 21).
docker compose -f infra/compose.prod.yaml logs scheduler --since 48h \
  | grep -E 'attendance.projection_(divergence|reconciliation)' \
  > "/var/backups/fichaje/evidencia/$INC-divergencias.log"

# 2. Los asientos de auditoría de la corrección. Esta tabla no se puede alterar,
#    pero conviene tener el extracto a mano.
docker compose -f infra/compose.prod.yaml exec -T postgres \
  psql -U fichaje_app -d fichaje -c "\copy (
      SELECT occurred_at, actor_type, actor_id, action, payload
        FROM audit_log
       WHERE subject_type = 'daily_totals'
         AND occurred_at > now() - interval '48 hours'
       ORDER BY occurred_at
  ) TO STDOUT WITH CSV HEADER" > "/var/backups/fichaje/evidencia/$INC-audit.csv"
```

Si sospechas de una escritura deliberada, para aquí y sigue la §2 de
[`rotura-cadena-auditoria.md`](rotura-cadena-auditoria.md): esa sección explica
cómo preservar evidencia de base de datos antes de que un mantenimiento se la
lleve.

---

## 3. Hubo divergencia

### Diagnóstico

**a) Qué días y qué campos.** El log de la §2 lo dice sin necesidad de consultar
nada:

```bash
grep 'attendance.projection_divergence' "/var/backups/fichaje/evidencia/$INC-divergencias.log" \
  | jq -r '[.work_date, .employee_uuid, (.fields | join(",")),
            (.projected_total_minutes|tostring), (.expected_total_minutes|tostring)] | @tsv'
```

Salida esperada: una línea por jornada, con la fecha, el empleado por su UUID,
las columnas que no cuadraban y los dos totales. Lee la columna `fields` antes
que ninguna otra cosa: **dice qué clase de problema tienes**.

| `fields` | Qué significa |
| --- | --- |
| `row` | Faltaba la fila entera. Una jornada con tramos que la proyección no tenía |
| `total_minutes` a secas | Alguien cambió el total sin tocar los tramos, o una migración lo recalculó mal |
| `total_minutes` + `shift_count` | Los tramos de ese día cambiaron sin que la proyección se enterase |
| `has_open_shift` / `last_out_at` | Un turno se cerró o se abrió por un camino que no publicó el evento |
| Todos, en muchas jornadas | Restauración parcial, importación o migración. Ve a la §4 |

**b) Confirma que ya está corregido.** La consulta de integridad, que es la misma
que ejecuta la reconciliación:

```bash
docker compose -f infra/compose.prod.yaml exec -T postgres \
  psql -U fichaje_app -d fichaje -c "
  SELECT COALESCE(t.employee_id, s.employee_id) AS employee_id,
         COALESCE(t.work_date,   s.work_date)   AS work_date,
         t.total_minutes AS proyectado, s.summed AS real
    FROM daily_totals t
    FULL OUTER JOIN (
      SELECT employee_id, work_date,
             COALESCE(SUM(duration_minutes)
                      FILTER (WHERE status NOT IN ('voided','superseded')), 0) AS summed
        FROM shift_entries GROUP BY 1,2
    ) s ON s.employee_id = t.employee_id AND s.work_date = t.work_date
   WHERE t.total_minutes IS DISTINCT FROM s.summed;"
```

Salida esperada: **cero filas**. Si devuelve algo, la corrección falló: mira
`attendance.projection_not_corrected` en el log y ve a la §6.

**c) Quién escribió.** La pregunta de verdad. Tres sitios, en este orden:

```bash
# ¿Hubo un despliegue o una migración en las horas previas?
docker compose -f infra/compose.prod.yaml exec -T app php artisan migrate:status | tail -20

# ¿Hay sesiones conectadas a la base que no sean la aplicación?
docker compose -f infra/compose.prod.yaml exec -T postgres \
  psql -U fichaje_app -d fichaje -c \
  "SELECT usename, application_name, client_addr, backend_start, state
     FROM pg_stat_activity WHERE datname = 'fichaje';"

# ¿Alguien restauró una copia?
ls -lt /var/backups/fichaje/ | head
```

### Resolución

La corrección **ya está hecha**: el comando reescribe la fila divergente en el
momento de detectarla. Lo que queda es cerrar la causa.

```bash
# 1. Reconcilia el rango completo que sospeches, no solo el día que saltó.
#    Sin --to, el rango es un solo día.
docker compose -f infra/compose.prod.yaml exec -T app \
  php artisan attendance:reconcile --from=2026-03-01 --to=2026-03-31

# 2. Repite. La segunda pasada tiene que salir limpia: si vuelve a encontrar
#    divergencias sobre las mismas jornadas, algo las está reescribiendo AHORA,
#    y eso ya no es un incidente pasado. Ve a la §6.
docker compose -f infra/compose.prod.yaml exec -T app \
  php artisan attendance:reconcile --from=2026-03-01 --to=2026-03-31
```

**Códigos de salida:** `0` sin divergencias · `1` hubo divergencias (o alguna
jornada no se pudo reconciliar) · `2` el rango está mal escrito.

**Qué NO hacer, nunca:**

- **No hagas `UPDATE daily_totals`.** Es reconstruible: se recalcula con este
  comando. Un `UPDATE` a mano es, con toda probabilidad, la causa de que estés
  leyendo esto.
- **No toques `shift_entries` para «cuadrar» un total.** Ahí está el registro con
  valor legal. Si las horas de alguien están mal, se corrigen desde el panel con
  el mecanismo trazado de RF-PA-04, que crea una versión nueva y conserva la
  anterior (RN-13).
- **No borres filas de `daily_totals`** para forzar su reconstrucción. Una
  jornada anulada por completo se queda a cero, no desaparece.

---

## 4. Divergencia masiva: restauración, importación o migración

Si la divergencia afecta a muchas jornadas a la vez, casi nunca hay un culpable:
hay un **procedimiento que se saltó el recálculo**.

```bash
# Reconcilia todo el histórico afectado. Es idempotente y no toca ningún tramo.
docker compose -f infra/compose.prod.yaml exec -T app \
  php artisan attendance:reconcile --from=2026-01-01 --to=2026-12-31
```

Dura lo que dura —una consulta por día de rango— y se puede lanzar en horario de
oficina: no bloquea el fichaje y `withoutOverlapping` impide que choque con la
pasada nocturna.

**Después, cierra el agujero.** Si vino de una migración, la migración tenía que
haber terminado con una reconciliación del rango tocado (doc 02 §10.4). Si vino
de una restauración, el procedimiento de
[`restaurar-backup.md`](restaurar-backup.md) tiene que incluirla.

---

## 5. Nadie está reconciliando (el silencio)

`ReconciliacionDeProyeccionAusente` no dice que haya nada mal: dice que **no se
sabe** si lo hay, que es peor de lo que parece, porque la alerta de la §3 depende
de que el comando corra.

```bash
# ¿Corre el scheduler?
docker compose -f infra/compose.prod.yaml ps scheduler

# ¿Está la tarea en la lista y a qué hora?
docker compose -f infra/compose.prod.yaml exec -T app php artisan schedule:list | grep reconcile

# Ejecútala a mano: si falla, el motivo sale aquí.
docker compose -f infra/compose.prod.yaml exec -T app php artisan attendance:reconcile

# ¿Llega el fichero de métricas a node-exporter?
ls -l /var/backups/fichaje/metrics/kronoqr_projection.prom
cat /var/backups/fichaje/metrics/kronoqr_projection.prom
```

Esperado en el fichero: `projection_divergence_total 0` y un
`projection_reconciliation_last_run_timestamp_seconds` de esta madrugada. Si el
contador vale más que cero pero la alerta de la §3 no está activa, es historia:
alguien ya la investigó. El histórico está en `audit_log`.

---

## 6. Escalado

| Situación | A quién | En cuánto |
| --- | --- | --- |
| Divergencia puntual, ya corregida, causa identificada | IT del cliente | Dentro de la jornada |
| La segunda pasada vuelve a encontrar las mismas jornadas | IT del cliente + fabricante (sin datos) | Inmediato: hay algo escribiendo ahora |
| Sesiones a la base que no son la aplicación | Responsable de seguridad | Inmediato |
| Divergencia con la cadena de auditoría rota a la vez | Responsable de seguridad **y** DPO | Inmediato: ve a [`rotura-cadena-auditoria.md`](rotura-cadena-auditoria.md) |
| Silencio de la reconciliación | IT del cliente | Dentro de la jornada |

**El fabricante no accede a los datos del cliente** (ADR-020, regla dura 16). La
salida de `attendance:reconcile` y el log de la pasada se pueden compartir tal
cual —llevan `employee_uuid`, fechas y cifras, nunca nombres—; el contenido de
`daily_totals` y de `shift_entries` **no sale de la instalación**.
