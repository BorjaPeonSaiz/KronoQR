# Runbook — Loki no responde: los registros técnicos no se están guardando

**Alerta que lleva aquí**, definida en `observability/prometheus/rules/observability.yml`
(bloque 9 de la 2.3.0, hallazgo V21-4-DV-4):

| Alerta | Umbral | Severidad | Destinatario | Sección |
| --- | --- | --- | --- | --- |
| `LokiSinRespuesta` | la sonda interna `/ready` de Loki falla, `for: 10m` | Media | IT del cliente | [§3](#3-lokisinrespuesta) |

**A las 06:30, quien la reciba hace esto:**

```bash
docker compose ps loki retention-guard
docker compose logs --tail 100 loki
docker compose up -d loki
```

## 1. Qué significa, y qué no

Loki guarda los **registros técnicos** de la aplicación (`app`, `horizon` y
`scheduler`) para poder diagnosticar un incidente después de que ocurra. Su imagen
no trae shell, así que Docker no puede preguntarle si está bien: lo pregunta
`blackbox-exporter` desde fuera (`GET http://loki:3100/ready`).

**Impacto en el fichaje: ninguno.** Si Loki cae, la aplicación sigue funcionando
(regla dura 15: el registro técnico nunca bloquea nada) y los registros siguen
saliendo por la salida estándar de cada contenedor. Lo que se pierde es el
**rastro centralizado**: lo que ocurra mientras Loki esté caído no se podrá
consultar en Grafana. **No se pierde** el registro horario ni la auditoría
(`audit_log` es una tabla de PostgreSQL) ni el histórico de errores del panel
(`error_events`).

Si no has puesto `LOKI_URL` en el `.env`, Loki corre sin que nadie escriba en
él ([`operacion.md`](../cliente/operacion.md), registros): la alerta sigue
siendo válida, porque mide que el servicio responde, no que reciba datos.

La alerta se calla durante la ventana de mantenimiento y mientras `update.sh`
tiene el mantenimiento puesto.

## 2. Diagnóstico común

Desde el directorio de la instalación:

```bash
docker compose ps loki retention-guard
docker compose logs --tail 100 loki
```

`retention-guard` es un servicio de **un solo uso**: valida `LOG_RETENTION_HOURS`
y termina. Debe aparecer como `exited (0)`. Loki **no arranca** si termina con
otro código.

## 3. `LokiSinRespuesta`

1. **`loki` parado o en bucle de reinicio:** `docker compose up -d loki` y mira
   el registro.
2. **`retention-guard` terminó con 64:** `LOG_RETENTION_HOURS` del `.env` no es
   un entero entre 24 y 17520. Corrígelo y `docker compose up -d loki`
   (`up -d` vuelve a lanzar la guarda).
3. **Disco lleno:** Loki deja de aceptar escrituras. Mira
   [`espacio-en-disco.md`](espacio-en-disco.md).
4. **Loki arranca y tarda:** tras un reinicio puede tardar un minuto en estar
   listo. La alerta espera 10 minutos precisamente por eso.

Mientras Loki esté caído, los registros recientes están en cada contenedor:

```bash
docker compose logs --since 1h app horizon scheduler
```

## 4. Verificación

```bash
docker compose exec -T prometheus wget -qO- 'http://localhost:9090/api/v1/query?query=probe_success%7Bjob%3D%22kronoqr-loki%22%7D'
```

Debe devolver `1`. La alerta se resuelve sola en unos minutos.
