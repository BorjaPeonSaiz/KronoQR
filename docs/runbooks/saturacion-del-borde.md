# Runbook — saturación del borde en el camino de fichaje (429 por encima de lo normal)

**Alerta que lleva aquí** (MITRE ATT&CK T1499.002, DoS de punto final:
inundación de servicio; doc 01 §8.1, tarea 3.8, hallazgo H-10 de
`docs/seguridad/revision-interna-asvs-2026-09.md`), definida en
[`infra/observability/prometheus/rules/threat-detection.yml`](../../infra/observability/prometheus/rules/threat-detection.yml):

| Alerta | Umbral | Severidad | Destinatario |
| --- | --- | --- | --- |
| `SaturacionDelBordeEnElFichaje` | > 20 respuestas `429` en 5 min en las rutas de fichaje, `for: 2m` | Alta | IT del cliente |

**Impacto en el fichaje, que es lo primero que hay que saber: ninguno directo.**
Un `429` en el quiosco lo absorbe la cola offline (regla dura 19: el quiosco
nunca bloquea al empleado — confirma localmente y sincroniza cuando el
servidor vuelve a aceptar la petición). Esta alerta no describe una avería
del fichaje: describe una tasa de rechazo por encima de lo que un uso
legítimo produce, que es exactamente la señal que faltaba para T1499.002
(doc 01 §8.1: «la alerta de saturación llega con la tarea 3.2» — la 3.2 cerró
sin ella; llega aquí, en la 3.8).

---

## 1. Qué mide esta alerta, y qué NO mide — léelo antes de diagnosticar

**Esta pila no despliega un exportador de Nginx** (ni `stub_status`, ni el
módulo VTS): `infra/observability/` no tiene hoy ninguna serie que cuente los
`429` que Nginx rechaza con `limit_req` **antes** de que la petición llegue a
PHP-FPM. La única fuente que existe es
`http_requests_total{route,status="429"}`, que escribe
`RecordHttpMetrics` — middleware **global** de Laravel — para toda petición
que **sí llega** a la aplicación, incluida la que la propia aplicación
rechaza con su `RateLimiter` (`throttle:scan`/`scan-batch`/`scan-pin`,
`AttendanceServiceProvider.php`).

En la práctica, esta alerta mide **saturación en la capa de aplicación**, no
en el borde de Nginx en sentido estricto. Las dos capas son independientes
(`backend/config/kiosk.php`, cabecera: «por qué hay límites aquí si ya los
hay en Nginx» — Nginx limita por **origen**, la aplicación por
**dispositivo**), así que:

- Un pico de `429` que **sí** ves en esta alerta puede venir de un dispositivo
  concreto excediendo su cuota (`scan_per_device`, `batch_per_device`,
  `pin_scan_per_device`) — eso lo ves en `route`/el propio log de la
  aplicación, aunque la etiqueta se pierda en la alerta (`sum()` la agrega a
  propósito: lo que importa es el total, no una ruta en concreto).
- Un pico de `429` que Nginx rechaza directamente (`limit_req
  zone=scan_kiosk_vlan`/`scan_other`, `infra/docker/nginx/templates/
  kronoqr.conf.template`) **no dispara esta alerta todavía** — sigue siendo
  invisible hasta que exista un exportador de Nginx en esta pila. Si
  sospechas que el borde está rechazando sin que esta alerta suene, sigue el
  §3.

---

## 2. Diagnóstico — capa de aplicación (lo que SÍ mide la alerta)

```bash
# Total de 429 en las rutas de fichaje, ultima hora, por minuto:
#   sum(increase(http_requests_total{route=~"attendance\\.scan(\\..*)?", status="429"}[1h]))
# Desglosado por ruta, para saber si es scan, scan.batch o scan.pin:
#   sum by (route) (increase(http_requests_total{route=~"attendance\\.scan(\\..*)?", status="429"}[15m]))
```

En Grafana → Explore, o `curl` directo contra Prometheus
(`http://127.0.0.1:9090/api/v1/query`, túnel SSH igual que en
[`operacion.md`](../cliente/operacion.md) §10.4).

**Logs estructurados (Loki):** el `RateLimiter` de la aplicación no escribe
un mensaje propio por cada `429` — lo que ves es la respuesta HTTP con
código 429 en el log de acceso de la aplicación (`trace_id`, `route`,
`device_id`). Cruza el `device_id` de los picos con el catálogo de quioscos
(`GET /api/v1/kiosk/devices` desde el panel, o `kiosk_last_seen_seconds` en
Prometheus) para saber si el origen es un dispositivo emparejado legítimo.

---

## 3. Diagnóstico — capa de Nginx (lo que la alerta NO mide todavía)

Sin exportador de Nginx, la única forma de ver los `429` rechazados en el
borde es leer el log de acceso directamente:

```bash
docker compose exec -T nginx sh -c \
  "awk '\$9 == 429' /var/log/nginx/access.log | tail -n 200"
```

(El formato exacto del log lo fija `infra/docker/nginx/templates/
kronoqr.conf.template`; ajusta el campo si tu instalación cambió
`log_format`.) Cuenta cuántas líneas hay por IP de origen: **todos los
quioscos de un hotel salen por la misma IP** (`KIOSK_VLAN_CIDR`), así que un
`429` de la zona `scan_kiosk_vlan` (600 r/m, ráfaga 50) con IPs **dentro** de
esa VLAN es distinto de uno de la zona `scan_other` (30 r/m, ráfaga 10) con
un origen que no debería estar mandando tráfico de fichaje en absoluto —
doc 02 §7.1 y la cabecera de `kronoqr.conf.template` explican por qué las
dos zonas existen y por qué los 30 r/m de la zona externa **no** se aplican
sin distinguir el origen (RNF-P-06).

---

## 4. Descartar el falso positivo antes de escalar

- **¿Coincide con el cambio de turno (06:00, 14:00, 22:00)?** Un pico
  puntual de fichajes simultáneos en un hotel grande puede acercarse a los
  límites por dispositivo sin ser un ataque — comprueba si `scan_per_device`
  (120/min de serie) es realista para el volumen real de ese quiosco en ese
  momento exacto (`kiosk_offline_queue_size`, `scans_total{device}` en el
  mismo periodo).
- **¿Es un único dispositivo con un bucle de reintento defectuoso?** Un
  cliente (PWA del quiosco) con un error de sincronización puede reintentar
  agresivamente contra `/scan/batch` sin backoff — revisa la versión
  desplegada de ese quiosco (`kronoqr-kiosks` en Grafana) y si coincide con
  un despliegue reciente.
- **¿Es un origen fuera de la VLAN de quioscos?** Eso es lo que de verdad
  preocupa: tráfico de fichaje que no debería estar llegando desde fuera del
  hotel. Sigue con el §5.

---

## 5. Cómo bloquear un origen, si hace falta

**Mismo mecanismo que [`ataque-a-credenciales.md`](ataque-a-credenciales.md)
§5** (no lo dupliques aquí): preferencia por el firewall del host
(`iptables -I DOCKER-USER -s <IP> -j DROP`), nunca tocar
`limit_req_zone`/las zonas declarativas del §7.1 a mano. Es un parche de
emergencia: si el bloqueo tiene que sobrevivir más de unas horas, hace falta
una regla persistente de verdad (firewall del host, o revisar si
`KIOSK_VLAN_CIDR` está dejando pasar tráfico que no debería).

---

## 6. Qué NO hacer

- **No subas `scan_per_device`/`batch_per_device`/`pin_scan_per_device`
  "para que deje de sonar".** Son el techo por dispositivo que RS-02 exige;
  subirlo sin entender la causa solo mueve el umbral de la alerta, no
  resuelve la saturación real si la hay.
- **No trates un solo pico aislado en el cambio de turno como un incidente.**
  `for: 2m` ya filtra ruido de un segundo; si el pico dura menos que eso, no
  llega a disparar.
- **No asumas que "sin alerta" significa "sin saturación en el borde".** El
  §1 lo explica: esta alerta no ve todavía los `429` que Nginx rechaza antes
  de llegar a la aplicación.

---

## 7. Escalado

| Situación | A quién | En cuánto |
| --- | --- | --- |
| Pico coincide con cambio de turno, un único dispositivo, sin origen fuera de la VLAN | IT del cliente: revisar si el límite por dispositivo es realista | Dentro de la jornada |
| Dispositivo con bucle de reintento defectuoso | IT del cliente: revisar la versión de esa PWA | Dentro de la jornada |
| Origen fuera de `KIOSK_VLAN_CIDR` insistiendo contra `/scan*` | IT del cliente: bloqueo del §5 | Inmediato |
| Sostenido más de una hora sin que el bloqueo lo frene, o coincide con `RechazoDeFirmaQr` | Responsable de seguridad además de IT del cliente | Inmediato |

**Relacionados:** [`ataque-a-credenciales.md`](ataque-a-credenciales.md)
(mismo mecanismo de bloqueo en el borde; T1606 puede llegar acompañado de
saturación si el origen prueba muchos payloads deprisa) ·
[`errores-en-el-panel.md`](errores-en-el-panel.md) (si en vez de `429` lo que
sube son `5xx`, es otra alerta) · `docs/02-stack-tecnologico-y-plan-implementacion.md`
§7.1 (las dos zonas de fichaje de Nginx, por qué son dos y no una).
