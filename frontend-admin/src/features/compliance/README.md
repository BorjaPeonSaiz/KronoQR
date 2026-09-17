# compliance

Vista de cumplimiento: descansos, jornada máxima, pausa en tramo continuado y
exceso semanal (RF-PA-06, tarea 3.4).

Carpeta por _feature_, no por tipo de fichero (doc 02 §3.5).

## Qué hay aquí

| Fichero                       | Qué hace                                                                                                                                                                                              |
| ----------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `compliance.api.ts`           | Cliente tipado de `GET /api/v1/compliance/summary`, con los filtros en camelCase que usa el panel. `undefined` no se serializa: sin filtro no se manda el parámetro.                                  |
| `compliancePresentation.ts`   | Presentación pura (sin Vue): `HH:MM` de cada hallazgo, si la diferencia es lo que falta o lo que sobra, el motivo de una regla suspendida y el agrupado por regla en el orden de `meta.rules[]`.      |
| `ComplianceView.vue`          | La pantalla: carga al abrir sin parámetros, filtros de periodo/departamento/regla, cuatro tarjetas con el umbral y el perfil, estados vacío/carga/error, y los criterios tal cual los da el servidor. |
| `ComplianceFindingsTable.vue` | Los hallazgos, agrupados por regla en tablas semánticas (sin virtualizar: el contrato no pagina esta vista). Enlace al registro de la persona y a la incidencia de la bandeja cuando existe.          |

## Por qué no hay store de Pinia ni `useQuery` para el propio resumen

A diferencia de `features/incidents` o `features/live`, esta vista no necesita
un reloj del servidor extrapolado ni una antigüedad que se repinte sola: es una
consulta que se repite cuando cambian los filtros, exactamente el mismo patrón
que `features/reports/PeriodReportView.vue`. `useQuery` sí se usa para
`listDepartments()`, que es una lista pequeña y compartida con otras pantallas.

## Por qué carga al abrir, al revés que el informe por periodo

`PeriodReportView` no pide nada hasta que alguien elige un periodo: es una
consulta cara que cruza la plantilla con el calendario, y generarla con un
rango inventado gastaría la base de datos que atiende el fichaje para una
cifra que nadie ha pedido. Aquí es al revés: el servidor ya acota la ventana
por omisión a 28 días —la que RRHH revisa— y los hallazgos son escasos, solo
incumplimientos. Abrir la pantalla sin nada que enseñar sería peor que la
petición de partida. Los filtros de fecha se rellenan con `meta.from`/`meta.to`
tras la primera carga, para que se vea exactamente qué rango se está mirando.

## El umbral se enseña con el nombre del perfil, siempre

Regla dura 14: los umbrales legales se leen del perfil de cumplimiento, nunca
de una constante del código. Cada tarjeta dice, por ejemplo, «12 h según el
perfil ES-hosteleria»: un aviso cuyo criterio no se ve es un aviso que nadie
defiende ante un empleado. La regla suspendida (`missing_break`, RN-12,
suspendida mientras el fichaje de pausa esté desactivado en «Ajustes
operativos», `ATTENDANCE_BREAK_CLOCKING`, tarea 3.5) lo dice también, con su
motivo traducido (`break_clocking_disabled`) — nunca se calla.

## Nada se calcula aquí (regla dura 7)

Los minutos medidos, el umbral y la diferencia de cada hallazgo vienen los
tres del servidor. `compliancePresentation.ts` solo traduce esos minutos a
`HH:MM` (`durationParts`, compartido con el resto del panel) y decide la clave
de i18n de cada hallazgo a partir de un valor YA enumerado por el contrato
—el nombre de la regla, si la diferencia es lo que falta o lo que sobra—,
nunca de una comparación nueva.

## El enlace a la incidencia y el que no hace falta ocultar

El enlace a `/employees/{uuid}/workdays` se enseña siempre: esta pantalla ya
exige `attendance:read`, el mismo ámbito que esa ruta, así que quien ve una
fila siempre puede abrir el registro de esa persona. El enlace «Ver
incidencia» (`/incidents?employee=<uuid>`, mismo filtro que usa el detalle de
jornada) sí se oculta sin `incidents:*` (regla dura 18, cortesía y no
seguridad): un responsable con `attendance:read` pero sin la bandeja no vería
más que un 403.
