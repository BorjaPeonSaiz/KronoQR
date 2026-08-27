# ADR-035 — La corrección estrena identificador y no cambia de jornada

| Campo | Valor |
|---|---|
| **Estado** | Aceptada |
| **Fecha** | 26 de agosto de 2026 |
| **Decide** | `arquitecto-dominio` |
| **Afecta a** | Tarea 1.15 · §5.5 del documento 01 · `docs/api/openapi.yaml` (`PATCH /shift-entries/{uuid}`) · Reglas duras 4 y 5 de `CLAUDE.md` |
| **Requisitos** | RN-01, RN-02, RN-05, RN-13, RL-04, RF-PA-04 |
| **Depende de** | [ADR-026](ADR-026-la-correccion-supersede.md) (los dos estados no vigentes), [ADR-006](ADR-006-los-turnos-no-se-parten-a-medianoche.md), [ADR-024](ADR-024-la-pausa-son-dos-tramos.md) |

## Contexto

ADR-026 decidió **qué le pasa a la versión anterior** de un tramo corregido: se queda en la tabla en estado `superseded`. Deja abiertas dos preguntas que la tarea 1.15 no puede implementar sin responder, y que ningún documento contesta hoy:

1. **¿Qué identificador público tiene la versión corregida?** `shift_entries.uuid` es `UNIQUE` (migración `create_shift_entries_table`, tarea 1.3) y la corrección **inserta una fila nueva**. Las dos versiones no pueden compartir `uuid`. Pero `uuid` es el identificador con el que habla la API (`PATCH /shift-entries/{uuid}`), el que el panel enlaza y el que viaja en los eventos de dominio.

2. **¿Puede una corrección mover un tramo a otra jornada?** La regla dura 4 dice que corregir la hora de un turno 22:00 → 06:00 no puede partirlo «ni cambiar su `work_date` **salvo que cambie la hora de inicio**». Y RN-05 define `work_date` como la fecha civil, en la zona del centro, de la entrada que **abre** la jornada. Corregir esa entrada al otro lado de la medianoche local mueve las horas a **otra jornada**, es decir, a otro agregado y a otra fila de `daily_totals`.

Sin decidir las dos, cada quien resuelve a su manera: la primera acaba en una API que devuelve un identificador que el cliente no esperaba, y la segunda en un caso de uso que carga dos agregados y protege mal las invariantes de los dos.

## Decisión

### 1. La versión corregida estrena `uuid`; la anterior conserva el suyo

Corregir un tramo produce una fila nueva con **identificador público propio** y `version + 1`. La versión anterior mantiene su `uuid`, pasa a `superseded` y apunta a la nueva por `superseded_by_id`. El historial se recorre hacia delante por ese puntero (RL-04).

Consecuencia para el contrato: **`PATCH /shift-entries/{uuid}` responde con un `uuid` distinto del que recibió**, y la respuesta incluye los dos —el vigente y el sustituido— para que el panel pueda enlazar el histórico sin una segunda consulta. Un `PATCH` repetido sobre el `uuid` viejo recibe `409`, no `404`: el tramo existió y ya no es la versión vigente.

### 2. Una corrección no cambia la jornada del tramo: se niega

`WorkDay` rechaza con `CorrectionWouldChangeWorkDate` toda corrección cuyo resultado haría que **la entrada más temprana de la jornada** cayera en otro día civil de la zona del centro. Es decir:

- Corregir la **salida** nunca la dispara: un turno 22:00 → 06:00 rectificado a 06:30 sigue siendo del día 14.
- Corregir la entrada de un tramo que **no abre** la jornada tampoco: la vuelta de una pausa a las 02:30 pertenece a la jornada de ayer (ADR-024) y corregirla no la mueve.
- Corregir la entrada que **sí abre** la jornada cruzando la medianoche local **se rechaza** con un `422` que explica qué hacer.

Quien necesita mover horas de un día a otro lo hace con **dos acciones explícitas y auditadas**: anular el tramo en la jornada de origen y darlo de alta en la de destino, cada una con su motivo del Anexo C.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Mantener el `uuid` en la versión vigente y dárselo nuevo a la copia histórica** | Obliga a **sobrescribir** la fila original con las horas corregidas, que es literalmente lo que prohíbe la regla dura 5. Además ADR-026 ya dice que la corrección «inserta la versión nueva» y marca la anterior |
| **Quitar el `UNIQUE` de `shift_entries.uuid` y compartirlo entre versiones** | El identificador público dejaría de identificar: `scan_events.shift_entry_id`, la exportación legal y el panel tendrían que decir además qué versión. Y se pierde la garantía de la base de datos sobre el identificador que sale por la API |
| **Que el caso de uso cargue las dos jornadas y mueva el tramo** | `WorkDay` es la frontera transaccional (doc 01 §5.2) y ninguno de los dos agregados puede proteger las invariantes del otro: la jornada de origen no sabe si en la de destino hay ya un turno abierto (RN-01) ni si el tramo movido solaparía con lo que allí haya (RN-02). Se convierte en una operación que toca dos raíces y dos filas de `daily_totals`, con dos recálculos que hay que acordarse de hacer |
| **Permitirlo y recalcular `work_date` en silencio** | Mueve horas de nómina de un día a otro sin que quien corrige lo haya pedido ni lo vea. Ante Inspección, además, el registro no diría que hubo un traslado: diría que ese tramo siempre fue del día 15 |
| **Rechazar toda corrección de la hora de entrada** | Excesivo: la mayoría de correcciones de entrada no cruzan ninguna medianoche, y son el caso más común del olvido de fichaje |

## Consecuencias

- **`docs/api/openapi.yaml` tiene que decirlo.** El esquema de respuesta del `PATCH` y del `POST /void` lleva `shift_entry_uuid` (el vigente) y `superseded_shift_entry_uuid` (el anterior, nulo en un alta o una anulación). El cliente no puede suponer que el identificador que envió sigue siendo válido.
- **El panel enlaza el histórico por `superseded_by_id`**, y la vista de detalle de jornada (tarea 1.16) muestra la cadena de versiones, no solo la última.
- **La respuesta del rechazo por cambio de jornada es accionable.** El `422` dice que hay que anular y dar de alta; si el texto solo dijera «no se puede», el responsable abriría una incidencia de soporte.
- **El agregado comprueba la entrada más temprana, no el tramo corregido.** Es la única formulación que respeta a la vez RN-05 y ADR-024; comprobar la fecha civil del tramo corregido rompería la vuelta de una pausa de madrugada, que es legítima y frecuente en un hotel.
- **Si en la Fase 2 se pide mover una jornada de verdad**, será un caso de uso propio —«trasladar tramo a otra jornada»— con su propio nombre, su propia entrada de auditoría y sus dos recálculos explícitos. No una consecuencia lateral de un `PATCH`.

## Verificación

- **Prueba unitaria:** corregir la salida de un turno 22:00 → 06:00 no cambia `work_date` ni fabrica marcas (`RN-05`, `RF-AT-08`). *Escrita en `CorrectionGuardsTest` y `ShiftCorrectionTest`.*
- **Prueba unitaria:** mover la entrada que abre la jornada al día siguiente lanza `CorrectionWouldChangeWorkDate` (`RN-05`). *Escrita.*
- **Prueba unitaria:** corregir la vuelta de una pausa de madrugada no mueve la jornada (ADR-024). *Escrita.*
- **Prueba unitaria:** la versión corregida tiene `uuid` propio y la anterior apunta a ella (`RN-13`). *Escrita.*
- **Prueba de contrato:** la respuesta del `PATCH` incluye los dos identificadores. *Pendiente, tarea 1.15 de `backend-laravel`.*
- **Prueba feature:** un `PATCH` sobre el `uuid` de una versión ya sustituida responde `409`. *Pendiente, tarea 1.15 de `backend-laravel`.*
