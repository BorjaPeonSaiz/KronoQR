# ADR-058 — Cómo terminó un tramo es un hecho del registro, y una jornada no pasa del día siguiente

| Campo | Valor |
|---|---|
| **Estado** | **Aceptada** por el propietario (18-10-2026: «Enmendar el ADR»). Implementada en la 2.3.0, bloque 1 |
| **Fecha** | 18 de octubre de 2026 |
| **Decide** | `arquitecto-dominio` (bloque 1 de la 2.3.0; hallazgos V21-3-AR-1, V21-2-QA-1 y el encadenado de turnos de la revisión de seguridad) |
| **Afecta a** | **Enmienda** [ADR-024](ADR-024-la-pausa-son-dos-tramos.md) (la pausa deja de ser solo el motivo del escaneo) · Extiende [ADR-057](ADR-057-el-registro-horario-no-se-borra-ni-se-reescribe-con-la-credencial-del-runtime.md) §1, §2 y §4 a una séptima columna · Respeta [ADR-006](ADR-006-los-turnos-no-se-parten-a-medianoche.md), [ADR-026](ADR-026-la-correccion-supersede.md) y [ADR-035](ADR-035-la-correccion-estrena-identificador-y-no-cambia-de-jornada.md) · `shift_entries.closed_by` (migraciones `2026_10_17_100000`, `_100100` y `_100200`) · `Attendance/Domain/ValueObject/EntryClosure.php` · `WorkDay::guardContinuesWorkDay()`, `guardVoidKeepsContinuation()` · `ScanIntentPolicy` · `RegisterScanHandler::breakStillOpenIn()` |
| **Requisitos** | RN-05, RN-10, RN-13, RF-AT-12, RF-PA-04, RL-04 · reglas duras 4, 5, 6 y 19 |

## Contexto

ADR-024 decidió que la pausa son dos tramos y que **lo que distingue una pausa de un fin de jornada es el motivo del escaneo**, no la estructura. El dato vivía en `scan_events.result` y solo en él.

Eso bastaba mientras la única forma de escribir un tramo fuera el quiosco. Con el alta y la corrección manuales (RF-PA-04) no basta:

- Un tramo escrito a mano **no tiene escaneo**, así que no hay forma de decir que terminó en pausa. O el panel no puede continuar nunca una jornada al día siguiente —y el turno de noche con su vuelta de madrugada no se puede completar a mano—, o puede continuarla siempre.
- Si puede siempre, un responsable **encadena los turnos de días seguidos en una sola jornada** (el 10 de 08:00 a 20:00 y el 11 de 07:00 a 19:00, con 11 h entre medias): la cota de RN-05 se cumple, el descanso insuficiente entre jornadas desaparece de la vista de cumplimiento (RN-10) y el registro dice que fue una sola.
- Ni en el quiosco ni en el panel había un tope: una cadena de pausas, cada una dentro de la cota, podía arrastrar una jornada varios días.

## Decisión

**1. `closed_by` es un hecho persistido del tramo.** `shift_entries.closed_by` (`clock_out` | `break_start`, nulo si y solo si el tramo sigue abierto) dice cómo terminó. Lo fija el escaneo —«Pausa» cierra con `break_start`, la salida con `clock_out`— y viaja con cada versión del tramo como cualquier otra marca (RN-13). Es la fuente de verdad: el detalle de jornada, la exportación legal, la exportación de datos y la conciliación lo leen de la columna, no de `scan_events`.

**2. La pausa se puede declarar a mano.** Al crear o corregir un tramo cerrado, `ends_in_break` dice que terminó en pausa; sin declararlo, termina en salida. Declararla en un tramo sin salida es un estado imposible (`422`). Cambiar solo esto es una corrección como cualquier otra: versión nueva, fila de `shift_corrections` con `closed_by` en el antes y el después, y asiento en `audit_log`.

**3. Solo una pausa continúa la jornada al día siguiente, y como mucho al día siguiente.** Un tramo cuya entrada cae en un día civil distinto de `work_date` pertenece a la jornada solo si el anterior terminó en pausa, su entrada queda a menos de `min_rest_hours` de la salida de ese tramo y cae, como mucho, en `work_date + 1`; y no lo continúa si entre medias hay un tramo vigente de otra jornada de la persona. Lo mismo al anular. **En el quiosco rige el mismo tope**: una vuelta de pausa posterior a `work_date + 1`, o que la persona ya no tiene en pausa (una corrección declaró salida, u otra jornada se interpuso), **abre jornada nueva y nunca se rechaza** (regla dura 19).

**4. Fase *expand*, y cuándo se contrae.** La columna llega en tres migraciones: la columna con un *trigger* `shift_entries_closed_by_default` que pone `clock_out` a una salida escrita sin cierre, la guarda de transiciones de ADR-057 §2 y el `GRANT`; el relleno por lotes desde los escaneos, por linaje; y el `CHECK` creado `NOT VALID` y validado aparte. El *trigger* por defecto existe porque la versión anterior, que sigue sirviendo durante el despliegue, no escribe la columna, y lo que escribe —una salida sin más— es exactamente una salida. **Se retira en la fase *contract***, en la primera versión posterior a la 2.3.0 en la que ninguna versión en servicio deje de escribir la columna; desde entonces una salida sin cierre será un error y no un `clock_out` implícito.

## Consecuencias

- **ADR-024 sigue en pie** en lo que decidió —la pausa son dos tramos, la intención viaja en la petición, el feedback enseña la acción decidida—, pero el dato que distingue la pausa ya no es solo el escaneo: es `closed_by`, que el escaneo escribe y el panel puede declarar.
- **ADR-057 crece en una columna.** El runtime puede escribir `closed_by` solo al cerrar un tramo abierto, junto con la salida; reescribirla en un tramo cerrado, ponerla en uno abierto o escribirla al anular lo rechaza la guarda (`42501`). La conciliación de §4 compara `closed_by` con la `action` del asiento `shift_entry.closed` o con el `after.closed_by` de la última corrección.
- **Lo que esto no impide.** El runtime puede cerrar un tramo abierto con `break_start` en vez de `clock_out` sin pasar por el dominio: es una escritura que el fichaje necesita, como cerrar con una hora falsa. Lo detecta la conciliación de §4. Y una corrección legítima puede declarar una pausa que no hubo: queda firmada, con motivo y en la auditoría (RN-13).
- **Datos anteriores.** Los tramos cerrados a mano antes de la 2.3.0 terminan en `clock_out`, que es lo que el registro afirmaba de ellos; los cerrados por «Pausa», en `break_start`. Las consultas de `docs/runbooks/actualizacion-cliente.md` §3 bis listan lo que hoy se rechazaría, para revisarlo con dos salidas: declarar la pausa en el tramo anterior o anular y dar de alta.
- **Ventana del relleno.** Medida en `actualizacion-cliente.md`: lotes de 2000 tramos, cada uno con su transacción, del orden de 90 ms por lote; las escrituras del fichaje esperan como mucho un lote.
