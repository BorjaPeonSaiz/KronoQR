# ADR-024 — La pausa se modela como dos tramos, no como un intervalo interno

| Campo | Valor |
|---|---|
| **Estado** | Aceptada |
| **Fecha** | 12 de agosto de 2026 |
| **Decide** | `arquitecto-dominio` |
| **Afecta a** | Tarea 3.5 |
| **Requisitos** | RF-AT-12, RN-01, RN-02, RN-05, RN-06, RN-12 |

## Contexto

RF-AT-12 introduce el fichaje de pausa. Al desarrollar la tarea 3.5 apareció que **ningún documento dice cómo se modela**, y hay dos formas incompatibles de hacerlo:

- **Como dos tramos**: la pausa cierra el tramo en curso y abre otro al volver. Una jornada con una pausa son dos `ShiftEntry`.
- **Como intervalo interno**: el tramo permanece abierto y la pausa se registra dentro, restándose de la duración computable.

La diferencia no es cosmética. Afecta a las dos invariantes que la base de datos garantiza declarativamente y a la forma en que se calcula el tiempo trabajado de media plantilla.

## Decisión

**La pausa son dos tramos.** Fichar la pausa cierra el `ShiftEntry` abierto; fichar la vuelta abre uno nuevo. No se introduce ningún concepto nuevo en el dominio.

Lo que distingue una pausa de un fin de jornada **no es la estructura, sino el motivo del escaneo**: el escaneo declara su intención como `break_start` o `break_end`, y eso es lo que permite a los informes y a la vista de cumplimiento tratarla como pausa. La estructura de datos es la que ya existe.

### Dos consecuencias que no son opcionales

Sin ellas esta decisión produce un registro horario incorrecto, así que forman parte de la decisión y no de su implementación:

**1. La intención viaja en la petición, no solo en la respuesta.** `break_start` y `clock_out` son estructuralmente idénticos —los dos cierran el tramo abierto—, y `break_end` y `clock_in` también. **El servidor no puede deducir cuál es.** Por tanto `POST /api/v1/scan` y cada elemento de `/scan/batch` llevan un campo de intención, opcional y con valor por defecto que preserva el comportamiento actual — aditivo y compatible con ADR-012. La cola offline del quiosco lo persiste y lo reenvía en los reintentos.

**2. `work_date` es propiedad de la jornada, no se deriva de cada tramo por separado.** RN-05 atribuye la jornada de un tramo a la fecha civil de su `clocked_in_at`. Aplicado tramo a tramo, una pausa que cruza medianoche **partiría la jornada**, que es justo lo que ADR-006 prohíbe:

| Turno | Tramos | Si `work_date` se deriva por tramo | Con `work_date` de la jornada |
|---|---|---|---|
| 22:00 → 06:00 sin pausa | 1 | día D: 480 min | día D: 480 min |
| 22:00 → 02:00 · pausa · 02:30 → 06:00 | 2 | día D: 240 min · **día D+1: 210 min** | día D: 450 min |

Dos personas con el mismo turno acabarían con registros diarios distintos según si ficharon la pausa, y eso altera la evaluación de RN-11, el informe diario y la nómina. **Un `break_end` continúa la jornada abierta y hereda su `work_date`; solo un `clock_in` que no sea `break_end` abre jornada nueva.**

Nótese que el problema **no lo crea esta decisión**: ya existe hoy para quien sale y vuelve de madrugada sin RF-AT-12. Lo que hace este ADR es hacerlo visible y obligar a resolverlo.

### Por qué el modelo ya lo soportaba

Tres señales que estaban en los documentos y que este ADR solo hace explícitas:

1. **RN-12 habla de «pausa registrada»**: «se alerta si un tramo continuo supera 6 h **sin pausa registrada**». La regla está enunciada sobre *tramos*, no sobre intervalos dentro de un tramo. Una pausa que parte el tramo es exactamente lo que hace que el tramo deje de ser continuo.
2. **«Jornada partida de 4 tramos» ya es un escenario de prueba obligatorio** del prompt de arranque del dominio ([documento 03](../03-agentes-y-skills-ia.md) §6.2). Una jornada de mañana y tarde con pausa para comer son cuatro tramos: el modelo se diseñó desde el principio para varios tramos por jornada.
3. **RN-06 recalcula el total como suma de los tramos de la jornada.** Con la pausa fuera de los tramos, el total sale correcto sin restar nada: el tiempo de pausa simplemente no está en ningún tramo. Con un intervalo interno habría que restar, y toda resta es una oportunidad de equivocarse en la nómina de alguien.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Intervalo interno al tramo** | Obligaría a revisar RN-01 (¿un tramo en pausa cuenta como abierto?), RN-02 y la restricción `EXCLUDE USING gist` de PostgreSQL, que razona sobre `tstzrange(clocked_in_at, clocked_out_at)` y no sabe de huecos internos. Y convertiría el cálculo de RN-06 en una suma con restas. Se toca la última línea de defensa del sistema para no ganar nada |
| **Un agregado o tabla aparte de pausas** | Un concepto nuevo, con sus propias invariantes, que hay que mantener coherente con los tramos. Complejidad sin capacidad añadida |
| **No registrar la pausa y deducirla del hueco entre tramos** | Es lo que ocurre hoy sin RF-AT-12, y es justo lo que el requisito viene a resolver: un hueco entre tramos puede ser una pausa o un olvido de fichaje, y confundirlos falsea el registro |

## Consecuencias

- **Cero conceptos nuevos en el dominio.** `WorkDay` y `ShiftEntry` no cambian de forma; lo que se amplía es el vocabulario de la acción de fichaje.
- **`POST /api/v1/scan` cambia en las dos direcciones**: la petición gana el campo de intención y la respuesta amplía su enum `action` a `clock_in`, `clock_out`, `break_start`, `break_end`. `scan_events.result` y `scan_events.intent` lo reflejan. Ambos cambios son aditivos y no rompen la v1, como exige ADR-012 mientras haya quioscos en esa versión.
- **La cola offline del quiosco cambia.** El campo de intención se persiste en Dexie al encolar —no al enviar— y se reenvía en cada reintento, igual que el `scan_id`. Es la consecuencia que más trabajo añade y la que hay que anticipar: la tarea 3.5 toca código de la Fase 1.
- **`ATTENDANCE_DEBOUNCE_SECONDS` necesita revisión.** Un `break_end` inmediato tras un `break_start` mal pulsado caería hoy en `rejected_debounce` con los 60 s del Anexo B. El anti-rebote debe considerar la intención, o corregir una pausa mal fichada exigirá pasar por el mecanismo de correcciones para algo que el empleado puede arreglar solo.
- **RN-12 se puede evaluar sin lógica nueva:** un tramo de más de 6 h sin pausa es, literalmente, un tramo largo.
- **Una pausa mal fichada se corrige con el mecanismo de correcciones que ya existe** (RN-13, tarea 2.3). Con un intervalo interno habría que inventar cómo corregir un hueco dentro de un tramo.
- **Aumenta el número de filas en `shift_entries`**: una jornada con dos pausas pasa de una fila a tres. Es intrascendente al volumen del sistema —500 empleados— y el índice parcial de turnos abiertos del §3.2 sigue resolviendo en O(log n).
- **El quiosco debe distinguir las cuatro acciones en su feedback.** Un empleado que ficha la pausa y ve «salida registrada» pensará que ha cerrado su jornada.

## Verificación

- Prueba unitaria: jornada con dos pausas → cuatro tramos, total igual a la suma de los cuatro, sin restas.
- Prueba unitaria: tramo de 6 h 1 min sin pausa → se alerta según RN-12; con pausa registrada a las 3 h → no se alerta.
- Prueba de integración: la restricción de exclusión sigue rechazando por SQL directo un solape entre un tramo y el siguiente tras la pausa.
- **Prueba unitaria del caso que motiva este apartado:** turno 22:00 → 02:00, pausa, 02:30 → 06:00. Los dos tramos pertenecen a la **misma jornada** y su `work_date` es el día D, el de apertura de la jornada — **no** el de inicio de cada tramo. El total del día D es 450 min y el del día D+1 es cero. Comparar contra el mismo turno sin pausa: 480 min el día D. Es la prueba que demuestra que fichar la pausa no cambia a qué día se imputan las horas.
- Prueba unitaria: un `clock_in` que **no** es `break_end`, tras un `clock_out`, abre jornada nueva aunque ocurra el mismo día natural.
- Prueba de integración: un lote offline con `break_start` y `break_end` sincronizado horas después conserva la intención y produce la misma atribución que si hubiera llegado en línea.
- E2E de quiosco: el feedback de `break_start` es distinguible del de `clock_out`.
