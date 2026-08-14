# ADR-007 — `daily_totals` es una proyección reconstruible, no fuente de verdad

| Campo | Valor |
|---|---|
| **Estado** | Aceptada |
| **Fecha** | 11 de agosto de 2026 (redactada el 14 de agosto de 2026, tarea 0.6) |
| **Decide** | `arquitecto-dominio` |
| **Afecta a** | Tareas 1.1, 1.3, 1.4, 2.3 y 2.7 · [ADR-026](ADR-026-la-correccion-supersede.md) · **Regla dura 7** de `CLAUDE.md` |
| **Requisitos** | RN-06, RN-13, RF-PR-02, RF-AT-07, RL-04, RQ-03 |

> Procede de la primera tabla del [documento 02](../02-stack-tecnologico-y-plan-implementacion.md) §4, que ya fijaba decisión, contexto y consecuencias. Esta redacción los desarrolla y los enlaza con los requisitos y con las reglas duras; no cambia la decisión.

## Contexto

El quiosco necesita mostrar el total acumulado del día en menos de 800 ms (RF-AT-05, RNF-P-01) y el panel tiene que listar 500 empleados sin recorrer dos millones de tramos. Hace falta un agregado precalculado: `daily_totals`.

La forma natural de mantenerlo es incrementarlo: al cerrar un tramo, sumar sus minutos. **Y es exactamente la forma que produce datos erróneos en un sistema como este**, porque hay al menos cuatro caminos que rompen un acumulado incremental:

- **Correcciones.** RN-13 obliga a conservar la versión anterior y crear una nueva ([ADR-026](ADR-026-la-correccion-supersede.md)). Un incremental que no reste lo viejo duplica el día; si resta, hay que acertar en qué restar y cuándo.
- **Anulaciones.** Un tramo `voided` deja de contar. El acumulado ya lo contó.
- **Reintentos e idempotencia.** El quiosco reenvía un `scan_id` ya procesado (regla dura 8). Si el reenvío vuelve a sumar, el día crece sin que nadie haya trabajado.
- **Lotes offline fuera de orden.** Un tramo antiguo que llega hoy modifica el total de un día ya cerrado.

Y hay un agravante: cuando un acumulado deriva, **no lo dice**. Sigue devolviendo un número plausible durante meses, hasta que alguien compara con la nómina.

## Decisión

**`daily_totals` es una proyección de lectura reconstruible. Se recalcula íntegramente —nunca se incrementa— y en la misma transacción que la escritura que la motiva.**

Tres reglas operativas:

1. **Recálculo completo del día afectado**, como suma de los tramos **vigentes** de esa jornada: `status NOT IN ('voided','superseded')` (ADR-026). Idempotente por construcción: aplicarlo dos veces da el mismo resultado.
2. **En la misma transacción.** Un fichaje que confirma y una proyección que se actualiza después son dos hechos que pueden separarse: basta un fallo del worker para que el quiosco muestre un total que no existe en el registro. Con la proyección en cola, `daily_totals` sería *eventualmente* correcto, y el empleado que mira la pantalla no está en el futuro.
3. **La fuente de verdad son `shift_entries` y `scan_events`.** `daily_totals` se puede **borrar y reconstruir entera** sin perder información. Ese es el criterio que decide si algo es proyección o no.

**Existe el comando `attendance:reconcile`** (RF-PR-02): recorre los eventos origen, recalcula y **alerta ante cualquier divergencia** con severidad crítica. La reconciliación nocturna no es mantenimiento rutinario: es el detector de que algo escribió donde no debía.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Acumulado incremental (`total += minutos`)** | Deriva ante correcciones, anulaciones y reintentos, y lo hace en silencio. Es la opción que este ADR existe para prohibir |
| **Sin proyección: agregar en cada consulta** | Correcto siempre, y demasiado lento donde importa: el quiosco necesita el total en el camino de 800 ms y el panel lista 500 empleados (RNF-P-04). La proyección existe por rendimiento, no por comodidad |
| **Recalcular en una cola, fuera de la transacción** | Introduce una ventana en la que el registro y su total no coinciden. El fallo se manifiesta como «el quiosco me dijo 7 h y el panel dice 6 h», que destruye la confianza en el sistema entero |
| **Materialized view de PostgreSQL** | Refresco de toda la tabla o dependencia de `REFRESH CONCURRENTLY` con su propio coste, y menos control sobre qué día se recalcula. El recálculo por jornada afectada es más barato y más explícito |
| **Triggers de base de datos que mantengan el total** | Reparte la regla de negocio entre el dominio y el motor. RN-06 es una regla del §4 y su sitio es el dominio, probado sin base de datos (RQ-01) |

## Consecuencias

- **Cada escritura recalcula un día completo.** El coste es despreciable —una jornada tiene unos pocos tramos— y compra idempotencia total: reprocesar un lote offline entero deja el mismo resultado.
- **La corrección de un tramo no necesita lógica especial en la proyección.** Se recalcula el día y ya está. Es lo que hace que ADR-026 solo tenga que cambiar un predicado.
- **`attendance:reconcile` y su alerta de divergencia son parte del producto, no una herramienta interna.** El destinatario es el IT del cliente y tiene runbook (`divergencia-proyeccion.md`). Una divergencia significa que alguien escribió `daily_totals` por un camino no previsto, o que hay datos manipulados.
- **La reconstrucción completa debe ser posible y probada.** Si reconstruir la proyección desde cero cambiara algún total, la proyección habría dejado de ser derivable y este ADR estaría incumplido.
- **`daily_totals` nunca se exporta como registro legal.** La exportación para Inspección (RL-06) se construye sobre `shift_entries`, que es la fuente de verdad. Un agregado no es el registro.
- **Ninguna otra tabla puede depender de `daily_totals` como si fuera un hecho**, ni referenciarla con clave foránea: es reconstruible, y lo reconstruible se puede borrar.

## Verificación

- Prueba unitaria de dominio: recalcular dos veces la misma jornada produce el mismo total (idempotencia de RN-06).
- Prueba de integración: reenviar el mismo `scan_id` no altera `daily_totals` (RF-AT-07, regla dura 8).
- Prueba de integración concurrente (RQ-03): N envíos simultáneos del mismo `scan_id` producen un evento y un total correcto.
- Prueba de integración: tras corregir un tramo de 480 a 450 minutos, el total del día es 450, no 930 (ADR-026).
- Prueba de integración: borrar `daily_totals` por completo y ejecutar `attendance:reconcile` deja exactamente los mismos valores que había.
- Prueba de integración: alterada una fila de `daily_totals` por fuera de la aplicación, la reconciliación detecta la divergencia y emite la alerta crítica.
- Búsqueda en el árbol: ninguna sentencia incrementa `total_minutes` (`+=`, `increment`, `total_minutes + `). El único camino de escritura es el recálculo.
