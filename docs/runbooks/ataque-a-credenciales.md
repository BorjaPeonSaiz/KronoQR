# Runbook — ataque a credenciales (fuerza bruta / credential stuffing)

**Alertas que llevan aquí** (OWASP A09, doc 02 §8.2), definidas en
[`infra/observability/prometheus/rules/auth.yml`](../../infra/observability/prometheus/rules/auth.yml):

| Alerta | Umbral | Severidad | MITRE ATT&CK | Sección |
| --- | --- | --- | --- | --- |
| `KronoqrAuthFailureBurst` | > 20 fallos en 5 min por canal, `for: 1m` | Advertencia | T1110 (Brute Force) | [§4](#4-diagnóstico) |
| `KronoqrAuthLockouts` | ≥ 3 bloqueos en 15 min por canal, `for: 1m` | Advertencia | T1110.004 (Credential Stuffing) | [§4](#4-diagnóstico) |
| `KronoqrAuthFailureSpike` | > 100 fallos en 5 min por canal, `for: 1m` | Crítica | T1110 (posible T1110.004) | [§4](#4-diagnóstico) |

**Impacto en el fichaje, que es lo primero que hay que saber: ninguno.** Estas
tres alertas nunca impiden fichar. `KronoqrAuthLockouts` sí puede significar
que **una o varias personas concretas** no pueden entrar al panel, al portal o
fichar por PIN hasta que expire su bloqueo — eso es RS-12 y RN-15 funcionando
como deben, no una avería que arreglar. El fichaje por QR, que es el camino que
usa el 99 % de la plantilla en el quiosco, no pasa por ninguna de las tres
puertas de este runbook y sigue intacto pase lo que pase aquí.

Destinatario: **responsable de seguridad**, con el mismo criterio que
[`rotura-cadena-auditoria.md`](rotura-cadena-auditoria.md) — la respuesta a un
intento de comprometer credenciales es una decisión de alcance y de si hace
falta rotar algo, no un reinicio. El bloqueo en el borde de la §5 sí lo
ejecuta materialmente el IT del cliente, con seguridad informado.

---

## 1. Qué hay montado, en 30 segundos

Tres puertas de autenticación, un único contador:

| Canal | Qué protege | Requisito |
| --- | --- | --- |
| `management` | Panel de gestión (correo y contraseña) | RF-ID-01 |
| `portal` | Portal del empleado (código y PIN) | RF-ID-06 |
| `kiosk_pin` | Fichaje de respaldo por PIN en el quiosco | RF-AT-11 |

```
kronoqr_auth_attempts_total{channel, outcome}
```

`outcome` es `success`, `failure` o `lockout` — **tres valores disjuntos, no
acumulativos**: el intento que **abre** un bloqueo cuenta como `lockout` —uno
por bloqueo abierto, no uno por intento rechazado— y deja además su asiento
`auth.lockout_started` en `audit_log`; **todo lo demás que no acaba en
sesión cuenta como `failure`**, incluido el intento que llega con un bloqueo ya
activo y ni siquiera llega a comprobar la credencial. La frontera exacta la
fija `App\Modules\Shared\Domain\ValueObject\AuthOutcome` y es deliberada: si
`lockout` contara cada intento rechazado mientras el bloqueo dura, una sola
persona insistiendo contra una sola cuenta llegaría a tres en segundos y
`KronoqrAuthLockouts` sería ruido desde el primer día (ver la cabecera de esa
clase). Contado así, además, `lockout` casa uno a uno con los asientos
`auth.lockout_started`: dos formas de contar el mismo hecho que no pueden
divergir. Nueve series en total, y ninguna etiqueta identifica a nadie (regla
dura 21): no hay `employee_uuid`, cuenta ni IP en la métrica.

La escribe `Shared\Infrastructure\Metrics\RedisAuthenticationMetrics` en cada
intento, sobre Redis (`HINCRBY`), y la sirve `/metrics` de la aplicación
(tarea 3.1; hasta entonces el contador se acumula sin que Prometheus lo
scrapee — si estas alertas nunca disparan y sospechas que deberían, comprueba
primero si `kronoqr-api` está `UP` en Prometheus).

---

## 2. Qué NO hace este mecanismo, y por qué importa aquí

- **No bloquea el fichaje por QR.** Solo protege login por contraseña, PIN de
  portal y PIN de respaldo del quiosco.
- **Un fallo nunca escribe en `audit_log`.** Cada asiento de auditoría toma el
  candado global de ADR-010 —el mismo por el que pasa cada fichaje— y un
  ataque de fuerza bruta es justo el tráfico que más fallos produce: auditar
  cada intento convertiría una intrusión en curso en una degradación del
  registro horario, que es lo único que nunca puede ceder. Los fallos van al
  **log estructurado y al contador**, nunca a la cadena de hash.
- **Los bloqueos sí se auditan, en los tres canales.** `auth.lockout_started`
  es la excepción: el actor es el sistema (o el dispositivo, en el quiosco),
  nunca la persona que falló, así que no hay conflicto con el candado.
- **El origen tiene una representación por almacén** (ADR-039). Los
  asientos `auth.*` de `audit_log` guardan la IP en su columna `ip`, como los
  demás escritores de la tabla, que vive en el servidor del cliente y nunca
  sale de él. El log estructurado —que sí viaja al fabricante dentro del
  paquete de diagnóstico— guarda `ip_hash`, un HMAC-SHA-256 con clave derivada
  de `APP_KEY` (ver §4.3): el fabricante no puede reconstruir nada; la
  instalación puede recalcularlo para cruzar el log con `audit_log`.

---

## 3. Falso positivo: cómo descartarlo antes de escalar

`kiosk_pin` es el canal con más fallos legítimos con diferencia: dedos fríos a
las 06:00, un turno nuevo que aún no se sabe el PIN de memoria. Antes de tratar
`KronoqrAuthFailureBurst` en `kiosk_pin` como un incidente:

- Mira si el pico coincide con un **cambio de turno** (06:00, 14:00, 22:00) en
  un **único dispositivo**. Un `channel="kiosk_pin"` con fallos concentrados en
  la ventana de fichaje de un solo quiosco, sin bloqueos, es casi siempre gente
  equivocándose de PIN, no un ataque.
- `KronoqrAuthLockouts` es mucho más difícil de confundir: RS-12 ya protege
  cada cuenta por separado, así que hacen falta **varias personas distintas**
  fallando lo bastante para bloquearse en la misma ventana de 15 minutos. Eso
  no lo produce un turno con prisa.
- `KronoqrAuthFailureSpike` (> 100 en 5 min) no lo produce nadie tecleando.
- **Un reenvío idempotente de la cola offline del quiosco puede duplicar
  `failure` en `kiosk_pin` tras un corte de red.** `RegisterPinScanHandler`
  vuelve a abrir el sobre y a verificar el PIN (y por tanto vuelve a escribir
  en `kronoqr_auth_attempts_total`) **antes** de que `RegisterScanHandler`
  compruebe la idempotencia por `scan_id` (ADR-008, regla dura 8): la
  idempotencia protege `scan_events` y `daily_totals` — nunca se duplica un
  tramo ni una jornada —, pero no protege el contador de intentos de
  autenticación. Si el sobre de un PIN incorrecto queda encolado por un corte
  de red y el quiosco lo reintenta al reconectar (comportamiento normal de la
  cola de ADR-008: reintentos con *backoff* hasta confirmación explícita),
  cada reintento suma un `failure` nuevo por el mismo intento real de la
  persona — `auth.login_failed` no lleva `scan_id` (§4.2: solo `channel`,
  `subject_uuid`, `ip_hash`, `reason`, `trace_id`), así que el reenvío no se
  ve como duplicado ahí. Antes de escalar un `KronoqrAuthFailureBurst` en
  `kiosk_pin`: comprueba si el pico coincide con la reconexión de un
  dispositivo tras un corte (latido del quiosco), y compara el número de
  `auth.login_failed` de la ventana con el número de filas rechazadas en
  `scan_events` (`origin = 'pin_kiosk'`) del mismo periodo — como `scan_id`
  es `UNIQUE`, cada reenvío solo puede dejar **una** fila ahí; si el log tiene
  más entradas que filas de rechazo en `scan_events`, es la firma de este
  reenvío duplicando el contador, no de varias personas fallando.

---

## 4. Diagnóstico

### 4.1 Cuánto, dónde y desde cuándo — la métrica

```bash
# Fallos por canal en la última hora, agrupados en ventanas de 5 min.
docker compose exec -T app curl -s http://localhost:9000/metrics 2>/dev/null | grep kronoqr_auth_attempts_total
# O, mejor, desde Prometheus (Grafana → Explore, o curl directo):
#   sum by (channel, outcome) (increase(kronoqr_auth_attempts_total[1h]))
```

Si `outcome="lockout"` está subiendo en varios canales a la vez, es más
probable que sea infraestructura compartida sondeando varias puertas que una
plantilla con mala suerte.

### 4.2 Qué mirar en los logs estructurados (Loki)

**No en `audit_log`: los fallos individuales no están ahí (§2).** Lo que hay
en Loki, con `channel`, `trace_id` e `ip_hash` en cada línea:

| Mensaje | Cuándo | Campos propios |
| --- | --- | --- |
| `auth.login_failed` | Cada fallo, en los tres canales | `reason`: `invalid_credentials`, `locked`, `sealed_pin_unreadable`, `session_not_issued` |
| `auth.lockout_started` | Cada vez que un bloqueo se activa | `lock_seconds` |
| `identity.portal_login_rejected` / `identity.portal_login_locked` | Duplican lo de arriba para el portal (telemetría previa a esta métrica) | `retry_after_seconds` en el bloqueo |

**`reason` es la pista que la respuesta HTTP nunca da** (regla dura 17, RS-03):
de puertas afuera, todo rechazo es un `401`/`422` idéntico. `reason` distingue
si el intento tenía forma de credencial real (`invalid_credentials`, la
sospechosa) de un sobre de PIN que no abrió (`sealed_pin_unreadable`, casi
siempre una tarjeta o un dispositivo defectuoso, no un ataque).

```bash
# Consulta en Loki (LogCLI o Grafana → Explore), fallos del ultimo periodo por canal:
logcli query --limit=500 '{channel="nginx.access"} |= "kronoqr_auth"' # ajusta el selector de tu instalacion de Loki
# O, mas directo si el stream tiene el nombre del mensaje como campo:
logcli query --limit=500 '{app="kronoqr"} | json | msg="auth.login_failed"'
```

(El selector exacto de Loki depende de cómo `infra/observability/loki/loki.yaml`
etiquete el stream en esta instalación; si no lo sabes, empieza filtrando por
`channel` en Grafana → Explore, que lista las etiquetas disponibles.)

### 4.3 De cuántos orígenes distintos viene — recalcular `ip_hash`

`ip_hash` es `HMAC-SHA256(APP_KEY derivada, IP)`, truncado a 16 hex
(`Compliance\Infrastructure\Audit\ClientAddressPseudonym`). No se puede
invertir, pero la instalación —que tiene `APP_KEY`— puede **recalcularlo para
un candidato** y comparar:

```bash
docker compose exec -T app php artisan tinker --execute='
  $key = hash_hkdf("sha256", base64_decode(substr(config("app.key"), 7)), 32, "kronoqr:auth:client-address");
  echo substr(hash_hmac("sha256", "203.0.113.7", $key), 0, 16), PHP_EOL;
'
```

Sustituye `203.0.113.7` por cada IP candidata (de los accesos de Nginx, de la
VPN, de la IP pública del hotel) y compara el resultado con los `ip_hash` que
ves en Loki para esa ventana. Coinciden byte a byte si es el mismo origen.
Con esto se responde lo que A09 exige poder responder: **¿cuántos orígenes
distintos hay detrás de estos fallos?** y **¿el acceso correcto que vino
después fue desde el mismo sitio que los fallos previos?** — esta segunda
pregunta es la que separa "alguien tardó tres intentos en acertar su propia
contraseña" de "alguien probó credenciales robadas hasta acertar una".

### 4.4 Quién se bloqueó — `audit_log`

```bash
docker compose exec -T postgres psql -U fichaje_migrator -d fichaje -c \
  "SELECT occurred_at, actor_type, actor_id, subject_type, subject_id, payload
     FROM audit_log
    WHERE action = 'auth.lockout_started'
      AND occurred_at > now() - interval '2 hours'
    ORDER BY occurred_at DESC"
```

`subject_type`/`subject_id` es `user` (con `users.id`) en `management`, y en
`portal`/`kiosk_pin` es el tipo sin identificador — el UUID del empleado, si se
conocía, va en `payload->>'employee_uuid'`. Si `KronoqrAuthLockouts` disparó,
esta consulta dice **exactamente cuántas cuentas distintas** se bloquearon:
eso es lo que confirma o descarta credential stuffing (T1110.004), que por
definición prueba contra muchas identidades a la vez.

---

## 5. Cómo bloquear un origen en Nginx

**No toques `limit_req_zone` ni las zonas declarativas del §7.1
(`infra/docker/nginx/templates/kronoqr.conf.template`).** Son configuración de
instalación, no un mecanismo de respuesta a incidentes, y tocarlas a mano deja
el fichero versionado desincronizado del contenedor en marcha hasta el próximo
despliegue.

**Opción preferida — cortarlo en el firewall del host, antes de que llegue a
Nginx.** No requiere entrar al contenedor, no se pierde al reiniciar el
contenedor de Nginx (si el host tiene `iptables`/`ufw` persistente) y no
interfiere con la config declarativa:

```bash
# Bloquea el origen en el host donde corre el contenedor de Nginx.
sudo iptables -I DOCKER-USER -s 203.0.113.7 -j DROP
# Para quitarlo cuando termine el incidente:
sudo iptables -D DOCKER-USER -s 203.0.113.7 -j DROP
```

`DOCKER-USER` y no `INPUT`: es la cadena que Docker respeta para tráfico hacia
contenedores publicados, así que el bloqueo aplica al puerto que publica
Nginx sin que el propio Docker lo puentee.

**Si hace falta bloquear DENTRO de Nginx** (por ejemplo, para responder con un
código concreto en vez de cortar la conexión, o si no hay acceso al firewall
del host): la imagen de Nginx de este producto **no monta un fichero de
bloqueo editable desde fuera** (`infra/compose.prod.yaml` solo monta el
certificado TLS). El único camino es editar el contenedor en marcha, y es
**temporal a propósito** — no sobrevive a un `docker compose up` que recree el
contenedor:

```bash
docker compose exec nginx sh -c \
  'echo "deny 203.0.113.7;" >> /etc/nginx/conf.d/kronoqr.conf'
docker compose exec nginx nginx -t   # valida antes de recargar
docker compose exec nginx nginx -s reload   # recarga SIN caida (RNF de despliegue)
```

`nginx -s reload` no interrumpe conexiones en curso ni provoca una parada: es
el mismo mecanismo con el que Nginx aplica cualquier cambio de configuración
en producción.

**Esto es un parche de emergencia, no una solución.** Registra el `deny` que
añadiste (con fecha y motivo) para poder quitarlo cuando el incidente se
cierre, y si el bloqueo tiene que sobrevivir más de unas horas, es una señal
de que hace falta una regla persistente de verdad (firewall del host,
`KIOSK_VLAN_CIDR`/`PORTAL_INTERNAL_CIDR` mal configurados dejando pasar tráfico
que no debería, o directamente contactar al proveedor de conectividad del
hotel si el origen es externo y reincidente).

---

## 6. Cuándo rotar

No todo intento fallido exige rotar nada: RS-12 ya contuvo el intento. Rota
cuando puedas responder que sí a alguna de estas:

| Pregunta | Si la respuesta es sí | Cómo |
| --- | --- | --- |
| ¿Alguna cuenta tuvo un `success` **después** de una racha de `failure`/`lockout` desde el mismo `ip_hash` (§4.3)? | La contraseña o el PIN de esa cuenta puede estar comprometido, no solo tanteado | Forzar cambio de contraseña (`management`) o restablecer el PIN desde el panel — el mismo flujo de RF-ID-09, auditado como `pin.reset` |
| ¿El origen es interno (VLAN de quioscos, red del hotel) y no se explica por nadie del equipo? | Puede haber un dispositivo comprometido dentro de la red de confianza | Revisar el emparejamiento de quioscos (`docs/runbooks/alta-nuevo-quiosco.md`) y considerar revocar y remplazar el token de ese dispositivo |
| ¿`KronoqrAuthFailureSpike` sostenido más de una hora sin que el bloqueo en Nginx (§5) lo frene? | El atacante rota de IP o va detrás de un proxy compartido | No hay credencial que rotar por esto solo: es la señal de subir el bloqueo a nivel de red (proveedor, VPN) |
| ¿Sospecha fundada de que `APP_KEY` pudo filtrarse (no solo de que alguien tanteó contraseñas)? | Rotar `APP_KEY` invalida también todos los `ip_hash` anteriores — no vuelven a coincidir con los nuevos que se generen | Procedimiento completo en `docs/runbooks/rotacion-secretos.md` (§7.7 doc 02) — **no la rotes por una racha de fallos sin más**: es la clave que también protege el sellado del PIN del quiosco y su rotación no es gratis |

**Rotar por rotar no es gratis.** Forzar el cambio de contraseña de toda una
plantilla porque un canal tuvo un pico de fallos genera más ruido operativo
del que resuelve, y RS-12 ya limita el daño de cualquier intento que no haya
tenido éxito.

---

## 7. Escalado

| Situación | A quién | En cuánto |
| --- | --- | --- |
| `KronoqrAuthFailureBurst` | Responsable de seguridad | Dentro de la jornada |
| `KronoqrAuthLockouts` | Responsable de seguridad | Dentro de la jornada; inmediato si afecta a `management` |
| `KronoqrAuthFailureSpike` | Responsable de seguridad **y** IT del cliente (para el bloqueo de §5) | Inmediato |
| Confirmado un `success` tras fallos sospechosos (§6) | Responsable de seguridad + persona titular de la cuenta | Inmediato |

**El fabricante no accede a los datos del cliente** (ADR-020, regla dura 16).
Los logs estructurados de este runbook se pueden compartir con el fabricante
solo dentro del paquete de diagnóstico anonimizado; `ip_hash` no identifica a
nadie sin `APP_KEY`, así que viaja bien en ese paquete, pero `subject_id`/
`employee_uuid` de `audit_log` no.
