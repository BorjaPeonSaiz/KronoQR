# incidents

Bandeja de incidencias y su resolucion (RF-PA-05, RF-PR-01). Tareas 2.5 y 2.6.

Carpeta por _feature_, no por tipo de fichero (doc 02 §3.5).

## Que hay aqui

| Fichero                     | Que hace                                                                                                                                                                                                                                                                          |
| --------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `incidents.api.ts`          | Cliente tipado de `GET /incidents` y `POST /incidents/{id}/resolve`, con los filtros en camelCase que usa el panel.                                                                                                                                                               |
| `incidents.store.ts`        | Pinia: la pagina, los filtros, el reloj del servidor para la antiguedad, la sustitucion de fila al resolver y la relectura por `409`.                                                                                                                                             |
| `incidentContext.ts`        | Presentacion pura (sin Vue) del `context` de una incidencia: solo empareja las claves que el contrato confirma (`rest_minutes`/`worked_minutes` con `threshold_minutes`, y desde la 3.11 los dos patrones de `anomalous_pattern`); todo lo demas se pinta en bruto, sin inventar. |
| `incidentPresentation.ts`   | La clase de color del badge de severidad, compartida con la marca incrustada en `features/workdays/WorkDayCard.vue`: el mismo hecho se ve igual en los dos sitios.                                                                                                                |
| `IncidentsView.vue`         | La bandeja: filtros al servidor (estado, tipo, severidad, departamento), el aviso de filtro por persona que llega desde el detalle de jornada, paginacion y estados vacio/carga/error.                                                                                            |
| `IncidentTable.vue`         | Las filas, virtualizadas a partir de 80 (como `features/live/PresenceTable.vue`). Severidad como badge con texto, antiguedad contra el reloj del servidor, enlace al registro horario si el ambito alcanza.                                                                       |
| `ResolveIncidentDialog.vue` | El dialogo de cierre: `outcome` resolver/descartar, nota obligatoria con validacion local y errores `422` del servidor, y el mensaje de quien se adelanto en un `409` (sin ofrecer reintentar).                                                                                   |

## Por que la bandeja es un store de Pinia y no una consulta de TanStack Query

Igual motivo que `features/live/presence.store.ts`: la antiguedad de cada fila se
calcula contra el reloj del **servidor** (`meta.generated_at`), no contra
`Date.now()` del navegador (regla dura 3). Una consulta de TanStack Query no
tiene un sitio natural para llevar ese reloj extrapolado; un store si.

## El `409` no trae quien se adelanto, asi que se releen

`POST /incidents/{id}/resolve` devuelve un `Problem` generico (RFC 9457) en un
`409`, sin la incidencia. `incidents.store.ts` responde releyendo las
incidencias `resolved` y luego `dismissed` de esa misma persona (el mismo
filtro por `employee_uuid` que usa la ficha de empleado) hasta encontrar la
fila por identificador. Como mucho dos peticiones extra, y solo cuando de
verdad hay un conflicto que explicar.

## La marca en el detalle de jornada no duplica nada

El paso 4 de la tarea 2.5 incrusta la ficha minima de cada incidencia
(`WorkDayDetail.incidents`, distinto de `has_incident`) en
`features/workdays/WorkDayCard.vue`, reutilizando ese componente en vez de
crear una vista aparte. El enlace a la bandeja filtra por `employee_uuid`
(`/incidents?employee=<uuid>`) y solo se enseña con el ambito `incidents:*`
(regla dura 18: la interfaz refleja permisos, pero no es la autorizacion real).

## `context` se pinta legible, nunca se inventa una clave

El contrato deja `IncidentContext` deliberadamente abierto: un mapa de enteros
cuyas claves dependen del tipo. `incidentContext.ts` solo empareja lo que el
contrato confirma por escrito (`rest_minutes`/`worked_minutes` con
`threshold_minutes`); una clave nueva o sin pareja se pinta tal cual, con su
nombre tecnico y su numero. Adivinar una pareja que nadie ha confirmado es
exactamente el error que producira una frase que dice lo contrario de lo que
paso.

## Patrones anomalos de uso de credencial (RF-PR-06, RN-16, tarea 3.11)

`context.pattern` distingue los dos hallazgos de
`incidents.type: anomalous_pattern`, y `incidentContext.ts` los traduce con
sus propias claves -nunca las inventa, las confirma la ficha de la tarea 3.11-:

- **`kiosk_coincidence`**: un hallazgo por PERSONA, no por par (segunda vuelta
  de la tarea 3.11, decision 13 -el diseño por par perdia hallazgos en
  silencio-). `device_id`/`device_name` (el quiosco, solo por nombre),
  `counterpart_employee_uuid` (la contraparte **principal**: la que acumula
  mas dias), `counterpart_count` (cuantas personas distintas en total, ⩾ 1),
  `coincidence_days` (de la contraparte principal), `min_repeats`,
  `window_seconds`, `first_coincidence_at`/`last_coincidence_at` (el primer y
  el ultimo dia con coincidencia de TODA la serie de la persona) y
  `last_gap_seconds`/`min_gap_seconds` (el hueco del ultimo dia frente al mas
  estrecho de toda la serie -**`last_gap_seconds` no es un maximo**, es
  literalmente el hueco del ultimo dia, que puede ser mayor que el mas
  ajustado de un dia anterior-). La linea de la contraparte es un ENLACE al
  filtro por empleado de la bandeja (`/incidents?employee=<uuid>`, mismo
  destino que el enlace desde `WorkDayCard.vue`), no un nombre: la otra
  persona puede ser de un departamento distinto del de quien revisa, y
  resolver su nombre aqui seria la ampliacion de finalidad que la
  minimizacion prohibe. Cuando `counterpart_count` pasa de 1 el texto añade
  «y N más» (ES/EN, con singular para N = 1): la persona vio una coincidencia
  con varias personas y el enlace solo lleva a la principal, que es la que
  sostiene `coincidence_days`. `IncidentsView.vue` observa
  `route.query.employee` con un `watch` -no solo lo lee al montar- porque
  este enlace navega dentro de la MISMA ruta, a diferencia del que llega del
  detalle de jornada.
- **`impossible_sequence`** (RN-16): `from_device_id`/`from_device_name`,
  `to_device_id`/`to_device_name`, `first_occurred_at`/`second_occurred_at`,
  `gap_seconds` emparejado con `transit_seconds`, `first_scan_id`,
  `second_scan_id`. Es la misma persona en dos quioscos, asi que no hay
  contrapartida que enlazar. Los dos momentos se pintan **con segundos**
  (`formatInstantWithSeconds`, local a `incidentContext.ts` y distinta de
  `formatInstant`): con precision de minuto un salto de 45 s se leia como la
  misma hora dos veces, que es justo lo que RN-16 no puede decir.

`IncidentContext` (`docs/api/openapi.yaml`) solo admite un valor **escalar**
por clave -entero o cadena de hasta 64 caracteres, «ni objetos ni listas»
(ADR-012)-, asi que `kiosk_coincidence` no lleva una lista de ocurrencias: el
backend resume la serie completa en los escalares de arriba. El detalle dia a
dia -si hiciera falta para revisar un caso, o para reconstruir el grupo
completo de una coincidencia sistemica- vive en `audit_log`, en el log
tecnico del comando y en las incidencias de los demas miembros del grupo, no
en el contexto de una sola incidencia.

Ninguna frase de los dos patrones lleva una palabra que califique («fraude»,
«sospechoso», «engaño»): RF-PR-06 dice que el sistema aporta el indicio,
nunca la conclusion.
