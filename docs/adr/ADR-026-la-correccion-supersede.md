# ADR-026 — La corrección supersede: estado `superseded` en `shift_entries`

| Campo | Valor |
|---|---|
| **Estado** | Aceptada |
| **Fecha** | 13 de agosto de 2026 |
| **Decide** | `arquitecto-dominio` |
| **Afecta a** | Tareas 1.3, 2.3 · §5.5 del documento 01 · §3.2 del documento 02 · Reglas duras 5 y 7 de `CLAUDE.md` |
| **Requisitos** | RN-01, RN-02, RN-06, RN-13, RL-04, RF-AT-14 |

## Contexto

Dos reglas duras del proyecto se cruzan en la misma transacción y, tal como estaban escritas, **se impiden mutuamente**:

- **Regla dura 5** (RN-13, RL-04): *«Nada se borra ni se sobrescribe. Las correcciones crean una versión nueva y conservan la anterior con autor, momento y motivo.»* El esquema ya lo prevé: `shift_entries` tiene `version` y `superseded_by_id`.
- **Regla dura 7** (RN-06, ADR-007): *«`daily_totals` es una proyección reconstruible. Se recalcula en la misma transacción, nunca se incrementa acumulativamente.»*

Corregir un tramo consiste, por tanto, en insertar una fila nueva con los valores rectificados y **dejar la anterior en la tabla**. Pero la fila anterior y la nueva describen el mismo intervalo de trabajo del mismo empleado, con solo unos minutos de diferencia. Consecuencia doble y ninguna de las dos aceptable:

1. **La restricción de exclusión rechaza la corrección.** `shift_entries_no_overlap` excluye hoy únicamente `status <> 'voided'`, de modo que la fila vieja sigue siendo «vigente» a ojos de PostgreSQL y su rango solapa con el de la fila nueva. La transacción aborta. La primera corrección de la Fase 2 no llega a escribirse.
2. **Si no la rechazara, el recálculo duplicaría el día.** El agregador de `daily_totals` filtra con el mismo predicado: sumaría los minutos del tramo original **y** los del corregido, y el día pasaría de 480 a 960 minutos. Eso es un dato de nómina erróneo producido por el propio mecanismo de corrección.

Anular la fila anterior con `voided` no vale: `voided` significa *«este tramo no ocurrió»* —lo usa la anulación de un fichaje espurio— y usarlo para el histórico de una corrección haría indistinguibles dos hechos distintos ante Inspección. Un tramo corregido **sí ocurrió**; lo que cambia es qué versión de él es la vigente.

## Decisión

**El enum de `shift_entries.status` gana un cuarto estado terminal: `superseded`.** Significa *«esta versión del tramo ocurrió y se conserva, pero ha sido sustituida por otra»*. Lo escribe únicamente el caso de uso de corrección, en la misma transacción que inserta la versión nueva y rellena `superseded_by_id`.

```
status: open | closed | anomalous | voided | superseded
```

**Las dos garantías declarativas pasan a excluir los dos estados no vigentes**, en `docs/01` §5.5 y en `docs/02` §3.2:

```sql
-- RN-01: como máximo un turno abierto por empleado
CREATE UNIQUE INDEX one_open_shift_per_employee
    ON shift_entries (employee_id)
    WHERE clocked_out_at IS NULL AND status NOT IN ('voided', 'superseded');

-- RN-02: los tramos vigentes de un mismo empleado no pueden solaparse
ALTER TABLE shift_entries ADD CONSTRAINT shift_entries_no_overlap
    EXCLUDE USING gist (
        employee_id WITH =,
        tstzrange(clocked_in_at, clocked_out_at) WITH &&
    ) WHERE (status NOT IN ('voided', 'superseded'));
```

Y el mismo predicado gobierna el recálculo de `daily_totals` (RN-06): **la proyección se construye solo sobre el conjunto vigente**. El histórico completo sigue en la tabla y se consulta por `version` y `superseded_by_id`, que es lo que sostiene RL-04.

El agregado `WorkDay` carga solo los tramos vigentes. Una versión superseded no es un `ShiftEntry` del agregado: es histórico, y el agregado no protege invariantes sobre hechos ya sustituidos.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Reutilizar `voided` para la versión anterior** | Colapsa dos hechos legalmente distintos —«el tramo no ocurrió» y «el tramo ocurrió y se rectificó»— en un solo valor. Ante Inspección hay que poder distinguirlos, y RN-13 exige que la corrección conserve la trazabilidad de qué se cambió, no que finja que no pasó |
| **Mover el histórico a una tabla aparte (`shift_entries_history`)** | La restricción de exclusión dejaría de aplicarse al histórico, con lo que se pierde la garantía de base de datos sobre él, y toda consulta de trazabilidad pasaría a ser una `UNION`. Además duplica el esquema de una tabla que va a crecer a ~2 M filas/año y cuya definición cambia con el producto |
| **Un campo booleano `is_current`** | Funcionalmente equivalente pero peor: crea un segundo eje de estado que puede contradecir a `status` (`voided` con `is_current = true` es representable y no significa nada). Un solo enum hace que el estado imposible no se pueda construir |
| **Quitar la restricción de exclusión y validar el solape solo en el agregado** | Renuncia a la última línea de defensa que ADR-003 justifica. RN-02 es la invariante que protege el dato de nómina: si la aplicación tiene un fallo de concurrencia, la base de datos es lo único que queda |
| **Borrar la fila anterior** | Regla dura 5 y RL-04. No es una opción: es lo que este ADR existe para evitar |

## Consecuencias

- **El esquema nace con `superseded` desde la tarea 1.3.** La tabla se crea vacía en la Fase 1, así que incorporarlo ahora no cuesta nada; hacerlo en la Fase 2, con el esquema desplegado y datos de fichaje reales, sería una migración sobre la tabla con valor probatorio del producto.
- **La tarea 2.3 (correcciones) razona sobre dos estados no vigentes, no sobre uno.** Su paso 8 y sus pruebas deben nombrar `superseded` junto a `voided`.
- **Toda consulta de `Reporting` que hoy filtre `<> 'voided'` debe filtrar los dos.** Es la fuente de error más probable a futuro: se resuelve con un único *scope* o *criteria* compartido, no repitiendo el literal.
- **`attendance:reconcile` (ADR-007) reconstruye igual**, porque reconstruir sobre el conjunto vigente es exactamente lo que ya hacía; solo cambia el predicado.
- **No se toca ninguna otra restricción.** `shift_entries_chk_order` (RN-03) sigue aplicándose a todas las versiones, vigentes o no: una versión histórica con salida anterior a la entrada nunca fue válida.

## Verificación

- **Prueba de integración:** corregir un tramo cerrado no viola `shift_entries_no_overlap`. Es la prueba que hoy fallaría.
- **Prueba de integración:** tras corregir un tramo de 480 min a 450 min, `daily_totals.total_minutes` del día vale 450, no 930. El recálculo no duplica minutos.
- **Prueba de integración:** con un tramo abierto en estado `superseded`, `one_open_shift_per_employee` no impide abrir el turno vigente (RN-01 se aplica al conjunto vigente).
- **Prueba de integración:** la fila anterior sigue en la tabla con su `version`, su `superseded_by_id` y su entrada en `shift_corrections` con autor, momento y motivo (RN-13, RL-04).
- **Prueba unitaria de dominio:** `WorkDay` reconstruido desde el repositorio no incluye tramos `superseded` entre sus `ShiftEntry`.
- **Búsqueda en el árbol:** ningún literal `<> 'voided'` suelto en migraciones, consultas ni *scopes*. El predicado vive en un solo sitio.
