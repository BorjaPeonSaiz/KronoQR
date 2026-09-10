# Runbook — un quiosco deja de mandar señales

**Alertas que llevan aquí** (doc 01 §9.3, fila *«Quiosco sin latido | > 10 min |
Crítica (operaciones)»*), definida en
[`infra/observability/prometheus/rules/kiosk.yml`](../../infra/observability/prometheus/rules/kiosk.yml):

| Alerta | Umbral | Severidad | Destinatario | Sección |
| --- | --- | --- | --- | --- |
| `QuioscoSinLatido` | `time() - kiosk_last_seen_seconds > 600`, `for: 5m` | Crítica (operaciones) | IT del cliente | [§2](#2-diagnóstico-en-dos-minutos) |

**A las 06:30, quien la reciba hace esto:** abre `kiosk:health`, mira qué
quiosco figura como `FALLO` y desde cuándo; si la tablet está encendida y con
red, espera un ciclo de latido mientras revisa la VLAN; si está apagada o
rota, atiende el punto físicamente y, si hace falta sustituirla, sigue
[`alta-nuevo-quiosco.md`](alta-nuevo-quiosco.md) §5.

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

### 2.1 La consola, que es la segunda red de seguridad

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

### 2.2 ¿Llega tráfico de esa tablet al servidor?

```bash
docker compose -f infra/compose.prod.yaml logs --tail 200 nginx | grep '/api/v1/kiosk/heartbeat'
```

- **Aparecen peticiones recientes de otras tablets pero ninguna de la
  señalada**: el problema está entre la tablet y el borde — red, VLAN o la
  tablet misma. Ve a §3.
- **No aparece tráfico de ningún quiosco**: el problema es del servidor, no
  de una tablet en concreto. Comprueba `docker compose ps` y
  `/api/v1/ready` antes de seguir con este runbook.

### 2.3 La VLAN de quioscos

```bash
grep KIOSK_VLAN_CIDR .env
```

Si la IP de la tablet cae **fuera** de `KIOSK_VLAN_CIDR`, el borde no le
niega el paso — le aplica el límite pensado para tráfico de internet, 30
peticiones por minuto en vez de 600 (doc 02 §7.1) — y el síntoma no es un
error visible: es una tablet que va lenta y a veces se queda sin latido en
hora punta. Detalle completo y comprobación con `curl` en
[`../cliente/instalacion.md`](../cliente/instalacion.md) §6.

### 2.4 La propia tablet

Si el paso 2.2 confirma que no llega nada de ella, el último tramo es
físico: ¿tiene corriente?, ¿está encendida la pantalla?, ¿el wifi del punto
de montaje sigue emitiendo? El apartado «Qué hacer si… la tablet no
encuentra el servidor» de
[`alta-nuevo-quiosco.md`](alta-nuevo-quiosco.md) §6 trae la tabla completa —
DNS, ruta, certificado — para cuando el problema no es solo «está apagada».

---

## 3. Causas, por frecuencia, y qué hacer con cada una

| Causa | Cómo se confirma | Qué hacer |
| --- | --- | --- |
| **La tablet está apagada o sin corriente** | La pantalla no enciende | Restablece la alimentación. Si el corte fue del hotel entero, revisa el arranque automático (`alta-nuevo-quiosco.md` §2.2): al volver la luz, la PWA tiene que reaparecer sola |
| **Wifi caído en ese punto, o la tablet salió de cobertura** | §2.2 sin tráfico de esa tablet; otras del mismo wifi también sin latido | Revisa el punto de acceso de esa zona. Si varias tablets del mismo AP fallan a la vez, es la red, no los dispositivos (anti-fatiga: la agrupación por `device` no oculta el patrón, solo evita cinco avisos separados) |
| **La tablet quedó fuera de `KIOSK_VLAN_CIDR`** | §2.3 | Corrige el DHCP o el rango en el `.env` (instalación, no urgencia de madrugada) |
| **El token del quiosco fue revocado** | La tablet muestra sola la pantalla de emparejamiento | No es un fallo de red: alguien la desvinculó, o rotó su token. Vuelve a emparejarla — [`alta-nuevo-quiosco.md`](alta-nuevo-quiosco.md) §3 — con el **mismo nombre** para conservar su historia |
| **Certificado TLS que la tablet dejó de aceptar** | Aviso de sitio no seguro en la propia tablet | [`renovacion-certificado-tls.md`](renovacion-certificado-tls.md) |
| **El servidor está caído o `/ready` falla** | §2.2 sin tráfico de ningún quiosco | No es este runbook: revisa `docker compose ps`, PostgreSQL y Redis primero |
| **La tablet se ha roto o se ha perdido** | No enciende, o ha desaparecido físicamente | [`alta-nuevo-quiosco.md`](alta-nuevo-quiosco.md) §5: sustitúyela con el mismo nombre. Si se ha extraviado, desvincúlala ya, sin esperar a que la cola se vacíe — y avisa a RRHH: los fichajes que la tablet tuviera en cola se pierden y hay que reconstruirlos por corrección manual (RN-13, `FALLO_TECNICO_QUIOSCO`) |

---

## 4. Si el quiosco tenía fichajes pendientes

Antes de tocar el dispositivo o de desvincularlo, comprueba su cola:

```bash
docker compose -f infra/compose.prod.yaml exec -T app php artisan kiosk:health
```

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
| El recuento de `kiosk:health` no coincide con el de la alerta (§2.1) | IT del cliente: revisar si Redis se vació recientemente | Dentro de la jornada |
| Tablet extraviada o robada con fichajes en cola | RRHH: hay que reconstruir esas horas por corrección manual (`FALLO_TECNICO_QUIOSCO`) | El mismo día |

**Relacionados:** [`alta-nuevo-quiosco.md`](alta-nuevo-quiosco.md) ·
[`cola-offline-atascada.md`](cola-offline-atascada.md) ·
[`renovacion-certificado-tls.md`](renovacion-certificado-tls.md) ·
[`../cliente/instalacion.md`](../cliente/instalacion.md) §6.
