# Runbook — leer el histórico de errores y decidir qué hacer

> **Quién lo usa:** el IT del cliente, desde el panel de gestión.
> **Cuándo:** al recibir `ErroresCriticosNuevos`, al investigar una tasa de
> error 5xx, una latencia alta del fichaje, una sonda del borde fallida o una
> detección de incidencias con fallos (las cuatro alertas de la tarea 3.2 que
> también apuntan aquí), o en la ronda semanal de mantenimiento. **Cuánto
> dura:** entre 2 y 10 minutos hasta tener una hipótesis; casi todos los casos
> se resuelven mirando la fila y siguiendo la columna «Qué hacer» de la §4.

---

## 1. Las cinco alertas que traen aquí, y la revisión sin alerta

| Alerta | Umbral | Severidad | Regla | Estado |
| --- | --- | --- | --- | --- |
| `ErroresCriticosNuevos` | Un grupo `critical` **nuevo o reabierto** en 5 min | Alta | `infra/observability/prometheus/rules/errors.yml` | ✅ Tarea 5.12 |
| `ErroresDeServidorEnElFichaje` | Tasa de `5xx` en `/api/v1/scan*` > 1 % en 5 min | Crítica | `infra/observability/prometheus/rules/api.yml` | ✅ Tarea 3.2 |
| `LatenciaDelFichajeAlta` | p95 de `/api/v1/scan*` > 500 ms en 10 min | Alta | `infra/observability/prometheus/rules/api.yml` | ✅ Tarea 3.2 |
| `SondaDelBordeFallida` | `probe_success == 0`, `for: 5m` (nada llega, o `/ready` dice que la base de datos o Redis no responden) | Crítica | `infra/observability/prometheus/rules/api.yml` | ✅ Tarea 3.2 |
| `DeteccionDeIncidenciasConFallos` | `incident_detection_last_failures > 0`, `for: 5m` | Alta | `infra/observability/prometheus/rules/incidents.yml` | ✅ Tarea 3.2 |

Las cuatro de la tarea 3.2 se apoyan en las mismas métricas que ya recoge
Prometheus desde la 3.1 (`http_requests_total`, `http_request_duration_seconds`
y `probe_success`) más el gauge de la última pasada de la detección nocturna de
incidencias. El procedimiento de todas es el mismo: la causa casi siempre
queda escrita en `error_events` con más detalle del que da la métrica
agregada, así que se empieza igual, por la §3 de este documento — salvo
`SondaDelBordeFallida`, que por definición suena cuando el servidor ni
siquiera responde para dejar un registro: ahí el primer paso es
`docker compose ps` y `docker compose logs postgres redis`, no el panel.

**Excepción: `DeteccionDeIncidenciasConFallos` no deja rastro en `error_events`.**
Un código de salida distinto de cero de una tarea programada en segundo plano
(`runInBackground()`) no se convierte en excepción, así que no abre ningún
grupo en el histórico; solo lo haría una excepción no capturada dentro de la
propia pasada. Su rastro es el log técnico: una línea `scheduler.command_failed`
con `command` y `exit_code`, y una `attendance.incident_not_opened` por
hallazgo, con `employee_uuid` y la clase de la excepción (nunca nombres).
Búscalas con `docker compose logs scheduler | grep -E 'scheduler.command_failed|incident_not_opened'`
o en Loki. Corregida la causa (casi siempre base de datos o disco:
`product:doctor`), repite `php artisan attendance:detect-incidents`: es
idempotente y no duplica lo que sí se abrió; la métrica vuelve a cero en la
siguiente pasada y la alerta se apaga sola. Lo mismo vale para
`ReconciliacionConFallos` y `attendance:reconcile`, cuyo runbook es
[`divergencia-proyeccion.md`](divergencia-proyeccion.md).

**Por qué la primera alerta cuenta grupos y no ocurrencias.** Hay dos
métricas: `application_errors_total{source,level}` sube con **cada**
ocurrencia —es la que usan `product:errors` y el panel para `occurrences`— y
`application_error_groups_opened_total{source,level}` sube solo cuando **se
crea una fila nueva o se reabre una ya resuelta**. `ErroresCriticosNuevos` usa
la segunda. Con la primera, una avería ya conocida y sin resolver —una cámara
rota que sigue fallando en cada intento de fichaje mientras esperas el
recambio— mantendría la alerta sonando sin parar: cada fichaje fallido es una
ocurrencia más del mismo grupo, ya abierto. Con la segunda, la alerta suena
una vez cuando el problema aparece (o reaparece tras darlo por resuelto) y se
calla mientras sigue siendo el mismo problema conocido — que es justo lo que
hace que valga la pena atenderla cuando suena.

**Y sin que suene nada.** Entra en «Errores» una vez por semana, aunque no
haya alerta: `product:errors --since=24h` (§5) es lo que hace `product:doctor`
por debajo, y una fila `error` que lleva días con `occurrences` subiendo sin
que nadie la mire es exactamente el ruido de fondo que una alerta puntual no
detecta.

**Impacto en el fichaje, que es lo primero que hay que saber.** Casi nunca
ninguno: la regla dura 19 hace que el quiosco encole y confirme igual aunque
algo falle detrás. La excepción es el origen `api` sobre `/scan*` y los
códigos `kiosk.*` que sí le impiden fichar (§4, filas marcadas). Compruébalo
antes de nada: es la diferencia entre «hay que mirarlo hoy» y «hay que
mirarlo ya».

---

## 2. Qué es esto, y qué NO es

`error_events` es una tabla de PostgreSQL, la misma base de datos que se
respalda a diario. Agrupa **por huella** —clase de excepción, punto de fallo y
mensaje sin sus identificadores variables— todo error de la aplicación (API,
cola, planificador, consola) y todo error reportado por los tres clientes
(quiosco, panel, portal). Cada repetición no crea una fila nueva: incrementa
`occurrences` y actualiza `last_seen_at` en la fila que ya existía. **Se
conserva 90 días** (`ERROR_HISTORY_RETENTION_DAYS`) y se purga sola, a diario,
sin que nadie tenga que confirmar nada.

Dos cosas con las que no hay que confundirlo:

- **No es el registro de auditoría (`audit_log`).** La auditoría es
  solo-append, encadenada por hash, se conserva **cuatro años** y tiene valor
  probatorio ante una inspección. `error_events` son datos técnicos: se puede
  marcar un error como resuelto, se purga a los 90 días y **resolver un error
  no deja asiento en `audit_log`** — es una decisión operativa, no un hecho
  legal.
- **No es el log técnico** (Loki, si lo tienes activado — §10 de
  [`operacion.md`](../cliente/operacion.md)). El log técnico tiene más
  detalle de una petición concreta, pero es **opcional**: puedes tenerlo
  apagado, puedes no tener quien lo mire, y lo pierdes si reinstalas.
  `error_events` vive siempre, en la base de datos que respaldas.

**Nunca lleva nombres, correos ni fichajes** — y no es una promesa sin
mecanismo, esto es lo que lo consigue (regla dura 21):

- **El mensaje se sanea en el servidor**, nunca confiando en que el cliente lo
  haya hecho ya: correos, DNI/NIE, teléfonos, horas y fechas se sustituyen por
  marcadores, y **todo texto entre comillas simples, dobles o angulares se
  reemplaza igual** — es donde una excepción interpola un valor variable, y es
  la única forma fiable de no dejar pasar un nombre de persona sin conocerlo
  de antemano.
- **Un fallo de base de datos no imprime lo que se intentó guardar.** Una
  `QueryException` de Laravel, sin este saneado, interpolaría los valores en
  claro en el mensaje — el propio nombre y apellidos que se intentaban
  insertar. El servidor **no usa ese mensaje**: compone el código `SQLSTATE`,
  el texto del motor y la consulta con sus marcadores de posición (`?`) sin
  los valores, así que conserva el dato útil para diagnosticar y descarta el
  dato personal.
- **El contexto solo admite una lista cerrada de claves técnicas**
  (`route`, `job`, `queue`, `command`, `http_status`…) con valores truncados;
  no hay una clave libre donde algo inesperado pueda colarse.

Aun así, sigue siendo texto libre saneado por un patrón, no un campo
estructurado: si alguna vez ves algo que no debería estar ahí, es exactamente
el tipo de hallazgo que hay que reportar al fabricante (§8), no algo que
debas «limpiar» tú mismo editando la fila (§7, no se edita a mano).

**Techo de grupos abiertos por origen.** Un origen con un catálogo de errores
que se dispara sin control podría llenar la tabla de filas nuevas más deprisa
de lo razonable. Por encima de `PRODUCT_ERRORS_MAX_OPEN_GROUPS_PER_SOURCE`
(500 de serie) grupos abiertos de un mismo origen, la siguiente ocurrencia que
no encaja en un grupo ya existente **no crea fila**: se acumula en un grupo de
desbordamiento de ese origen, con `code = overflow`. Si ves una fila así,
significa que ese origen está generando más tipos de error distintos de lo
normal — mira `product:doctor`, que avisa del tamaño de la tabla — y es
motivo para escalar (§8), no para ignorarla. El parámetro está en
[`operacion.md`](../cliente/operacion.md) §15.5.

---

## 3. La pantalla «Errores» del panel

Ruta **Errores** (requiere el permiso `diagnostics:*`; un acceso de soporte
con alcance `diagnostics` la ve pero no puede resolver, §7).

- **Filtros**: origen, severidad, estado (`Abiertos` por defecto, o
  `Resueltos`, o `Todos`) y periodo.
- **Cada fila**: severidad, origen, mensaje (el de la primera aparición,
  saneado), **`occurrences`**, primera vez y última vez (relativas y con la
  fecha exacta en la zona del centro), versión de la aplicación en la última
  aparición, y el **`trace_id`** de esa última aparición, copiable con un
  clic.
- **El detalle de una fila** trae la clase de la excepción, `file:line` (si
  lo hay — los errores de cliente no lo llevan nunca, ver §4), el contexto
  técnico, y **un texto «qué hacer» redactado para quien no conoce el
  sistema**, en tu idioma, que remite a esta misma sección y al apartado que
  corresponda de `operacion.md`.
- **«Marcar como resuelto»**: lo marca sin dejar asiento de auditoría (§2).
  **Si el mismo error vuelve a ocurrir, la fila se reabre sola** —
  `resolved_at` y `resolved_by_user_id` vuelven a quedar vacíos, conservando
  el recuento de `occurrences` que ya llevaba—: un error que diste por
  arreglado y que reaparece es justo lo que tienes que ver, no algo que el
  sistema deba ocultar por haber estado marcado antes.
- **Quién lo resolvió** se muestra con nombre a una cuenta de gestión normal,
  pero **no a un acceso de soporte**: un acceso de soporte con alcance
  `diagnostics` o `read_only` ve la fila y que está resuelta, nunca el nombre
  de quién la resolvió — el mismo criterio que ya aplicaba el paquete de
  diagnóstico (§8).

---

## 4. Origen × severidad: qué mirar en cada caso

**Qué significa `critical` exactamente**, para no tener que adivinarlo fila a
fila: un *job* de cola que **agota sus reintentos**, cualquier tarea del
planificador, cualquier petición a `/api/v1/scan*`, y estos códigos del
quiosco — los que le impiden fichar: `kiosk.camera.*`, `kiosk.scanner.*`,
`kiosk.offline.storage_unavailable`, `kiosk.offline.confirm_not_persisted`,
`kiosk.roster.decrypt_failed`, `kiosk.pin.seal_failed`. **Todo lo demás es
`error`** — molesta, pero no es de los que paran algo que nadie ve o que no
puede parar.

**El `critical` de un error de cliente solo lo produce un quiosco.** Los
códigos de `kiosk.*` de arriba son un catálogo cerrado por origen: el
servidor valida cada código contra el catálogo del origen que lo envía y
rechaza el que no encaja, y decide el nivel **con el origen delante** —una
sesión de panel o de portal nunca puede fabricar una fila `critical`,
aunque envíe un código con la misma forma que uno de quiosco. Si ves
`critical` en una fila `admin` o `portal`, no es de esta lista: es un *job*
que se lanzó desde esa petición y agotó sus reintentos (fila `worker`,
mira ahí primero).

| Origen | Nivel | Qué mirar primero | Qué hacer |
| --- | --- | --- | --- |
| `kiosk` | `critical` (cámara, escáner, cola local, padrón, sellado de PIN) | **Ese quiosco no puede fichar.** Mira `kiosk:health` — cola pendiente y último contacto | Cámara y permisos: [`alta-nuevo-quiosco.md`](alta-nuevo-quiosco.md) §6 («…la tablet dice que no puede acceder a la cámara»). Almacenamiento local lleno o corrupto: reinicia la PWA — recarga completa, no solo la pantalla — y si persiste, trata la tablet como averiada (mismo runbook, §5). Padrón cifrado que no descifra: token de dispositivo caducado o revocado; vuelve a emparejar |
| `kiosk` | `error` | Casi siempre cosmético: un fallo de red que la cola absorbió | Si `occurrences` sube deprisa en un solo quiosco, revisa su latido y su versión; si sube en varios a la vez, sospecha del borde (certificado, `KIOSK_VLAN_CIDR`) antes que del dispositivo |
| `scheduler` | `critical` | **Toda tarea del planificador es crítica** por definición (decisión de la ficha 5.12): un fallo aquí es la purga, la reconciliación o la verificación de auditoría que no corrieron | `docker compose exec app php artisan product:doctor` primero — casi siempre explica la causa (disco, base de datos, permisos). Si el mensaje señala a la reconciliación nocturna: `attendance:reconcile --from= --to=` sobre el rango afectado, y comprueba después que la alerta de divergencia no ha saltado ([`divergencia-proyeccion.md`](divergencia-proyeccion.md)). Si señala a la copia: [`restaurar-backup.md`](restaurar-backup.md) §2 |
| `worker` | `critical` (agotó sus reintentos) | Cola atascada o caída: mira Horizon (`operacion.md` §12.1 lista lo que `doctor` comprueba de colas) | `product:doctor` para ver si Redis responde y si hay un trabajador vivo. Si el *job* es de un módulo concreto, el mensaje saneado suele decir cuál; reintenta manualmente si el fallo era transitorio (red, base de datos momentáneamente lenta) |
| `worker` | `error` | Un intento fallido que Horizon va a reintentar solo | Nada urgente. Si la misma huella acumula `occurrences` a la vez que el *job* sigue fallando, pasa a mirar el `critical` correspondiente cuando agote los intentos |
| `api` | `critical` (ruta `/api/v1/scan*`) | **Impacto directo en el fichaje**: alguien pudo quedarse sin ver la confirmación en pantalla, aunque el quiosco haya encolado igual (regla dura 19) | `product:doctor` (base de datos y disco primero, son la causa más frecuente de un 5xx en el endpoint de fichaje), después mira si coincide con un pico de tráfico real (cambio de turno) o con una migración a medias tras una actualización reciente |
| `api` | `critical` (no `/scan*`) | Un *job* que agotó reintentos también puede aparecer aquí si se lanzó desde una petición | Igual que `worker`/`critical`: `product:doctor`, y el mensaje saneado suele apuntar al módulo |
| `api` | `error` | Rutas de gestión o del portal devolviendo un error controlado que aun así se registró | Revisa si coincide con quejas de uso del panel o del portal; si `occurrences` es alto y aislado a una sola ruta, es candidato a incidencia de producto — abre un caso con soporte (§8) |
| `console` | `error`/`critical` | Un comando ejecutado a mano (no programado) que falló | Suele ser el propio IT ejecutando algo — revisa el mensaje, no hace falta escalar salvo que no reconozcas el comando |
| `admin` | cualquiera | Errores del panel en el navegador de una cuenta de gestión | Comprueba la **versión desplegada** (`docker compose exec app php artisan product:doctor` la incluye) frente a lo que muestra el navegador — un desajuste después de actualizar se arregla con una recarga forzada (`Ctrl+Shift+R`) o vaciando la caché del sitio |
| `portal` | cualquiera | Errores del portal en el navegador de un empleado | Mismo diagnóstico que `admin`: versión y caché del navegador. Sin `file:line` ni `stack` — nunca viajan del cliente — así que el mensaje saneado y el `trace_id` son lo único que tienes; si no basta, pide a la persona que repita la acción con las herramientas de desarrollador abiertas |

---

## 5. Los comandos

```bash
docker compose exec app php artisan product:errors --since=24h --level=critical
docker compose exec app php artisan product:errors:prune --dry-run
```

`product:errors` lista los grupos **abiertos** del periodo indicado y sale con
un código pensado para un script: `0` si no hay ninguno, `1` si hay `error`,
`2` si hay `critical` — igual criterio que `product:doctor` y `kiosk:health`.
Con `--json` da lo mismo en formato máquina; con `--source=` acota a un
origen.

`product:errors:prune` es la purga manual — la programada corre sola a diario
a las 03:35 UTC y no pide confirmación, porque es una purga técnica de datos
sin valor legal (RL-11), no la retención del registro horario (RF-PR-03, que
sí exige confirmación explícita: `operacion.md` §3). `--dry-run` solo cuenta,
no borra nada; sin él, borra las filas con `last_seen_at` anterior a
`ERROR_HISTORY_RETENTION_DAYS` días.

---

## 6. Correlacionar con el log técnico, si lo conservas

Si tienes el perfil de observabilidad encendido (`COMPOSE_PROFILES=observability`,
`operacion.md` §10), cada fila trae un `trace_id`. Búscalo en Loki/Grafana
para ver la petición completa —la traza distribuida, no solo el mensaje
saneado que se guardó en `error_events`—. Si lo tienes apagado, o si el log ya
rotó (90 días también ahí), el `trace_id` copiado no encuentra nada: no es un
fallo, es que ese registro ya no existe donde lo buscas.

---

## 7. Marcar como resuelto, y por qué se reabre

Márcalo cuando la causa esté corregida y no antes: no hay ninguna urgencia
por «vaciar» la bandeja, y una fila resuelta a la ligera **vuelve a abrirse
sola** en cuanto se repite (§3), con el mismo recuento de `occurrences` que
ya llevaba. Verlo reabrirse es la señal de que el arreglo no fue tal — no un
error del panel.

Un acceso de soporte con alcance `diagnostics` o `read_only` puede **leer**
esta pantalla pero no puede resolver nada (§8 de `operacion.md`, alcances):
resolver es una decisión sobre tu instalación, no sobre el diagnóstico.

---

## 8. Qué viaja a soporte, y cuándo escalar al fabricante

El paquete de diagnóstico (`operacion.md` §12) incluye, **anonimizado por
defecto**, el resumen por origen y nivel más los grupos del periodo —hasta
500, por `last_seen_at`— con su `trace_id`. Es lo primero que pide soporte al
abrir una incidencia, y es lo único que necesita en la mayoría de los casos
(runbook del fabricante,
[`incidencia-sin-acceso.md`](incidencia-sin-acceso.md), §4.4). No hace falta
copiar filas a mano ni hacer capturas de la tabla.

Escala a soporte del fabricante cuando:

- El mensaje saneado o el «qué hacer» del panel no encajan con ninguna fila
  de la §4 de este documento.
- Un `critical` de `api` o `kiosk` persiste después de que `product:doctor`
  no encuentre nada y hayas descartado lo obvio (red, certificado, disco).
- `occurrences` crece a un ritmo que no explica el uso normal de la
  instalación — puede ser un caso que el paquete de diagnóstico todavía no
  cubre bien, y es justo el tipo de hallazgo que el fabricante quiere ver.

No hace falta conceder un acceso de soporte para esto: el paquete basta. Si
soporte pide más, sigue [`incidencia-sin-acceso.md`](incidencia-sin-acceso.md)
§6 antes de conceder nada.

---

## 9. A quién se escala, y en cuánto

| Situación | A quién | En cuánto |
| --- | --- | --- |
| `critical` en `api` sobre `/scan*`, persistente | Tú mismo primero (`product:doctor`, disco, base de datos); soporte si no se resuelve | Mismo turno |
| `critical` en `scheduler` que afecta a la copia o a la auditoría | Tú mismo; sigue el runbook que corresponda a la tarea concreta | El mismo día |
| Una fila que, al leerla, resulta ser una escritura fuera de la aplicación | Responsable de seguridad — no es esta guía: [`rotura-cadena-auditoria.md`](rotura-cadena-auditoria.md) | Inmediato |
| Ninguna de las filas de la §4 explica lo que ves | Soporte del fabricante, con el paquete de diagnóstico (§8) | 1 día hábil |

**Relacionados:** [`../cliente/operacion.md`](../cliente/operacion.md) §12 ·
[`alta-nuevo-quiosco.md`](alta-nuevo-quiosco.md) ·
[`divergencia-proyeccion.md`](divergencia-proyeccion.md) ·
[`restaurar-backup.md`](restaurar-backup.md) ·
[`incidencia-sin-acceso.md`](incidencia-sin-acceso.md)
