# ADR-062 — La baja se puede programar, y la readmisión abre un periodo de alta nuevo en la misma ficha

| Campo | Valor |
|---|---|
| **Estado** | **Aceptada**: el propietario aprobó D16 y D17 el 18-10-2026 (plan de la 2.3.0, bloque 25), y este texto las concreta. El 29-10-2026 decidió además que la baja con cese de hoy también se programa, con «Aplicar ya» (`apply_now`) para el efecto inmediato (§3). Revisión de `seguridad-cumplimiento`: §2 revisado (30-10-2026, con las tareas 25.3 y 25.4); quedan §3.3, §4 y §8 (25.5). Lo implementan las tareas 25.2 (baja programada), 25.3 y 25.7 (readmisión), 25.4 (corrección de la fecha de cese) y 25.6 (panel) |
| **Fecha** | 29 de octubre de 2026 |
| **Decide** | `arquitecto-dominio` (bloque 25 de la 2.3.0, tarea 25.1; V3-AR-6 y R4-SC-01 en seguimiento) |
| **Afecta a** | **Enmienda** [ADR-046](ADR-046-la-ficha-del-empleado-se-escribe-bajo-candado-y-por-columnas.md): «`terminated` es final», el disparador descartado y la fila de una persona de baja en la importación. Precisa [ADR-043](ADR-043-el-pin-rechazado-conserva-a-quien-correspondia-el-codigo.md) por su enmienda del 29-10-2026 (D15) · Respeta [ADR-006](ADR-006-los-turnos-no-se-parten-a-medianoche.md), [ADR-010](ADR-010-auditoria-solo-append-encadenada.md), [ADR-025](ADR-025-frontera-de-dependencias-del-nucleo.md), [ADR-028](ADR-028-limites-del-plan-no-bloquean.md), [ADR-034](ADR-034-el-token-nace-al-imprimir-no-al-emitir.md), [ADR-040](ADR-040-un-centro-por-instalacion-y-por-licencia.md), [ADR-047](ADR-047-ningun-fichaje-sale-de-la-cola-sin-desenlace-del-servidor.md), [ADR-057](ADR-057-el-registro-horario-no-se-borra-ni-se-reescribe-con-la-credencial-del-runtime.md), [ADR-058](ADR-058-como-termino-un-tramo-es-un-hecho-del-registro.md) y [ADR-060](ADR-060-al-vencer-su-plazo-la-ficha-y-la-cuenta-de-gestion-se-minimizan-en-sitio-con-asiento.md) · `Workforce`: `Employee`, `EmployeeRepository`, `OffboardEmployeeHandler`, `PlanEmployeeImport`, `ApplyEmployeeImport` y los casos de uso nuevos de §10 · `Identity`: la revocación de la baja y la emisión de la readmisión · `Attendance`: `EmployeeDirectory`, `ScanIntentPolicy`, `ManualEntryEligibility` · tablas nuevas `employment_periods` y `employment_period_corrections` |
| **Requisitos** | RN-14, **RN-27** (nueva), RN-20, RF-GP-01, RF-GP-03, RF-GP-04, RF-GP-05, RF-PA-04, RF-IN-03, RL-04, RL-11 · art. 34.9 ET · reglas duras 2, 3, 4, 5, 6, 13, 14, 17, 19 y 21 |

## Contexto

Desde la 2.2.0 (bloque 17) la baja es efectiva al registrarla y **una fecha de cese futura se
rechaza** (RN-14). Fue la salida segura a R4-SC-01: antes, una baja de temporada registrada en
septiembre con cese el 31 de octubre dejaba a la persona seis semanas sin fichar. El precio está
escrito en el doc 05 §2: el hotel de temporada tiene que acordarse de dar de baja a cada persona
**el día que termina**, y no antes.

El segundo hueco es la vuelta. Quien trabajó la temporada pasada y vuelve no tiene camino. Su
documento ya está en una ficha `terminated`. El alta responde `409` por el documento
(`employees_national_id_hash_unique`), y la importación rechaza su fila con `employee_terminated`. Y
ADR-046 escribió «`terminated` es final» porque no existía la readmisión: descartó un disparador
sobre el estado **«hasta que la 2.3.0 diseñe la readmisión»**.

El tercero es la fecha de cese mal puesta. Si RRHH pone un cese anterior al último día trabajado, esas
horas no pueden entrar en el registro: no hay tramos después del cese y la fecha de una baja no se
corrige. Doc 07 §6 lo acepta con destino a la 2.3.0.

Las tres decisiones del propietario (18-10-2026):

- **D17.** Se guarda la fecha de cese futura y una tarea diaria la aplica en la fecha civil del centro.
  La persona ficha hasta su último día, y la baja se puede anular antes de la fecha.
- **D16.** Readmisión en la misma ficha, con un periodo de alta nuevo y una credencial nueva. El
  documento sigue siendo único.
- **D15.** El fichaje por PIN de una persona de baja se atribuye si el PIN es correcto y su fecha no
  pasa del cese. Está en la enmienda de ADR-043.

El 18-10-2026 el propietario pidió además que la readmisión fuera **un botón con un solo dato
obligatorio**, que sirva para varias personas a la vez y que la importación la proponga (tarea 25.7).

## Decisión

### 1. Los periodos de alta son una tabla; la ficha conserva el periodo en curso

**`employment_periods`** (Workforce) es la historia laboral de la persona. Cada fila es un periodo de
alta:

| Columna | Tipo | Qué es |
|---|---|---|
| `id` | `BIGINT` PK | |
| `uuid` | `UUID` v7 | identificador público (§9) |
| `employee_id` | FK → `employees`, `NOT NULL` | |
| `hired_on` | `DATE NOT NULL` | fecha de alta del periodo. **No cambia nunca** |
| `terminated_on` | `DATE NULL` | fecha de cese. `NULL` si no hay baja. Con fecha y `offboarded_at` nulo, es una **baja programada** |
| `offboarded_at` | `TIMESTAMPTZ NULL` | instante en que la baja se hizo efectiva (registrada en el acto o aplicada por la tarea de §4). Una vez escrito, no cambia |
| `created_at`, `created_by_user_id` | | quién abrió el periodo. `NULL` en el relleno de la migración y en una importación sin cuenta |

Restricciones:

- `CHECK (terminated_on IS NULL OR terminated_on >= hired_on)`.
- `CHECK (offboarded_at IS NULL OR terminated_on IS NOT NULL)`.
- `EXCLUDE USING gist (employee_id WITH =, daterange(hired_on, terminated_on, '[]') WITH &&)`: dos
  periodos de la misma persona no se solapan. Es la misma técnica que RN-02 y que
  `employment_contracts`.
- Índice único parcial `one_open_employment_period` sobre `(employee_id) WHERE offboarded_at IS NULL`:
  como mucho un periodo sin baja efectiva.

**`employees.hired_at`, `terminated_at` y `status` se quedan.** Son la **proyección del periodo en
curso**, el de `hired_on` más reciente:

- `hired_at` es su `hired_on`;
- `terminated_at` es su `terminated_on`;
- `status = 'terminated'` si y solo si su `offboarded_at` no es nulo.

La proyección se escribe en la misma transacción que el periodo, siempre por el mismo método del
repositorio. Un *constraint trigger* `DEFERRABLE INITIALLY DEFERRED`,
`employees_current_period_matches`, sobre las dos tablas, falla el `COMMIT` si no coinciden.

**Migración, solo *expand*.**

1. Se crea la tabla.
2. Se rellena con un periodo por ficha: `hired_on = hired_at`, `terminated_on = terminated_at` y
   `offboarded_at`. Para una baja, `offboarded_at` es el `occurred_at` del último asiento
   `employee.offboarded` de esa persona y, si no lo hay, el `updated_at` de la ficha.
3. Se crea el disparador de §2 y el de la proyección.

Es reversible mientras no exista una segunda fila para ninguna persona. El `down()` lo comprueba y se
niega si la hay: bajar con readmisiones hechas perdería historia (regla dura 5).

**No hay fase *contract*.** Las columnas de `employees` se quedan como proyección por tres motivos. Las
leen el `CHECK employees_chk_terminated_after_hired`, la función de minimización de ADR-060 y
`EmployeeSnapshot`. Quitarlas obligaría a un `JOIN` en el fichaje. Y la pregunta «¿está de alta hoy?»
es de una sola fila.

Las **bajas de la 2.1.0 con fecha de cese futura** (doc 07 §6) se rellenan tal cual: `terminated` con
`offboarded_at`. **No se convierten en programadas**: la baja ya se aplicó y su tarjeta ya está
revocada. Para readmitir a una de esas personas, RRHH corrige antes la fecha de cese (§7).

### 2. El disparador que ADR-046 descartó, ahora con las transiciones que existen

ADR-046 no puso disparador sobre el estado porque ningún camino legítimo salía de `terminated`. La
readmisión abre ese camino. Desde ese momento, el predicado `status <> 'terminated'` deja de bastar
para impedir que una escritura cualquiera reactive a una persona. **`employees_guard_employment_transition`**,
`BEFORE UPDATE OF status, hired_at, terminated_at ON employees`, admite solo estas transiciones:

| De | A | Condición | Caso de uso |
|---|---|---|---|
| `active` ↔ `suspended` | | `hired_at` y `terminated_at` sin cambio | suspender, levantar (RN-25) |
| `active`/`suspended` | el mismo estado | solo cambia `terminated_at`, de nulo a fecha, de fecha a fecha o de fecha a nulo | programar, reprogramar o anular la baja (§3) |
| `active`/`suspended` | `terminated` | `terminated_at` no nulo y `hired_at` sin cambio | baja en el acto, o aplicada por la tarea (§4) |
| `terminated` | `terminated` | solo cambia `terminated_at` | corrección de la fecha de cese (§7) |
| `terminated` | `active` | `NEW.hired_at > OLD.terminated_at` y `NEW.terminated_at IS NULL` | readmisión (§6) |

Cualquier otra combinación responde `42501`. Eso incluye `terminated → suspended`, cambiar `hired_at`
sin readmitir y reactivar sin fecha de alta nueva. El disparador **no** compara con «hoy»: la base no
conoce la zona del centro como la conoce el caso de uso (regla dura 3), y esa regla la protege el
dominio.

En `employment_periods`, el rol de la aplicación tiene `INSERT` y `UPDATE (terminated_on, offboarded_at)`
y no tiene `DELETE` (como ADR-057). `employment_periods_guard`, `BEFORE UPDATE`, rechaza cambiar
`offboarded_at` una vez escrito y quitar `terminated_on` a un periodo con baja efectiva.

Las dos funciones entran en la comprobación de existencia, activación y `SECURITY DEFINER` de
`work-record-privileges.sh` y de `product:doctor`. Su huella queda con la de las demás funciones guarda
(HANDOFF, «Pendiente» del bloque 20).

### 3. La baja programada

**Una fecha de cese de hoy o posterior se programa** (RN-14, RF-GP-03). Para una fecha futura, es la
decisión D17. Para la de hoy, es la decisión del propietario del 29-10-2026: la persona puede fichar la
salida de su último turno. Como hasta ahora, «hoy» es la fecha civil del centro en el instante en que
el servidor recibe la baja. La resuelve el caso de uso con `Clock` y `sites.timezone`, y el dominio
compara fechas, nunca instantes.

**«Aplicar ya»** es un indicador explícito de la petición, `apply_now`, que es `false` si no se envía.
Sirve para un despido con efecto inmediato: con cese de hoy, la baja se hace efectiva en el acto.

| Fecha de cese | Sin `apply_now` | Con `apply_now: true` |
|---|---|---|
| Anterior a la fecha de alta | `422` en `terminated_at`, como hoy | igual |
| Anterior a hoy y no anterior al alta | **Baja en el acto**: tarjetas revocadas, portal cerrado, asiento `employee.offboarded`. No hay nada que esperar: el último día ya pasó | igual |
| Hoy | **Baja programada**: se aplica al terminar el día, por la tarea de §4. Asiento `employee.offboarding_scheduled` | **Baja en el acto**, con asiento `employee.offboarded` y `apply_now: true` |
| Posterior a hoy | **Baja programada**: la persona sigue `active` (o `suspended`), con `terminated_at` = la fecha. Ficha, tarjetas y portal no cambian. Asiento `employee.offboarding_scheduled` | `422` en `apply_now`: no se aplica en el acto una baja cuyo último día no ha llegado. Un despido con efecto inmediato lleva el cese de hoy |
| Fecha de alta futura y cese igual a esa fecha | **Baja programada**, como cualquier fecha de hoy o posterior: el extra de un solo día ficha ese día y la tarea aplica la baja | **Baja sin efectos en el acto**: la contratación que no llegó a empezar. Es la única excepción a «`apply_now` con cese futuro da `422`» |

**Enmienda del 29-10-2026 (decisión del propietario).** La última fila cambia: antes, el cese igual a un alta futura era siempre la baja sin efectos en el acto, y un contrato de un solo día que aún no había empezado no se podía programar. Ahora sin `apply_now` se programa y con `apply_now` es la baja sin efectos.

`Employee::offboard($terminatedOn, $today, $applyNow)` decide el efecto con esta tabla. El indicador
es un dato de la decisión, no del canal: la validación de la petición lo comprueba primero, pero lo
garantiza el dominio.

El estado «baja programada» **no es un valor nuevo de `status`**. Se deriva del par: `status <>
'terminated' AND terminated_at IS NOT NULL`. Ningún `switch` sobre `status` tiene que aprender un
caso nuevo, y la persona sigue siendo «de alta» para todo lo que pregunta por el estado. Las dos
columnas solo pueden tener ese par por los caminos de la tabla de §2.

**Hasta su último día, nada cambia.** Ficha con tarjeta, PIN y portal. Lo que sí cambia, desde que hay
fecha de cese, es una regla que no depende de la tarea:

> **Ningún tramo nuevo puede quedar en una jornada (`work_date`, RN-05) posterior a la fecha de
> cese**, la fije una baja efectiva o una programada.

«Nuevo» es a propósito. Una baja registrada días después de producirse, con fecha de cese pasada, no
se compara con el último fichaje (RN-14), y los tramos que la persona fichó después de esa fecha se
conservan tal cual (regla dura 5). La regla impide escribir tramos después del cese, no borra los que
ya estaban.

La aplican el fichaje (`ScanIntentPolicy`) y el alta y la corrección manuales (`ManualEntryEligibility`).
Las dos la leen de `EmployeeSnapshot`, que ya lleva `terminatedOn` (ADR-025). La consecuencia:

- El turno de noche del último día cierra con su salida a las 06:00 del día siguiente, porque su
  jornada es la del cese (regla dura 4).
- La vuelta de una pausa dentro de la cota de continuación de ADR-058 sigue siendo de la jornada del
  cese.
- Una **entrada** que abriría la jornada del día siguiente se rechaza con el rechazo genérico de RS-03,
  aunque la tarea todavía no haya pasado. La tarjeta auténtica queda atribuida a su titular y sin
  marca de revisión, como una tarjeta posterior a su retirada (RN-20). El PIN, sin persona (enmienda
  de ADR-043).

**Reprogramar** es volver a llamar a la baja con otra fecha. Si la fecha nueva es futura, se
reprograma con asiento `employee.offboarding_scheduled` y `previous_terminated_on`. La fecha nueva
puede ser hoy, y entonces sigue programada. Si es anterior a hoy, o es hoy con `apply_now`, la baja se
hace efectiva en el acto. Así «Aplicar ya» sirve también sobre una baja que ya estaba programada.

**Anular** es una acción propia (§9). Solo cabe **mientras la fecha de cese no haya pasado**: con hoy
≤ cese, se pone `terminated_at` a nulo, con asiento `employee.offboarding_cancelled`. Pasada la fecha,
la baja ya rige por la regla de arriba aunque la tarea no la haya aplicado todavía. La anulación
responde `409` (`offboarding-due`). Si la persona sigue trabajando, la vía es readmitirla (§6) o
corregir la fecha (§7) cuando la baja ya sea efectiva.

### 4. La tarea que aplica la baja

**`workforce:apply-scheduled-offboardings`**, caso de uso `ApplyDueOffboardings` (`Workforce`).

- **Cuándo corre.** Cada hora (`hourlyAt(7)`, `withoutOverlapping()`), en UTC como todo el
  `scheduler`. D17 dice «diaria» por la fecha: la baja se aplica una vez, el día civil siguiente al
  cese. Correr cada hora resuelve tres cosas sin código propio:
  - **El horario de verano.** No hay que programar «las 00:05 del centro» en UTC, que se mueve dos
    veces al año. Un día de 23 o de 25 horas tiene igualmente 23 o 25 pasadas.
  - **La recuperación.** Una pasada perdida la cubre la siguiente.
  - **El aplazamiento** del punto siguiente.
- **Qué aplica.** Cada pasada resuelve «hoy» una sola vez, con `Clock` y la zona del centro, y toma a
  las personas con `status <> 'terminated' AND terminated_at < hoy`. Compara fechas civiles, nunca
  instantes, así que el cambio de hora no la afecta. Sin centro no hay nada que aplicar: no se pudo
  programar ninguna baja (ADR-040). **Una baja con cese de hoy no tiene trato aparte**: «al terminar
  el día» es la primera pasada del día civil siguiente, salvo que haya aplazamiento. La que se aplicó
  con `apply_now` ya está `terminated` y la tarea no la ve.
- **Aplazamiento.** No se aplica mientras **la jornada del cese pueda seguir creciendo**, es decir, si
  la persona tiene un tramo abierto de una jornada hasta el cese, o uno cerrado en pausa dentro de su
  cota de continuación (ADR-058). Así el turno de noche y la pausa de madrugada del último día terminan
  fichados y no con una corrección. **Tope:** al empezar el segundo día civil después del cese (hoy ≥
  cese + 2) se aplica igual. El tramo que siga abierto lo detecta RF-PR-01 y se cierra con una
  corrección (RF-PA-04), como hoy. La pregunta la hace el puerto `Shared\Application\Port\FinalWorkDayActivity`,
  que implementa `Attendance` porque la cota es suya. Va en `Shared` y no en `Workforce` porque
  implementarlo desde `Attendance` obligaría a importar `Workforce`, arista que ADR-025 y Deptrac
  prohíben; es el mismo patrón que `SerializedLedgerWrite`. El adaptador lee sin bloquear para no
  invertir el orden de candados de ADR-046.
- **Cómo aplica.** Una transacción por persona: un fallo no detiene a las demás. Dentro, el orden de
  ADR-046: `withChainLock` → `findForUpdate` → se comprueba otra vez que la baja sigue vencida y sin
  anular → `Employee::applyScheduledOffboarding($today)` → `saveTermination` → `offboarded_at = now`
  en el periodo → evento `EmployeeOffboarded` (con `scheduled: true`). Por ese evento, `Identity`
  revoca las tarjetas y cierra el portal **en la misma transacción**, con el mismo caso de uso que la
  baja en el acto, y `Compliance` escribe el asiento `employee.offboarded` con actor `system`.
- **Solo lo que se programó por el producto** (enmienda del 29-10-2026). Antes de aplicar, con la
  cadena tomada, la tarea comprueba que el último asiento `employee.offboarding_*` de esa persona es
  `employee.offboarding_scheduled` con la misma fecha de cese, por el puerto
  `Shared\Application\Port\ScheduledOffboardingRecord`, que implementa `Compliance`. Si no lo es, la
  fecha se escribió por fuera del producto: no la aplica, la cuenta como `failed`, deja
  `workforce.scheduled_offboarding_without_audit` en el log y la persona queda pendiente, así que la
  ve la alerta del retraso. Así `system` nunca firma una baja que nadie pidió.
- **Idempotente.** La segunda pasada no encuentra a nadie, y `saveTermination` lleva el predicado de
  ADR-046 §3. Ni una persona ni una tarjeta reciben dos asientos.
- **Llegar tarde no cambia la fecha.** Si el `scheduler` estuvo parado tres días, la baja se aplica en
  la siguiente pasada con **su fecha de cese**. `offboarded_at` y `credentials.revoked_at` son los del
  momento real de la aplicación, y el asiento lleva `due_on` y los días de retraso. Mientras tanto,
  nadie pudo fichar después del cese por la regla de §3.
- **Rastro.**
  - Métrica `kronoqr_offboardings_applied_total{outcome="applied|deferred|failed"}` y *gauge*
    `kronoqr_scheduled_offboardings_overdue`, con las personas cuyo cese pasó hace más de un día sin
    aplicar.
  - Alerta `ScheduledOffboardingOverdue` si ese *gauge* pasa de 0 durante 2 h.
  - *Gauge* `kronoqr_scheduled_offboardings_last_run_timestamp_seconds` al final de cada pasada, y
    alerta `ScheduledOffboardingTaskAbsent` si falta o lleva más de 2 h sin moverse (30 min): el
    *gauge* de pendientes solo se mueve cuando la tarea corre. La programación lleva
    `withoutOverlapping(55)`.
  - `product:doctor` lo comprueba.
  - En el log técnico, solo `employee_uuid` (regla dura 21).

### 5. Concurrencia con el fichaje del día del cese

El fichaje toma `FOR KEY SHARE` sobre la ficha y la cadena al final. La tarea toma la cadena, después la
ficha con `FOR NO KEY UPDATE` y después las tarjetas. Es el orden de ADR-046 §1 y no hay ciclo.

| Cruce | Resultado |
|---|---|
| La salida del último turno llega mientras pasa la tarea | Si el tramo seguía abierto al leerlo con la cadena tomada, la tarea aplaza. Si la salida confirmó antes, se aplica con el tramo cerrado |
| Llega de la cola una entrada del día del cese y la tarea confirma entre la lectura del fichaje y su escritura | El tramo se abre en la jornada del cese y la persona queda `terminated` con ese tramo abierto. Es **legal**: la jornada está dentro del periodo. Lo detecta RF-PR-01 y se cierra con una corrección. No se añade una relectura al fichaje para cerrar este hueco: pondría la cadena antes en el camino caliente (RNF-P-01) para un caso que ya tiene salida |
| Una entrada de la jornada siguiente al cese, antes o después de la tarea | Se rechaza siempre, por la regla de §3, que no depende de la tarea |
| Anular o reprogramar mientras pasa la tarea | Las dos toman la cadena y la ficha. La que llega segunda relee: la anulación ve `terminated` y responde `409`, y la tarea ve la baja anulada y no hace nada |

**La invariante:** una baja programada nunca deja un tramo con `work_date` posterior al cese. Como mucho, queda abierto un
tramo de la jornada del cese.

### 6. La readmisión

**Una persona `terminated` vuelve a estar de alta en la misma ficha, con un periodo nuevo** (RN-27).

- **Condiciones.**
  - La ficha está `terminated` y no minimizada. Una ficha minimizada (ADR-060) ya no tiene documento
    con el que reconocerla, y `employees_guard_anonymized` impide tocarla. Quien vuelve después de la
    minimización es un alta nueva.
  - La fecha de alta nueva es **posterior a la fecha de cese** del último periodo, sin solape y sin
    compartir día.
  - La fecha de alta tiene los mismos límites que la del alta inicial.
  - La persona no ha de estar en otra readmisión simultánea: el candado de la ficha las serializa.
- **Qué se escribe**, en una transacción con el orden de ADR-046, ampliado a la derecha de la ficha
  (departamento nuevo con `FOR KEY SHARE` antes de la cadena → cadena → `employees` →
  `employment_periods` y `employment_contracts` → `credentials` → sesiones del portal):
  1. un periodo nuevo (`hired_on`, sin cese) y la proyección: `status = 'active'`, `hired_at` nueva y
     `terminated_at` nula;
  2. departamento e idioma, si cambian. Nombre, documento y código **no**: es la misma persona
     (25.7);
  3. contrato. Si la petición no trae `contract`, se registra uno nuevo con los términos del último y
     `valid_from` = la fecha de alta. Con `contract: null`, ninguno. El último contrato, si seguía con
     `valid_to` nulo, se cierra en el **cese del periodo anterior**, no en la víspera de la readmisión,
     para que el hueco no cuente como contratado (RF-IN-03);
  4. el evento `EmployeeRehired`. Por él, `Identity` emite una **credencial nueva pendiente de
     imprimir** (ADR-034: el token nace al imprimirla). Las tarjetas revocadas siguen revocadas: una
     tarjeta no se reactiva nunca;
  5. el asiento `employee.rehired`.
- **PIN y portal.** El PIN no se borra con la baja, y en la readmisión **se conserva**. El portal
  vuelve a admitir a la persona porque lo decide `status`: no hay nada que reactivar. Sus sesiones se
  cerraron en la baja. Con `reset_pin: true`, se emite un PIN nuevo como en
  `POST /employees/{uuid}/pin/reset`: se calcula antes de la cadena (ADR-046 A-3), se muestra una vez
  y queda pendiente de entrega (RF-ID-09). Una persona que nunca tuvo PIN sigue con el PIN pendiente.
- **El documento sigue siendo único.** La readmisión no inserta ficha, así que el índice no interviene.
  El alta (`POST /employees`) sigue respondiendo `409` por un documento repetido. Si el documento es
  de una persona de baja con ficha no minimizada, el `409` añade `rehirable_employee_uuid` para que el
  panel ofrezca «Dar de alta de nuevo». Solo lo recibe `rrhh+`, que ya ve la plantilla entera.
- **Licencia.** La persona readmitida cuenta en `max_employees` como un alta, y el límite no bloquea
  (ADR-028).
- **En lote.** Varias personas con una fecha común, todo o nada en una transacción, con el mismo tope
  de filas que la importación. Cada persona va con su propio candado, en orden de `id` para que dos
  lotes no se crucen, y deja su asiento. Las credenciales nuevas se imprimen después con el lote de
  impresión que ya existe.
- **En la importación** (RF-GP-05). La fila cuyo documento es de una persona de baja **ya no se
  rechaza con `employee_terminated`**. `PlanEmployeeImport` la planifica como `rehire`, con el aviso
  `rehire_proposed`, y la fecha de alta de la fila es la de la readmisión. Sin fecha de alta en la
  fila, o con una fecha no posterior al cese, la fila da error (`rehire_requires_hired_at`,
  `rehire_not_after_termination`). Al aplicar, la fila pasa por el mismo caso de uso de la
  readmisión. El resumen gana el contador `rehire`, con el nombre de la acción como los demás.
  **Solo se readmite a quien la fila identifica por su documento y con el nombre de su ficha** (sin
  distinguir mayúsculas, tildes ni espacios); si coincide solo por correo, o el nombre es otro, la fila
  da error (`rehire_identity_mismatch`): un correo compartido o una errata en el documento
  readmitirían a otra persona en su ficha. Si la fila trae otro nombre u otro correo y aun así se
  readmite, el aviso `rehire_ignores_identity_changes` dice que no se aplican. **Las readmisiones que se
  aplican son las revisadas** (revisión del 30-10-2026): la comprobación devuelve `rehire_digest` y
  `apply` lo exige en `confirm_rehire_digest`; una baja o una readmisión por otra vía entre las dos
  peticiones cambia la huella y la aplicación entera responde `409` sin escribir nada (ADR-046 §5,
  enmendado). La que llega durante la propia aplicación da `409` como hasta ahora
  (`employee-terminated` o `employee-not-terminated`).

### 7. La corrección auditada de la fecha de cese

RRHH puede corregir la fecha de cese de un **periodo con baja efectiva**, con motivo (tarea 25.4). Una
baja programada no se corrige: se reprograma (§3).

- **Fechas admitidas.**
  - No es posterior a hoy.
  - No es anterior a la fecha de alta del periodo.
  - Es anterior a la fecha de alta del periodo siguiente, si lo hay.

  Cualquier otra fecha da `422` en `terminated_at` y el mensaje dice cuál es el límite. **No se
  compara con el último tramo ni con las ausencias**, igual que la baja (RN-14): adelantar la fecha
  no borra nada, y los tramos que queden después se conservan tal cual. El panel avisa con un texto
  fijo, como en la baja.
- **Nada se sobrescribe sin rastro** (RN-13, RL-04). Se inserta una fila en
  **`employment_period_corrections`** (`id`, `employment_period_id`, `previous_terminated_on`,
  `terminated_on`, `reason` de 10 a 1 000 caracteres, `corrected_by_user_id`, `corrected_at`;
  solo-append), se actualiza `terminated_on` del periodo y, si es el periodo en curso, la proyección.
  El asiento es `employee.termination_date_corrected`. Lleva fechas e identificadores, **nunca el
  motivo**, que vive en la tabla, como el motivo de una corrección de tramo.
- **Efectos.**
  - Los días que pasan a estar dentro del periodo **admiten tramos a mano** (RF-PA-04).
  - Los fichajes que se rechazaron esos días **no se reprocesan**: el registro no se rellena solo.
    La guía de RRHH dice cómo listarlos y completarlos.
  - Las tarjetas no se reactivan.
  - El plazo de minimización de ADR-060 se cuenta desde la fecha corregida, porque lo lee de
    `terminated_at`.
- **Quién.** `rrhh` y `admin`, como la baja.

### 8. El fichaje por PIN después de la baja (D15)

Lo decide la [enmienda del 29-10-2026 de ADR-043](ADR-043-el-pin-rechazado-conserva-a-quien-correspondia-el-codigo.md#enmienda-del-29-10-2026-fichaje-por-pin-después-de-la-baja-d15).
Aquí solo se fija la relación con los periodos. La fecha civil del `occurred_at` se compara con **el
periodo que cerró la última baja**, el de la proyección de la ficha (`hired_at` ≤ fecha ≤
`terminated_at`). Esas dos columnas están en la misma fila que ya lee el verificador del PIN, así que
no hay E/S nueva. Un intento de un periodo anterior a una readmisión, o del hueco entre dos periodos,
no se atribuye a nadie.

La tarjeta lleva el mismo límite superior (RN-20): solo se marca para revisión si, además de ser
anterior a `revoked_at`, su fecha civil no pasa del cese. Sin ese límite, una baja aplicada tarde por
la tarea (§4) haría pasar por «anterior a la retirada» un fichaje posterior al cese.

### 9. Asientos

Siguen el catálogo de `AuditAction` (`<sujeto>.<participio>`). El *payload* lleva `employee_uuid`,
fechas e identificadores de la organización, **nunca nombre, documento ni motivo libre** (regla dura
21):

| Acción | Actor | Cuándo | *Payload* además de `employee_uuid` |
|---|---|---|---|
| `employee.offboarding_scheduled` | cuenta de gestión | se programa o se reprograma una baja, también con cese de hoy sin `apply_now` | `terminated_on`, `previous_terminated_on` (al reprogramar) |
| `employee.offboarding_cancelled` | cuenta de gestión | se anula | `terminated_on` anulada |
| `employee.offboarded` | cuenta de gestión o `system` | la baja se hace efectiva, en el acto o por la tarea | `terminated_on`; `apply_now: true` si se aplicó ya con cese de hoy; si la aplica la tarea, `scheduled: true`, `due_on`, `days_late` y, si la jornada del cese siguió después de su día (un turno de noche o una pausa que la hizo esperar), `deferred: true`: explica unos `days_late` que no son una tarea parada |
| `employee.rehired` | cuenta de gestión | readmisión, individual, en lote o por importación | `employment_period_uuid`, `hired_at`, `department_id`, `previous_department_id` (si cambia), `pin_reset`, `via_import`; si cerró un contrato abierto, `previous_contract_valid_from`, `previous_contract_valid_to`, `previous_contract_weekly_hours`, `previous_contract_annual_hours` y `previous_contract_schedule_type`, también con `contract: null`, para que ese cierre no quede sin asiento |
| `employee.termination_date_corrected` | cuenta de gestión | corrección de §7 | `employment_period_uuid`, `previous_terminated_on`, `terminated_on`, `correction_id` |

**Se mantiene `employee.offboarded` para la baja que aplica la tarea, y no se crea
`employee.terminated`.** Es el mismo hecho legal, la baja efectiva, y la conciliación, la
invariante de ADR-046 §6 y las consultas de RRHH ya buscan esa acción. Que la aplique el sistema lo
dice el actor `system`. Las tarjetas dejan su `credential.revoked` y la tarjeta nueva su
`credential.issued`, que ya existen. El PIN nuevo deja el asiento de la emisión del PIN, que también
existe.

### 10. Forma de los endpoints

El contrato se escribe en `docs/api/openapi.yaml` en las tareas 25.2 y 25.3, antes que el código. Esta
es la forma que tiene que tener. Todos son `rrhh+`, auditados, con *policy* y autorización negativa
por rol (regla dura 18).

| Operación | Ruta y cuerpo | Respuestas |
|---|---|---|
| Baja, en el acto o programada | `POST /api/v1/employees/{uuid}/offboard`, `{ terminated_at, reason?, apply_now?: false }`. **`apply_now` es el único campo nuevo**, opcional y `false` por omisión: con cese de hoy aplica la baja en el acto (despido con efecto inmediato) | `200` `Employee`, que gana `offboarding: { state: "scheduled" \| "effective", terminated_at } \| null`. `409` `employee-terminated` si ya es efectiva. `422` `terminated_at`, o `apply_now` con un cese posterior a hoy salvo el cese en un alta futura (la baja sin efectos). `409` `offboarding-due` si se cambia la fecha de una baja programada cuyo cese ya pasó |
| Anular la baja programada | `POST /api/v1/employees/{uuid}/offboard/cancel`, sin cuerpo | `200` `Employee`. `409` `offboarding-not-scheduled`, `offboarding-due` o `employee-terminated` |
| Readmisión | `POST /api/v1/employees/{uuid}/rehire`, `{ hired_at, department_id?, locale?, contract?: { weekly_hours, annual_hours, schedule_type } \| null, reset_pin?: false }` | `200` `{ employee, employment_period, credential: { uuid, status: "pending_print" }, pin? }`. `pin` solo con `reset_pin`, una vez. `409` `employee-not-terminated` o `employee-record-minimised`. `422` `hired_at` |
| Readmisión en lote | `POST /api/v1/employees/rehire`, `{ employee_uuids: [uuid, …], hired_at, reset_pin?: false }` | `200` `{ rehired: [ … como arriba ] }`. `422` con un error por posición y nada escrito |
| Corrección de la fecha de cese | `POST /api/v1/employees/{uuid}/employment-periods/{periodUuid}/termination-corrections`, `{ terminated_at, reason }` | `201` `EmploymentPeriodCorrection`. `409` `offboarding-not-effective`. `422` `terminated_at` o `reason` |
| Periodos | `GET /api/v1/employees/{uuid}` gana `employment_periods: [{ uuid, hired_at, terminated_at, state }]`, en la ficha de `rrhh+` (la reducida del responsable no lo lleva) | — |
| Importación | `POST /api/v1/employees/import`, **sin cambio de cuerpo** | El informe gana la acción `rehire`, el aviso `rehire_proposed`, los errores `rehire_requires_hired_at` y `rehire_not_after_termination`, y el contador `rehire`. `employee_terminated` deja de emitirse |
| Alta con documento de una baja | `POST /api/v1/employees` | El `409` del documento gana `rehirable_employee_uuid` si procede |

### 11. Módulos y puertos

- **`Workforce`** es dueño de `employees`, `employment_periods`, `employment_period_corrections`, de
  la tarea y de los casos de uso nuevos:
  - `OffboardEmployeeHandler`, que ahora también programa la baja;
  - `CancelScheduledOffboardingHandler`;
  - `ApplyDueOffboardings`;
  - `RehireEmployeeHandler` y `RehireEmployeeBatchHandler`;
  - `CorrectTerminationDateHandler`.

  Su dominio gana:
  - el objeto de valor `EmploymentPeriod`, en `Shared/Domain/ValueObject` porque lo lee también
    `Attendance`;
  - `EmploymentHistory`, la lista ordenada y sin solapes de una persona;
  - en `Employee`: `offboard()`, que ya no rechaza el futuro, `cancelScheduledOffboarding()`,
    `applyScheduledOffboarding()`, `rehire()` e `isOffboardingScheduled()`;
  - el agregado `TerminationDateCorrection`.

  Ninguno llama a `now()`: reciben «hoy» ya resuelto (reglas duras 2 y 14).
- **`Identity`** revoca por `EmployeeOffboarded`, como hoy, y emite por `EmployeeRehired` con un
  *listener* en su `Infrastructure` y un caso de uso `IssueCredentialForRehiredEmployee`. Es el mismo
  patrón que `RevokeCredentialsOnOffboarding`. `Workforce` no importa `Identity`.
- **`Attendance`** lee los periodos por `EmployeeDirectory` (`EmployeeSnapshot` gana
  `employmentPeriods`) e implementa el puerto `Shared` `FinalWorkDayActivity` para la tarea. No importa `Workforce`.
- **`Compliance`** escribe los asientos desde `RecordWorkforceChange`.
- **`Reporting`** cuenta a una persona «de alta» un día si ese día cae en alguno de sus periodos. El
  hueco entre dos periodos no es un día de alta sin actividad, y el prorrateo de RF-IN-03 solo cuenta
  días dentro de un periodo.

## Alternativas descartadas

- **Ficha nueva para cada readmisión.** Lo descartó D16. Partiría el registro de una persona en dos y
  obligaría a relajar el índice único del documento, que es lo que hoy impide el alta duplicada.
- **Columnas en `employees` (`rehired_at`, `previous_terminated_at`) en vez de tabla.** Guardan un
  periodo anterior, no todos. Una segunda readmisión tendría que sobrescribir el primero (regla dura
  5).
- **Solo la tabla, sin proyección en `employees`.** Rompe el `CHECK` del cese, la función de ADR-060
  y la lectura de una fila del fichaje. Ver §1.
- **Un valor `leaving` o `offboarding_scheduled` en `status`.** Cada `match` sobre el estado tendría
  que aprender que «se va» sigue fichando. El par estado–fecha ya lo dice, y la regla que importa
  (§3) es de fechas, no de estados.
- **Una tarea diaria a una hora del centro.** Exige convertir la hora a UTC con su cambio de hora y no
  recupera una pasada perdida hasta el día siguiente. Ver §4.
- **Aplicar en el acto la baja con cese de hoy, como en la 2.2.x.** Dejaba a la persona sin fichar la salida de su último turno si RRHH la registraba antes. El propietario decidió el 29-10-2026 programarla por defecto y dejar «Aplicar ya» para el despido con efecto inmediato.
- **Aplicar la baja al empezar el día del cese.** Dejaría a la persona sin fichar su último día, que
  es justo lo que D17 pide que no pase.
- **Que la baja rija solo cuando la aplica la tarea.** Un `scheduler` parado dejaría fichar días
  después del cese. La regla de fechas de §3 hace que la tarea solo materialice el estado y nunca
  decida el registro.
- **Anular una baja ya efectiva.** Borraría el hecho de que esa persona no podía fichar entre la baja y
  la anulación. Para eso están la readmisión y la corrección de la fecha, que dejan los dos hechos.
- **Una acción `employee.terminated` para la tarea.** Ver §9.

## Consecuencias

- **Para RRHH:** la baja de temporada se registra cuando se conoce, y la persona ficha hasta su último
  día. La vuelta es un botón. El doc 05 §2 y §3.3 lo prometen desde la 2.3.0.
- **Una persona tiene varias fechas de alta y de cese a lo largo de su vida.** Toda lectura que
  necesite «¿estaba de alta el día X?» pregunta a los periodos, no a `hired_at`/`terminated_at`. Las
  consultas de `Reporting` y la exportación legal se revisan en las tareas 25.3 y 25.7 con una prueba
  de un periodo, un hueco y otro periodo.
- **ADR-046 cambia en tres puntos**: el disparador existe, `terminated` deja de ser final por la
  readmisión, y la importación propone la readmisión en vez de rechazar la fila. Su enmienda lo
  dice.
- **La retención de la ficha cuenta desde la última baja** (ADR-060). Los contratos y las ausencias de
  un periodo antiguo se conservan mientras la persona siga en la plantilla. Lo tiene que validar el
  DPO del cliente, igual que el plazo de `absences` (33.11). Está en la lista de validación jurídica
  del plan de la 2.3.0 (decisión del propietario del 29-10-2026: el diseño no cambia).
- **Hay una tarea programada más**, con su métrica, su alerta y su comprobación en `product:doctor`.
- **Un fichaje cuya jornada cae antes del alta del periodo en curso se trata como el posterior al
  cese** (decisión del 30-10-2026, revisión de la 25.3): `ScanIntentPolicy::opensBeforeHire()`,
  simétrico de `opensAfterTermination()`, con el mismo rechazo genérico y por el mismo camino,
  también desde la cola del quiosco. Vale para cualquier alta futura, no solo para la readmisión. El
  motivo: un tramo en el hueco entre dos periodos no se podría corregir desde el panel
  (`work_date_between_periods`), y sería registro legal de días en los que la persona no estaba de
  alta.
- **El riesgo de doc 07 §6 «días trabajados después de la fecha de cese»** queda cubierto por §7 y
  por la enmienda de ADR-043 cuando se implementen las tareas 25.4 y 25.5. No antes.

## Verificación

**Unitarias** (`backend-laravel`; mutación por encima del 80 % sobre `Employee`, `EmploymentPeriod`,
`EmploymentHistory` y `TerminationDateCorrection`):

- `offboard`: pasado, hoy y futuro, cada uno con y sin `apply_now` (hoy con `apply_now` es efectiva, futuro con `apply_now` es `422`); la contratación que no empezó; la reprogramación a futuro, a hoy y a hoy con `apply_now`.
- La anulación con hoy < cese, hoy = cese y hoy > cese.
- La aplicación, solo si está vencida.
- `rehire`: alta el día del cese (no), el día siguiente (sí), sin baja (no) y minimizada (no).
- La corrección en sus tres límites, hacia una fecha posterior y hacia una anterior.
- La regla «ningún tramo después del cese» en `ScanIntentPolicy` y `ManualEntryEligibility`: la
  salida a las 06:00 del turno de noche del cese y la vuelta de una pausa admitidas, y la entrada del
  día siguiente rechazada.
- Su simétrica, «ningún tramo antes del alta» (`ScanIntentPolicy::opensBeforeHire()`): la entrada de
  la víspera del alta rechazada, la del propio día admitida y la salida, que no abre, admitida.

**Integración** (`qa-testing`, `CommittedDatabase`):

- El disparador de §2: cada transición de la tabla se admite y cada una de las demás responde `42501`.
- `employees_current_period_matches` falla el `COMMIT` con la proyección descuadrada.
- El `EXCLUDE` de los periodos, `one_open_employment_period` y la falta de `DELETE`.
- La migración, con datos de la 2.2.x y con una baja de la 2.1.0 con cese futuro.
- La tarea:
  - con reloj falso en Europe/Madrid y en Atlantic/Canary, a las 23:30 y a las 00:30, en los dos
    domingos del cambio de hora;
  - dos pasadas seguidas, sin asientos dobles;
  - tres días sin pasar;
  - el aplazamiento por un tramo abierto y su tope.
- Las tandas nuevas de `OffboardUpdateRaceTest`: la tarea contra el fichaje del día del cese, la
  anulación contra la tarea y la readmisión contra la emisión de una tarjeta. Criterio: cero `40P01`,
  ningún tramo nuevo después del cese y ninguna persona `terminated` con tarjeta activa.

**Feature, contrato y autorización negativa por rol** para cada fila de §10.

**E2E de RRHH:** programar y anular una baja, readmitir a una persona, readmitir a tres en lote y
corregir una fecha de cese.

Los escenarios Gherkin del doc 01 §11 «Baja programada…», «Readmisión…» y «Corrección de la fecha de
cese…» tienen su fila en la tabla de escenarios y pruebas.
