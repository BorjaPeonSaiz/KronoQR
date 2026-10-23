# Runbook — la cola offline de un quiosco no se vacía

**Alertas que llevan aquí** (doc 01 §9.3, fila *«Cola offline de un dispositivo
| > 50 elementos o > 2 h | Alta»*), definidas en
`infra/observability/prometheus/rules/kiosk.yml`:

| Alerta | Umbral | Severidad | Destinatario | Sección |
| --- | --- | --- | --- | --- |
| `ColaOfflineAtascada` | `kiosk_offline_queue_size > 50`, `for: 5m` | Alta | IT del cliente | [§2](#2-diagnóstico) |
| `ColaOfflineSinVaciar` | la cola no ha bajado a 0 en las últimas 2 h, `for: 5m` | Alta | IT del cliente | [§2](#2-diagnóstico) |
| `KioskQueueStorageDegraded` | `kiosk_queue_storage_degraded == 1`, `for: 10m` | Alta | IT del cliente | [§7](#7-almacenamiento-de-la-cola-degradado-kioskqueuestoragedegraded) |
| `KioskUnreportedDiscards` | `kiosk_unreported_discards > 0`, `for: 30m` | Aviso | IT del cliente | [§8](#8-descartes-sin-avisar-kioskunreporteddiscards) |
| `ScanBatchItemNotProcessed` | `increase(scan_batch_items_not_processed_total[30m]) >= 3`, `for: 5m` | Aviso | IT del cliente | [§9](#9-un-elemento-del-lote-que-no-se-procesa-scanbatchitemnotprocessed) |
| `KioskDiscardedScansAttributed` | `increase(kiosk_discarded_scans_total{attributed="true",source!="server"}[1h]) > 0`, `for: 0m` | Aviso | IT del cliente | [§10](#10-descartes-atribuidos-en-un-quiosco-kioskdiscardedscansattributed) |
| `ScanDiscardedByServer` | `increase(kiosk_discarded_scans_total{source="server"}[15m]) > 0`, `for: 0m` | Aviso | IT del cliente | [§11](#11-descartes-del-servidor-scandiscardedbyserver-y-scandiscardbudgetexhausted) |
| `ScanDiscardBudgetExhausted` | `increase(kiosk_scan_discard_budget_exhausted_total[15m]) > 0`, `for: 0m` | Crítica | IT del cliente | [§11](#11-descartes-del-servidor-scandiscardedbyserver-y-scandiscardbudgetexhausted) |

**A las 06:30, quien la reciba hace esto:** mira `kiosk:health` para ver de
qué quiosco es y cuánto lleva creciendo; si el resto de la instalación
funciona, es casi siempre red o certificado en ese punto concreto — sigue
§3; si el servidor entero no responde, es la sonda del borde
(`SondaDelBordeFallida`), y el procedimiento es otro (§3, última fila). Si la
red va bien y aun así la cola no llega a cero nunca, es el caso de §4.

---

## 1. Qué significa esto, y qué no significa

`kiosk_offline_queue_size{device}` es el número de fichajes que un quiosco
tiene **guardados en su tablet** esperando a que el servidor los confirme. Lo
publica el propio latido (`pending_queue_size`, cada 60 s) y **no es una cola
del servidor**: vive dentro de la aplicación de la tablet, en su
almacenamiento local, exactamente igual que cuando no hay red en absoluto
(regla dura 19). Que este número crezca no dice que el quiosco esté fallando
al fichar — al contrario, dice que **está haciendo justo lo que tiene que
hacer** cuando algo le impide hablar con el servidor: seguir aceptando
tarjetas, confirmar en pantalla y guardar.

**Lo que sí dice es que ese algo lleva un rato sin resolverse.** Dos lecturas
del mismo hecho, con umbrales distintos a propósito:

- `ColaOfflineAtascada` — hay **mucho** acumulado (más de 50 elementos): algo
  se ha cortado hace poco pero de forma intensa, o lleva ya un rato.
- `ColaOfflineSinVaciar` — la cola **no ha bajado a cero ni una sola vez en
  dos horas**, aunque el número absoluto no sea alarmante. Es la lectura
  correcta de un problema intermitente: la tablet sincroniza un poco, se
  vuelve a cortar, sincroniza otro poco… y nunca llega a vaciarse del todo.

**Lo que no significa: que se esté perdiendo ningún fichaje.** Cada entrada
de la cola conserva su hora real (`occurred_at`), no la hora en la que por
fin llegue al servidor (`recorded_at`, regla dura 9). Cuando la tablet
recupera la conexión, los envía en lote y el registro legal queda exactamente
igual que si hubieran llegado al instante — con la salvedad de que, si la
ventana fue larga, aparecerá una incidencia de sincronización en la bandeja,
que no es un fallo: es el sistema diciendo que hubo una ventana.

**Desde esta versión eso vale también para el caso raro**: el fichaje que, por
el orden en que llegó, no puede encajar en ninguna jornada **queda registrado y
marcado para revisión** en lugar de reintentarse sin fin. Es el §4.

---

## 2. Diagnóstico

### 2.1 De qué quiosco es, y desde cuándo

```bash
docker compose exec -T app php artisan kiosk:health
```

La columna **cola pendiente** y el **veredicto** (`aviso` con cola
distinta de cero) dan el dispositivo. El panel muestra lo mismo desde
**Quioscos**, si está accesible.

### 2.2 ¿Es solo ese quiosco, o son varios?

Si **un solo** quiosco tiene cola y el resto de la instalación va bien, el
problema es local a ese punto: red del sitio, certificado que dejó de
aceptar, o el propio dispositivo. Ve a §3.

Si **varios quioscos a la vez** acumulan cola, sospecha del servidor antes
que de las tablets: revisa si `SondaDelBordeFallida` también está sonando
(§1 de este documento, última fila de la tabla de alertas) o si hay un
despliegue o una migración en curso —`docker compose run --rm --no-deps -T migrate php artisan
migrate:status --database=pgsql_migrator`—. Durante una actualización (`update.sh`), **es lo
esperado**: los quioscos encolan mientras dura la ventana de mantenimiento y
vacían solos al final ([`actualizacion-cliente.md`](actualizacion-cliente.md)
§3).

### 2.3 Confirmar que sigue creciendo, o que ya está bajando

```bash
docker compose exec -T app php artisan kiosk:health --json | jq '.devices[] | {name, pending_queue_size, verdict}'
```

Repite la orden un par de minutos después. Si el número **baja**, la tablet
ya está sincronizando y no hace falta ninguna acción sobre ella — solo
esperar y, si procede, cerrar la causa de raíz para que no se repita.

---

## 3. Causas, por frecuencia, y qué hacer con cada una

| Causa | Cómo se confirma | Qué hacer |
| --- | --- | --- |
| **Muchos fichajes por PIN en la cola** (no es una avería) | La cola **baja**, pero despacio: unos 10 por minuto. Pasa tras un corte de red en una tablet donde se ha fichado mucho por PIN | Nada: esperar. Los fichajes por PIN viajan de uno en uno —no tienen envío por lotes— y el servidor admite como mucho 10 por minuto y por tablet (`KIOSK_PIN_SCAN_RATE_PER_DEVICE`), el freno contra quien prueba PIN a ciegas. **40 PIN tardan unos 4 minutos**, y lo que se fichó detrás espera su turno con su hora real. No subas ese límite para acelerarlo ([`../cliente/operacion.md`](../cliente/operacion.md) §16.3 bis) |
| **Corte de red en ese punto** | Sin tráfico de esa tablet en `docker compose logs nginx` | Revisa el wifi de esa zona. Cuando vuelva, la tablet sincroniza sola — no hay nada que forzar |
| **Token de dispositivo caducado o revocado** | La tablet muestra la pantalla de emparejamiento en vez de la de fichaje | **Antes de volver a emparejar, comprueba la cola con `kiosk:health` desde la consola**: si es distinta de cero, no desvincules todavía — ver la advertencia de §5 |
| **Borde caído** (`SondaDelBordeFallida`) | Varios quioscos con cola a la vez; `/api/v1/ready` no responde | No es un problema de la tablet. Revisa `docker compose ps`, PostgreSQL y Redis; el runbook de esa alerta es [`errores-en-el-panel.md`](errores-en-el-panel.md) |
| **Redis caído** | `docker compose ps redis` no está `healthy`; `/api/v1/ready` da 503; el panel y el portal fallan o piden acceso de nuevo, pero el quiosco sigue fichando | **El fichaje no se detiene**: el limitador de peticiones falla abierto solo en `scan`, `scan-batch` y `scan-pin`, y la cola de la tablet baja con normalidad. El resto de la API (panel, portal, acceso) **sí** depende de Redis y no funcionará hasta que vuelva, así que no lo tomes por un problema de las tablets. Arranca Redis (`docker compose up -d redis`) y vigila que `product:doctor` vuelva a verde |
| **Reloj de la tablet desincronizado** | Los fichajes llegan con `occurred_at` muy distinto de la hora real al sincronizar, aunque la cola sí baje | No bloquea nada (regla dura 19: un desfase de reloj tampoco frena el fichaje) ni es esta alerta en sí, pero conviene corregir la hora del sistema Android de esa tablet para que los partes de incidencia no confundan a quien los lea después |
| **Certificado TLS que la tablet dejó de aceptar** | Aviso de sitio no seguro en la propia tablet | [`renovacion-certificado-tls.md`](renovacion-certificado-tls.md) |
| **La tablet lleva sin latido más de 10 min** | `QuioscoSinLatido` también está activa | Es la misma causa raíz vista desde dos ángulos: sigue [`quiosco-no-responde.md`](quiosco-no-responde.md) primero — si recupera el latido, la cola empieza a vaciarse sola |
| **Un elemento que jamás podrá cuadrar** | La cola no baja **aunque la red vaya bien**, la tablet late y el resto de la instalación funciona; en el panel, el «más antiguo» de la columna **Pendientes** se queda clavado en la misma hora un día tras otro | §4 de este documento. **Desde esta versión se resuelve solo**: si sigue pasando, lo que falta es actualizar el servidor |

---

## 4. Un elemento que jamás podrá cuadrar

### 4.1 El síntoma, que no se parece a los demás

Todas las causas de §3 tienen algo en común: son **transitorias**. Vuelve la
red, se renueva el certificado, se levanta el borde, y la cola se vacía sola.
Esta no.

Se reconoce por descarte, y el cuadro es siempre el mismo:

- La tablet **late con normalidad** (`kiosk:health` no la da por sin señal).
- El resto de la instalación **va bien** y otras tablets no acumulan nada.
- La cola **no llega a cero nunca**, aunque suba y baje.
- Y lo que lo confirma: en el panel, **«Pendientes» dice siempre la misma hora**
  en «el más antiguo de…», día tras día. No es una cola que crece: es **un
  elemento concreto que no sale**.

`ColaOfflineSinVaciar` es la alerta que lo delata, precisamente porque mira que
la cola llegue a cero y no cuánto hay acumulado.

### 4.2 Qué está pasando por debajo

Un fichaje de esa cola llegó al servidor con una hora que **no cabe en el
registro de esa persona**. No es un error de formato ni un fallo de red. Ocurre
de dos maneras, con el mismo síntoma en la cola y la misma resolución:

- **Una salida anterior —o igual— a la entrada del turno que ya estaba
  abierto**: un tramo que habría terminado antes de empezar.
- **Una entrada que cae dentro o antes de un tramo ya cerrado**: la persona
  fichó 09:00–13:00 en otra tablet y esta cola trae una entrada de las 08:00.
  Serían dos tramos pisándose el mismo rato.

Ninguna hora calculada a partir de ese fichaje sería cierta, así que el sistema
no la calcula.

**Antes de esta versión**, el servidor lo trataba como un error temporal y la
tablet —que está diseñada para no rendirse jamás con un fichaje, regla dura
19— lo reintentaba indefinidamente: unos 288 intentos al día, para siempre. La
cola nunca bajaba a cero, las dos alertas sonaban sin ninguna causa de red que
encontrar, y **el fichaje no quedaba registrado en ningún sitio**: ni se
procesaba ni se guardaba.

**Desde esta versión** el servidor lo reconoce y hace tres cosas:

1. **Lo guarda** con su hora tal y como llegó, marcado para revisión. Deja de
   ser un fichaje perdido y pasa a ser un dato del registro.
2. **Responde a la tablet «recibido, no lo reintentes»** — el mismo código de
   respuesta (`422`) con el que ya se descartan los escaneos rechazados—, así
   que la tablet **lo borra de su cola** y sigue con el resto.
3. **Abre una incidencia** «Fichaje fuera de orden» en la bandeja de RRHH en la
   revisión de la madrugada siguiente.

### 4.3 Qué hace IT con esto: nada sobre la tablet

**No es una avería, ni del servidor ni del quiosco, y no hay nada que tocar en
la tablet.** Lo que queda es una decisión de negocio —qué horas trabajó de
verdad esa persona— y la toma RRHH con una corrección firmada, no IT con un
comando.

Lo único que corresponde a IT es mirar **si se repite siempre en el mismo
punto**. Si una tablet concreta genera estas incidencias con frecuencia, tiene
un problema de red o de hora: revisa el enlace de esa zona y la hora del
sistema Android del dispositivo (fila «Reloj de la tablet desincronizado» de
§3).

Lo que sí conviene es **avisar a RRHH** de que va a aparecer la incidencia y de
que corresponde al corte de red de tal día, con el nombre del quiosco: el
procedimiento que ellos siguen está en
[`../cliente/guia-rrhh.md`](../cliente/guia-rrhh.md) §4.4. Si te preguntan dónde
ver la hora del fichaje: **está en la propia incidencia**, al pulsar «Resolver»,
junto con el identificador del escaneo. En el registro horario no aparece — esa
pantalla enseña tramos, no escaneos.

### 4.4 Un quiosco que sigue atascado tras actualizar el servidor

Es el caso que trae a este documento a quien ya tenía la cola envenenada antes
de la actualización.

**Actualizar el servidor basta.** No hay que actualizar la tablet, ni
desvincularla, ni borrar sus datos —eso sí perdería fichajes reales (§5)—. La
decisión la toma el servidor, no la aplicación de la tablet, y **cualquier
versión desplegada del quiosco vacía ese elemento en cuanto recibe la respuesta
nueva**.

Qué esperar, en orden:

1. Termina la actualización ([`actualizacion-cliente.md`](actualizacion-cliente.md)).
2. La tablet vuelve a intentarlo por su cuenta. El reintento se espacia hasta un
   **máximo de 5 minutos**, así que no hay que esperar más de eso desde que el
   servidor vuelve a responder.
3. La cola baja y llega a cero. Compruébalo con el mismo comando de §2.1:

   ```bash
   docker compose exec -T app php artisan kiosk:health
   ```

4. `ColaOfflineSinVaciar` se apaga sola en cuanto la cola toca cero.
5. A la madrugada siguiente aparece la incidencia en la bandeja de RRHH.

**Si la cola no baja tras esos cinco minutos**, no es este caso: vuelve a §3 y
empieza por la red y el certificado de ese punto.

**Avisa a RRHH de que la incidencia va a llevar fecha antigua.** Lo que decide
si un fichaje entra en la revisión es **cuándo llega al servidor**, no de cuándo
es su hora; pero la incidencia se abre sobre **la jornada a la que pertenece esa
hora real**. Un elemento que llevaba dos semanas atascado produce, en la
revisión de esta madrugada, una incidencia fechada hace dos semanas. No es un
reproceso del histórico ni un fallo de la actualización: es el fichaje llegando
por fin.

---

## 5. Qué NO hacer

- **No desvincules el quiosco con la cola sin vaciar.** Al revocar el token,
  la tablet **descarta lo que tenía pendiente**: son fichajes de personas
  reales que ya habían pasado su tarjeta, y se pierden de verdad — no quedan
  en ningún sitio a la espera. Espera a que `kiosk:health` marque la cola a
  cero, o asume conscientemente la pérdida y avisa a RRHH de que habrá que
  reconstruir esas horas por corrección manual.
- **No borres los datos de la aplicación en la tablet** («borrar caché»,
  «borrar almacenamiento», restablecer de fábrica) antes de confirmar que la
  cola está a cero. Es la misma pérdida que desvincular, por otra vía.
- **No reinicies la tablet repetidamente esperando que «se arregle sola»**
  sin mirar antes si es un problema de red del lado del servidor: un
  reinicio no cambia nada si el borde está caído, y cada reinicio es una
  ventana más sin punto de fichaje.
- **No subas el umbral de la alerta (50 elementos o 2 h)** para que deje de
  sonar en un quiosco con tráfico alto. Si un punto concreto genera de forma
  habitual más de 50 fichajes en cola durante una ventana normal, es una
  señal de que ese enlace de red necesita revisión, no de que el umbral esté
  mal puesto.

---

## 6. Escalado

| Situación | A quién | En cuánto |
| --- | --- | --- |
| Un quiosco con cola, causa de red identificada y en vías de resolverse | IT del cliente | Dentro de la jornada |
| Varios quioscos con cola a la vez, o `SondaDelBordeFallida` activa | IT del cliente — revisar el servidor antes que las tablets | Inmediato |
| Cola que no baja tras resolver la causa aparente | **Descarta primero §4**; si no es eso, soporte del fabricante con el paquete de diagnóstico | El mismo día |
| Incidencias «Fichaje fuera de orden» repetidas en el mismo quiosco (§4) | RRHH las resuelve; IT revisa la red y la hora de ese punto | Dentro de la semana |
| Se desvinculó un quiosco con cola pendiente por error | RRHH: hay que reconstruir las horas perdidas por corrección manual | El mismo día |

---

## 7. Almacenamiento de la cola degradado (`KioskQueueStorageDegraded`)

### Qué significa

La tablet no ha podido abrir o seguir usando el almacenamiento local del
navegador (IndexedDB) y guarda la cola **en memoria** (`memory`) o no tiene
dónde guardarla (`unavailable`) (RN-21). El quiosco **sigue fichando** (regla
dura 19), pero lo que se encole mientras dure **se pierde si la tablet o la
aplicación se reinician**, y por eso su tamaño de cola es **desconocido**, no
cero: la aplicación deja de publicar `kiosk_offline_queue_size` para ese
dispositivo.

**Por eso `ColaOfflineAtascada` y `ColaOfflineSinVaciar` callan en este caso**:
no hay serie que evaluar. No es un fallo de esas alertas; este es el aviso que
las sustituye. No lo tomes por «la cola está vacía».

### Cómo comprobarlo en el panel

En **Quioscos** (RF-PA-07), el dispositivo aparece con salud de **fallo**,
razón `queue_storage_degraded`, y la cola pendiente como **«desconocido»**. En
Grafana, panel «Cola degradada y descartes sin avisar» del cuadro *Operación de
quioscos*. Desde la consola:

```bash
docker compose exec -T app php artisan kiosk:health --json | jq '.devices[] | {name, queue_storage, pending_queue_size, verdict}'
```

### Qué hacer

1. **Con la red bien y el quiosco sin colas pendientes que proteger**: recarga
   la aplicación (PWA) de la tablet. Si el almacenamiento vuelve, el siguiente
   latido declara `durable` y la alerta se apaga sola.
2. **Si sigue en `memory`/`unavailable`**: libera espacio en la tablet
   (aplicaciones y descargas ajenas) y comprueba que el navegador no está en
   modo privado/invitado. Recarga de nuevo.
3. **No borres los datos de la aplicación ni reinicies la tablet** mientras
   pueda haber fichajes solo en memoria: se pierden (§5). Si el servidor es
   alcanzable, espera a que sincronice y confirma con `kiosk:health` que el
   latido ya no declara cola pendiente antes de intervenir.
4. Si no se recupera, sustituye la tablet siguiendo
   [`alta-nuevo-quiosco.md`](alta-nuevo-quiosco.md) **después** de vaciar la
   cola.

## 8. Descartes sin avisar (`KioskUnreportedDiscards`)

### Qué significa

El servidor rechazó un fichaje de esa tablet con un `400`/`422` que no es un
rechazo normal de tarjeta, y la tablet lo **descartó de su cola** para no
bloquear el orden (RN-21). Debe **avisar** al servidor para que RRHH reciba la
incidencia `discarded_scan` (RN-22), y lleva más de 30 minutos sin lograrlo
(sin red hacia ese endpoint, aplicación desactualizada, aviso rechazado). El
contador `kiosk_unreported_discards{device}` son los descartes pendientes de
avisar. **Hasta que se avise, nadie en RRHH ve ese fichaje.**

### Cómo comprobarlo en el panel

En **Quioscos** (RF-PA-07), la razón `discards_unreported` y el número de
descartes pendientes por dispositivo. Mismo panel de Grafana que §7.

### Qué hacer

1. Comprueba la conexión de esa tablet (§2 y §3): si hay red, el aviso se
   reenvía solo y la alerta se apaga.
2. Confirma que la versión de la aplicación del quiosco es la actual
   (`kiosk:health`, columna versión); una PWA vieja con un servidor nuevo
   puede seguir avisando, pero recarga la aplicación si lleva días abierta.
3. Cuando el aviso llega, el servidor abre (en la revisión de la madrugada
   siguiente) la incidencia **`discarded_scan`** en la bandeja de RRHH, si
   puede atribuirla a una persona. **Revisa que aparezca**; la decisión sobre
   las horas la toma RRHH ([`../cliente/guia-rrhh.md`](../cliente/guia-rrhh.md)).
   El contador `kiosk_discarded_scans_total{device,attributed}` indica cuántos
   se han recibido y cuántos se atribuyeron.
4. **No vacíes la tablet ni la desvincules** con descartes sin avisar: son los
   únicos que existen de esos fichajes (§5).

Esta alerta no contiene ni debe llevar datos de personas: usa el dispositivo y
los recuentos; el detalle está en la incidencia.

### Si es el propio aviso el que el servidor rechaza con 400

Caso menos frecuente: la tablet sí llega al servidor, pero `POST /api/v1/scan/discarded`
responde `400` porque el aviso no cumple el contrato (`scan_id` UUID v7,
`occurred_at` en UTC con `Z`, `problem_type` con el patrón `urn:kronoqr:problem:*`;
el quiosco ya normaliza `problem_type` a nulo si no lo cumple). Se reconoce
porque **`unreported_discards` no baja aunque haya red y la versión sea la
correcta**. Qué hacer:

1. Mira los `error_events` de ese dispositivo: busca `kiosk.offline.discard_report_failed`
   con `http_status` 400.
2. Comprueba la versión de la PWA de la tablet y actualízala si está desfasada.
3. Si el aviso sigue sin entrar, **anota a mano el fichaje** desde la pantalla
   de diagnóstico de la tablet (RF-KI-08 muestra los descartados: `scan_id` y
   hora) y pásalo a RRHH **antes de desvincular o limpiar la tablet** (§5):
   es el único sitio donde existe.


## 9. Un elemento del lote que no se procesa (`ScanBatchItemNotProcessed`)

### Qué significa

Al sincronizar, la tablet envía su cola en lote y el servidor **falló al
procesar un elemento** (un error del servidor, no un rechazo de tarjeta). La
alerta salta con 3 o más en 30 min para un mismo dispositivo. Desde RN-21 ese
fichaje **no se salta**: los demás fichajes de ese quiosco **esperan detrás**
en su cola. No se pierde nada, pero nada de esa tablet llega al registro hasta
que se corrige la causa. La cola crecerá y es probable que `ColaOfflineAtascada`
suene también.

### Qué mirar

Busca en el log de la aplicación el evento `attendance.batch_scan_failed`; trae
el `scan_id` (UUID, sin datos personales) y el error del servidor que hay
detrás:

```bash
docker compose logs --since 1h app | grep attendance.batch_scan_failed
```

Con ese error (base de datos, bloqueo, restricción, versión) sabrás si es un
fallo de la instalación o un fichaje con un caso no previsto.

### Qué hacer

1. **Corrige la causa en el servidor** (despliegue, migración, base de datos).
   Si no es evidente, escala a soporte con el paquete de diagnóstico y el
   `scan_id`.
2. **Nunca borres la cola de la tablet ni la desvincules** (§5): contiene
   fichajes reales, incluido el que falla y los que esperan detrás.
3. En cuanto el servidor lo procese, la tablet vacía sola; comprueba con
   `kiosk:health` y espera a que la alerta se apague.

## 10. Descartes atribuidos en un quiosco (`KioskDiscardedScansAttributed`)

### Qué significa

En la última hora, ese quiosco ha avisado de fichajes que descartó y que el
servidor **ha atribuido a una persona** (`kiosk_discarded_scans_total{attributed="true"}`,
RN-22). Que lo haga un solo quiosco mientras los demás no es la firma de dos
cosas: **una PWA desfasada** que genera fichajes que el servidor no acepta, o
**un token de dispositivo robado** que fabrica avisos contra personas. Cada
aviso atribuido acaba en una incidencia «Fichaje descartado por el quiosco» de
RRHH, así que no es ruido.

### Qué comprobar

1. **Versión de la PWA de ese dispositivo**: `php artisan kiosk:health` (columna
   versión) o «Salud de quioscos». Si es anterior a la del resto de la
   instalación, es el caso habitual: actualízala siguiendo
   [`actualizacion-cliente.md`](actualizacion-cliente.md) (cola a 0 primero).
2. **Las incidencias «Fichaje descartado por el quiosco» de ese día** en la
   bandeja de RRHH, filtradas por ese dispositivo (el contexto trae su
   `device_uuid`): ¿encajan con fichajes reales de ese hotel y horario, o son
   horas y personas que no pasaron por esa tablet?
3. **¿Debería ese quiosco estar enviando?** Si está en una ubicación y turno
   donde nadie ficha, o no sabes dónde está físicamente, trátalo como token
   robado.

### Qué hacer

- **Versión desfasada**: actualiza la tablet; la alerta se apaga sola al pasar
  la hora sin nuevos descartes. RRHH resuelve las incidencias ya abiertas.
- **El quiosco no es el que debería estar enviando**: **desvincúlalo** desde
  «Quioscos» en el panel. Revoca el token **en el acto**. Antes comprueba su
  cola si es una tablet real y localizada (§5: se descartan los pendientes); si
  no la tienes, no esperes. Avisa a RRHH de que las incidencias de ese
  dispositivo pueden ser falsas, y escala al responsable de seguridad.
- Nunca borres datos de una tablet real con pendientes (§5).

## 11. Descartes del servidor (`ScanDiscardedByServer` y `ScanDiscardBudgetExhausted`)

### Qué significa

Desde la 2.3.0 (ADR-061, RN-22), si el servidor falla **el mismo fichaje** al
menos 10 veces durante al menos 60 minutos, por una causa que no es una base
de datos o un Redis caídos, lo **descarta** para que la cola de esa tablet
siga. El fichaje **no entra en el registro**; el empleado no notó nada, porque
la tablet se lo confirmó al fichar (regla 19). Cada descarte es **un defecto del
producto** que hay que reproducir; no es un problema de la instalación.

| Alerta | Qué dice | Qué hacer primero |
| --- | --- | --- |
| `ScanDiscardedByServer` (aviso) | Hubo un descarte en los últimos 15 min | Conservar la evidencia (abajo), avisar a RRHH y abrir caso con el fabricante. Nada urgente en la cola |
| `ScanDiscardBudgetExhausted` (crítica) | El quiosco ya tiene 3 descartes del servidor en 24 h y ha aparecido otro fichaje que fallaría: **no se descarta y la cola vuelve a atascarse a propósito** | Tratarlo como una avería del servidor, no del fichaje: ver «Cupo agotado» |

### Qué ve RRHH

- Si el servidor supo de quién era el fichaje, una incidencia **«Fichaje
  descartado»** (una por persona y jornada) con el origen `servidor` y el número
  de intentos. Es la misma incidencia de RN-22 que abre el aviso de un quiosco.
- Si la tarjeta no se pudo atribuir por su firma, el servidor busca a su titular
  por la **huella del contenido** en fichajes aceptados anteriores de esa misma
  tarjeta (atribución `fingerprint_match`) y abre la misma incidencia.
- Si tampoco así, una incidencia **sin persona** para el administrador, una por
  quiosco y jornada, con el quiosco y la hora. Crúzala con el cuadrante.

### Cómo se corrige, con trazabilidad

El descarte nunca crea, cierra ni toca un tramo (regla 5). Si la persona
trabajó, RRHH crea el tramo desde la incidencia con una **corrección con motivo**
(RN-13): queda la versión nueva con autor, momento y motivo. **No se reprocesa
el fichaje original** ni se edita nada en la base de datos: el servidor no
guarda su contenido a propósito, y reenviarlo tras arreglar el defecto daría un
fichaje doble con la corrección. El descarte queda en `audit_log` como
`scan.discarded` (actor `system`) y en `scan_events` con
`result = discarded_unprocessable`. Cualquier reenvío del mismo `scan_id`
devuelve el mismo `422 scan-discarded`.

### Evidencia que hay que conservar antes de abrir el caso

```bash
docker compose -f infra/compose.prod.yaml logs --since 2h app | grep attendance.scan_discarded_after_failures
docker compose -f infra/compose.prod.yaml exec postgres psql -U kronoqr -c \
  "SELECT scan_id, device_id, origin, exception_class, failed_at FROM scan_processing_failures ORDER BY failed_at DESC LIMIT 30;"
```

Trae el `scan_id`, el quiosco, los intentos, el primer fallo y la **clase** de
la excepción, sin mensaje ni nombres. `scan_processing_failures` es una tabla
técnica (se purga sola a los 30 días); sale en el paquete de diagnóstico solo
como recuentos.

### Cupo agotado (`ScanDiscardBudgetExhausted`)

Si ya hubo 3 descartes en 24 h en ese quiosco y sigue apareciendo uno más, lo
probable es que **el servidor esté roto para todos** (una actualización, una
migración, un esquema), no que lo estén esos fichajes. Por eso el servidor
deja de descartar y la cola de esa tablet **espera**, igual que en §9, sin
perder nada.

1. Comprueba si ha habido un despliegue o una migración en las últimas horas
   (`docker compose -f infra/compose.prod.yaml logs --since 24h migrate`) y el
   `ScanBatchItemNotProcessed` de §9 en los demás quioscos.
2. Si hay una versión anterior buena, **vuelve atrás**
   ([`actualizacion-cliente.md`](actualizacion-cliente.md)); si no, escala al
   fabricante con el paquete de diagnóstico y los `scan_id` de arriba.
3. **No subas `SCAN_DISCARD_DAILY_BUDGET` ni lo pongas a 0 para «desatascar»** sin
   que lo pida el fabricante: es el freno que evita vaciar el registro en
   descartes, uno por hora. Los números (`SCAN_DISCARD_MIN_ATTEMPTS`,
   `SCAN_DISCARD_MIN_MINUTES`, `SCAN_DISCARD_DAILY_BUDGET`) viven en el `.env`.
4. Nunca borres ni desvincules la tablet (§5): su cola es el registro.

**Si aun así se reempareja la tablet como dispositivo nuevo** (avería, sustitución):
un fichaje envenenado que quedara en su cola **deja de sumar intentos** a su
historia, porque los intentos solo cuentan con el mismo quiosco que el primero
(ADR-061 §1). Vuelve a empezar desde cero: más espera antes de un descarte, nunca
un descarte de más. Falla hacia lo seguro; no hay que hacer nada.

**Relacionados:** [`quiosco-no-responde.md`](quiosco-no-responde.md) ·
[`alta-nuevo-quiosco.md`](alta-nuevo-quiosco.md) ·
[`renovacion-certificado-tls.md`](renovacion-certificado-tls.md) ·
[`actualizacion-cliente.md`](actualizacion-cliente.md) ·
[`errores-en-el-panel.md`](errores-en-el-panel.md) ·
[`../cliente/guia-rrhh.md`](../cliente/guia-rrhh.md) §4.4.
