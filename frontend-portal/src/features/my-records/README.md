# my-records

Consulta del registro propio: jornadas, tramos y totales (RF-ID-05, RF-ID-06, RF-ID-07, RL-05). Tarea 1.11.

- `MyRecordsView.vue` — resumen legible arriba, detalle debajo. Filtro de rango de fechas; sin rango, lo resuelve el servidor (RN-04). El `WorkDateRange`/`UNBOUNDED_RANGE` del portal se declaran aqui, junto al endpoint que los consume; la validacion del rango (`exceedsMaxRange`, `isInvertedRange`, `MAX_RANGE_DAYS`) es de `@kronoqr/web-kit/dateRange` (ADR-036).
- `workdays.api.ts` — `GET /api/v1/me/workdays`. **Sin ningun identificador de empleado**: la ausencia es la autorizacion (RF-ID-07, regla dura 18).
- `ShiftEntryTable.vue`, `CorrectionHistory.vue`, `WorkDayCard.vue` — misma forma de datos que el detalle de jornada del panel (tarea 1.16), pantalla mas simple: sin acciones de correccion, solo lectura.

**`workdayTotals.ts` ya no vive aqui.** La aritmetica de la jornada —suma de tramos, contraste
con el total declarado (RN-06, regla dura 7)— se movio a `@kronoqr/web-kit/workdayTotals`
(ADR-036): es la pieza que diverguio de verdad entre panel y portal, y no puede haber una segunda
copia.

**Las horas se leen, no se convierten.** El servidor manda cada instante dos veces —en UTC y ya
resuelto en la zona del centro (`*_local`)—, y eso incluye el momento en el que se firmo una
correccion (`performed_at_local`): `CorrectionHistory.vue` lo lee con
`readLocalTimestamp`/`formatCivilDate` de `@kronoqr/web-kit/datetime`, nunca reconvirtiendo
`performed_at` con la zona del navegador (regla dura 3). Esta fue precisamente la divergencia real
que motivo compartir el paquete con el panel (ADR-036).

Carpeta por _feature_, no por tipo de fichero (doc 02 §3.5).
