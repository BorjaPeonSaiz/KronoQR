# DAST — ZAP *baseline* (doc 07 §6, «Sin DAST»)

Aplazado hasta el cierre de la Fase 3 con dueño `devops-observabilidad`
(`make dast`, manual, **fuera de `ci.yml`** por presupuesto de tiempo — doc 02
§9.2 fija ①–③ en menos de 4 minutos, y un *baseline* de ZAP tarda minutos, no
segundos).

## Qué hace `make dast`

Un [ZAP *baseline* scan](https://www.zaproxy.org/docs/docker/baseline-scan/)
(pasivo: arañazo + análisis pasivo del tráfico, **sin ataques activos**) contra
la pila de desarrollo levantada con `make up`, desde dentro de la red de
contenedores (`kronoqr-app`), igual que el job `kronoqr-uptime` de Prometheus:

```bash
docker run --rm --network kronoqr-app \
  -v infra/security/zap:/zap/wrk/config:ro \
  -v docs/seguridad/evidencia:/zap/wrk/out:rw \
  ghcr.io/zaproxy/zaproxy:stable zap-baseline.py \
  -t https://nginx:8443/api/v1/health \
  -c /zap/wrk/config/zap-baseline.conf \
  -J out/dast-<fecha>.json \
  -I
```

**Contra `https://nginx:8443`, nunca el `8080` sin TLS** (mismo motivo que
`kronoqr-uptime`: el puerto sin `limit_req` no es el que expone un cliente
real). **El ancla es `/api/v1/health`, no la raíz**: `https://nginx:8443/`
devuelve `404` y `/admin/`, `/kiosk/`, `/portal/` devuelven `403` sin el
`Host` real de la instalación (`server_name` de `kronoqr.conf.template`
exige el dominio del cliente, no el nombre del servicio Docker) — el mismo
motivo por el que la sonda `kronoqr-uptime` de Prometheus usa esa ruta y no
la raíz. `-I` porque es un control manual e informativo: el código de salida
no bloquea nada, y el triaje humano vive en
`docs/seguridad/evidencia/dast-<fecha>.md`.

## Por qué esta superficie no es la de un DAST habitual

La API no tiene sesión de navegador (Bearer de Sanctum) y las tres SPA son del
mismo origen que sirve Nginx: un *baseline* sin autenticar solo alcanza
pantallas de entrada (login del panel y del portal, emparejamiento del
quiosco) y cabeceras del borde, que **RS-09 ya prueba con
`Architecture\QualityGatesTest`** (`Strict-Transport-Security`,
`Content-Security-Policy`, `X-Content-Type-Options`, `Referrer-Policy`,
`Permissions-Policy`). El valor de este control no es sustituir esa prueba —
sería redundante— sino **detectar regresiones que ninguna prueba unitaria
cubre todavía** (cabeceras nuevas del ecosistema ZAP, cookies mal marcadas,
divulgación de información en páginas de error) y servir de primer análisis
dinámico documentado hasta que llegue la revisión externa de RS-11.

## Exclusiones deliberadas

- **`/metrics`**: restringido a la red interna por
  `App\Http\Middleware\RestrictToMetricsNetwork` (RS-09, doc 02 §8.1). Un
  *baseline* sin sesión que llegara a verlo desde dentro de la red de
  contenedores marcaría «sin autenticación» sobre un control que es de red, no
  de aplicación — el mismo motivo por el que `blackbox-exporter` sondea por
  `8443` y no por `8080`. El arañazo no lo alcanza porque ninguna página enlaza
  a `/metrics`; `zap-baseline.conf` lo excluye además de forma explícita por si
  algún día lo hiciera.
- **Reverb / websocket (`/app/*`, cabecera `Upgrade`)**: un *baseline* HTTP no
  sabe hablar el protocolo de *upgrade* y solo produciría ruido (conexión
  rechazada, malinterpretada como hallazgo).
- **Activos estáticos de las tres SPA** (`/admin/assets/*`, `/kiosk/assets/*`,
  `/portal/assets/*`): no procesan entrada; incluirlos solo alarga el
  arañazo sin ganar cobertura.

## Qué no cubre este control

- **Ataques activos** (SQLi, XSS reflejado con carga real, *fuzzing*):
  eso es un *full scan* o una prueba de intrusión, no un *baseline*. No se
  ejecuta contra la pila de desarrollo con datos sintéticos sin que
  `seguridad-cumplimiento` lo autorice explícitamente: un *full scan* manda
  tráfico de escritura real (`POST`, `PATCH`, `DELETE`) contra los mismos
  endpoints que factura el fichaje.
- **Rutas que exigen sesión** (todo lo de gestión, el propio fichaje): el
  *baseline* no inicia sesión. Cuando exista un `context_file` con
  credenciales de una cuenta de prueba, se documenta aquí antes de usarlo
  nunca con credenciales reales.

## Triaje de cada ejecución

Cada `make dast` añade un fichero `docs/seguridad/evidencia/dast-AAAA-MM-DD.md`
con el resumen por severidad y, para cada alerta `WARN`/`FAIL`, uno de estos
tres veredictos (mismo criterio que
[`docs/runbooks/triaje-hallazgos-seguridad.md`](../../../docs/runbooks/triaje-hallazgos-seguridad.md)):

1. **Falso positivo**, con el motivo.
2. **Ya cubierto por una prueba propia**, con el fichero.
3. **Hallazgo real**, con dueño y fecha de cierre.

El informe JSON completo (`dast-AAAA-MM-DD.json`) se conserva junto al `.md`;
el HTML se descarta si pesa más de lo razonable para el repositorio (el JSON
ya es la fuente de verdad de la que sale el `.md`).
