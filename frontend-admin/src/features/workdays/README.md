# workdays

Detalle de jornada de un empleado (RF-PA-03, tarea 1.16): tramos vigentes, total del día y el
historial completo de correcciones con su «de → a» (RN-13, RL-04).

**Las marcas de pausa (tarea 3.5, ADR-024, RF-AT-12) viven en `ShiftEntryTable.vue`, pero el
predicado no.** La pausa son dos tramos, no un hueco dentro de uno: cuando el tramo anterior lo
cerró un escaneo `break_start` (`closed_by`) y el siguiente lo abrió un `break_end` (`opened_by`),
la tabla enseña una fila «Pausa de HH:MM a HH:MM (N min)» entre los dos, y el tramo que cierra la
pausa lleva una insignia «Pausa» junto a su salida. **`breakBetween` vive en
`@kronoqr/web-kit/breaks`** (ADR-036, segunda vuelta de la tarea 3.5): el portal necesita
exactamente la misma regla —los mismos dos escaneos, la misma resta de instantes— y una primera
versión de cada SPA ya había divergido (`null` sin fila frente a texto con `0 min`, `1 h 30 min`
frente a `90 min`). La insignia y el texto de la fila comparten además el mismo componente,
`@kronoqr/web-kit/components/BreakBadge.vue` (mismo icono SVG, misma pareja de tokens
`bg-kq-primary-soft`/`text-kq-on-primary-soft` —texto e icono, nunca solo color, WCAG 1.4.1—;
`kq-accent` es decorativo y no debe llevar un significado como «esto fue una pausa», doc 06 §6.5).
Aquí solo queda el formato final (`durationParts`, «1 h 30 min», nunca minutos crudos) y el
`data-test` (`break-badge`, `break-row`). Un tramo con `closed_by: null` sigue siendo «abierto» y
uno corregido o dado de alta a mano sin escaneo detrás llega como `clock_in`/`clock_out`, sin
marca de pausa.

**Corregir vive aquí también, desde la tarea 5.11b (RF-PA-04).** `CorrectionDialog.vue` es un único
diálogo con tres modos —añadir un tramo, corregir sus marcas, anularlo— que abren
`EmployeeWorkDaysView.vue` (el botón de la cabecera, para el caso sin ninguna jornada previa) y
`ShiftEntryTable.vue` (los dos botones por fila, para un tramo ya vigente). `corrections.api.ts`
lleva las tres llamadas; `zonedTime.ts` es la única conversión que faltaba en el panel: de lo que
alguien teclea pensando en la hora del centro al `UtcTimestamp` que exige el contrato. Los botones
se ocultan por el ámbito `attendance:correct` (`ATTENDANCE_CORRECT`) y, solo para anular, además por
rol (`canVoidShiftEntry`, `features/auth/abilities.ts`): el servidor reserva `void` a `rrhh+`
(`ShiftEntryPolicy::void`) aunque el ámbito del token sea el mismo que para añadir y corregir.

**La marca de incidencia (RF-PA-05, tarea 2.5) se incrusta aquí, no se duplica.**
`WorkDayCard.vue` pinta la ficha mínima de cada `WorkDayDetail.incidents` (tipo, severidad,
estado) con el mismo badge de `features/incidents/incidentPresentation.ts`, y enlaza a la
bandeja filtrada por `employee_uuid` cuando el ámbito `incidents:*` alcanza. `ShiftEntryTable` y
`useEmployeeWorkDays` no cambian: siguen siendo solo de tramos y totales. La fila de la bandeja,
a su vez, enlaza de vuelta a esta pantalla (`employee-workdays`) en su columna de jornada.

**La lectura y la corrección exigen ámbitos distintos.** `EmployeeWorkDaysView.vue` se abre con
`attendance:read` (RF-PA-03); las tres operaciones de `CorrectionDialog.vue` exigen
`attendance:correct` (RF-PA-04), que un rol de solo lectura —el `auditor`— no lleva. Eso es lo que
permite que ese rol consulte el registro entero sin que la pantalla le ofrezca nada que lo cambie
(regla dura 18): la comprobación real, de todos modos, la hace el servidor.

**Las horas se leen, no se convierten.** El servidor manda cada instante dos veces —en UTC y ya
resuelto en la zona del centro (`*_local`)—, así que el navegador no vuelve a convertir nada y no
usa nunca su propia zona (regla dura 3). La única conversión que queda es la de las marcas
`before`/`after` del libro de correcciones, que solo viajan en UTC: se resuelven con la zona que
viene en la respuesta, jamás con la del navegador.

**El total y la suma se comparan, no se sustituyen.** `ShiftEntryTable` pinta la suma de los
tramos y, si el total que declara el servidor no coincide, enseña los dos y avisa (RN-06,
ADR-007). Elegir uno en silencio convertiría un fallo de proyección en una nómina mal pagada.

Carpeta por _feature_, no por tipo de fichero (doc 02 §3.5).
