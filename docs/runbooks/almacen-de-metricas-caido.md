# Runbook — el almacén de métricas (Redis) no responde: las alertas de quiosco y cola no son fiables

**Alertas que llevan aquí**, definidas en
[`infra/observability/prometheus/rules/metrics-store.yml`](../../infra/observability/prometheus/rules/metrics-store.yml)
(bloque 22 de la 2.2.0, hallazgo R4-DV-01):

| Alerta | Umbral | Severidad | Destinatario | Sección |
| --- | --- | --- | --- | --- |
| `AlmacenDeMetricasCaido` | `kronoqr_metrics_store_up == 0`, `for: 15s` | Crítica | IT del cliente | [§3](#3-almacendemetricascaido) |
| `AlmacenDeMetricasAusente` | `absent(kronoqr_metrics_store_up)`, `for: 2m` | Crítica | IT del cliente | [§4](#4-almacendemetricasausente) |

**A las 06:30, quien la reciba hace esto:** `docker compose ps redis` y
`./doctor.sh`. Si `redis` no está sano, sigue
[`errores-en-el-panel.md`](errores-en-el-panel.md) §1.1 (reparar el AOF) o
arranca el servicio. **Hasta que la alerta se resuelva, no te fíes de un cuadro
en verde**: ni un quiosco caído ni una cola atascada se verían.

---

## 1. Qué significa, y qué no

Las métricas de negocio del producto (latido de quioscos, tamaño de la cola
offline, peticiones, trabajos pendientes) se guardan en Redis y `/metrics` las
lee de ahí. Si Redis cae, **esas series desaparecen** de Prometheus: no valen
cero, no existen. Antes de esta alerta, `QuioscoSinLatido` pasaba de 29
quioscos a 0 y se «resolvía» justo cuando la situación era peor.

**Impacto en el fichaje: ninguno.** Los quioscos siguen fichando y encolando
(regla dura 19), la caché y la cola de trabajos tienen respaldo y el limitador
de `/api/v1/scan*` falla abierto. Lo que se pierde es **visibilidad**.

Mientras la alerta está activa, Alertmanager **inhibe** las alertas de
`component: kiosk` (`QuioscoSinLatido`, `ColaOfflineAtascada`,
`ColaOfflineSinVaciar`…) y `ErroresDeServidorEnElFichaje` y
`LatenciaDelFichajeAlta`, que leen esas series. No se inhiben la sonda del borde,
la copia, la auditoría, la integridad ni la autenticación. Cuando el almacén
vuelve, las alertas inhibidas se reevalúan solas: si algún quiosco estuvo
caído durante el hueco, `QuioscoSinLatido` volverá a sonar tras su propio
`for: 5m`.

**Qué más queda ciego, y que esta alerta NO inhibe.** Tampoco pueden saltar
las de autenticación (`auth.yml`), la de firma QR (`RechazoDeFirmaQr`), las de
gestión de cuentas ni `ErroresCriticosNuevos`: sus series viven también en
Redis. **Mientras dure, revisa los intentos de acceso en el registro de
auditoría del panel.** Durante la caída, el acceso a la gestión y al portal
falla cerrado, el contador de fallos del PIN sigue en disco y el diario de
autenticación sigue en `audit_log`.

**El hueco que queda.** `AlmacenDeMetricasCaido` se evalúa cada 15 s y espera
un ciclo, así que tarda 15-30 s en activarse. Las de quiosco se «resuelven» en
la primera evaluación tras desaparecer su serie: si el `group_interval` de
Alertmanager (5 min) cae justo en ese hueco, puede llegar una notificación de
«resuelta» falsa (aproximadamente 1 de cada 20 caídas). Si la recibes y
`AlmacenDeMetricasCaido` está activa, ignórala: no está resuelta.

## 2. Diagnóstico común

Desde el directorio de la instalación:

```bash
docker compose ps redis
docker compose logs --tail 30 redis
./doctor.sh
```

Con la alerta delante, dos preguntas lo separan todo:

- **¿Está `redis` en pie y sano?** No: §3. Sí: mira §3.3.
- **¿Responde la aplicación a `/api/v1/health`?** No: §4.

## 3. `AlmacenDeMetricasCaido`

La aplicación responde y dice que su almacén no.

### 3.1 Redis parado o en bucle de reinicio

Es lo más frecuente tras un corte de luz (AOF corrupto): sigue
[`errores-en-el-panel.md`](errores-en-el-panel.md) §1.1. Si solo está parado:

```bash
docker compose up -d redis
```

### 3.2 Redis sin memoria o sin disco

Mira `docker compose logs --tail 100 redis` (`OOM`, `No space left`) y
[`espacio-en-disco.md`](espacio-en-disco.md).

### 3.3 Redis sano y la alerta sigue

Comprueba que la aplicación llega a Redis con sus credenciales:
`./doctor.sh` lo señala (sección Redis). Una contraseña rotada sin reiniciar
`app` ([`rotacion-secretos.md`](rotacion-secretos.md)) lo produce: reinicia
`app`, `horizon` y `scheduler`.

### 3.4 Un cortacircuitos se ha quedado abierto

Tras recuperarse Redis o PostgreSQL, la aplicación deja de intentar la conexión
durante unos segundos (cortacircuitos, 10 s). Se rearma solo; si pasados unos
minutos con Redis sano `/ready` sigue sin dar 200, borra las marcas a mano
dentro del contenedor `app`:

```bash
docker compose exec -T app sh -c 'rm -f storage/framework/redis-circuit-open storage/framework/database-circuit-open'
curl -sk -o /dev/null -w '%{http_code}\n' https://localhost/api/v1/ready
```

Es inocuo: son solo marcas de «no insistir» y se recrean si el servicio sigue
caído. El mismo procedimiento sirve cuando `/ready` devuelve `503` con la base
de datos o Redis ya sanos ([`errores-en-el-panel.md`](errores-en-el-panel.md)).

## 4. `AlmacenDeMetricasAusente`

La aplicación no publica ni la propia gauge: Prometheus no la alcanza o `/metrics`
no responde. No es lo mismo que `SondaDelBordeFallida` (que comprueba `/health`
y `/ready` desde fuera): pueden sonar juntas o cada una por su cuenta.

```bash
docker compose ps app nginx prometheus
docker compose logs --tail 50 nginx
```

Si `SondaDelBordeFallida` suena a la vez, sigue
[`errores-en-el-panel.md`](errores-en-el-panel.md). Si solo suena esta, el
borde sirve pero `/metrics` no: revisa `METRICS_ALLOW_CIDR` y el estado del
objetivo en Prometheus (job `kronoqr-api`):

```bash
docker compose exec -T prometheus wget -qO- http://127.0.0.1:9090/api/v1/targets \
  | jq -r '.data.activeTargets[] | select(.labels.job == "kronoqr-api") | [.health, .lastError] | @tsv'
```

## 5. Verificación

```bash
docker compose exec -T prometheus wget -qO- 'http://localhost:9090/api/v1/query?query=kronoqr_metrics_store_up'
```

Debe devolver `1`. Las dos alertas se resuelven solas en pocos minutos. Después,
comprueba que `kiosk_last_seen_seconds` vuelve a tener una serie por quiosco
vinculado y revisa en el panel que ningún quiosco quedó sin latido durante el hueco
([`quiosco-no-responde.md`](quiosco-no-responde.md)).
