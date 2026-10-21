# Runbook — blackbox-exporter no responde: las alertas de fuera se quedan sin serie

**Alerta que lleva aquí**, definida en `observability/prometheus/rules/observability.yml`
(bloque 9 de la 2.3.0, revisión):

| Alerta | Umbral | Severidad | Destinatario | Sección |
| --- | --- | --- | --- | --- |
| `SondasDeBlackboxSinRespuesta` | `up == 0` de un job de sonda (`kronoqr-uptime`, `kronoqr-uptime-tls-verified` o `kronoqr-loki`), `for: 5m` | Alta | IT del cliente | [§3](#3-sondasdeblackboxsinrespuesta) |

**A las 06:30, quien la reciba hace esto:**

```bash
docker compose ps blackbox-exporter
docker compose logs --tail 50 blackbox-exporter
docker compose up -d blackbox-exporter
```

## 1. Qué significa, y qué no

`blackbox-exporter` es quien hace las comprobaciones **desde fuera** del proceso de
la aplicación: que el borde responde (`/api/v1/health` y `/api/v1/ready`), que el
certificado no caduca ni deja de verificar, y que Loki responde. Prometheus le
pide cada sonda; si **él** cae, esas lecturas fallan y las series (`probe_success`,
`probe_ssl_earliest_cert_expiry`) **desaparecen**.

Sin series, estas alertas no tienen nada que evaluar y **callan**:
`SondaDelBordeFallida`, `CertificadoTlsProximoACaducar`, `CertificadoTlsCaducado`,
`CertificadoTlsNoVerificable` y `LokiSinRespuesta`. Es el peor momento para
quedarse ciego: el servidor puede estar caído sin que nada lo diga. Por eso esta
alerta lee `up`, que genera el propio Prometheus y no depende de blackbox.

**Impacto en el fichaje: ninguno.** Los quioscos fichan y encolan (regla dura 19).
Lo que se pierde es **visibilidad**: hasta que vuelva, **comprueba a mano** que el
panel responde (`./doctor.sh`).

La alerta se calla durante la ventana de mantenimiento y mientras `update.sh`
tiene el mantenimiento puesto (recrea blackbox).

## 2. Diagnóstico común

```bash
docker compose ps blackbox-exporter prometheus
docker compose logs --tail 50 blackbox-exporter
./doctor.sh
```

Y los objetivos tal como los ve Prometheus (el error de cada uno):

```bash
docker compose exec -T prometheus wget -qO- http://127.0.0.1:9090/api/v1/targets \
  | jq -r '.data.activeTargets[] | select(.labels.job | test("kronoqr-(uptime|loki)")) | [.labels.job, .health, .lastError] | @tsv'
```

## 3. `SondasDeBlackboxSinRespuesta`

1. **`blackbox-exporter` parado o en bucle de reinicio:** `docker compose up -d
   blackbox-exporter` y lee el registro. Una configuración inválida
   (`observability/blackbox/blackbox.yml` editada a mano con un error) lo deja en
   bucle: el registro dice la línea. Devuélvela a la del paquete o corrígela.
2. **Está en marcha y Prometheus no lo alcanza:** el último error de la lista de
   arriba lo dice (`connection refused`, `no such host`). Comprueba que `prometheus`
   y `blackbox-exporter` están en la misma red (`docker compose ps`) y recrea
   Prometheus: `docker compose up -d --force-recreate prometheus`.
3. **Responde pero un módulo no existe** (`module "http_2xx_internal" not found`):
   blackbox sigue con una configuración anterior a una actualización. Recréalo:
   `docker compose up -d --force-recreate blackbox-exporter`. Desde la 2.3.0
   `update.sh` lo hace solo cuando cambia `observability/` (la huella del `.env`),
   pero una edición a mano posterior no.

## 4. Verificación

```bash
docker compose exec -T prometheus wget -qO- 'http://localhost:9090/api/v1/query?query=up%7Bjob%3D~%22kronoqr-uptime.*%7Ckronoqr-loki%22%7D'
```

Cada serie debe valer `1`. La alerta se resuelve sola a los pocos minutos, y
`SondaDelBordeFallida` y las de certificado vuelven a tener serie: revisa que
ninguna esté activa por algo que ocurriera mientras blackbox estaba caído.
