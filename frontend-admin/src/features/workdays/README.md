# workdays

Detalle de jornada de un empleado (RF-PA-03, tarea 1.16): tramos vigentes, total del día y el
historial completo de correcciones con su «de → a» (RN-13, RL-04).

**La marca de incidencia (RF-PA-05, tarea 2.5) se incrusta aquí, no se duplica.**
`WorkDayCard.vue` pinta la ficha mínima de cada `WorkDayDetail.incidents` (tipo, severidad,
estado) con el mismo badge de `features/incidents/incidentPresentation.ts`, y enlaza a la
bandeja filtrada por `employee_uuid` cuando el ámbito `incidents:*` alcanza. `ShiftEntryTable` y
`useEmployeeWorkDays` no cambian: siguen siendo solo de tramos y totales. La fila de la bandeja,
a su vez, enlaza de vuelta a esta pantalla (`employee-workdays`) en su columna de jornada.

**Solo lee.** Ninguna pantalla de esta carpeta cambia el registro: rectificar es
`PATCH /api/v1/shift-entries/{uuid}`, que exige el ámbito `attendance:correct` y no el
`attendance:read` con el que se abre esta sección.

**Las horas se leen, no se convierten.** El servidor manda cada instante dos veces —en UTC y ya
resuelto en la zona del centro (`*_local`)—, así que el navegador no vuelve a convertir nada y no
usa nunca su propia zona (regla dura 3). La única conversión que queda es la de las marcas
`before`/`after` del libro de correcciones, que solo viajan en UTC: se resuelven con la zona que
viene en la respuesta, jamás con la del navegador.

**El total y la suma se comparan, no se sustituyen.** `ShiftEntryTable` pinta la suma de los
tramos y, si el total que declara el servidor no coincide, enseña los dos y avisa (RN-06,
ADR-007). Elegir uno en silencio convertiría un fallo de proyección en una nómina mal pagada.

Carpeta por _feature_, no por tipo de fichero (doc 02 §3.5).
