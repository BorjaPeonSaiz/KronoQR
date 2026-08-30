# incidents

Bandeja de incidencias y su resolucion (RF-PA-05, RF-PR-01). Tareas 2.5 y 2.6.

Carpeta por _feature_, no por tipo de fichero (doc 02 §3.5).

## Que hay aqui

| Fichero                     | Que hace                                                                                                                                                                                                                 |
| --------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `incidents.api.ts`          | Cliente tipado de `GET /incidents` y `POST /incidents/{id}/resolve`, con los filtros en camelCase que usa el panel.                                                                                                      |
| `incidents.store.ts`        | Pinia: la pagina, los filtros, el reloj del servidor para la antiguedad, la sustitucion de fila al resolver y la relectura por `409`.                                                                                    |
| `incidentContext.ts`        | Presentacion pura (sin Vue) del `context` de una incidencia: solo empareja las claves que el contrato confirma (`rest_minutes`/`worked_minutes` con `threshold_minutes`); todo lo demas se pinta en bruto, sin inventar. |
| `incidentPresentation.ts`   | La clase de color del badge de severidad, compartida con la marca incrustada en `features/workdays/WorkDayCard.vue`: el mismo hecho se ve igual en los dos sitios.                                                       |
| `IncidentsView.vue`         | La bandeja: filtros al servidor (estado, tipo, severidad, departamento), el aviso de filtro por persona que llega desde el detalle de jornada, paginacion y estados vacio/carga/error.                                   |
| `IncidentTable.vue`         | Las filas, virtualizadas a partir de 80 (como `features/live/PresenceTable.vue`). Severidad como badge con texto, antiguedad contra el reloj del servidor, enlace al registro horario si el ambito alcanza.              |
| `ResolveIncidentDialog.vue` | El dialogo de cierre: `outcome` resolver/descartar, nota obligatoria con validacion local y errores `422` del servidor, y el mensaje de quien se adelanto en un `409` (sin ofrecer reintentar).                          |

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
