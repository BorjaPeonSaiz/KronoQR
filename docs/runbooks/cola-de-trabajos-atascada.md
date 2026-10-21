# Runbook — hay trabajos en cola y nadie los procesa (Horizon parado o atascado)

**Alertas que llevan aquí**, definidas en
`observability/prometheus/rules/queue.yml` (bloque 9 de la 2.3.0, hallazgos
V21-4-DV-2 y CH2):

| Alerta | Umbral | Severidad | Destinatario | Sección |
| --- | --- | --- | --- | --- |
| `ColaDeTrabajosAcumulada` | `queue_jobs_pending` > 50 durante `for: 10m` | Alta | IT del cliente | [§3](#3-coladetrabajosacumulada) |
| `ColaDeTrabajosSinProcesar` | `queue_jobs_waiting` > 0 y en 20 min no ha terminado ningún trabajo, `for: 10m` | Alta | IT del cliente | [§4](#4-coladetrabajossinprocesar) |

**A las 06:30, quien la reciba hace esto:**

```bash
docker compose ps horizon
docker compose exec -T app php artisan horizon:status
docker compose logs --tail 100 horizon
```

- `horizon` no está en `running` (o está `unhealthy`): `docker compose up -d horizon`
  y mira el registro para saber por qué cayó (§5).
- `horizon:status` dice «paused»: `docker compose exec -T app php artisan horizon:continue`.
- Está en marcha y la cola no baja: §5.3.

---

## 1. Qué significa, y qué no

KronoQR procesa en segundo plano, con **Horizon**, lo que no debe retrasar una
petición: las **exportaciones íntegras** (la que se entrega a la Inspección o al
empleado que ejerce su derecho de acceso), los **informes en diferido** y la
**difusión de la presencia** al panel en vivo. Todo eso viaja por una cola de
Redis. La métrica `queue_jobs_pending` dice cuántos trabajos hay esperando o en
ejecución en cada cola, y `queue_jobs_waiting` solo los que **esperan** a que un
trabajador los coja; es un estado que se calcula en el momento de cada lectura.

**Impacto en el fichaje: ninguno.** El quiosco ficha y encola por su cuenta
(regla dura 19) y el servidor registra el fichaje en la propia petición, no en
la cola. Lo que se retrasa es:

- las exportaciones y los informes en diferido (la persona que los pidió ve su
  petición «pendiente» y no se genera nada),
- la difusión de la presencia al panel (el panel se actualiza solo al recargar),
- y, mientras Horizon esté parado, cualquier trabajo nuevo que se encole.

**No se pierde ningún trabajo**: están en Redis y se procesan al volver Horizon
(salvo que se vacíe Redis). Una exportación que se quedó a medias se desbloquea
sola pasado `REPORTING_EXPORT_STALE_AFTER` (una hora de serie): la persona puede
volver a pedirla.

**Qué no es.** Una exportación íntegra muy grande puede tardar hasta 30 min
(informes) o 60 min (exportación de datos): es **un trabajo en ejecución**, que
cuenta en `queue_jobs_pending` pero **no** en `queue_jobs_waiting`.
`ColaDeTrabajosSinProcesar` lee solo los que esperan, así que no salta con ella,
por larga que sea. Si salta y el registro de `horizon` muestra que algo avanza,
es que todos los trabajadores están ocupados con exportaciones y hay otros
trabajos detrás: espera a que terminen.

La alerta se calla durante la ventana de mantenimiento y mientras `update.sh`
tiene el mantenimiento puesto (reinicia `horizon`).

## 2. Diagnóstico común

Desde el directorio de la instalación:

```bash
docker compose ps horizon redis
docker compose exec -T app php artisan horizon:status
docker compose logs --tail 100 horizon
./doctor.sh
```

Y cuántos trabajos hay esperando ahora:

```bash
docker compose exec -T prometheus wget -qO- 'http://localhost:9090/api/v1/query?query=queue_jobs_pending'
```

`horizon:status` sale con 0 si todo va bien, con 1 si está en pausa y con 2 si no
hay ningún Horizon registrado. `doctor.sh` lo vigila también (sonda `queue.worker`)
y el contenedor `horizon` lleva un `healthcheck` con el mismo comando: un
`unhealthy` en `docker compose ps` es esta misma situación.

## 3. `ColaDeTrabajosAcumulada`

Más de 50 trabajos esperando durante 10 min seguidos. Horizon puede estar vivo
pero sin dar abasto, o parado con tráfico.

1. Mira `horizon:status` (§2). Si está parado o en pausa, sigue §5.
2. Si está en marcha, la causa más probable es **un trabajo pesado que ocupa el
   proceso** (una exportación grande) o **Reverb parado**: cada difusión de
   presencia espera unos segundos a que Reverb conteste y la cola crece con
   cada fichaje. Comprueba `docker compose ps reverb` y
   [`reverb-parado.md`](reverb-parado.md).
3. Si la cola baja sola, no hay nada más que hacer: fue una ráfaga.

## 4. `ColaDeTrabajosSinProcesar`

Hay al menos un trabajo esperando y en 20 min no ha terminado **ninguno**:
Horizon está parado, en bucle de reinicio, en pausa o colgado. Es la señal que
ve **una sola exportación atascada**.

Sigue §5. Mientras la alerta esté activa, la persona que pidió una exportación
la ve en «pendiente»: avísala de que el servidor la está reponiendo.

## 5. Restablecer Horizon

### 5.1 `horizon` parado o en bucle de reinicio

```bash
docker compose up -d horizon
docker compose logs --tail 100 horizon
```

Las causas habituales: Redis caído o con la contraseña rotada sin reiniciar
([`almacen-de-metricas-caido.md`](almacen-de-metricas-caido.md) §3,
[`rotacion-secretos.md`](rotacion-secretos.md)), disco lleno
([`espacio-en-disco.md`](espacio-en-disco.md)), o una actualización que dejó los
procesos de fondo sin arrancar
([`actualizacion-cliente.md`](actualizacion-cliente.md) §5).

### 5.2 Horizon en pausa

```bash
docker compose exec -T app php artisan horizon:continue
```

### 5.3 En marcha, pero colgado

Si `horizon:status` dice que corre y nada avanza (un trabajo bloqueado o el
proceso sin memoria):

```bash
docker compose restart horizon
```

Es seguro: un trabajo que se interrumpe se reintenta, y una exportación a
medias se marca fallida pasado `REPORTING_EXPORT_STALE_AFTER` y se puede volver
a pedir. **No vacíes Redis** para «limpiar la cola»: borrarías también las
sesiones y los contadores de seguridad.

## 6. Verificación

```bash
docker compose exec -T app php artisan horizon:status
docker compose exec -T prometheus wget -qO- 'http://localhost:9090/api/v1/query?query=queue_jobs_pending'
```

`horizon:status` debe decir «running» y `queue_jobs_pending` bajar a 0 en
pocos minutos. La alerta se resuelve sola. Después, pide a quien tenía una
exportación pendiente que compruebe que se ha generado, o que la pida de nuevo
si quedó marcada como fallida. Los trabajos que agotan sus intentos quedan
registrados: `docker compose exec -T app php artisan product:errors`
(origen `worker`, [`errores-en-el-panel.md`](errores-en-el-panel.md)).
