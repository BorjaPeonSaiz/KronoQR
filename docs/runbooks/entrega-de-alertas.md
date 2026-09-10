# Runbook — Alertmanager no responde, o las alertas no llegan

**Alertmanager se vigila a sí mismo.** Las diez alertas de este catálogo no
sirven de nada si el propio servicio que las envía está caído o no consigue
entregar — es el hueco que cerró la revisión de seguridad de la tarea 3.2
(decisión 17c): sin esto, una copia fallando **y** un correo que rebota a la
vez habrían dejado a alguien sin saberlo.

**Alertas que llevan aquí**, definidas en
[`infra/observability/prometheus/rules/alerting.yml`](../../infra/observability/prometheus/rules/alerting.yml):

| Alerta | Umbral | Severidad | Destinatario | Sección |
| --- | --- | --- | --- | --- |
| `EnrutadoDeAlertasCaido` | `up{job="alertmanager"} == 0` (o la serie ni existe), `for: 5m` | Crítica | IT del cliente | [§3](#3-alertmanager-no-responde) |
| `EntregaDeAlertasFallando` | `increase(alertmanager_notifications_failed_total[15m]) > 0`, `for: 5m` | Alta | IT del cliente | [§4](#4-alertmanager-responde-pero-no-entrega) |

**A las 06:30, quien la reciba hace esto:** `docker compose ps alertmanager`;
si está caído o reiniciando, mira sus registros — el renderizador muere con
un mensaje que **nombra la variable** que falló, así que casi nunca hace
falta adivinar. Si está en pie pero `EntregaDeAlertasFallando` suena,
comprueba el SMTP y el webhook del destinatario que corresponda y haz una
entrega de prueba (§4.2).

---

## 1. Qué significa esto, y qué no significa

**No es una alerta sobre el registro horario.** Ninguna de las dos describe
un fallo del fichaje, de la copia o de la auditoría — describen que el
**canal** por el que te enterarías de esos fallos está roto. Es la razón por
la que este runbook no es de los 20 del catálogo de la carpeta: no responde
a una fila del doc 01 §9.3, responde a un modo de fallo de la propia
infraestructura de alertas (§8.4 del doc 02, decisión 17c).

**`EnrutadoDeAlertasCaido` es la más grave de las dos**: si Alertmanager no
responde, **ninguna** alerta de esta instalación llega a nadie, aunque
Prometheus siga evaluando reglas con normalidad. Es indistinguible de «todo
va bien» mirando solo el correo.

**`EntregaDeAlertasFallando` es más sutil.** Alertmanager está vivo y
recibiendo alertas de Prometheus, pero al intentar entregarlas —por correo o
por webhook— algo falla: el SMTP del hotel rechaza la conexión, la
contraseña rotó y nadie actualizó el `.env`, el servicio del webhook
responde con un error. **Puede que esta alerta tampoco te llegue por
correo**, si lo que está roto es justamente el correo — por eso el mismo
dato está también en el cuadro «Salud de la API» de Grafana y en la propia
interfaz de Alertmanager (§2), que no dependen del canal que ha fallado.

---

## 2. Impacto en el fichaje: ninguno, directo

El fichaje no pasa por Alertmanager en ningún momento: los quioscos hablan
con la API, no con la infraestructura de observabilidad. Lo que está en
juego es que un fallo **real** del sistema —una copia que no se hizo, la
cadena de auditoría rota, un quiosco sin latido— no llegue a quien tiene que
actuar sobre él. Por eso, aunque el impacto directo sea nulo, el destinatario
es IT del cliente y conviene resolverlo el mismo día: cada minuto que
Alertmanager está caído es un minuto en el que cualquier otra alerta de esta
instalación es ruido que nadie escucha.

---

## 3. Alertmanager no responde

### Diagnóstico

```bash
docker compose ps alertmanager
docker compose logs --tail 100 alertmanager
```

`render-config.sh` es el punto de entrada del contenedor: renderiza
`alertmanager.yml` desde la plantilla y **antes de arrancar el binario real**
valida el resultado con `amtool check-config`. Si algo está mal, el
contenedor no llega a levantar Alertmanager y se reinicia en bucle — pero el
mensaje en el registro dice exactamente qué variable falló, no solo que algo
falló:

```
render-config.sh: ALERT_EMAIL_IT contiene un salto de linea o un retorno de
carro. No se admite: rompe el YAML renderizado y podria usarse para
inyectar una clave que nadie autorizo. Revisa el .env: seguramente una
variable quedo mal citada.
```

```
render-config.sh: ALERT_MAINTENANCE_WEEKDAY='0' no es un dia valido
(monday..sunday, en ingles y en minusculas). Corrigelo en el .env.
```

```
render-config.sh: ALERT_MAINTENANCE_START='2:00' no tiene forma HH:MM con
HH entre 00 y 23. Corrigelo en el .env.
```

```
render-config.sh: amtool check-config ha rechazado
/alertmanager/alertmanager.yml (ver el motivo arriba). No se arranca
Alertmanager con una configuracion que la propia herramienta de
verificacion no acepta.
```

### Causas, por frecuencia

| Causa | Cómo se confirma | Qué hacer |
| --- | --- | --- |
| Un valor de `ALERT_*` o `MAIL_*` mal escrito en el `.env` (comilla suelta, copia y pega con un salto de línea) | El mensaje del registro nombra la variable | Corrígela en el `.env` y `docker compose up -d alertmanager` |
| `ALERT_MAINTENANCE_WEEKDAY` con un número o en otro idioma | El mensaje del registro lo dice literalmente | Usa `monday`…`sunday`, en inglés y minúsculas (`configuracion.md` §6.20) |
| `ALERT_MAINTENANCE_START`/`END` sin la forma `HH:MM` | Íd | Corrige el formato; la hora es la del **servidor**, no la del centro |
| El contenedor no arranca por un problema ajeno (memoria, disco, imagen) | `docker compose logs` no muestra ninguno de los mensajes de arriba | Trátalo como cualquier otro contenedor caído: `docker compose ps`, espacio en disco ([`espacio-en-disco.md`](espacio-en-disco.md)) |

### Resolución

```bash
# Después de corregir el .env:
docker compose up -d alertmanager
docker compose logs --tail 20 alertmanager   # debe terminar sin ningun "render-config.sh:"
```

No hay vuelta atrás que ejecutar: `render-config.sh` no escribe nada si la
validación falla, así que no hay ningún estado a medias que deshacer. En
cuanto el `.env` es correcto, el contenedor arranca a la primera.

---

## 4. Alertmanager responde, pero no entrega

### 4.1 Diagnóstico

```bash
docker compose ps alertmanager   # tiene que estar "healthy" o "running"
docker compose logs --tail 100 alertmanager | grep -i 'error\|fail'
```

La propia interfaz de Alertmanager (`http://127.0.0.1:9093` por el túnel de
§4.2) muestra, para cada alerta activa, el **error exacto de su última
entrega** por cada integración (`{{ $labels.integration }}`: `email` o
`webhook`) — no hace falta adivinarlo desde el registro del contenedor.

Comprueba el transporte de correo, que es el mismo SMTP que usa el resumen
nocturno de incidencias (`configuracion.md` §6.21): `MAIL_HOST`, `MAIL_PORT`,
`MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_SCHEME`. Un fallo típico: la
contraseña del buzón rotó por política del hotel y nadie actualizó
`MAIL_PASSWORD` — el correo del resumen nocturno también habría empezado a
fallar por la misma causa, así que si ves esta alerta, revisa también si
`operacion.md` §3 (el resumen de incidencias) ha dejado de llegar.

Si usas webhook (`ALERT_WEBHOOK_IT`/`RRHH`/`SEGURIDAD`), comprueba que el
servicio de destino responde:

```bash
curl -si https://tu-webhook.ejemplo/endpoint -X POST -H 'Content-Type: application/json' -d '{}'
```

Una respuesta que no sea `2xx`, o que tarde más de lo que Alertmanager
espera, es lo que incrementa `alertmanager_notifications_failed_total`.

### 4.2 Prueba de entrega, sin esperar a la siguiente alerta real

Por túnel SSH, igual que para ver la interfaz (`operacion.md` §10.4):

```bash
ssh -L 9093:127.0.0.1:9093 tu-usuario@fichaje.tuhotel.local
```

Y desde tu propia máquina, con el túnel abierto:

```bash
curl -s -X POST http://127.0.0.1:9093/api/v2/alerts \
  -H 'Content-Type: application/json' \
  -d '[{
    "labels": {"alertname": "PruebaDeEntrega", "severity": "high", "destinatario": "it-cliente"},
    "annotations": {"summary": "Prueba manual de entrega, borrar tras confirmar"}
  }]'
```

O, desde el propio servidor, con `amtool` (vive en la misma imagen):

```bash
docker compose exec alertmanager amtool alert add \
  alertname="PruebaDeEntrega" severity="high" destinatario="it-cliente" \
  --annotation=summary="Prueba manual de entrega, borrar tras confirmar"
```

Si el correo o el webhook de IT llegan, el canal funciona y el fallo
registrado era puntual — revisa igualmente el motivo antes de descartarlo.
Si no llega, el problema está confirmado en ese canal concreto: repite la
prueba cambiando solo el receptor (`destinatario="rrhh"` o
`destinatario="seguridad"`) para saber si es uno solo o los tres.

### 4.3 Qué vigilar mientras el canal está roto

Mientras se resuelve, las alertas **siguen disparándose y quedan
registradas**; lo único que falla es la notificación activa. Dos sitios que
no dependen del canal roto:

- **Prometheus**, por el mismo túnel de siempre (`127.0.0.1:9090`):
  `http://127.0.0.1:9090/alerts` lista todo lo que está `firing`, con
  independencia de si se entregó.
- **Grafana**, cuadro «Salud de la API»: la serie
  `alertmanager_notifications_failed_total` está ahí, y el resto de
  cuadros siguen reflejando el estado real de la instalación aunque nadie
  reciba el correo.

### 4.4 Resolución

No hay nada que «reiniciar» en Alertmanager para que las alertas pendientes
se reentreguen: en cuanto el canal vuelve a responder, el propio
Alertmanager reintenta solo con su política de reintentos. Corrige la causa
(§4.1) y confirma con una prueba de entrega (§4.2); no hace falta reiniciar
el contenedor salvo que el registro diga lo contrario.

---

## 5. Qué no hacer

- **No apagues el perfil `observability`** para que la alerta deje de
  sonar. Es exactamente lo contrario de lo que hace falta: sin el perfil,
  ni siquiera sabrías que las alertas no llegan.
- **No cambies `ALERT_MAINTENANCE_*` ni el resto de variables de alertas**
  a un valor cualquiera solo para que `render-config.sh` arranque. El
  mensaje del registro dice el valor correcto; poner algo que «pase» sin
  ser correcto puede dejar la ventana de mantenimiento mal configurada.
- **No dejes la prueba de entrega de §4.2 sin resolver de verdad.** Que la
  prueba llegue no dice nada del canal roto original si no averiguaste la
  causa: puede volver a fallar con la próxima rotación de la contraseña
  SMTP.

---

## 6. Escalado

| Situación | A quién | En cuánto |
| --- | --- | --- |
| `EnrutadoDeAlertasCaido`, causa identificada en el `.env` | IT del cliente | El mismo día |
| `EnrutadoDeAlertasCaido` por un problema del contenedor ajeno a la configuración | IT del cliente; soporte del fabricante si no se resuelve | El mismo día |
| `EntregaDeAlertasFallando` por el SMTP del hotel | IT del cliente, coordinando con quien administra el correo | El mismo día |
| `EntregaDeAlertasFallando` por un webhook de terceros | IT del cliente, con el proveedor de ese servicio | Según la urgencia del canal afectado (seguridad, primero) |
| Los dos canales de un mismo destinatario llevan más de un día sin funcionar | Escalar fuera del canal roto: llamar, no esperar a que se arregle solo | Inmediato |

**Relacionados:** [`../cliente/operacion.md`](../cliente/operacion.md) §10.4 ·
[`../cliente/configuracion.md`](../cliente/configuracion.md) §6.20 y §6.21 ·
[`espacio-en-disco.md`](espacio-en-disco.md).
