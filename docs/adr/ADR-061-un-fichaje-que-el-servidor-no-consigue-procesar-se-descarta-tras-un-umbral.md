# ADR-061 — Un fichaje que el servidor no consigue procesar se descarta tras un umbral, y la cola sigue

| Campo | Valor |
|---|---|
| **Estado** | **Aceptado** por el propietario el 23-10-2026 (las diez decisiones del diseño de la tarea 17.9 de la 2.3.0, con las recomendaciones tal cual). La política de dominio está escrita (`UnprocessableScanPolicy`). El contrato, las migraciones, el servicio que la aplica en las tres rutas, el asiento y la métrica son de `backend-laravel`. El quiosco es de `frontend-quiosco`, y las alertas y el runbook, de `devops-observabilidad` |
| **Fecha** | 23 de octubre de 2026 |
| **Decide** | `arquitecto-dominio` (bloque 17 de la 2.3.0, tarea 17.9; fila «cola atascada» de doc 07 §6) |
| **Afecta a** | **Enmienda** [ADR-047](ADR-047-ningun-fichaje-sale-de-la-cola-sin-desenlace-del-servidor.md) (la consecuencia «un `503` que no se cura atasca toda la cola») · Respeta [ADR-008](ADR-008-offline-first-con-idempotencia-por-scan-id.md), [ADR-010](ADR-010-auditoria-solo-append-encadenada.md), [ADR-029](ADR-029-configuracion-en-el-entorno-del-contenedor.md), [ADR-048](ADR-048-el-texto-libre-del-historico-de-errores-pasa-por-una-lista-blanca-de-palabras.md) y [ADR-059](ADR-059-con-una-dependencia-caida-el-fichaje-se-encola-y-el-resto-responde-503-con-rastro.md) · `Attendance/Domain/Policy/UnprocessableScanPolicy.php`, `ScanDiscardThreshold`, `ScanFailureHistory`, `UnprocessableScanVerdict` · puertos `ScanDiscardSettings`, `ScanFailureLedger`, `ScanFailureClassifier` · `RegisterScanBatchHandler`, `RegisterScanHandler`, `RegisterPinScanHandler` · tabla nueva `scan_processing_failures` · `scan_events.result`, `discarded_scan_reports` · `docs/api/openapi.yaml` (`ScanDiscarded`) · `syncRunner.ts` |
| **Requisitos** | RN-22 (párrafo nuevo), RN-21, RF-KI-04, RF-PA-07, RN-13, RS-03 · reglas duras 5, 6, 8, 9, 13, 14, 17, 19 y 21 |

## Contexto

ADR-047 decidió que, en un lote del quiosco, tras el primer elemento **no procesado** (`503`) los
posteriores se aplazan (RN-21). Su precio estaba escrito en las consecuencias: **un `503` que no se
cura atasca toda la cola del quiosco**, no solo la de una persona. Doc 07 §6 lo aceptó en la 2.2.0
con destino a la 2.3.0 y con la corrección ya descrita: que el servidor convierta en un descarte con
incidencia el elemento que falla de forma repetida.

Lo que había en el código el 23-10-2026:

- `RegisterScanBatchHandler::process()` captura el `Throwable` y devuelve `scan-not-processed`. La
  transacción se deshace y **no queda rastro de que ese `scan_id` ya falló**. El servidor no distingue
  el primer fallo del ducentésimo.
- `POST /scan` y `POST /scan/pin` responden `500` a un defecto. **El tramo de PIN de la cola sale
  siempre por `/scan/pin`, de uno en uno.** Un PIN envenenado atasca la cola igual que un QR.
- El quiosco de la 2.2.x decide cada elemento del lote **por su `status`**: `200` o `422` lo sacan de
  la cola; cualquier otro se reintenta para siempre. En las rutas individuales, un `422` que no es
  `scan-rejected` va a descartados y se avisa (RN-22).
- El retroceso del quiosco es de 1 s, 2 s, 4 s… con techo de 5 min. **Diez intentos ocurren en unos
  8,5 minutos.** Un número de intentos sin ventana de tiempo no separa un fallo pasajero de uno
  permanente.

## Decisión

**Cuando el mismo fichaje falla en el servidor de forma repetida, durante un tiempo mínimo y por una
causa que no es una dependencia caída, el servidor lo da por decidido. Lo descarta con incidencia
para revisión humana, nunca lo registra, responde un desenlace terminal e idempotente, y la cola
sigue.**

### 1. Cuenta el servidor, por `scan_id`, y solo él

- Solo el servidor distingue un defecto de una dependencia caída, porque tiene la excepción. El
  quiosco ve un `500` o un `503` sin causa, y así debe ser (RS-03, ADR-059).
- Mantiene el principio de ADR-047: el descarte **es** un desenlace del servidor.
- Sirve a las PWA ya desplegadas sin cambiarlas (§5), sobrevive a reinstalar la tablet y usa un solo
  reloj, el del servidor.
- Los fallos se apuntan en **`scan_processing_failures`**, una tabla técnica solo-append: `scan_id`,
  `device_id`, `origin`, `payload_fingerprint`, `failed_at` (reloj del servidor) y `exception_class`
  (el nombre de la clase, sin mensaje, ADR-048).
  - Se escribe **solo en el camino del fallo**, en una transacción propia, después del `ROLLBACK`.
    El fichaje que sale bien no gana ninguna escritura (RNF-P-01).
  - **Un intento solo suma si llega del mismo quiosco y con el mismo contenido** que el primero.
    Nadie con otro token puede envenenar el `scan_id` de otro quiosco.
  - Sin dato personal. Retención técnica de 30 días. El paquete de diagnóstico solo lleva recuentos.
  - Si no se puede escribir el fallo, no se cuenta, y eso es lo correcto. Si lo que falla es
    PostgreSQL, es una dependencia caída y no un veneno.

### 2. Qué cuenta como fallo

**Cuenta** un intento cuando el caso de uso de registro lanza una excepción que no es un rechazo de
negocio (`BusinessRejection`) ni es transitoria. Por ejemplo:

- un `\Error`;
- una excepción de dominio que rompe un invariante;
- un SQLSTATE de clase `22` (dato inválido);
- un SQLSTATE de clase `23` (integridad), salvo el `23505` de `scan_events.scan_id`, que es la carrera
  de idempotencia.

**No cuenta**, y sale como hoy (`503` o `500`, se conserva):

- Toda dependencia caída según **ADR-059 §1**. La lista se reutiliza, no se copia.
- La contención: `40001`, `40P01` y `55P03`.
- Las clases `53` (recursos), `57` (cancelación) y `58` (E/S).
- La carrera de RN-01.
- Lo que no llega al caso de uso o ya es un desenlace: el mantenimiento, los `429`, `401` y `403`,
  los `400` y `422` de forma (ya son RN-22), los rechazos de RS-03, RN-18 y RN-20, el anti-rebote, el
  reenvío idempotente y los aplazados, que el servidor no mira.

La clasificación es una **lista de exclusión**. Una lista de inclusión dejaría atascado para siempre
el veneno de una clase no prevista, que es justo el caso que motiva esta decisión. Por eso nunca
decide sola: la cubren la ventana y el cupo. La clasificación vive en un adaptador detrás del puerto
`ScanFailureClassifier`.

### 3. Cuándo se descarta: tres condiciones a la vez

Lo decide `UnprocessableScanPolicy`, una política pura (sin reloj ni configuración):

1. **Al menos `N` intentos contados**, el actual incluido. De serie, **10**; el suelo, que no se
   configura, es 3.
2. **Al menos `T` minutos entre el primer intento contado y el actual**, con el reloj del servidor y
   el borde incluido. De serie, **60**; el suelo es 15.
3. **El quiosco no ha agotado su cupo** de descartes del servidor en las últimas 24 h, una ventana
   que no se configura. De serie, **3**. **Con `0` la funcionalidad queda apagada** y se vuelve a
   conservar para siempre.

El cupo se mira el último. Si se habría descartado y el cupo está agotado, el veredicto es
`BudgetExhausted`: se conserva y salta la alerta crítica. **Si el siguiente fichaje también falla, no
es el fichaje, es el servidor**: sin cupo, una actualización que rompiera el registro para todos
convertiría, uno por hora, todos los fichajes de la cola en descartes.

Un reloj del servidor corregido hacia atrás no cumple la ventana: se sigue reintentando.

**`N`, `T` y el cupo son configuración técnica, no umbrales legales.** No van al perfil de
cumplimiento (regla 14). Van al `.env` (regla 13, ADR-029): `SCAN_DISCARD_MIN_ATTEMPTS`,
`SCAN_DISCARD_MIN_MINUTES` y `SCAN_DISCARD_DAILY_BUDGET`. `ScanDiscardThreshold` no admite un valor
por debajo de los suelos, así que el error salta al leer la configuración.

**Se aplica en las tres rutas de escritura del fichaje** (`/scan/batch`, `/scan` y `/scan/pin`),
desde un solo servicio de aplicación por el que las tres tienen que pasar. `WorkDay` no cambia: el
descarte nunca crea, cierra ni toca un tramo.

### 4. Qué se responde (contrato aditivo)

- Problema nuevo **`422 urn:kronoqr:problem:scan-discarded`**, «Escaneo descartado», con `detail`
  fijo y `scan_id`. Entra en `ScanBatchEntry.outcome` y en el `422` de `/scan` y `/scan/pin`. No hay
  código de estado nuevo ni campo obligatorio nuevo.
- **`422`, y no `200`, `409` ni `410`**: el quiosco de la 2.2.x reintentaría para siempre todo lo que
  no sea `200` o `422`. **Un `type` propio, y no `scan-rejected`**: un descarte no es un rechazo, y el
  log, el panel y las pruebas tienen que distinguirlos.
- Tras un `scan-discarded`, **el resto del lote se sigue procesando**: es un desenlace, como
  cualquier `422`.
- **Idempotente** (regla 8). Cualquier reenvío del mismo `scan_id`, por cualquiera de las tres rutas,
  devuelve el mismo `422` sin volver a intentarlo. Si RRHH ya creó el tramo y un reenvío tardío se
  procesara tras arreglar el defecto, habría un fichaje doble. La idempotencia la da la fila de
  `scan_events` (§6), que la primera consulta del caso de uso ya encuentra. **No se añade ninguna
  consulta al camino de los demás escaneos.**
- Se rellena hasta el suelo de tiempo constante, como `/scan/discarded`: la atribución verifica la
  firma, y su duración no debe hablar (RS-03).

### 5. Compatibilidad con la cola del quiosco

| Quiosco | Ruta | Qué hace con `scan-discarded` |
|---|---|---|
| 2.2.x y actual | lote | Lo saca de la cola por ser un `422`. Correcto: el servidor ya lo tiene |
| 2.2.x y actual | `/scan`, `/scan/pin` | Lo trata como un `422` no estándar (RN-22): lo mueve a descartados y lo avisa. El aviso choca con el `UNIQUE (scan_id)` de `discarded_scan_reports`, se acusa y no abre una segunda incidencia |
| Nuevo | las tres | Lo reconoce y lo saca sin avisar |

Ningún quiosco reintenta para siempre lo ya descartado. El empleado no ve nada: el descarte exige
`N` intentos y `T` minutos, así que solo ocurre en el drenaje y nunca en el primer envío, cuando ya
se le confirmó en pantalla (regla 19).

**Se cierra a la vez un hueco del quiosco.** En el lote, `classify()` sacaba **cualquier** `422`,
también uno que no fuera `scan-rejected`, sin el aviso de RN-22. Ahora `scan-rejected` y
`scan-discarded` salen de la cola y cualquier otro `422` sigue RN-22.

### 6. Cómo queda registrado

El descarte se escribe en una transacción nueva, después del `ROLLBACK` del intento que alcanzó el
umbral. Nada se borra (regla 5).

1. **`scan_events`**: fila con `result = discarded_unprocessable`, `occurred_at` (del quiosco, el que
   vale para el registro legal) y `recorded_at` (el del servidor), con las columnas mínimas.
   `shift_entry_id` queda nulo. **`employee_id` lleva al titular solo si se atribuye por una
   tarjeta auténtica**, con el mismo significado que en RN-20: una firma válida dice de quién es la
   tarjeta.
   - **Atribuido por código de empleado (vía PIN), `employee_id` queda nulo, y también
     `claimed_employee_id`.** Un código tecleado no autentica a nadie, y por eso ADR-043 lo separa
     de `employee_id`.
   - `claimed_employee_id` es la entrada de RN-19: rellenarlo abriría además una
     `rejected_pin_scan` sobre algo que no es un PIN rechazado, es decir, dos incidencias por el
     mismo fichaje.
   - El dueño por PIN vive en un solo sitio: `discarded_scan_reports.owner_employee_id`, con
     `attribution = employee_code`, que es lo que lee la revisión diaria. Lo decidió
     `arquitecto-dominio` el 23-10-2026 (duda 3 de la implementación).
2. **`discarded_scan_reports`**: la fila que ya lee la revisión diaria, con las columnas nuevas
   `source = server`, `failed_attempts` y `first_failed_at`, y además `http_status = 422`, el
   `problem_type` del descarte y `discarded_at = recorded_at`. La atribución es la de ADR-047 §2
   (tarjeta auténtica, o código de una persona que puede fichar) y no guarda ni el payload ni el
   código. Si la atribución lanza, queda `none` y se sigue.
3. **`audit_log`**: asiento **`scan.discarded`**, actor `system`. Es la primera vez que el servidor
   decide por sí solo que un fichaje confirmado al empleado no entra en el registro. El volumen está
   acotado por el cupo, así que no aprieta el candado de la cadena (ADR-010). El aviso del quiosco
   sigue sin asiento: lo controla quien tiene el token y no decide nada.
4. **Telemetría**: `kiosk_discarded_scans_total{device,attributed,source}` y la línea `warning`
   `attendance.scan_discarded_after_failures`, con `scan_id`, `device_id`, intentos, primer fallo y
   clases de excepción. Nunca nombres (regla 21).

Si la transacción del descarte falla, el elemento queda como antes (`503`, se conserva): ante la
duda, se falla hacia conservar.

**La incidencia es la `discarded_scan` de RN-22**, una por persona y jornada, con `source` y
`attempts` en el `context`. Quien la revisa crea el tramo con una corrección trazada (RN-13) si la
persona trabajó. **Un descarte sin atribuir no abre incidencia**, como en RN-22: lo ve la alerta, y
el runbook explica cómo cruzarlo con el quiosco y la hora.

### 7. Vigilancia

- **`ScanDiscardedByServer`** (`warning`): cualquier descarte del servidor. Es un defecto del producto
  que hay que reproducir.
- **`ScanDiscardBudgetExhausted`** (`critical`): el cupo está agotado y la cola vuelve a atascarse a
  propósito.
- Las alertas de cola atascada (`ColaOfflineAtascada`, `ScanBatchItemNotProcessed`) siguen avisando
  durante la ventana de `T` minutos. Runbook `cola-offline-atascada.md` §9.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| Contar en el quiosco y que abandone tras N intentos | No distingue defecto de dependencia caída, rompe el principio de ADR-047 y no llega a las PWA desplegadas |
| Contar en los dos lados | Dos contadores dan dos verdades, y el del servidor basta |
| Contador en Redis, o con `UPDATE` | El de Redis no es durable, y ADR-059 quiere el fichaje independiente de Redis. El de `UPDATE` pierde la historia y necesita candado |
| Solo `N`, sin ventana | Diez intentos son 8,5 minutos: un parche en media hora llegaría tarde |
| Sin cupo | Un defecto general vaciaría el registro en descartes, uno por hora |
| Responder `200` con `action: discarded`, o `409`/`410` | El quiosco 2.2.x reintentaría para siempre. Un `200` además diría «registrado» |
| Responder el `scan-rejected` genérico | Un descarte no es un rechazo, y nadie podría distinguirlos |
| Guardar el payload y reprocesar tras el arreglo | Guarda el secreto portador de la tarjeta, escribe en el registro sin validación humana y arriesga un fichaje doble con la corrección |
| Solo `discarded_scan_reports`, sin fila en `scan_events` | La idempotencia necesitaría una consulta más en todos los escaneos y cambiaría el número de consultas que fijan las pruebas de RS-03 |
| Escribir un marcador antes de cada intento para contar también las muertes del proceso | Una escritura más en cada fichaje del cambio de turno para un caso rarísimo |
| Retener por persona en vez de por quiosco | Ya se descartó en ADR-047: no se sabe de quién es el elemento que falla |

## Consecuencias

- **La cola deja de atascarse por un fichaje envenenado.** El precio es una incidencia para RRHH.
- **Se pierde el orden de esa persona en esa jornada.** Si se descarta la entrada de las 07:00, la
  salida de las 15:00 que va detrás abre un turno en vez de cerrarlo. La incidencia es de esa persona
  y esa jornada, así que quien la revise ve las dos, y `turno-abierto-prolongado` avisa del turno de
  más. Los fichajes posteriores no se marcan para revisión: el lote no sabe de quién era la cabeza.
- **Un defecto general sigue atascando la cola tras agotar el cupo**, a propósito y con alerta crítica.
- **El cupo es aproximado bajo concurrencia, y se acepta así** (duda 4 de la implementación,
  decidida por `arquitecto-dominio` el 23-10-2026). Se cuenta antes de la transacción del descarte y sin candado.
  - Dos descartes simultáneos de `scan_id` distintos del mismo quiosco pueden excederlo: como mucho,
    en el número de peticiones de ese quiosco en vuelo a la vez, que en la práctica es una o dos.
  - El caso para el que existe el cupo, el defecto general, **es secuencial** por RN-21: solo falla
    la cabeza de la cola, y la siguiente no se envía hasta que la anterior tiene desenlace. La
    carrera no lo afecta.
  - El exceso cuesta una o dos incidencias más para RRHH, y ningún fichaje registrado mal.
  - Un candado por dispositivo (por ejemplo, un *advisory lock*) añadiría un candado más al orden
    único de ADR-046, por delante de la cadena de auditoría que toma el asiento. Se reconsidera si
    el exceso aparece en producción.
- **Riesgo residual aceptado: un fichaje envenenado que mata el proceso no se cuenta nunca.** Pasa con
  el tiempo de PHP-FPM agotado, la memoria agotada o un `502`/`504` de nginx: no hay `catch` que
  escriba. Lo siguen viendo las alertas de cola atascada (doc 07 §6).
- Con un token de quiosco robado, sembrar incidencias así exige un defecto reproducible y esperar `T`
  por cada una. El techo es el de RN-22 (una por persona y jornada, en la ventana de fechas, solo con
  tarjeta auténtica o código de quien puede fichar), y `/scan/discarded` ya lo permite de forma más
  barata. **No sube el techo aceptado en ADR-047.**
- `scan_events.result` gana un valor. Toda consulta por `employee_id` que quiera solo lo aceptado ya
  filtraba por `result` (ADR-047).

## Verificación

- **Unitaria** (ya escrita, MSI 100 %): `tests/Unit/Attendance/Domain/Policy/UnprocessableScanPolicyTest.php`
  (los bordes de las tres condiciones, el cupo mirado el último, el cupo a cero, el reloj hacia atrás)
  y `tests/Unit/Attendance/Domain/ValueObject/ScanDiscardThresholdTest.php` (los suelos). Pendiente:
  el clasificador con cada clase de ADR-059 y cada SQLSTATE, también envueltos en `previous`, y el
  lote con la cabeza descartada, que sigue procesando los siguientes.
- **Integración**: el fallo se cuenta tras el `ROLLBACK`; con PostgreSQL caído no se cuenta; diez
  fallos simultáneos del mismo `scan_id`; las tablas sin `UPDATE`; la revisión abre una sola
  `discarded_scan` con `source: server`; la purga cubre la tabla nueva.
- **Feature y contrato**: dentro de la ventana sigue el `503`; fuera de ella llega el `422
  scan-discarded`; el reenvío por las tres rutas da la misma respuesta byte a byte; un aviso posterior
  por `/scan/discarded` se acusa sin una segunda fila; un `503` de dependencia nunca suma; la
  respuesta respeta el suelo de tiempo.
- **Quiosco y E2E**, con reloj simulado: la cola se detiene, a la hora llega `scan-discarded` y el
  resto se registra en orden. El mismo escenario con la lógica de la 2.2.x también vacía la cola.
- **Arquitectura**: las tres rutas pasan por el servicio que aplica la política.
