# Runbook — Reverb no responde: el panel en vivo pierde el tiempo real

**Alerta que lleva aquí**, definida en `observability/prometheus/rules/queue.yml`
(bloque 9 de la 2.3.0, hallazgo V3-CH-3):

| Alerta | Umbral | Severidad | Destinatario | Sección |
| --- | --- | --- | --- | --- |
| `ReverbSinRespuesta` | la tarea de presencia corre y no recibe respuesta de Reverb, `for: 5m` | Media | IT del cliente | [§3](#3-reverbsinrespuesta) |

**A las 06:30, quien la reciba hace esto:**

```bash
docker compose ps reverb
docker compose logs --tail 100 reverb
docker compose up -d reverb
```

## 1. Qué significa, y qué no

Reverb es el servidor WebSocket que empuja los cambios de presencia al panel
sin recargar la página. Cada minuto, la tarea `reporting:presence-metrics` le
pregunta cuántas conexiones tiene (`websocket_connections_active`). Si Reverb
no contesta, la serie **no se publica**: ausente no es cero (cero es «nadie
tiene el panel abierto», lo normal de madrugada).

**Impacto en el fichaje: ninguno.** Nadie deja de fichar ni se pierde un
registro. Lo que ocurre:

- el panel de presencia deja de actualizarse solo (**recargar la página da el
  dato correcto**; el panel cae a sondeo),
- cada difusión pendiente ocupa unos segundos al trabajador de colas
  (`horizon`) esperando a Reverb, y con muchos fichajes la cola crece: puede
  sonar también `ColaDeTrabajosAcumulada`
  ([`cola-de-trabajos-atascada.md`](cola-de-trabajos-atascada.md)).

Si la alerta no suena pero el panel no se actualiza solo, mira que la tarea de
presencia corre (`scheduler` en marcha, `./doctor.sh`): sin ella no hay latido
y la alerta no puede evaluarse.

La alerta se calla durante la ventana de mantenimiento y mientras `update.sh`
tiene el mantenimiento puesto.

## 2. Diagnóstico común

```bash
docker compose ps reverb
docker compose logs --tail 100 reverb
docker compose exec -T reverb wget -qO- http://127.0.0.1:8080/up
./doctor.sh
```

`/up` responde `{"health":"OK"}` si Reverb vive. El contenedor `reverb` lleva
un `healthcheck` con esa misma comprobación: `unhealthy` en `docker compose ps`
es esta situación.

## 3. `ReverbSinRespuesta`

1. **`reverb` parado o en bucle de reinicio:** `docker compose up -d reverb` y
   lee el registro. Causas habituales: el puerto interno ocupado, Redis caído
   ([`almacen-de-metricas-caido.md`](almacen-de-metricas-caido.md) §3), o las
   claves `REVERB_APP_ID`, `REVERB_APP_KEY` y `REVERB_APP_SECRET` cambiadas en
   el `.env` sin reiniciar `app`, `horizon` y `reverb`
   ([`rotacion-secretos.md`](rotacion-secretos.md)).
2. **`reverb` sano y la alerta sigue:** la aplicación no llega a él. Las
   variables `REVERB_HOST` y `REVERB_PORT` del `.env` deben apuntar al servicio
   `reverb` y al puerto 8080 de la red interna. Tras corregirlas:
   `docker compose up -d app horizon scheduler reverb`.
3. **El panel pierde la conexión solo en algunos puestos** y la alerta no suena:
   no es Reverb, es el borde (proxy o cortafuegos que corta los WebSocket).
   Comprueba que `/app` llega a `reverb` por el borde
   ([`errores-en-el-panel.md`](errores-en-el-panel.md)).

## 4. Verificación

```bash
docker compose exec -T prometheus wget -qO- 'http://localhost:9090/api/v1/query?query=websocket_connections_active'
```

Debe devolver una serie (con 0 conexiones también). La alerta se resuelve sola
a los pocos minutos. Abre el panel de presencia, haz un fichaje de prueba y
comprueba que la presencia se actualiza sin recargar.
