# Runbook — un quiosco deja de mandar señales

**Alertas que llevan aquí** (doc 01 §9.3, fila *«Quiosco sin latido | > 10 min |
Crítica (operaciones)»*), definida en
[`infra/observability/prometheus/rules/kiosk.yml`](../../infra/observability/prometheus/rules/kiosk.yml):

| Alerta | Umbral | Severidad | Destinatario | Sección |
| --- | --- | --- | --- | --- |
| `QuioscoSinLatido` | `time() - kiosk_last_seen_seconds > 600`, `for: 5m` | Crítica (operaciones) | IT del cliente | [§2](#2-diagnóstico-en-dos-minutos) |

**A las 06:30, quien la reciba hace esto:** abre **Quioscos** en el panel, mira
qué quiosco está en fallo, con qué razón y desde cuándo; si la tablet está
encendida y con red, espera un ciclo de latido mientras revisa la VLAN; si está
apagada o rota, atiende el punto físicamente y, si hace falta sustituirla,
sigue [`alta-nuevo-quiosco.md`](alta-nuevo-quiosco.md) §5.

---

## 1. Impacto en el fichaje — lo primero que hay que saber, y depende de un hecho

**Si la tablet está encendida, sigue funcionando y nadie se ha quedado sin
fichar.** «Sin latido» quiere decir que el quiosco no ha hablado con el
servidor en los últimos 10 minutos, no que no esté fichando: la regla dura 19
obliga a que el quiosco **nunca** bloquee al empleado, así que sigue
aceptando tarjetas, confirmando en pantalla y encolando en su almacenamiento
local aunque no tenga red. Cuando la vuelva a tener, envía todo lo acumulado
con la hora real de cada fichaje (`occurred_at`).

**Si la tablet está apagada, sin corriente o rota, sí hay impacto: nadie
puede fichar en ese punto mientras dure.** Es la diferencia entre «hay que
mirarlo hoy» y «hay que ir ahora mismo», y es la primera pregunta del
diagnóstico (§2): ¿la pantalla está encendida?

En los dos casos, la corrección de las horas de quien no pudo fichar se hace
después desde el panel con el motivo `FALLO_TECNICO_QUIOSCO` (RN-13): no se
inventa la hora, se pregunta.

**Anti-fatiga.** Un quiosco aislado que se reinicia y recupera el latido
antes de los cinco minutos del `for:` no llega a notificar; cinco a la vez sí
llegan, agrupados. Y si el aviso llega dentro de la ventana de mantenimiento
semanal declarada (`ALERT_MAINTENANCE_*`) o de la que abre `update.sh`
mientras dura una actualización, está silenciado a propósito — es el mismo
motivo por el que estas cuatro alertas de quiosco, junto con las de API,
certificado y disco, son justo las que se callan en esas ventanas y no las de
integridad, copia o auditoría.

---

## 2. Diagnóstico en dos minutos

### 2.1 La pantalla «Quioscos» del panel, que es por donde se empieza

**Panel → «Quioscos»** (`/devices`), rol administrador. En treinta segundos
dice casi todo. Mira, de la fila del quiosco señalado:

| Qué miras | Qué te dice |
| --- | --- |
| **El veredicto y su razón** | *Sin señal* y *Nunca ha dado señal* son fallo; *Latido tardío*, *Batería baja* y *Fichajes pendientes* son aviso, y cada uno lleva a un sitio distinto de este runbook |
| **«Hace X»** del último contacto | Cuánto lleva callado, medido con el reloj del servidor. Si son minutos, puede ser un latido perdido; si son horas, el punto lleva horas aislado |
| **Batería** | Un quiosco que se descarga **sin cargar** es un cargador desenchufado, y se arregla yendo al punto, no tocando el servidor. «No informa» no es un fallo: hay tablets que no publican el dato |
| **Pendientes y el más antiguo** | Si hay cola, **no desvincules nada** y ve al §4 antes de tocar la tablet |
| **Versión de la aplicación** | Una versión distinta de la de las demás explica comportamientos raros tras una actualización |

El panel, `kiosk:health` y la alerta usan **la misma regla y los mismos
umbrales** (`KIOSK_HEALTH_FRESH_WITHIN_SECONDS`,
`KIOSK_HEALTH_SILENT_AFTER_SECONDS`, `KIOSK_HEALTH_BATTERY_LOW_PERCENT`):
si los tres no dicen lo mismo del mismo quiosco, la explicación está en §2.2.
Detalle de la pantalla, columna a columna, en
[`../cliente/operacion.md`](../cliente/operacion.md) §16.

**Y antes de seguir, lo del §1:** que esté en fallo no significa que nadie
esté fichando ahí.

### 2.2 La consola, que es la segunda red de seguridad

```bash
docker compose -f infra/compose.prod.yaml exec -T app php artisan kiosk:health
```

Mira la fila del quiosco señalado: **último contacto** (relativo y en la zona
del centro) y **veredicto**. `FALLO` es más de 10 minutos sin latido — el
mismo umbral que la alerta, a propósito (`KIOSK_HEALTH_SILENT_AFTER_SECONDS`,
600 de serie): la consola y Prometheus tienen que decir lo mismo del mismo
quiosco.

**Por qué esto es una segunda red y no una redundancia superflua (decisión
10 de la tarea 3.2).** `QuioscoSinLatido` se calcula sobre
`kiosk_last_seen_seconds{device}`, una serie que vive **en Redis** — la
escribe cada latido con `HSET`, no con un contador (`RedisKioskMetrics`). Si
Redis se vacía — un despliegue, un `FLUSHALL`, una recuperación tras una
caída —, la serie de un quiosco que **ya estaba callado** desaparece hasta su
siguiente latido, que por definición no va a llegar: para ese quiosco
concreto, `QuioscoSinLatido` **no suena**, aunque siga sin dar señales.
`kiosk:health`, en cambio, lee `devices.last_seen_at` de PostgreSQL, que
Redis no toca. Por eso la ronda de la mañana (`endurecimiento.md`, lista
trimestral) incluye ejecutar este comando aunque no haya sonado ninguna
alerta, y por eso el cuadro «Operación de quioscos» muestra el recuento de
dispositivos emparejados frente al de series presentes en Prometheus: si
difieren, es exactamente este límite.

### 2.3 ¿Llega tráfico de esa tablet al servidor?

```bash
docker compose -f infra/compose.prod.yaml logs --tail 200 nginx | grep '/api/v1/kiosk/heartbeat'
```

- **Aparecen peticiones recientes de otras tablets pero ninguna de la
  señalada**: el problema está entre la tablet y el borde — red, VLAN o la
  tablet misma. Ve a §3.
- **No aparece tráfico de ningún quiosco**: el problema es del servidor, no
  de una tablet en concreto. Comprueba `docker compose ps` y
  `/api/v1/ready` antes de seguir con este runbook.

### 2.4 La VLAN de quioscos

```bash
grep KIOSK_VLAN_CIDR .env
```

Si la IP de la tablet cae **fuera** de `KIOSK_VLAN_CIDR`, el borde no le
niega el paso — le aplica el límite pensado para tráfico de internet, 30
peticiones por minuto en vez de 600 (doc 02 §7.1) — y el síntoma no es un
error visible: es una tablet que va lenta y a veces se queda sin latido en
hora punta. Detalle completo y comprobación con `curl` en
[`../cliente/instalacion.md`](../cliente/instalacion.md) §6.

### 2.5 La propia tablet, y su pantalla de diagnóstico

Si el paso 2.3 confirma que no llega nada de ella, el último tramo es
físico: ¿tiene corriente?, ¿está encendida la pantalla?, ¿el wifi del punto
de montaje sigue emitiendo? El apartado «Qué hacer si… la tablet no
encuentra el servidor» de
[`alta-nuevo-quiosco.md`](alta-nuevo-quiosco.md) §6 trae la tabla completa —
DNS, ruta, certificado — para cuando el problema no es solo «está apagada».

**Si la tablet enciende, pregúntale a ella.** Mantén pulsado **tres segundos
el reloj** de la pantalla de fichaje y teclea el **código de servicio** de la
instalación (8 a 12 dígitos; lo custodia tu IT y se pone en Panel → «Ajustes
operativos» — [`../cliente/operacion.md`](../cliente/operacion.md) §16.5). Si
la instalación no tiene código, la pantalla se abre directamente y lo dice en
su cabecera; **y también se abre sin código si esa tablet concreta no ha
recibido ningún latido posterior al momento en que se configuró** —una tablet
que lleva sin red desde antes no tiene forma de conocerlo—, que es justo la
situación de este runbook: no te extrañe y no lo trates como un fallo.
**Funciona sin red**, que es el caso que estás diagnosticando, y **no impide
fichar**: vuelve sola a la pantalla de fichaje a los dos minutos, y también con
el botón «Volver a fichar».

Filas que hay que mirar, por orden de utilidad en este runbook:

| Fila | Qué buscas |
| --- | --- |
| **Red** | Si dice «sin conexión», el problema es el wifi del punto (§3). Si dice que hay conexión pero el servidor no responde, es DNS, ruta o certificado: `alta-nuevo-quiosco.md` §6. **La pantalla late mientras está abierta** —manda uno al abrirse—, así que «servidor alcanzable» te está diciendo lo que pasa **ahora**, no lo que pasó la última vez; si se pone en verde mientras la miras, el quiosco acaba de recuperarse y lo verás en el panel en seguida. Y mira el **desfase de reloj**: un desfase grande no impide fichar, pero explica incidencias de hora |
| **Cola** | Cuántos fichajes hay sin enviar y de cuándo es el más antiguo. **Es la cifra que decide si puedes desvincular la tablet o no** (§4) |
| **Token** | Si la tablet aparece **sin token**, no es un problema de red: alguien la desvinculó o su credencial caducó, y hay que volver a emparejarla. La pantalla enseña ocho caracteres de la huella del token —nunca el token— para poder compararlo con el del panel |
| **Cámara** | Si los empleados se quejan de que «no lee», aquí están los tres avisos habituales: fondo difuminado, enfoque no continuo y resolución por debajo de 1280×720. No bloquean el fichaje; explican el síntoma. Cómo se corrige cada uno: `alta-nuevo-quiosco.md` §6 |
| **Versión** | Si no coincide con la del resto de tablets, la PWA se quedó atrás: recarga y comprueba |

La pantalla no enseña ningún nombre ni ningún fichaje: identifica por
dispositivo y muestra recuentos (regla dura 21).

---

## 3. Causas, por frecuencia, y qué hacer con cada una

| Causa | Cómo se confirma | Qué hacer |
| --- | --- | --- |
| **La tablet está apagada o sin corriente** | La pantalla no enciende | Restablece la alimentación. Si el corte fue del hotel entero, revisa el arranque automático (`alta-nuevo-quiosco.md` §2.2): al volver la luz, la PWA tiene que reaparecer sola |
| **Se quedó sin batería porque lleva horas sin cargar** | El panel avisó antes por **batería baja** (nivel bajando y «no carga», §2.1), y ahora está en fallo | Es el aviso que te habría ahorrado el fallo: ve al punto y mira el cargador, la regleta y el cable. Cuando vuelva a cargar, el aviso se retira solo. Una tablet que marca «no informa» no da este aviso: para ese modelo, el único síntoma es el fallo |
| **Wifi caído en ese punto, o la tablet salió de cobertura** | §2.3 sin tráfico de esa tablet; otras del mismo wifi también sin latido | Revisa el punto de acceso de esa zona. Si varias tablets del mismo AP fallan a la vez, es la red, no los dispositivos (anti-fatiga: la agrupación por `device` no oculta el patrón, solo evita cinco avisos separados) |
| **La tablet quedó fuera de `KIOSK_VLAN_CIDR`** | §2.4 | Corrige el DHCP o el rango en el `.env` (instalación, no urgencia de madrugada) |
| **El token del quiosco fue revocado** | La tablet muestra sola la pantalla de emparejamiento | No es un fallo de red: alguien la desvinculó, o rotó su token. Vuelve a emparejarla — [`alta-nuevo-quiosco.md`](alta-nuevo-quiosco.md) §3 — con el **mismo nombre** para conservar su historia |
| **Certificado TLS que la tablet dejó de aceptar** | Aviso de sitio no seguro en la propia tablet | [`renovacion-certificado-tls.md`](renovacion-certificado-tls.md) |
| **El servidor está caído o `/ready` falla** | §2.3 sin tráfico de ningún quiosco | No es este runbook: revisa `docker compose ps`, PostgreSQL y Redis primero |
| **La tablet se ha roto o se ha perdido** | No enciende, o ha desaparecido físicamente | [`alta-nuevo-quiosco.md`](alta-nuevo-quiosco.md) §5: sustitúyela con el mismo nombre. Si se ha extraviado, desvincúlala ya, sin esperar a que la cola se vacíe — y avisa a RRHH: los fichajes que la tablet tuviera en cola se pierden y hay que reconstruirlos por corrección manual (RN-13, `FALLO_TECNICO_QUIOSCO`) |

---

## 4. Si el quiosco tenía fichajes pendientes

Antes de tocar el dispositivo o de desvincularlo, comprueba su cola:

```bash
docker compose -f infra/compose.prod.yaml exec -T app php artisan kiosk:health
```

La misma cifra está en la columna «Pendientes» del panel —con la antigüedad
del más antiguo— y en la fila «Cola» de la pantalla de diagnóstico de la
tablet (§2.5), que es la única de las tres que sigue estando disponible si el
quiosco no tiene red.

Si `pendingQueueSize` no es cero, sigue
[`cola-offline-atascada.md`](cola-offline-atascada.md): **los fichajes no se
pierden** mientras la tablet no se desvincule ni se le borren los datos, pero
sí se pierden si se desvincula con la cola sin vaciar.

---

## 5. Qué no hacer

- **No inventes la hora de quien no pudo fichar.** Pregunta y corrige desde
  el panel con `FALLO_TECNICO_QUIOSCO` (RN-13).
- **No desvincules una tablet con cola pendiente** solo para que la alerta
  calle. Vacía la cola primero, o asume que esos fichajes se pierden y
  avisa a RRHH de que habrá que corregir a mano.
- **No borres los datos del sitio en la tablet** (caché, almacenamiento) para
  «resetear» el problema sin comprobar antes la cola: es lo mismo que perder
  los fichajes pendientes.
- **No dejes el código de servicio apuntado en la tablet ni cerca de ella.**
  Se teclea y ya está; si alguien más ha tenido que conocerlo para atender
  esta incidencia, cámbialo después desde el panel — llega a todas las
  tablets en menos de un minuto.
- **No subas `KIOSK_HEALTH_SILENT_AFTER_SECONDS` ni el umbral de la alerta**
  para que deje de sonar. Los dos tienen que cambiar juntos si de verdad hace
  falta (decisión 9 de la tarea 3.2, `../cliente/configuracion.md` §6.14), y
  cambiarlos sin motivo solo retrasa cuándo te enteras.

---

## 6. Escalado

| Situación | A quién | En cuánto |
| --- | --- | --- |
| Un quiosco sin latido, tablet encendida, causa identificada | IT del cliente | Dentro de la jornada |
| Tablet apagada o averiada en horario de fichaje | IT del cliente, in situ | Inmediato |
| Varios quioscos de la misma zona a la vez | IT del cliente — es la red, no las tablets | Inmediato |
| La tablet vuelve sola a la pantalla de emparejamiento y nadie la desvinculó | Responsable de seguridad del cliente | Inmediato — ver `alta-nuevo-quiosco.md` §6 |
| El recuento de `kiosk:health` no coincide con el de la alerta (§2.2) | IT del cliente: revisar si Redis se vació recientemente | Dentro de la jornada |
| Tablet extraviada o robada con fichajes en cola | RRHH: hay que reconstruir esas horas por corrección manual (`FALLO_TECNICO_QUIOSCO`) | El mismo día |

**Relacionados:** [`alta-nuevo-quiosco.md`](alta-nuevo-quiosco.md) ·
[`cola-offline-atascada.md`](cola-offline-atascada.md) ·
[`renovacion-certificado-tls.md`](renovacion-certificado-tls.md) ·
[`../cliente/instalacion.md`](../cliente/instalacion.md) §6.
