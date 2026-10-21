# ADR-059 — Con una dependencia caída, el fichaje se encola y el resto responde 503 con rastro

| Campo | Valor |
|---|---|
| **Estado** | **Propuesto**. Pendiente de aprobación del propietario |
| **Fecha** | 21 de octubre de 2026 |
| **Decide** | `arquitecto-dominio` (bloque 10 de la 2.3.0, tarea 10.1; hallazgos V6-AR-1 y R6-AR-04). Revisión pedida a `seguridad-cumplimiento` por los §3 y §4 |
| **Afecta a** | **Matiza** [ADR-038](ADR-038-limite-de-tasa-por-dispositivo-y-por-ip-no-por-credencial.md) (qué hace el límite de tasa cuando su almacén no responde) · Precisa [ADR-011](ADR-011-websockets-con-fallback-a-sondeo.md) (el reintento de la difusión de presencia) · Respeta [ADR-008](ADR-008-offline-first-con-idempotencia-por-scan-id.md), [ADR-019](ADR-019-la-licencia-nunca-bloquea-el-registro.md), [ADR-023](ADR-023-frontera-registro-legal-y-funcionalidad-accesoria.md), [ADR-047](ADR-047-ningun-fichaje-sale-de-la-cola-sin-desenlace-del-servidor.md) y [ADR-048](ADR-048-el-texto-libre-del-historico-de-errores-pasa-por-una-lista-blanca-de-palabras.md) · `Support/Resilience/CircuitBreaker`, `Support/Redis/*`, `Support/Database/*` · `ThrottleScanFailOpen` · `bootstrap/app.php` (manejador de excepciones) · `config/queue.php` (`resilient`), `config/cache.php` (`resilient`, `limiter`) · `ConcurrencyLimitedReportRenderer` · `BroadcastPresenceChange` · `docs/api/openapi.yaml` (`info.description`) |
| **Requisitos** | RNF-D-01, RNF-D-03, RF-AT-10, RN-15, RN-21, RS-02, RS-03, RS-12, RL-03, RL-05 · reglas duras 8, 15, 17, 19 y 21 |

## Contexto

La instalación depende de tres cosas que pueden faltar sin que falte la aplicación: **PostgreSQL**
(el registro), **Redis** (caché, límite de tasa, cola, embudo de PDF y métricas) y **el embudo de
PDF**, que reparte dos Chromium entre todas las peticiones. Las decisiones sobre qué hacer cuando
faltan se han ido tomando por piezas, sin ADR:

- **CH1 (2.2.0, `ed846a6`).** Con Redis caído, el límite de tasa de `/scan`, `/scan/batch` y
  `/scan/pin` falla **abierto** (`ThrottleScanFailOpen`). El resto falla **cerrado**. La caché por
  defecto cae al disco del contenedor. Los oyentes del fichaje caen a `deferred`. ADR-038 no habla de
  la caída (R6-AR-04).
- **Bloque 22 (2.2.0).** Dos cortacircuitos con el mismo mecanismo, uno por dependencia. Con
  PostgreSQL inalcanzable, **cualquier** ruta responde `503 service-unavailable` con `Retry-After`. El
  contrato lo declara como respuesta global. Mientras el circuito está abierto, la avería **no** se
  informa: solo queda `database.circuit_opened` (V6-AR-1).

Las verificaciones de la 2.2.0 y de la 2.2.1 encontraron lo que queda sin decidir:

- **Con Redis caído, 500 en vez de 503, y sin rastro** (R3-CH-03, V3-CH-1). Pasa en el acceso al
  panel y al portal, el latido, el padrón, `/me/export` y `PATCH /shift-entries`. `RedisCircuitOpen`
  está en `dontReportWhen` y ningún `render` la traduce. Quien recibe «Error interno» a las 06:30 no
  encuentra la causa en el log.
- **PostgreSQL parado con una transacción abierta da 500** (V21-3-CH-1). El error llega al deshacer
  («no connection to the server»), y `DatabaseUnavailable::UNREACHABLE` solo reconoce los fallos al
  conectar.
- **El `503` del embudo de PDF «sin Redis» nunca llega por HTTP** (V21-3-CH-2). El límite de tasa del
  portal falla antes, también en CSV.
- **Con PostgreSQL caído, Horizon da por terminado `BroadcastPresenceChange`** sin reintentarlo, y
  el panel en vivo se queda desfasado (2.2.1, tanda 3, «se pasó por alto»).
- **Tres textos que no coinciden.** RNF-D-03 dice «si cae Redis, las colas caen a driver de base de
  datos». `.env.example` dice que no hay respaldo en base de datos. El doc 02 §8 describe los
  cortacircuitos sin decir qué ruta falla abierta.

**Lo que hay de verdad en el código** (comprobado el 21-10-2026):

- **No hay cola de respaldo en base de datos.** No existe tabla `jobs`, solo `failed_jobs`.
  `QUEUE_CONNECTION=redis`. Horizon solo supervisa colas de Redis. La conexión `failover`
  (`database`, `deferred`) de `config/queue.php` no la usa nadie.
- **Lo único que cae es la conexión `resilient`**, de `QUEUE_CONNECTION` a `deferred`. La usan
  `BroadcastPresenceChange` y `RecordWorkedMinutes`.

## Decisión

**El registro horario no se para porque falte una dependencia: el quiosco conserva y reenvía, y las
tres rutas de escritura del fichaje no dependen de Redis. Todo lo demás responde `503` con
`Retry-After`, nunca `500`, y cada respuesta así deja una línea en el log.**

### 1. Qué responde una ruta que no puede atender

- **Dependencia caída → `503`**, `application/problem+json`, `type` `urn:kronoqr:problem:service-unavailable`,
  con `Retry-After` igual al plazo del cortacircuitos de esa dependencia (10 s de serie, mínimo 1).
  Vale para PostgreSQL y para Redis.
- **El cuerpo no nombra la dependencia.** Es el mismo `title` y `detail` fijo de
  `ProblemDetails::dependencyUnavailable()`. Decir «Redis» o «base de datos» enumera la
  infraestructura a quien no la administra (mismo criterio que `/ready`). La causa está en el log.
- **Tres `503` con `type` propio, porque a quien lo recibe le cambian la acción siguiente:**
  - `maintenance`: hay una actualización en curso y gana sobre todo lo demás.
  - `pdf-rendering-busy`: el embudo está lleno. Se reintenta pasado `Retry-After` o se descarga en CSV.
  - `not-ready`: solo lo da `/ready`.
- **Un `500` solo puede ser un defecto del producto**, nunca una avería de infraestructura.
- **Qué cuenta como dependencia caída:**
  - en PostgreSQL, `DatabaseUnavailable` en la cadena `previous`, **o** la conexión perdida a mitad
    de una sentencia, del `COMMIT` o del `ROLLBACK` (SQLSTATE `08000`, `08003`, `08006`, `57P01`,
    `57P02`, `57P03`, o los mensajes de libpq de conexión perdida);
  - en Redis, toda `RedisException`, se lance al conectar o a mitad de una orden, y
    `RedisCircuitOpen`.
- **Cambio respecto a hoy:**
  - con Redis caído, las rutas que fallan cerradas pasan de `500 internal-error` a `503 service-unavailable`;
  - con PostgreSQL perdido a mitad de transacción, de `500` a `503`.
- **Una conexión perdida no abre el circuito por sí sola.** Lo abre el intento de conexión
  siguiente si el servidor no está. Así un `pg_terminate_backend` o el corte de una conexión inactiva
  no dejan 10 s de `503` en toda la instalación.

### 2. Qué rastro deja

| Qué | Dónde | Cuántas veces |
|---|---|---|
| La avería empieza | `<dependencia>.circuit_opened` (`warning`, con la clase de la excepción y el plazo) | Una por avería y contenedor, como hoy |
| La avería termina | `<dependencia>.circuit_closed` (`info`) | Una por avería y contenedor, como hoy |
| El primer fallo de Redis, el que abre el circuito | `error_events`, por el informe normal | Una por avería. PostgreSQL está arriba, así que se puede escribir |
| Cada petición que responde `503` por una dependencia | Una línea `warning` `http.dependency_unavailable` con `dependency` (`database` o `redis`), el nombre de la ruta, `retry_after` y la correlación (`trace_id`, y `device_id` o `employee_uuid` si los hay). **Ni el mensaje de la excepción, que lleva host y puerto, ni texto libre** (regla dura 21, ADR-048) | Una por petición |
| Esa misma petición, en la traza | *Span* de la petición con `setStatus(error)` y `recordException` (solo la clase) | Una por petición |
| Esa misma petición, en `error_events` | **No se escribe** | — |

- **Por qué una línea por petición y no solo una por avería.** El caso de V3-CH-1 es real: quien
  mira el log de `app` por un «Error interno» concreto tiene que encontrarlo. En un hotel el
  volumen está acotado: un quiosco que reintenta con espera y unos pocos paneles que sondean cada
  15 s. Es una línea estructurada, sin traza de pila.
- **Por qué no `error_events` por petición.**
  - Con PostgreSQL caído es imposible, y reintentarlo es justo lo que el cortacircuitos evita.
  - Con Redis caído convertiría una avería de diez minutos en miles de filas que ocultan los
    errores del producto en el paquete de diagnóstico (ADR-020).
  - La avería ya tiene su fila.
- **Cambio respecto a hoy:**
  - hoy un `503` de PostgreSQL y un `500` de Redis con el circuito abierto no dejan ninguna línea.
    Pasan a dejar la línea `warning`;
  - la primera `RedisException` sigue informándose como ahora.
- **Fuera de este ADR.** Que una avería de PostgreSQL, que no puede escribirse en `error_events`
  mientras dura, se apunte allí al cerrarse el circuito. Queda como pendiente para decidir en la
  tarea 10.3.

### 3. Dónde se falla abierto y dónde cerrado (con Redis caído)

**Se falla abierto solo donde fallar cerrado dejaría jornadas sin registrar:**

- **Fallo abierto, solo el límite de tasa de `scan`, `scan-batch` y `scan-pin`**
  (`ThrottleScanFailOpen`). Esto ratifica CH1.
  - Ese límite por dispositivo es un control secundario. El primario es nginx, que limita por
    origen sin Redis (§7.1). En el PIN hay además bloqueo por empleado (RS-12), que vive en la caché
    `resilient` y sigue contando en el disco del contenedor `app`. Hay un solo contenedor `app` por
    instalación (ADR-017, ADR-040).
  - Perder unos minutos el techo por tablet es un riesgo acotado. Dejar de fichar no es aceptable.
- **Fallo cerrado, con `503`, en todo lo demás:**
  - acceso y segundo factor del panel;
  - acceso al portal y `/me/*`;
  - puesta en marcha y emparejamiento;
  - gestión, incluidas las correcciones;
  - informes y exportaciones;
  - latido, padrón y descartes del quiosco;
  - `broadcasting/auth` y el sondeo de presencia.

  En el acceso, el límite es la defensa contra la fuerza bruta de un PIN de pocas cifras o de una
  contraseña. Abrirlo durante una avería, que es cuando nadie mira, es entregarlo. En las rutas ya
  autenticadas protege a PHP-FPM y a PostgreSQL de un bucle de sondeo o de exportaciones, que son lo
  que el fichaje necesita libre (RS-02, RNF-P-06).

**Lo que esto cuesta, y se acepta.** Mientras dure una avería de Redis, nadie entra al portal ni
descarga su registro. RL-03 y RL-05 piden el registro «a disposición» y «en cualquier momento». Se
acepta porque:

- la indisponibilidad es de infraestructura, dura lo que tarde IT en levantar Redis y la avisan
  `AlmacenDeMetricasCaido` y la alerta de rutas que fallan cerradas (bloque 9, tarea 9.3);
- el registro sigue íntegro y se puede extraer por consola (`compliance:legal-export`);
- la alternativa debilita RS-12.

**Requiere aprobación del propietario.**

**Cambio respecto a hoy:** ninguno en qué falla abierto o cerrado. Cambia el código (`503` y no
`500`) y el rastro (§2).

### 4. Qué pasa con el registro horario

- **Una respuesta `503` no deja nada a medias.** Cada caso de uso escribe en una transacción
  (ADR-007, regla dura 7). Si falta PostgreSQL, no se escribe. Si la conexión se pierde antes del
  `COMMIT`, se deshace.
- **Si la conexión se pierde en el propio `COMMIT`, el desenlace es incierto.** El contrato ya lo
  dice: «puede que el fichaje se haya registrado o no».
  - **El fichaje** se reenvía con el mismo `scan_id`. Si se registró, recibe la respuesta original
    (regla dura 8).
  - **Una corrección** (`PATCH /shift-entries`) no se reenvía a ciegas. El panel relee la jornada
    antes de repetirla. Si se aplicó, el reenvío habría dado `409 shift-entry-superseded`, que es la
    señal correcta, pero releer primero evita la confusión.
- **El quiosco nunca bloquea** (regla dura 19, ADR-047):
  - un `503` en `/scan`, `/scan/pin` o `/scan/batch` es «no decidido»: el fichaje se queda en la
    cola, el empleado ya fue confirmado en pantalla y el lote aplaza lo que va detrás (`scan-held-back`,
    RN-21);
  - el PIN sin respuesta a tiempo muestra «pendiente»;
  - un `503` en el latido o en el padrón no cambia nada para el empleado. El quiosco ficha con el
    padrón que tiene en caché. La rotación del token espera al siguiente latido que responda: el
    margen es de 18 días (ADR-044).
- **Los rechazos siguen siendo genéricos y de tiempo constante** (RS-03, regla dura 17).
  - Con Redis caído, el límite del acceso responde `503` **antes** de mirar la credencial. Nadie
    distingue un código existente de uno inexistente por el código de respuesta.
  - En el fichaje, que sigue abierto, el suelo de tiempo no depende de Redis.
- **La licencia no interviene** (regla dura 15, ADR-019):
  - ninguna ruta del fichaje ni del registro legal lee la licencia para decidir si atiende;
  - si leerla falla (PostgreSQL caído), el estado es «desconocido» y no «sin licencia» (tarea 10.7).
    No degrada ni bloquea nada. Nunca se le aconseja a IT reactivar la licencia durante una avería.

### 5. Colas y trabajos en segundo plano

**No hay cola de respaldo en base de datos, y no se va a construir.**

- La cola que no puede fallar ya existe: es la del quiosco (ADR-008).
- Una cola en base de datos cargaría la misma PostgreSQL que atiende el fichaje (RNF-P-02).
- Horizon no la consumiría.
- En la práctica nadie la usaría: las peticiones que encolan trabajos pesados ya fallan cerradas
  por el límite de tasa (§3).

| Trabajo | Con Redis caído | Con PostgreSQL caído |
|---|---|---|
| `BroadcastPresenceChange`, `RecordWorkedMinutes` (conexión `resilient`) | Se ejecutan en el mismo proceso **después de responder** (`deferred`), con aviso `queue.failed_over`. El fichaje no espera | Fichajes nuevos no hay (el fichaje responde `503`). Lo que ya estaba en la cola: ver la fila siguiente |
| `BroadcastPresenceChange` en Horizon, si `stateOf()` encuentra PostgreSQL inalcanzable o perdido | — | **Se devuelve a la cola** con espera igual a `Retry-After` y se reintenta hasta 60 s después del hecho. Pasado ese plazo, `warning` y se abandona. Otro fallo (Reverb caído, por ejemplo) no se reintenta, como hoy. Ejecutado en `deferred` o `sync` no hay a dónde devolverlo: `warning`, como hoy |
| Exportación íntegra e informes en diferido (`tries = 1`) | No llegan a pedirse: la petición responde `503` por el límite de tasa y no crea nada | Fallan una vez, sin reintento, como hoy. El trabajo lo apunta en el log y, si PostgreSQL vuelve a tiempo, en `error_events` (tarea 10.3). La obsolescencia la marca fallida. Quien la pidió la repite |
| Lo que ya estaba encolado en Redis | Espera en Redis y se ejecuta al volver | Según su propia política (filas de arriba) |
| Tareas programadas (detección, resumen, métricas) | Corren. Lo que necesita Redis falla y deja `scheduler.command_failed` y `error_events` (tarea 10.3) | Fallan, dejan `scheduler.command_failed` en el log y se repiten en la próxima ejecución programada. No se reintentan antes |

**Por qué se reintenta `BroadcastPresenceChange`.** El docblock actual dice que no se reintenta
porque un mensaje viejo pisaría un estado más fresco. Eso ya no es cierto, por dos motivos:

- el trabajo **relee** el estado en el momento de ejecutarse, así que difunde el actual;
- el panel descarta, por persona, un `occurred_at` anterior al último que aplicó
  (`presence.store.ts`).

Y no reintentarlo tiene un coste. Con el WebSocket conectado, el panel **no sondea** (ADR-011), así
que un mensaje perdido deja a esa persona mal pintada hasta su siguiente fichaje. El tope de 60 s
evita que una avería larga llene la cola de difusiones inútiles.

- **Cambio respecto a hoy:** `BroadcastPresenceChange` pasa de «no se reintenta nunca» a
  «se reintenta durante 60 s si falta PostgreSQL».

**Invariante de las solicitudes en diferido.** Una solicitud de exportación o de informe nunca se
queda «en curso» sin un trabajo detrás. Si el encolado falla después de crear la fila, la misma
petición la marca fallida y responde `503`. Si no, bloquearía la siguiente solicitud (RL-20) hasta
que la obsolescencia la retire.

### 6. El embudo de PDF

| Situación | Respuesta | Rastro |
|---|---|---|
| Embudo lleno tras esperar `wait_seconds` | `503 pdf-rendering-busy` con `Retry-After` y la salida en CSV en el `detail` | `reporting.pdf_rendering_busy` (`warning`) y la métrica de rechazo |
| Redis caído, petición del portal o del panel | `503 service-unavailable` **del límite de tasa**, antes de llegar al embudo, en PDF y en CSV | §2 |
| Redis cae entre el límite y el embudo | `503 service-unavailable` del embudo (`ReportRenderingUnavailable`), que falla cerrado: abrirlo quitaría el tope justo cuando la instalación está peor | `reporting.pdf_rendering_limiter_unavailable` |
| Chromium no arranca | `503 service-unavailable` | El log del motor |

El `503` del embudo sin Redis solo se ve si Redis cae justo entre el límite y el embudo. Es lo
esperable: el límite de tasa va antes y falla cerrado.

**Lo que tiene que llegar por HTTP siempre es `pdf-rendering-busy` con su `Retry-After`.** El
cliente necesita ese `type` para ofrecer el CSV. Con eso V21-3-CH-2 queda resuelto por decisión, no
por código: el `503` sin Redis que no llega es el resultado correcto del §3.

### 7. Los cortacircuitos son compartidos, y eso es deliberado

- **Uno por dependencia y por contenedor.** `app`, `horizon` y `scheduler` lo aprenden cada uno por
  su cuenta.
- **Dentro del contenedor lo comparten todos:**
  - todos los procesos, sea PHP-FPM o una orden de Artisan;
  - en Redis, todos sus usos: caché, límite de tasa, cola, embudo y métricas;
  - en PostgreSQL, todas las conexiones `pgsql`: la de la aplicación, la de `error_events`, la del
    migrador y la del rol de mantenimiento.
- **Por qué compartido.** Es un solo servidor por instalación (ADR-017). Si no responde a uno de sus
  usos, no responde a ninguno.
- **El estado vive en `storage/framework/`**, que no es un volumen compartido. No vive en Redis ni
  en PostgreSQL, que son justo lo que puede faltar.
- **Plazo:** 10 s de serie, `0` lo apaga y nunca pasa de 60 s.

**La consecuencia que hay que conocer** (tanda 4 de la 2.2.1). Una orden ejecutada dentro de `app`
con `DB_HOST` o `REDIS_HOST` apuntando a un sitio inexistente **abre el circuito para PHP-FPM**. Lo
mismo con `php artisan tinker` o con Pest. La receta de caos simula la caída de una de estas dos
maneras:

- con `DB_CIRCUIT_BREAKER_SECONDS=0` o `REDIS_CIRCUIT_BREAKER_SECONDS=0` en el proceso que simula;
  apagado, el cortacircuitos no escribe su fichero;
- parando el servicio de verdad (`docker compose stop`), que es el caso que importa.

Lo dice el doc 02 §8.2.

### 8. Tabla ruta × caída

`503 SU` es `503 urn:kronoqr:problem:service-unavailable` con `Retry-After`, y su rastro es el del
§2. «Como hoy» marca lo que este ADR no cambia.

| Ruta o familia | PostgreSQL caído | Redis caído | Embudo de PDF | Registro horario |
|---|---|---|---|---|
| `POST /scan`, `/scan/pin`, `/scan/batch` | `503 SU`. En el lote, el primero `503` y los siguientes `503 scan-held-back` (como hoy) | **Atiende** (`200`, `201`, `422` genérico). El límite falla abierto con `attendance.rate_limiter_fail_open`. La difusión y los minutos van a `deferred` (como hoy) | — | Con PostgreSQL caído se queda en la cola del quiosco y se reenvía con el mismo `scan_id`. Con Redis caído se registra normal |
| `POST /scan/discarded` | `503 SU` | `503 SU`. **Cambio:** era `500` | — | El aviso se queda en la lista del quiosco hasta su `acknowledged` (ADR-047) |
| `POST /kiosk/heartbeat`, `GET /kiosk/roster` | `503 SU` | `503 SU`. **Cambio:** era `500` | — | Nada. El quiosco sigue con su padrón en caché y la rotación del token espera |
| Emparejamiento del quiosco | `503 SU` | `503 SU`. **Cambio:** era `500` | — | Nada |
| `POST /auth/login`, segundo factor, `POST /auth/password` | `503 SU` | `503 SU`, antes de mirar la credencial. **Cambio:** era `500` | — | Nada |
| Acceso al portal y `GET /me/*` | `503 SU` | `503 SU`. **Cambio:** era `500` | — | No se puede consultar durante la avería. Riesgo aceptado (§3) |
| `GET /me/export`, informes, exportaciones, enlaces de descarga | `503 SU` | `503 SU`, también en CSV. **Cambio:** era `500` | `503 pdf-rendering-busy` en PDF. El CSV no pasa por el embudo | No se puede descargar durante la avería. Nada se crea a medias (§5) |
| `PATCH /shift-entries`, altas y anulaciones manuales | `503 SU`. También con la conexión perdida a mitad de transacción. **Cambio:** era `500` | `503 SU`. **Cambio:** era `500` | — | No se escribe nada, o queda entera. El panel relee antes de repetir (§4) |
| Resto de la gestión (`/employees`, `/settings`…) | `503 SU` | `503 SU`. **Cambio:** era `500` | — | Nada |
| Presencia: WebSocket | Los mensajes no salen (no hay fichajes) | **Sigue**: Reverb no usa Redis (`REVERB_SCALING_ENABLED=false`) y la difusión va a `deferred`. Un panel que reconecta no se puede suscribir (`broadcasting/auth` da `503 SU`) | — | Nada del registro viaja por aquí (ADR-011) |
| Presencia: sondeo (`GET /attendance/live`) | `503 SU` | `503 SU`. **Cambio:** era `500` | — | Nada. El panel conserva la última lista y lo dice |
| Trabajos de Horizon | §5 | §5 | — | Nada. Son vistas, métricas o copias |
| Tareas programadas | Fallan y se repiten en la próxima ejecución, con `scheduler.command_failed` | Corren. Lo que necesita Redis falla con rastro (§5) | — | Nada |
| `GET /health` | `200`, porque no consulta dependencias (como hoy) | `200` (como hoy) | — | — |
| `GET /ready` | `503 not-ready` sin detalle, en menos de 3 s (como hoy) | `503 not-ready` (como hoy) | — | — |
| `/metrics` | `200` con lo que hay (como hoy) | `200` con lo que no depende de Redis y `kronoqr_metrics_store_up 0` (como hoy). Un `503` aquí lo leería Prometheus como «aplicación caída» | — | — |
| Cualquier ruta en mantenimiento | `503 maintenance` (gana) | `503 maintenance` | — | El quiosco conserva |

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Cola de respaldo en base de datos** (lo que decía RNF-D-03) | Carga la PostgreSQL del fichaje, Horizon no la consume y en la práctica nadie la usaría: las peticiones que encolan trabajos pesados fallan cerradas antes (§5). La cola que importa ya está en la tablet |
| **Fallar abierto en todo con Redis caído** | Desactiva la defensa contra la fuerza bruta del PIN del portal y de la contraseña del panel durante la avería (RS-12). Es justo cuando nadie mira |
| **El límite de tasa con el disco detrás**, como la caché | El almacén `file` no incrementa de forma atómica entre procesos de FPM. Un control de seguridad que se debilita en silencio es peor que uno que se cierra y avisa |
| **Fallar abierto solo en las rutas ya autenticadas** (`/me/export`, gestión) | El acceso sigue cerrado, así que no devuelve el portal, y deja sin techo las exportaciones sobre la PostgreSQL del fichaje |
| **Un `type` distinto por dependencia** (`redis-unavailable`…) | Enumera la infraestructura a quien no la administra, y al cliente le toca hacer lo mismo en los dos casos: esperar `Retry-After` |
| **Una fila en `error_events` por cada `503`** | Imposible con PostgreSQL caído, y con Redis caído ahoga los errores del producto en el paquete de diagnóstico |
| **Seguir sin rastro por petición** | Es V3-CH-1: el «Error interno» concreto no se encuentra en el log |
| **Abrir el circuito con la conexión perdida** | Un `pg_terminate_backend` o el corte de una conexión inactiva dejarían 10 s de `503` en toda la instalación |
| **No reintentar nunca la difusión de presencia** (lo de hoy) | Con el WebSocket vivo el panel no sondea: el mensaje perdido se queda perdido hasta el siguiente fichaje de esa persona |

## Consecuencias

- **Para el cliente HTTP cambia el código:** `503` donde había `500`, con `Retry-After`. El quiosco
  ya trata `503` como «no decidido» (ADR-047). Lo que enseñan el panel y el portal ante un `503`
  lo revisan las tareas 10.5 y 14.x. El contrato lo declara como respuesta global en
  `info.description`, ahora también para Redis (tarea 10.2). Es un cambio aditivo.
- **El log de `app` gana una línea por petición rechazada durante una avería.** Su volumen está
  acotado por lo que ya llega a PHP, detrás del límite de nginx.
- **ADR-038 queda matizado.** Su límite por dispositivo e IP falla abierto en las tres rutas de
  escritura del fichaje y cerrado en las demás cuando su almacén no responde.
- **ADR-011 queda precisado.** La difusión se reintenta durante 60 s si falta PostgreSQL, y el
  orden por `occurred_at` del panel es lo que lo hace seguro: no se puede quitar sin revisar esto.
- **El docblock de `BroadcastPresenceChange`** («No se reintenta, y es deliberado») se reescribe con
  la tarea 10.2.
- **La conexión `failover` de `config/queue.php`** (`database`, `deferred`) sobra. Se retira con la
  tarea 10.2 para que nadie la lea como un respaldo que existe.
- **RNF-D-03, `.env.example`, el doc 02 §8.2 y `docs/cliente/configuracion.md` §6.3** (y su versión
  en inglés) se corrigen en este mismo cambio para decir lo mismo que este ADR.
- **El doc 05 no cambia.** Promete el fichaje sin conexión y un 99,9 % del acto de fichar (RNF-D-01).
  Nunca prometió una cola de respaldo en base de datos ni el portal durante una avería de Redis.

## Verificación

- **Feature, una por familia de la tabla del §8**, con Redis simulado caído (una `RedisException`
  al conectar y `RedisCircuitOpen`):
  - `503 service-unavailable` con `Retry-After`;
  - cuerpo sin la palabra Redis;
  - una línea `http.dependency_unavailable`;
  - ninguna fila nueva en `error_events`;
  - el fichaje por `/scan`, `/scan/pin` y `/scan/batch` sigue respondiendo `200` o `201`.
- **Feature con PostgreSQL perdido a mitad de transacción**, con una conexión que lanza «no
  connection to the server» al deshacer: `503` y no `500`, y nada escrito.
- **Feature del acceso con Redis caído:**
  - `503` idéntico para un código existente y uno inexistente (RS-03);
  - el límite no se abre: `ThrottleFailOpenScopeTest` sigue enumerando el router.
- **Unitaria o feature de `BroadcastPresenceChange`:**
  - con `DatabaseUnavailable`, en worker, se devuelve a la cola con espera;
  - pasados 60 s desde el hecho, `warning` y fin;
  - en `deferred`, `warning` sin excepción.
- **Feature del embudo:** lleno → `503 pdf-rendering-busy` con `Retry-After` por HTTP, en el portal
  y en el panel.
- **Caos (CI, etapa ⑧, y verificación por tandas):** parar `redis` y `postgres` de verdad, y repetir
  la tabla. La receta usa `*_CIRCUIT_BREAKER_SECONDS=0` en el proceso que simula (§7).
