# Runbook — patrón anómalo de uso de credencial

**Esto no es una alerta y no es una avería. Es una incidencia en la bandeja**,
del tipo «Patrón anómalo de uso de credencial» (`anomalous_pattern`), que la
detección nocturna abre para que **una persona la revise**. El destinatario es
el **responsable del departamento** de la persona afectada y, por encima, RRHH.
El IT del cliente solo entra en este documento por la §5, que trata de que la
detección corra, no de lo que encuentra.

**Requisitos que cubre:** RF-PR-06 (detección de patrones anómalos de uso de
credencial) y RN-16 (secuencia imposible entre dos quioscos). Es, además, **la
contrapartida explícita de no usar biometría** (ADR-009, regla dura 20): el
producto no reconoce caras ni huellas, y lo que pone en su lugar es esta
revisión humana de indicios. Por eso este runbook existe: para que el indicio
se revise **sin convertirse en una acusación**.

| Qué llega y por dónde | Destinatario | Sección |
| --- | --- | --- |
| Incidencia `anomalous_pattern` en la bandeja del panel, con el aviso por correo del resumen de la madrugada | Responsable del departamento · RRHH | [§1](#1-qué-dice-el-indicio-y-qué-no-dice) a [§4](#4-cómo-se-cierra) |
| `DeteccionDePatronesAusente` — más de 26 h sin ejecutarse la detección | IT del cliente | [§5](#5-si-la-detección-no-corre) |
| `DeteccionDePatronesConFallos` — la última pasada dejó hallazgos sin convertir en incidencia | IT del cliente | [§5](#5-si-la-detección-no-corre) |

**Los hallazgos en sí no generan ninguna alerta, a propósito** (doc 01 §9.3).
Un indicio sobre dos personas concretas se revisa en la bandeja por quien conoce
el turno; no se enruta a un canal de guardia. Lo que sí alerta es que la pasada
no corra o falle, que es operación y no personas.

**Impacto en el fichaje, que es lo primero que hay que saber: ninguno.** Nadie
se ha quedado sin poder fichar, ningún fichaje ha cambiado y ninguno va a
cambiar por esta incidencia. La regla dura 19 sigue intacta.

---

## 1. Qué dice el indicio, y qué NO dice

La detección corre cada madrugada sobre los fichajes hechos **en los
quioscos** —con tarjeta o con PIN de respaldo— de los últimos
`COMPLIANCE_PATTERN_LOOKBACK_DAYS` días (30 de serie): los aceptados y también
la **segunda presentación de una misma tarjeta que el anti-rebote descartó**
(RF-AT-06; en el registro, `rejected_debounce`), porque es una tarjeta que se
presentó en una tablet y resolvió a una persona. Los rechazos por credencial
desconocida, revocada o con mala firma no entran —no son un uso de credencial—,
ni los fichajes manuales del panel ni los importados —no pasan por ningún
quiosco—. Produce **dos tipos de hallazgo**, y la incidencia dice cuál de los
dos es.

### 1.1 «Coincidencia en el mismo quiosco»

**Lo que mide.** Dos personas distintas pasan su credencial **por el mismo
quiosco** con menos de `ATTENDANCE_PATTERN_WINDOW_SECONDS` segundos de
diferencia (10 de serie; el límite es estricto: 9 s cuenta, 10 s no). Eso es una
*coincidencia*.

**Cómo se cuenta.** Una coincidencia **como mucho por día** entre esas dos
personas. Dos coincidencias la misma mañana dicen lo mismo que una —que
llegaron a la vez— y se cuentan como una. Hay hallazgo cuando el mismo par de
personas acumula **al menos `ATTENDANCE_PATTERN_MIN_REPEATS` días** con
coincidencia (3 de serie) dentro de la ventana revisada. Ese número mínimo de
días es lo que RF-PR-06 llama «sistemático»: un día no es un patrón.

**Una incidencia por persona, no por pareja.** Si una persona alcanza el
mínimo con varias contrapartes —tres compañeros que entran juntos—, recibe
**una sola** incidencia, que nombra la **contraparte principal** (la que más
días coincide; en empate, la de la primera coincidencia) y dice con **cuántas
personas más** ocurre. Cada una de las otras tiene su propia incidencia, en la
bandeja de su responsable: el grupo entero se reconstruye leyendo las de sus
miembros (§3.1), nunca desde una sola.

**Lo que NO dice.** Que dos personas fichen juntas varios días seguidos es
**exactamente lo que hace una pareja de compañeros que llega en el mismo coche,
comparte turno o sale del vestuario a la vez**. La detección no distingue eso
de un préstamo de tarjeta, porque desde el quiosco son indistinguibles: en los
dos casos hay dos escaneos separados por segundos. El indicio dice «estas dos
credenciales se usan juntas con frecuencia», nada más. Quien lo convierte en
otra cosa es una persona, con información que el sistema no tiene (§3).

**Qué muestra la incidencia.** El quiosco (su nombre), la contraparte
principal —enlazada, no nombrada en el dato— y cuántas personas más, los días
con coincidencia frente al mínimo exigido, la ventana en segundos aplicada, y
**cuatro escalares** en lugar de una lista de momentos: la **primera** y la
**última** coincidencia, el **hueco del último día** y el **hueco más estrecho
de toda la serie**. El más estrecho es el dato que sostiene el indicio: 9 s
todos los días es una cola; 1 s un día es otra pregunta. Si hace falta el
detalle de un día concreto, está en el registro horario de cada persona.

### 1.2 «Secuencia imposible entre dos quioscos» (RN-16)

**Lo que mide.** La **misma** credencial se presenta en **dos quioscos
distintos** con menos de `ATTENDANCE_MIN_TRANSIT_SECONDS` segundos de
diferencia (120 de serie; también estricto). No es una frecuencia: **un solo
caso basta**, porque lo que describe es un tránsito que no da tiempo a hacer.
Cuenta también la presentación que el anti-rebote descartó (`rejected_debounce`):
el anti-rebote es por persona y no por quiosco, así que una tarjeta pasada en
recepción y 40 s después en cocina se registra como rechazo, y sin contarla la
regla quedaría ciega justo en la franja que nadie puede explicar.

**Lo que NO dice.** Que alguien haya prestado nada. Las causas que ven los
hoteles, por frecuencia: dos tablets en la misma puerta o a pocos metros (el
umbral está mal para ese centro: [`../cliente/configuracion.md`](../cliente/configuracion.md)
§2.1 dice cómo ajustarlo, y `0` desactiva este hallazgo); una tablet con la
cámara sucia y la persona probando en la de al lado; un pasillo de servicio más
corto de lo que el umbral supone. Un préstamo de tarjeta es la última de la
lista, y el indicio no la señala más que a las otras.

**Lo que se excluye a propósito: una hora en duda por el reloj.** Un escaneo
cuyo desfase entre el reloj de la tablet y el del servidor superó la tolerancia
de `ATTENDANCE_MAX_CLOCK_SKEW_MINUTES` (RF-AT-10; el mismo criterio que abre la
incidencia «Desfase de reloj») **se quita de la lista antes de formar pares**:
sus dos vecinos limpios sí se comparan entre sí, y él no se compara con nadie.
Una hora que ya está en duda no sostiene una imposibilidad, y sin esa exclusión
una tablet adelantada acusaría a personas concretas de un patrón que solo
existe en su reloj. El fichaje por PIN **sí entra** aunque el sistema lo marque
siempre para validación humana: la marca de revisión del PIN no dice nada de la
hora, y el PIN es una credencial personal que se presta con más facilidad que
la tarjeta.

**Qué muestra la incidencia.** Los dos quioscos (sus nombres), el momento de
cada escaneo, el hueco entre ambos y el tránsito mínimo aplicado.

### 1.3 Lo que ninguno de los dos dice

- **No dice quién prestó a quién**, ni si hubo préstamo. En la coincidencia, la
  incidencia se abre **a cada persona del grupo**, una por persona, porque el
  indicio es simétrico.
- **No dice que ninguna hora sea falsa.** Cada fichaje que aparece en la
  incidencia sigue en el registro tal como se hizo.
- **No dice nada de la persona.** Dice cosas de dos credenciales y de un
  quiosco.

---

## 2. Qué NO hace el sistema

Esto está fijado en los requisitos y no es configurable:

| El sistema… | Regla |
| --- | --- |
| **No anula ni marca** ningún fichaje. Ningún tramo cambia de estado; la nómina y el registro legal no se enteran de que existe esta incidencia | RF-PR-06 · regla dura 5 |
| **No concluye** nada. La incidencia describe lo observado y no lleva veredicto, ni palabra que califique a nadie | RF-PR-06 |
| **No sanciona ni propone sanción.** No hay acción automática de ningún tipo | RF-PR-06 · doc 01 §9.2 («se revisa, no se sanciona automáticamente») |
| **No bloquea** a ninguna de las dos personas. Sus tarjetas y sus PIN siguen funcionando igual | Regla dura 19 |
| **No avisa a nadie fuera de la bandeja.** Solo el responsable del departamento recibe el aviso, dentro del mismo resumen de la madrugada que el resto de incidencias. No hay alerta, ni correo aparte, ni destinatario de seguridad | Doc 01 §9.3 · decisión 9 de la tarea 3.11 |
| **No usa biometría** para «confirmar» el indicio, ni la usará | ADR-009 · regla dura 20 |

**Por qué está diseñado así.** Un indicio que el sistema convirtiera en marca o
en sanción sería una decisión sobre una persona tomada por un algoritmo con dos
números y sin contexto: exactamente lo que el RGPD limita (art. 22) y lo que un
comité de empresa impugnaría con razón. El sistema pone el indicio sobre la
mesa; **lo que se haga con él lo decide la empresa según su procedimiento**, no
el software y no «el informe».

---

## 3. Cómo revisarlo sin convertirlo en acusación

### 3.1 Antes de hablar con nadie

1. **Lee las incidencias de todo el grupo, no una.** En la coincidencia hay una
   por persona, cada una asignada al responsable de **su** departamento, y la
   tuya te dice la contraparte principal y cuántas personas más hay. Si son de
   departamentos distintos, hay varios responsables, y conviene que hablen entre
   ellos antes de que ninguno hable con nadie más: cada uno conoce el turno de
   la suya.

   ```bash
   docker compose -f infra/compose.prod.yaml exec -T app php artisan tinker --execute="
     DB::table('incidents')
       ->join('employees', 'employees.id', '=', 'incidents.employee_id')
       ->where('incidents.type', 'anomalous_pattern')
       ->where('incidents.status', 'open')
       ->orderBy('incidents.work_date')
       ->get(['employees.employee_code', 'incidents.work_date', 'incidents.assigned_to_user_id',
              'incidents.context'])
       ->each(fn(\$i) => print_r(\$i));
   "
   ```

   Salida esperada: una fila por persona y patrón, con `context.pattern`
   (`kiosk_coincidence` o `impossible_sequence`), el quiosco o quioscos, y en
   la coincidencia `counterpart_employee_uuid`, `counterpart_count`,
   `coincidence_days`, `first_coincidence_at`, `last_coincidence_at`,
   `last_gap_seconds` y `min_gap_seconds`. Las filas cuyo `counterpart_employee_uuid`
   se apuntan unas a otras, o comparten quiosco y fechas, son el grupo. En el
   panel, la bandeja las enseña con el mismo contexto y con la contraparte
   enlazada.

2. **Contrasta con el cuadrante.** ¿Las dos personas tienen el mismo turno
   esos días? ¿Entran a la misma hora? Si sí, la coincidencia es lo esperable y
   probablemente no hay nada que preguntar.

3. **Contrasta con la supervisión presencial.** Es la otra mitad de la
   mitigación que el producto promete (doc 01 §8.1): el jefe de turno sabe si
   esa persona **estaba** en su puesto esos días. Si estaba, la tarjeta la pasó
   ella. No hace falta más.

4. **Mira los momentos, no solo el recuento.** Tres días con hueco de 8 s a la
   hora de entrada del turno de mañana es la cola del quiosco. Tres días con
   hueco de 2 s a horas en que solo una de las dos tenía turno es otra cosa, y
   sigue sin ser una conclusión: es lo que hay que aclarar.

5. **En la secuencia imposible, mira primero los quioscos.** Si el tránsito
   real entre esos dos es menor que `ATTENDANCE_MIN_TRANSIT_SECONDS`, el
   problema es el umbral y no la persona: ajústalo en el panel (Ajustes
   operativos) y descarta la incidencia diciendo eso. Si además hay una
   incidencia «Desfase de reloj» de alguna de las dos tablets en esas fechas,
   el dato de hora está en duda y el indicio no se sostiene.

### 3.2 Si después de eso queda algo que preguntar

**Quién conduce la conversación: la empresa, según su procedimiento interno**
—el responsable del departamento, RRHH, o quien el convenio y el reglamento
interno digan—. **Nunca el sistema, y nunca «el informe»**: la frase «el
sistema ha detectado que…» es lo que convierte un indicio en una acusación,
porque le atribuye a una máquina una conclusión que la máquina no ha tomado.

**Qué se puede preguntar.** Lo que se observó, tal cual, y en abierto:

- «Estos días fichaste a las 06:58 y tu compañera a las 06:58 y pico, en la
  misma tablet. ¿Entráis juntas?»
- «El martes tu tarjeta se pasó en la tablet de cocina y a los 40 segundos en la
  de recepción. ¿Recuerdas qué pasó?»

**Qué no se hace.**

- No se afirma nada que el indicio no diga (§1.3). «Alguien fichó por ti» no
  está en el dato.
- No se enseña el dato de la otra persona más allá de lo imprescindible. La
  incidencia de cada una es la suya.
- No se pregunta por escrito a través del sistema: la bandeja no es un canal de
  comunicación con la plantilla, y la nota de resolución (§4) no la ve la
  persona afectada.
- No se toma ninguna medida a partir de la incidencia sola. Si la empresa
  decide abrir un procedimiento, lo hace por su vía, con sus garantías y con
  representación de la persona si procede; **esta incidencia es, como mucho,
  el motivo por el que alguien empezó a mirar**, y así debe constar.

### 3.3 Si el patrón es real y explicable

La mayoría lo son. Dos personas que comparten coche, una pareja que trabaja en
el mismo turno, dos tablets demasiado cerca. Se cierra como «revisada, sin
nada que corregir» con la explicación (§4).

**Y no vuelve a la noche siguiente.** La detección se mira en la bandeja antes
de emitir: mientras una persona tenga una incidencia de coincidencia **abierta**,
no se le abre otra (el indicio ya está sobre la mesa; si aparecen contrapartes
nuevas, entrarán en la siguiente); y una vez **resuelta o descartada**, solo
cuentan los días de coincidencia **posteriores** al cierre, así que hacen falta
`ATTENDANCE_PATTERN_MIN_REPEATS` días **nuevos** para que vuelva a abrirse. Quien
descarta «llegan juntos en coche» no ve la misma incidencia al día siguiente; si
el patrón persiste, la vuelve a ver dentro de tres días con datos nuevos. Si
eso genera ruido en un centro concreto, la respuesta es ajustar
`ATTENDANCE_PATTERN_MIN_REPEATS` o la ventana en el panel como decisión
consciente —y avisar a la plantilla de que el control se ajusta, porque es un
sistema de control ya informado—, no ignorar la bandeja.

---

## 4. Cómo se cierra

Como cualquier otra incidencia (RF-PA-05): botón «Resolver» en la bandeja, con
uno de los dos desenlaces y una nota obligatoria.

| Desenlace | Estado | Cuándo |
| --- | --- | --- |
| «Se ha corregido» | `resolved` | Solo si de la revisión salió algo que cambiar en el registro **y ya se cambió** por su vía —una corrección trazada con su motivo (RN-13)—. Que es poco frecuente aquí: este indicio casi nunca describe una hora mal registrada |
| «Revisada: no había nada que corregir» | `dismissed` | La revisión terminó y el registro se queda como está. Es el cierre habitual de este tipo, **sea cual sea lo que la revisión concluyera**: la incidencia no es el sitio donde se decide nada sobre la persona |

**La nota describe, no sentencia.** Escribe qué se contrastó y qué se vio:
«mismo turno de mañana los tres días según cuadrante; entran juntas desde el
aparcamiento, confirmado con jefa de sala», o «tránsito real entre las dos
tablets de 30 s, umbral ajustado a 20 s». No escribas conclusiones sobre la
persona, ni calificativos, ni el resultado de un procedimiento que se lleva en
otro sitio. Dentro de dos años esa nota la puede leer un auditor, la
Inspección o la propia persona ejerciendo su derecho de acceso
([`solicitud-derechos-rgpd.md`](solicitud-derechos-rgpd.md)), y tiene que
describir lo que se hizo, no lo que alguien pensó.

**Resolver la incidencia no cambia ninguna hora.** Son dos acciones distintas a
propósito; aquí, además, casi nunca hay hora que cambiar.

**Queda en `audit_log`.** La apertura (`incident.opened`) y el cierre
(`incident.resolved`, con el desenlace y quién lo firmó) son asientos de la
cadena de auditoría (regla dura 6). No se pueden borrar ni editar: si la nota
quedó mal, la traza es la traza; lo que se puede es dejar constancia aparte en
el procedimiento de la empresa.

**Una incidencia cerrada no vuelve a abrirse** sobre el mismo hallazgo: la
detección de la noche siguiente la reconoce por persona, fecha y tipo y no la
repite. En la coincidencia, además, mientras haya una **abierta** para esa
persona no se abre otra, y tras cerrarla hacen falta
`ATTENDANCE_PATTERN_MIN_REPEATS` días de coincidencia **posteriores al cierre**
(§3.3). Si aparece otra, es porque el patrón siguió después de que alguien lo
revisara, o porque hubo una secuencia imposible nueva.

---

## 5. Si la detección no corre

Esta parte sí es del **IT del cliente**: lo que falla es el planificador, no el
registro ni ninguna persona.

| Pieza | Qué hace | Dónde |
| --- | --- | --- |
| `attendance:detect-patterns` | Revisa los fichajes de quiosco de la ventana y abre las incidencias, **04:35 UTC** a diario, entre `attendance:detect-incidents` (04:30) y `reporting:compliance-metrics` (04:45) | Contenedor `scheduler` |
| `COMPLIANCE_PATTERN_LOOKBACK_DAYS` | Días hacia atrás que revisa, 30 de serie. Variable del `.env`, no del panel: es operación, como su hermana `COMPLIANCE_INCIDENT_LOOKBACK_DAYS` | `.env` |
| `ATTENDANCE_PATTERN_WINDOW_SECONDS` · `ATTENDANCE_PATTERN_MIN_REPEATS` · `ATTENDANCE_MIN_TRANSIT_SECONDS` | Los tres umbrales. Se cambian **en el panel** (Ajustes operativos); la línea del `.env` solo fija el valor de serie | `installation_settings` |
| `pattern_detection_last_run_timestamp_seconds` | Cuándo terminó la última pasada. Se publica **también** cuando no hay centro o no hay hallazgos | `BACKUP_PATH/metrics/kronoqr_pattern_detection.prom` |
| `pattern_detection_last_failures` | Hallazgos de la última pasada que no se pudieron convertir en incidencia | Mismo fichero |
| `anomalous_patterns_detected_total{pattern}` | Contador por tipo de hallazgo (`kiosk_coincidence`, `impossible_sequence`). Alimenta el panel «Patrones anómalos detectados por tipo» de Grafana. **Sin alerta** | `/metrics` |

**`DeteccionDePatronesAusente`** (más de 26 h sin pasada, `for: 30m`): la
bandeja está **ciega** para este tipo. No distingue «no hay patrones» de «nadie
está mirando».

```bash
# ¿Corre el planificador?
docker compose -f infra/compose.prod.yaml ps scheduler

# ¿Cuándo fue la última pasada?
docker compose -f infra/compose.prod.yaml exec -T app sh -c 'cat "$BACKUP_PATH/metrics/kronoqr_pattern_detection.prom"'

# Lánzala a mano. Es idempotente: no duplica lo que ya se abrió.
docker compose -f infra/compose.prod.yaml exec -T app php artisan attendance:detect-patterns
```

Salida esperada del comando: el recuento por patrón («kiosk_coincidence: n»,
«impossible_sequence: m», o «Sin hallazgos») y código de salida `0`. Se puede
acotar la ventana con `--days=` (mayor que cero). **«Idempotente» quiere decir
tres cosas y ninguna es «reabre»:** el mismo hallazgo no se inserta dos veces;
una persona con una coincidencia **abierta** no recibe otra; y una **cerrada**
no revive —hacen falta días de coincidencia nuevos (§3.3)—. Si dos hallazgos
distintos de la misma persona y fecha chocan en la restricción de la bandeja,
el segundo **no se pierde en silencio**: cuenta como fallo, sube
`pattern_detection_last_failures` y deja la línea
`attendance.pattern_incident_collided` en el log. Causas por frecuencia, las mismas que en su
hermana: contenedor `scheduler` parado, `BACKUP_PATH` sin permisos de escritura
para el usuario de la aplicación, `METRICS_TEXTFILE_ENABLED` en `false`.
`product:doctor` las enseña.

**`DeteccionDePatronesConFallos`** (`pattern_detection_last_failures > 0`,
`for: 5m`): la pasada corrió, encontró algo y **no pudo abrir la incidencia**.
El fichaje no está afectado; lo que está en riesgo es que un indicio se quede
sin revisar. Como en `attendance:detect-incidents`, un código de salida
distinto de cero de una tarea en segundo plano **no deja rastro en
`error_events`**; su rastro es el log técnico:

```bash
docker compose -f infra/compose.prod.yaml logs scheduler | grep -E 'attendance.pattern_detection|scheduler.command_failed|incident_not_opened'
```

La línea `attendance.pattern_detection` lleva los recuentos de la pasada
(hallazgos por patrón, incidencias abiertas, fallos) y **nunca personas**; la de
cada hallazgo fallido lleva `employee_uuid` y la clase de la excepción, nunca
nombres (regla dura 21). Corregida la causa —casi siempre base de datos o
disco—, repite el comando: la métrica vuelve a cero en la siguiente pasada y la
alerta se apaga sola.

**Si la incidencia se abrió pero nadie recibió aviso:** el departamento no
tiene responsable asignado (`assigned_to_user_id` a nulo). La incidencia sigue
abierta y visible en la bandeja; lo que falta es asignar el responsable en el
panel. Es el mismo caso que en
[`turno-abierto-prolongado.md`](turno-abierto-prolongado.md) §6.

---

## 6. Qué queda registrado, y durante cuánto tiempo

| Qué | Dónde | Contiene | Cuánto |
| --- | --- | --- | --- |
| La incidencia: tipo, patrón, quiosco(s), umbrales aplicados, los cuatro escalares de la coincidencia (primera, última, hueco del último día, hueco mínimo) o los dos momentos de la secuencia, la contraparte principal por identificador y cuántas más, estado, desenlace y nota | `incidents` | **Identificadores y quioscos, ningún nombre.** El nombre de la otra persona lo resuelve el panel al enseñarla, dentro del alcance de quien mira; en el dato no viaja | La misma retención que el registro horario: `retention_years` del perfil de cumplimiento (4 años de serie) |
| Apertura y cierre, con autor, momento y desenlace | `audit_log` | Identificadores. Solo-append, encadenado por hash | 4 años, el mismo perfil |
| Los fichajes que originaron el indicio | `scan_events` / `shift_entries` | Lo que había antes. **Nada nuevo se escribe en ellos** por esta incidencia | 4 años |
| Recuentos de cada pasada | Log técnico (`attendance.pattern_detection`) | Números. Nunca personas | `TECHNICAL_LOG_RETENTION_DAYS` (90 días) |
| Fallos al abrir una incidencia | Log técnico | `employee_uuid` y clase de excepción. Nunca nombres | 90 días |
| Contador por patrón y frescura de la pasada | Prometheus | Agregados, sin persona | Retención de Prometheus |

**Finalidad.** Este dato se recoge y se conserva para la **gestión de la
presencia y del registro horario**, que es la finalidad del tratamiento
declarada. No sirve para elaborar perfiles: no se agrega por persona más allá
de la propia incidencia, no alimenta ningún indicador individual, no se exporta
a nómina y no viaja en el paquete de diagnóstico ni en la telemetría más que
como recuento. Si la empresa quiere usarlo para otra cosa, eso es un cambio de
finalidad y tiene que valorarlo quien es responsable del tratamiento, no
decidirlo quien revisa la bandeja.

**Derecho de acceso.** La persona afectada puede pedir lo que consta sobre
ella, y la incidencia —con su contexto y su nota— **forma parte de la
respuesta**. Es la razón última de la regla de la §4: la nota tiene que poder
leerla la persona a la que describe. **Lo que no se entrega es la contraparte**:
ni su identificador ni su nombre, porque son datos de un tercero (art. 15.4
RGPD); se entrega el resto del contexto y, si hace falta, se dice que existe
una contraparte sin identificarla. El procedimiento de extracción está en
[`solicitud-derechos-rgpd.md`](solicitud-derechos-rgpd.md) §3.
