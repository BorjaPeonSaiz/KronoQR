# Operación de KronoQR — lo que hay que atender, y cada cuánto

> **Estado.** Las secciones 1 a 6 son de la **tarea 2.10**: la retención y la
> purga, que es la única operación del producto que borra datos. La 7 es de la
> **5.3** (licencia). Las **8, 9 y 10** son de la **5.4**: los códigos de
> salida de los cinco scripts, la custodia de secretos y qué pierdes si apagas
> la observabilidad. La **11** es de la **5.7**: actualizar. Las **12 y 13**
> son de la **5.9** y la **5.10**: diagnóstico, soporte y exportación íntegra.
> La **15** es de la **5.12**: el histórico de `error_events`. La **tarea 5.11**
> añadirá los quioscos; no reescribirá nada de lo que ya está aquí.

---
> **Los comandos de esta guía se ejecutan desde el directorio del paquete**, que
> es donde están `docker-compose.yml` y el `.env`. Si los lanzas desde otro
> sitio, añade `-f /ruta/al/paquete/docker-compose.yml`.


## 1. El calendario, en una tabla

Todo esto corre solo en el contenedor `scheduler`. Lo que aparece en la columna
«tú» es lo que **no** hace ninguna máquina.

| Cuándo | Qué ocurre | Tú |
| --- | --- | --- |
| 02:45 UTC, a diario | Se comprueba que existe la partición anual de auditoría | Nada, salvo que avise |
| 03:15 UTC, a diario | Copia de seguridad lógica | Sacarla del servidor |
| 04:05 UTC, a diario | Se verifica la cadena de hash de la auditoría | Atender la alerta si suena: es crítica |
| 04:30 UTC, a diario | Revisión del registro: turnos abiertos, descansos, jornadas anómalas | Resolver las incidencias en el panel |
| Lunes 05:10 UTC | **Propuesta de retención**: informe de lo que se purgaría | Leerlo cuando haya algo vencido |
| Cada hora | Métricas de credenciales y limpieza de temporales | Nada |
| Cada hora | Se purgan las exportaciones íntegras caducadas: se borra el ZIP, la anotación queda (§13) | Nada |
| Lunes 05:40 UTC | **Telemetría**, solo si la has activado (§13.4): se envía el informe semanal al destino que fijaste | Nada |
| Trimestral | — | **Simulacro de restauración** de la copia |
| Trimestral | — | **Repasar la lista de comprobación de endurecimiento** ([`endurecimiento.md`](endurecimiento.md), último apartado): red, certificado, cuentas, tablets, copias fuera del servidor |

---

## 2. La propuesta de retención (semanal, no borra nada)

Cada lunes queda un informe en:

```
storage/app/retention-reports/retencion-propuesta-AAAAMMDD-HHMMSS.txt
```

Se puede pedir a mano en cualquier momento, y **es seguro**: no modifica ni una
fila.

```bash
docker compose -f docker-compose.yml exec app \
  php artisan compliance:apply-retention --dry-run
```

Lo que dice el informe:

- **La fecha de corte** del registro de jornada —«anterior a AAAA-MM-DD»— y los
  años de retención con los que se calculó.
- **Cuántos registros hay vencidos**, tabla por tabla, con su rango de fechas.
- **Qué particiones de auditoría** han vencido enteras.
- **La frase de confirmación** que haría falta para ejecutar **ese** informe.

**Mientras el total sea 0, no hay nada que hacer.** Es lo normal durante los
primeros cuatro años de la instalación.

**No contiene datos personales**: recuentos, tablas y fechas. Se puede archivar y
adjuntar sin más precaución.

---

## 3. Ejecutar la purga (manual, confirmada y auditada)

**Cuándo:** cuando la propuesta diga que hay registros vencidos y el responsable
lo autorice. Es una operación de una o dos veces al año.

**Antes de empezar:**

1. Comprueba que **la copia de anoche está hecha y fuera del servidor**. La purga
   es irreversible.
2. Comprueba que la **verificación de la cadena de auditoría** terminó en verde
   esta madrugada. Si no lo hizo, para: la purga abortará de todas formas y lo que
   toca es
   [`rotura-cadena-auditoria.md`](../runbooks/rotura-cadena-auditoria.md).
3. Ten a mano la contraseña del rol **`fichaje_maintenance`**. No está en el
   `.env` de la aplicación a propósito: es el único rol que puede soltar una
   partición de auditoría, y si viviera ahí, el reparto de permisos sería
   decorativo.

**La orden**, con la frase exacta que imprimió el informe:

```bash
docker compose -f docker-compose.yml run --rm \
  -e DB_MAINTENANCE_PASSWORD='<contraseña de fichaje_maintenance>' app \
  php artisan compliance:apply-retention \
    --confirm=PURGAR-AAAA-MM-DD-xxxxxx \
    --responsible=<id de la cuenta de gestión que autoriza>
```

**Qué pasa, en orden:**

1. Se comprueba la frase. Si no corresponde a lo que se purgaría **ahora**, no se
   sigue: un informe de hace tres meses no puede ejecutarse.
2. Se **verifica la cadena** de cada partición de auditoría que iba a soltarse. Si
   una no verifica, **aborta sin borrar nada**.
3. Se purga el registro de jornada vencido, con su asiento en `audit_log` en la
   misma transacción.
4. Se **sella** cada partición vencida en `audit_chain_anchors` y se **suelta
   entera**. La auditoría nunca se borra fila a fila.
5. Se limpian el log técnico y el histórico de errores de más de 90 días.
6. Queda el informe de lo purgado en
   `storage/app/retention-reports/retencion-purga-*.txt`.

**Después:**

- **Archiva el informe** junto a la autorización escrita.
- Lanza `php artisan compliance:verify-audit-chain`. Tiene que terminar en verde
  y decir «Purga sellada reconocida: particion AAAA». Si dijera otra cosa, es un
  incidente de seguridad.

---

## 4. Si algo sale mal

| Síntoma | Qué significa | Qué hacer |
| --- | --- | --- |
| «La frase de confirmación no corresponde…» | El informe caducó, o cambió el perfil de cumplimiento | Vuelve a lanzar `--dry-run` y usa la frase nueva |
| «La cadena de la partición audit_log_AAAA NO verifica» | Alguien tocó la auditoría | **Incidente de seguridad.** `rotura-cadena-auditoria.md`. No repitas la purga |
| «La purga no ha podido completarse contra la base de datos» | Falta la credencial de `fichaje_maintenance`, o el rol no la tiene puesta | Provisiónala con `infra/docker/postgres/initdb/02-application-roles.sh` y repite |
| «La instalación no tiene centro de trabajo» | La puesta en marcha no se ha completado | Termina el asistente; sin centro no hay perfil de cumplimiento y no hay plazo |
| La propuesta semanal deja de aparecer | El planificador no está corriendo | Revisa el contenedor `scheduler`; la métrica `retention_last_run_timestamp_seconds` lo delata |

---

## 5. Qué mirar sin esperar a que suene nada

Métricas publicadas para el colector de `node-exporter`
(`kronoqr_retention.prom`):

| Serie | Qué dice | Cuándo preocuparse |
| --- | --- | --- |
| `retention_pending_rows{scope}` | Registros vencidos que siguen ahí | Si crece y se queda: hay una purga pendiente de autorizar |
| `retention_purged_rows{scope}` | Lo que se llevó la última purga real | Compáralo con el informe |
| `retention_last_run_timestamp_seconds{mode}` | Cuándo corrió la última pasada | Si la de `simulation` tiene más de una semana, el planificador no está corriendo |
| `retention_cutoff_timestamp_seconds` | Fecha de corte vigente | Si salta hacia atrás o hacia delante, alguien cambió el perfil de cumplimiento |

---

## 6. Parámetros que gobiernan todo esto

| Variable | De serie | Qué hace |
| --- | --- | --- |
| *(perfil del centro)* `retention_years` | 4 | Años del registro de jornada y de la auditoría. **No es una variable de entorno**: es una fila de `compliance_profiles`, porque lo fija la jurisdicción |
| `TECHNICAL_LOG_RETENTION_DAYS` | 90 | Días de log técnico |
| `ERROR_HISTORY_RETENTION_DAYS` | 90 | Días del histórico de errores |
| `COMPLIANCE_RETENTION_BATCH_SIZE` | 1000 | Filas por sentencia de borrado. Súbelo solo si la purga tarda demasiado |
| `COMPLIANCE_RETENTION_REPORT_PATH` | `storage/app/retention-reports` | Dónde quedan los informes. **No se limpian solos**: son la constancia de la purga |
| `DB_MAINTENANCE_USERNAME` | `fichaje_maintenance` | Rol que ejecuta la purga de auditoría |
| `DB_MAINTENANCE_PASSWORD` | *(vacía)* | **No se pone en el `.env`.** Se aporta al ejecutar la purga |

---

## 7. La licencia, en dos comandos

No hay nada programado que revise la licencia: se mira cuando se quiere mirar.

```bash
docker compose exec app php artisan license:show
```

**Códigos de salida**, por si quieres vigilarlo desde tu propio sistema:

| Código | Significa |
| --- | --- |
| `0` | Licencia vigente y sin exceso de plan. Nada que hacer |
| `1` | **Hay algo que mirar**: no hay licencia, caducó, caduca pronto, no se puede verificar, o se ha superado una cifra del plan |

> **`1` no significa que el sistema esté parado**, y el propio comando lo dice.
> Se sigue fichando, consultando el registro, exportando para la Inspección y
> haciendo copias exactamente igual.

Para activar una clave nueva:

```bash
docker compose exec app php artisan license:activate "KQL1...."
```

| Código | Significa |
| --- | --- |
| `0` | Activada y vigente |
| `1` | Activada, pero **no vigente** todavía: caducada, o su vigencia empieza más adelante. Se guardó igual |
| `2` | **No se activó nada.** La clave no verifica, o no indicaste ninguna. La licencia anterior sigue como estaba |

El estado también sale en la sonda de salud, para vigilarlo sin entrar por SSH:

```bash
curl -sS https://TU-SERVIDOR/api/v1/health
# {"status":"ok","version":"1.4.2","license":"valid"}
```

Ese campo puede decir `unknown`: significa que la sonda no ha podido saberlo
**sin tocar la base de datos**, que es su regla —una sonda de vida que consulta
PostgreSQL hace reiniciar el servicio cuando lo caído es PostgreSQL—. El dato
autoritativo es `license:show`.

Todo lo demás sobre la licencia —qué se degrada al caducar, qué no se degrada
nunca, los límites del plan y qué hacer si una clave no se activa— está en
[`configuracion.md`](configuracion.md), sección 3 bis.

---

## 8. Los cinco scripts y su tabla de códigos de salida

`install.sh`, `update.sh`, `doctor.sh`, `backup.sh` y `restore.sh` **comparten
una sola tabla**. Los ejecuta la misma persona, a veces encadenados en un cron,
y un `3` que significara una cosa en uno y otra en otro sería una trampa.

**El código dice en qué fase se paró y qué quedó escrito. El detalle va en el
mensaje, que es lo que hay que leer.**

| Código | Significa siempre | En cada script |
| --- | --- | --- |
| `0` | Correcto | — |
| `1` | **Uso incorrecto.** Nada tocado | Un argumento que no existe, o falta un valor |
| `2` | **Requisitos no cumplidos. NADA escrito.** La máquina está como estaba | `install.sh`: falta Docker, disco, puerto ocupado. `backup.sh`/`restore.sh`: falta `pg_dump`, el destino no es escribible, no hay espacio, o falta `BACKUP_ENCRYPTION_KEY`. `update.sh`: una precondición no se cumple, o **la copia previa ha fallado**; la instalación sigue en su versión. `doctor.sh`: **Docker no responde**, o la versión de Compose no es la soportada; no se ha podido comprobar nada más |
| `3` | **Estado previo incompatible. NADA escrito** | `install.sh`: ya hay una instalación (usa `update.sh`). `backup.sh`: no hay copia que verificar, o el destino ya existe. `restore.sh`: quedan conexiones abiertas contra la base. `update.sh`: ya está en la versión de destino, o no hay instalación que actualizar. `doctor.sh`: **no hay ninguna instalación que diagnosticar** en este servidor — si es uno nuevo, lo que hace falta es `install.sh` |
| `4` | **Falló y se deshizo todo lo hecho** en esa ejecución. Se puede reintentar | `install.sh`: contenedores, volúmenes y `.env` devueltos a su estado. `backup.sh`: los ficheros a medias barridos, la copia anterior intacta. `restore.sh`: base de trabajo eliminada, la de producción sin tocar. `update.sh`: copia previa restaurada y **versión anterior en marcha y verificada**. `doctor.sh`: **no lo usa**, no escribe ni deshace nada |
| `5` | **Falló y NO se pudo deshacer todo. Hay que intervenir a mano.** El mensaje dice exactamente qué queda y qué orden lo retira | Es el único código que exige a una persona delante. `doctor.sh`: **no lo usa**, no escribe ni deshace nada |
| `6` | **El trabajo se hizo pero la verificación posterior falló.** No se deshace nada | `install.sh`: los servicios están en pie, revisa certificado y logs. `backup.sh`: la copia existe pero **no verifica: trátala como inexistente**. `restore-drill.sh`: hoy no se podría recuperar el registro. `update.sh`: **casi nunca** (toda verificación de la versión nueva que falla deshace); la única excepción es que el asiento `system.updated` de `audit_log` no se pudiera escribir tras una actualización que sí terminó — el trabajo se hizo y no se deshace por eso. `doctor.sh`: **el diagnóstico ha encontrado al menos un fallo** — con la aplicación en marcha, en su propio informe (`product:doctor`); con la aplicación parada, en una de las comprobaciones externas. El mensaje dice qué leer |

`install.sh` y `update.sh` invocan `product:doctor` en su fase de verificación
(RF-PD-13): en `install.sh` un aviso (código `1` de `product:doctor`) se
muestra y no bloquea, y solo un fallo (`2`) se traduce al `6` de la tabla de
arriba. En `update.sh` el diagnóstico no se traduce a `6`: es informativo, y
un fallo de `product:doctor` en la versión nueva provoca la vuelta atrás
(código `4`) igual que cualquier otro fallo del paso 5, nunca un `6`. La única
traducción a `6` que sí existe en `update.sh` es otra cosa por completo: el
asiento `system.updated` que el paso 5 deja en `audit_log` (sección 11).

### Si tenías un cron escrito contra la tabla anterior de `backup.sh`

Hasta la versión 2.0.0, `backup.sh` y `restore.sh` usaban una tabla propia.
Equivalencia:

| Antes | Ahora |
| --- | --- |
| `1` la operación ha fallado | `4` si se deshizo lo hecho · `5` si quedó algo a medias · `6` si falló la verificación · `3` si no había nada sobre lo que operar |
| `2` error de uso | `1` |
| `3` falta una herramienta o precondición | `2` |
| `4` destino no escribible o sin espacio | `2` |
| `5` clave ausente o incorrecta | `2` al comprobar la precondición · `6` al fallar el descifrado de una copia |

**Lo que un cron necesita saber sigue siendo lo mismo: `0` es bien, cualquier
otra cosa es mal.** La diferencia es que ahora el número te dice si puedes
reintentar sin mirar (`2`, `3`, `4`) o si tienes que mirar (`5`, `6`).

---

## 9. Custodia de secretos

El instalador genera todos los secretos **en tu servidor** y no los transmite a
nadie. **El fabricante no los conoce y no puede recuperarlos.** La lista
completa, con la consecuencia de perder cada uno, está en
[`instalacion.md`](instalacion.md), sección 3.

### `BACKUP_ENCRYPTION_KEY`: esta sale del servidor

Es la única que hay que custodiar **fuera** de la máquina, y el motivo es
directo: si se pierde el servidor y la clave estaba solo ahí, las copias
cifradas son bytes sin valor y el registro horario —que hay que conservar
cuatro años— se ha perdido.

**Hazlo el día de la instalación, antes de cerrar la sesión:**

```bash
cd /opt/kronoqr-2.1.0
sudo sed -n 's/^BACKUP_ENCRYPTION_KEY=//p' .env
```

Copia ese valor al gestor de contraseñas de la empresa, o a un sobre cerrado en
la caja fuerte junto al resto de credenciales críticas del hotel. Anota **la
fecha** y **la instalación** a la que corresponde.

Tres cosas que no hay que hacer:

- **No la mandes por correo ni por mensajería.** El fabricante no la necesita y
  no la quiere.
- **No la dejes en un fichero del mismo servidor.** Si se pierde el servidor,
  se pierden los dos.
- **No la rotes sin leer antes** [`../runbooks/rotacion-secretos.md`](../runbooks/rotacion-secretos.md):
  las copias anteriores **solo se descifran con la clave con la que se hicieron**.

Limpia el historial del intérprete de órdenes cuando termines:

```bash
history -c
```

### `fichaje_maintenance`: el rol que nace sin contraseña

Es el único rol de base de datos que puede soltar una partición vencida del
registro de auditoría, y por eso **su contraseña no vive en el `.env`**: si la
aplicación corriente pudiera autenticarse con él, el reparto de tres roles
sería decorativo.

Nace sin credencial —existe y no se puede usar por red— y se le asigna una **en
el momento** de la purga anual, con el rol de migración, que sí está en el
`.env`:

```bash
# 1. Genera una contraseña y asígnala al rol, solo para esta operación
clave="$(openssl rand -base64 24)"
docker compose exec -T postgres psql -U fichaje_migrator -d fichaje \
  -c "ALTER ROLE fichaje_maintenance PASSWORD '${clave}'"

# 2. Ejecuta la purga aportando la credencial, sin escribirla en ningún fichero
docker compose run --rm -e DB_MAINTENANCE_PASSWORD="${clave}" app \
  php artisan compliance:apply-retention --confirm=PURGAR-...

# 3. Retírasela en cuanto termines
docker compose exec -T postgres psql -U fichaje_migrator -d fichaje \
  -c "ALTER ROLE fichaje_maintenance PASSWORD NULL"

unset clave
history -c
```

La **simulación** (`--dry-run`), que es la que corre sola cada lunes, **no
necesita nada de esto**: solo cuenta, y cuenta con el rol de la aplicación.

---

## 10. La observabilidad, y qué pierdes si la apagas

El `.env` trae `COMPOSE_PROFILES=observability`. Levanta siete servicios más
—Prometheus, node-exporter, Alertmanager, Grafana, Loki, Tempo y
blackbox-exporter— y ocupa unos 850 MiB.

**Está encendida de serie porque es lo que avisa de los dos fallos que
convierten una instalación sana en una pérdida de datos sin que nadie lo note
mirando la pantalla:**

1. **La copia de anoche falló.** El resultado de cada copia se publica como
   métrica y hay una alerta sobre ella. Sin el perfil, nadie te lo dice.
2. **El archivado del WAL se ha parado.** PostgreSQL sigue funcionando y el WAL
   se acumula en su propio volumen hasta llenar el disco; entonces se para
   entero. La alerta correspondiente es de las críticas.

Puedes apagarla dejando `COMPOSE_PROFILES=` vacío y reiniciando los servicios.
Es una configuración soportada, pero **asume esta tarea manual**:

| Cada | Qué comprobar |
| --- | --- |
| Semanal | `docker compose exec app php artisan backup:verify` — que la última copia existe y verifica |
| Semanal | `df -h` sobre el disco de Docker y sobre `BACKUP_PATH` |
| Trimestral | El simulacro de restauración (sección 1), que no cambia |

Grafana escucha **solo en `127.0.0.1:3000`**: se llega por túnel SSH o desde el
propio servidor, **nunca desde internet**.

### 10.1 Los tres registros del sistema

Se confunden con facilidad y sirven para cosas distintas; mezclarlos es un
error que se paga tarde, normalmente delante de un inspector o de un cliente
enfadado.

| Registro | Dónde vive | Retención | Para qué sirve | Quién lo lee |
| --- | --- | --- | --- | --- |
| **Log técnico** | Fichero JSON en `stderr`, y en Loki si `LOKI_URL` tiene valor (no `LOG_STACK`: ver más abajo) | 90 días (`TECHNICAL_LOG_RETENTION_DAYS`) | Depurar con detalle una petición concreta: qué pasó, en qué orden, con qué contexto técnico | Quien tiene delante el cuadro de mandos de observabilidad (normalmente soporte del fabricante, con tu paquete de diagnóstico, o tu propio IT si sabe leer Grafana) |
| **`error_events`** | Tabla en tu PostgreSQL | 90 días (`ERROR_HISTORY_RETENTION_DAYS`) | Que **tú** veas qué está fallando y desde cuándo, sin necesidad de conocer el sistema por dentro | Tú, desde el panel («Soporte» → histórico de errores, sección 15) |
| **`audit_log`** | Tabla en tu PostgreSQL, solo-añadir y encadenada por hash | Años, según tu perfil de cumplimiento | Valor probatorio ante una inspección: quién hizo qué y cuándo | Tú, un auditor, la Inspección |

**El perfil `observability` enciende el contenedor de Loki; `LOKI_URL` decide si
la aplicación le envía algo.** Son dos decisiones distintas, a propósito, con
el mismo criterio que `OTEL_EXPORTER_OTLP_ENDPOINT` (§10.2): `LOKI_URL` viene
**vacía de serie** en `.env.example`, así que una instalación recién puesta en
marcha tiene Loki corriendo pero sin nadie escribiendo en él, hasta que pongas
`LOKI_URL=http://loki:3100` en el `.env` y reinicies `app`, `horizon` y el
planificador. `LOG_STACK` no interviene en esta decisión: nombrar `loki` ahí
sin `LOKI_URL` no activa nada.

**Por qué `error_events` no es redundante con Loki.** Loki es parte del perfil
`observability`, que es opcional: puedes apagarlo, puede que nadie lo mire, y
lo pierdes si reinstalas sin conservar su volumen. `error_events` vive en la
misma base de datos que respaldas a diario y viaja en el paquete de
diagnóstico, así que sigue ahí aunque Loki no exista.

**Los registros de Nginx, PostgreSQL y Redis no viajan a Loki.** Solo lo hace
el registro técnico de la aplicación (Laravel, Horizon, el planificador).
Si necesitas los de Nginx, están en `docker compose logs nginx`; los de
PostgreSQL y Redis, con el mismo patrón.

### 10.2 Qué añaden Tempo y blackbox-exporter

**Tempo** guarda las trazas: el recorrido completo de una petición, desde el
`fetch` de la tablet hasta la consulta SQL que escribió el fichaje, con los
mismos 90 días de retención que el registro técnico (cambiar el plazo exige
tocar `infra/observability/loki/loki.yaml` **y** `infra/observability/tempo/tempo.yaml`
a la vez: ver `configuracion.md` §6.20). No se activa por sí solo: hace falta
además que `OTEL_EXPORTER_OTLP_ENDPOINT` apunte a `http://tempo:4318` en tu
`.env` (vacío de serie, incluso con el perfil encendido).

**blackbox-exporter** hace una petición HTTP real a `/api/v1/health` y
`/api/v1/ready` cada 30 segundos, desde fuera del proceso: es la diferencia
entre «el proceso vive» (lo que ya comprueba Docker) y «el borde sirve
tráfico de verdad». Es el mismo exportador que usa la comprobación de
caducidad del certificado TLS.

### 10.3 Seguir un fichaje concreto: de `scan_id` a la traza completa

Cuando alguien dice «yo fiché a las 07:02 y el sistema no lo tiene», el
camino para comprobarlo es:

1. En Grafana, entra en **Explore** con la fuente de datos **Loki** y filtra
   por `scan_id` (va en cada línea del registro técnico que tocó ese
   fichaje).
2. Cada línea de log que tenga traza asociada muestra un enlace **«Tempo»**
   junto al campo `trace_id`: ábrelo y verás la petición completa —cada paso,
   cada consulta a la base de datos— con sus tiempos.
3. Si la línea no tiene `trace_id` (por ejemplo, porque las trazas estaban
   apagadas ese día), el propio registro técnico sigue teniendo el detalle:
   la traza es un atajo, no la única fuente.

Esto solo funciona con el perfil `observability` encendido y con
`OTEL_EXPORTER_OTLP_ENDPOINT` configurado; sin trazas, el paso 1 (Loki) sigue
disponible igual.

### 10.4 Cuadros de mando y alertas

Prometheus evalúa el catálogo de alertas del documento 01 §9.3 sobre las
métricas del §8.2, y Grafana carga **cinco cuadros de mando versionados como
código** en `infra/observability/grafana/dashboards/` de la instalación —no
se editan desde la interfaz (`allowUiUpdates: false`): un cambio se hace en
el fichero y se despliega, igual que cualquier otra configuración—.

| Cuadro | Audiencia | Qué pregunta responde |
| --- | --- | --- |
| **Operación de quioscos** (`kronoqr-kiosks`) | Soporte / tu IT | El cuadro muestra el estado de cada dispositivo, su último latido, el tamaño de su cola pendiente, la versión desplegada y los escaneos por hora: responde a «¿algún punto de fichaje necesita atención ahora mismo?» |
| **Salud de la API** (`kronoqr-api`) | Desarrollo | El cuadro muestra el patrón RED (ritmo, errores, duración) por ruta, el estado de las colas y de la base de datos: responde a «¿el sistema está sirviendo tráfico con normalidad?» |
| **Integridad del dato** (`kronoqr-integrity`) | Desarrollo y cumplimiento | El cuadro muestra las divergencias de la reconciliación nocturna, el resultado de la verificación de la cadena de auditoría, las correcciones manuales y las incidencias por antigüedad: responde a «¿el registro sigue siendo fiable?» — y las dos series que deben quedarse siempre a cero, `projection_divergence_total` y `audit_chain_verification_failures_total`, son lo primero que muestra |
| **Negocio** (`kronoqr-business`) | RRHH y dirección | El cuadro muestra horas trabajadas por departamento, turnos abiertos, incidencias por tipo y alertas de cumplimiento: responde a «¿cómo va la plantilla esta semana?» |
| **Impacto y adopción** (`kronoqr-adoption`) | Dirección, y el propio fabricante en la venta | El cuadro muestra jornadas con registro completo, el reparto de fichajes por origen (QR, PIN, manual) y los patrones anómalos detectados: responde a «¿esto está sirviendo para algo?» |

Ninguno lleva marca ni nombre de cliente, y ninguno identifica a una
persona: como mucho, `device` o `employee_uuid` (regla dura 21). **Lo que el
cuadro de Negocio todavía no puede mostrar** —horas contratadas frente a
trabajadas, absentismo, impuntualidad— lo dice el propio panel de texto del
cuadro: son indicadores de tareas posteriores que hoy no tienen serie que
los alimente.

**Cómo llegan las alertas.** Alertmanager envía por **correo, con tu propio
SMTP** (las `MAIL_*` que ya rellenaste al instalar, §10.1) a tres
destinatarios — IT, RRHH y seguridad — y, si lo configuras, también a un
**webhook** por cada uno. Las nueve variables (`ALERT_EMAIL_IT`,
`ALERT_EMAIL_RRHH`, `ALERT_EMAIL_SEGURIDAD`, `ALERT_WEBHOOK_IT`,
`ALERT_WEBHOOK_RRHH`, `ALERT_WEBHOOK_SEGURIDAD`, `ALERT_MAINTENANCE_*`)
nacen vacías o con su valor de serie: un destino vacío no genera ese envío,
así que rellena al menos las tres de correo — `product:doctor` avisa **por
cada papel que se quede sin ningún camino de entrega**, uno a uno: IT, RRHH
y seguridad. No basta con rellenar uno solo y dar el aviso por bueno — con
solo IT relleno, una `RoturaDeCadenaDeAuditoria` (que es de seguridad) no
llegaría a nadie, y antes de esta revisión `doctor` no lo habría dicho. Una
instalación con alertas que no llegan a nadie es peor que sin alertas: se
cree vigilada sin estarlo. Tabla completa en
[`configuracion.md`](configuracion.md) §6.20.

**La interfaz de Alertmanager**, para ver el enrutado y los silencios
activos, escucha igual que Grafana — **solo en `127.0.0.1:9093`, nunca desde
internet** —, y se llega igual, por túnel SSH:

```bash
ssh -L 9093:127.0.0.1:9093 tu-usuario@fichaje.tuhotel.local
```

Y después, `http://127.0.0.1:9093` en tu navegador. **Alertmanager se vigila
a sí mismo** con las dos primeras filas de la tabla de abajo
(`EnrutadoDeAlertasCaido`, `EntregaDeAlertasFallando`): si el propio
servicio cae, o si algo impide entregar (el correo caído, un webhook que no
responde), lo sabrás — la segunda puede no llegarte por correo si lo roto es
justamente el correo, pero se ve igual en el cuadro «Salud de la API» y en
esta misma interfaz. Procedimiento en
[`entrega-de-alertas.md`](../runbooks/entrega-de-alertas.md).

**El catálogo completo**, con lo que hay que hacer al recibir cada una —
incluye las alertas del propio catálogo del doc 01 §9.3 y las que vigilan el
silencio de cada una (que ninguna alerta quede ciega porque el comando que
la alimenta dejó de ejecutarse):

| Alerta | Umbral | Severidad | Destinatario | Runbook | A las 06:30 |
| --- | --- | --- | --- | --- | --- |
| `EnrutadoDeAlertasCaido` | Alertmanager no responde, `for: 5m` | Crítica | IT | [`entrega-de-alertas.md`](../runbooks/entrega-de-alertas.md) | `docker compose ps alertmanager` y sus registros primero |
| `EntregaDeAlertasFallando` | Notificaciones fallidas en 15 min | Alta | IT | [`entrega-de-alertas.md`](../runbooks/entrega-de-alertas.md) | Revisa el SMTP y el webhook configurados; puede no llegarte por correo si lo roto es el correo |
| `QuioscoSinLatido` | > 10 min sin latido | Crítica | IT | [`quiosco-no-responde.md`](../runbooks/quiosco-no-responde.md) | Comprueba si la tablet enciende y tiene red; si sí, espera un latido; si no, atiéndela en persona |
| `ColaOfflineAtascada` | Cola de un dispositivo > 50 elementos | Alta | IT | [`cola-offline-atascada.md`](../runbooks/cola-offline-atascada.md) | Mira `kiosk:health`: no se pierde nada, pero no desvincules esa tablet todavía |
| `ColaOfflineSinVaciar` | La cola no baja a 0 en 2 h | Alta | IT | [`cola-offline-atascada.md`](../runbooks/cola-offline-atascada.md) | Igual: revisa la red o el certificado de ese punto |
| `ErroresDeServidorEnElFichaje` | > 1 % de `5xx` en `/api/v1/scan*`, 5 min | Crítica | IT | [`errores-en-el-panel.md`](../runbooks/errores-en-el-panel.md) | `product:doctor` primero: base de datos y disco son la causa más frecuente |
| `LatenciaDelFichajeAlta` | p95 del fichaje > 500 ms, 10 min | Alta | IT | [`errores-en-el-panel.md`](../runbooks/errores-en-el-panel.md) | Mira si coincide con el cambio de turno o con una actualización reciente |
| `SondaDelBordeFallida` | El servidor no responde, `for: 5m` | Crítica | IT | [`errores-en-el-panel.md`](../runbooks/errores-en-el-panel.md) | `docker compose ps` y los registros de `postgres`/`redis`, antes que el panel |
| `CertificadoTlsProximoACaducar` | < 21 días | Alta | IT | [`renovacion-certificado-tls.md`](../runbooks/renovacion-certificado-tls.md) | Empieza el trámite de renovación con el emisor de tu certificado |
| `CertificadoTlsCaducado` | Caducado | Crítica | IT | [`renovacion-certificado-tls.md`](../runbooks/renovacion-certificado-tls.md) | Todos los quioscos afectados: coloca el certificado renovado y recarga Nginx |
| `EspacioEnDiscoBajo` | < 20 % libre en `/` | Alta | IT | [`espacio-en-disco.md`](../runbooks/espacio-en-disco.md) | `docker system df`; libera imágenes antiguas antes que nada del registro |
| `MetricasDelAnfitrionAusentes` | Sin métricas del anfitrión | Media | IT | [`espacio-en-disco.md`](../runbooks/espacio-en-disco.md) | Comprueba que `node-exporter` sigue en pie |
| `TurnoAbiertoProlongado` | Turno abierto > 12 h | Media | RRHH | [`turno-abierto-prolongado.md`](../runbooks/turno-abierto-prolongado.md) | Pregunta a la persona a qué hora salió; el sistema nunca cierra el turno solo |
| `DescansoEntreJornadasInsuficiente` | Descanso por debajo del mínimo legal | Media | RRHH | [`turno-abierto-prolongado.md`](../runbooks/turno-abierto-prolongado.md) | Comprueba que las horas son correctas antes de tratarlo como planificación |
| `MetricaDeIncidenciasAusente` / `DeteccionDeIncidenciasAusente` | Silencio de la detección nocturna | Media | IT | [`turno-abierto-prolongado.md`](../runbooks/turno-abierto-prolongado.md) | Comprueba que el `scheduler` sigue vivo |
| `DeteccionDeIncidenciasConFallos` | La pasada de anoche falló | Alta | IT | [`errores-en-el-panel.md`](../runbooks/errores-en-el-panel.md) | `product:doctor`, y si señala a la reconciliación, sigue ese runbook en su lugar |
| `ErroresCriticosNuevos` | Grupo `critical` nuevo o reabierto en 5 min | Alta | IT | [`errores-en-el-panel.md`](../runbooks/errores-en-el-panel.md) | Abre «Errores» en el panel y sigue la columna «Qué hacer» de esa fila |
| `DivergenciaEnReconciliacionNocturna` | Cualquiera | Crítica | IT | [`divergencia-proyeccion.md`](../runbooks/divergencia-proyeccion.md) | La corrección ya está hecha; averigua quién escribió fuera del recálculo |
| `ReconciliacionConFallos` | La pasada de anoche falló | Alta | IT | [`divergencia-proyeccion.md`](../runbooks/divergencia-proyeccion.md) | Ejecuta `attendance:reconcile` a mano y mira el motivo del fallo |
| `ReconciliacionDeProyeccionAusente` | > 26 h sin reconciliar | Media | IT | [`divergencia-proyeccion.md`](../runbooks/divergencia-proyeccion.md) | Comprueba que el `scheduler` sigue vivo y ejecuta `attendance:reconcile` a mano |
| `RoturaDeCadenaDeAuditoria` | Cualquiera | Crítica | Seguridad | [`rotura-cadena-auditoria.md`](../runbooks/rotura-cadena-auditoria.md) | Preserva la evidencia (§2 de ese runbook) antes de tocar nada |
| `VerificacionDeAuditoriaAusente` | > 26 h sin verificar | Crítica | Seguridad | [`rotura-cadena-auditoria.md`](../runbooks/rotura-cadena-auditoria.md) | Comprueba que el `scheduler` sigue vivo |
| `ParticionDeAuditoriaAusente` | Falta la partición del año en curso | Crítica | IT | [`rotura-cadena-auditoria.md`](../runbooks/rotura-cadena-auditoria.md) | **El fichaje está caído**: `compliance:ensure-audit-partitions` ya |
| `ParticionDeAuditoriaDelProximoAnoSinPreparar` | Falta la del año próximo, desde noviembre | Media | IT | [`rotura-cadena-auditoria.md`](../runbooks/rotura-cadena-auditoria.md) | Comprueba el `scheduler` y `DB_MIGRATION_USERNAME` en el `.env` |
| `CopiaDeSeguridadFallida` / `CopiaDeSeguridadSinVerificar` | Cualquiera | Crítica | IT | [`restaurar-backup.md`](../runbooks/restaurar-backup.md) | `backup:verify` y reintenta con `backup:run` |
| `CopiaDeSeguridadAusente` | Sin métrica en 30 min | Crítica | IT | [`restaurar-backup.md`](../runbooks/restaurar-backup.md) | Comprueba el `scheduler` y que `BACKUP_PATH` está montado |
| `ArchivadoDeWalDetenido` | > 30 min sin archivar | Crítica | IT | [`restaurar-backup.md`](../runbooks/restaurar-backup.md) | Urgente: sin espacio, PostgreSQL termina parándose entero |
| `DiscoDeCopiasCasiLleno` | < 20 % libre en el volumen de copias | Media | IT | [`restaurar-backup.md`](../runbooks/restaurar-backup.md) | Amplía el disco o baja `BACKUP_RETENTION_DAYS` |
| `SimulacroDeRestauracionNuncaEjecutado` | Ninguno registrado todavía | Media | IT | [`restaurar-backup.md`](../runbooks/restaurar-backup.md) | Ejecútalo: sin él, nadie ha comprobado que las copias restauran de verdad |
| `SimulacroDeRestauracionCaducado` | Fallido o > 100 días | Media | IT | [`restaurar-backup.md`](../runbooks/restaurar-backup.md) | Repite el simulacro con la copia anterior si la última falla |
| `KronoqrAuthFailureBurst` | > 20 fallos en 5 min, un canal | Media | Seguridad | [`ataque-a-credenciales.md`](../runbooks/ataque-a-credenciales.md) | Determina si es una persona equivocándose o un intento automatizado |
| `KronoqrAuthLockouts` | ≥ 3 bloqueos distintos en 15 min, un canal | Media | Seguridad | [`ataque-a-credenciales.md`](../runbooks/ataque-a-credenciales.md) | Acota cuántas cuentas y si alguna llegó a entrar antes del bloqueo |
| `KronoqrAuthFailureSpike` | > 100 fallos en 5 min, un canal | Crítica | Seguridad | [`ataque-a-credenciales.md`](../runbooks/ataque-a-credenciales.md) | Preserva la evidencia antes de bloquear el origen en el borde |
| `VentanaDeMantenimientoActiva` | Mientras dura una actualización, tope 2 h | Info (no notifica) | — | [`actualizacion-cliente.md`](../runbooks/actualizacion-cliente.md) | Nada: solo silencia otras alertas mientras dura |

**Lo que las alertas de certificado NO vigilan.** `CertificadoTlsProximoACaducar`
y `CertificadoTlsCaducado` miran únicamente la **fecha de caducidad** — es lo
único que mide `probe_ssl_earliest_cert_expiry`. La sonda que la alimenta
sondea con `insecure_skip_verify: true` (tarea 3.1, deliberado: el
certificado del servidor de un hotel puede ser autofirmado o de una CA
propia, y verificar la cadena o el nombre daría siempre un falso negativo),
así que **la validez de la cadena, quién la firmó y si el nombre (`CN`)
coincide con tu dominio no los vigila ninguna alerta**. Si alguien sustituye
tu certificado por uno que no corresponde, no verás una alerta de
certificado por ello — verás que las tablets dejan de conectar
(`QuioscoSinLatido`, y con el tiempo `ColaOfflineAtascada`), porque son ellas
las que sí validan la identidad del servidor. Revisar la cadena a mano es
parte de la lista trimestral de endurecimiento
([`endurecimiento.md`](endurecimiento.md)).

**Si conectas un webhook a un servicio externo** (`ALERT_WEBHOOK_IT` y las
otras dos), las etiquetas y los textos de cada alerta que dispare —el nombre
del centro, el dispositivo, el componente afectado, el resumen y la
descripción— salen del servidor del hotel hacia ese servicio. **Nunca lleva
datos de personas** (regla dura 21: como mucho `device` o `employee_uuid`),
pero sí identifica a tu organización y a su instalación. Decidir si ese
servicio externo es adecuado, y contratarlo como encargado del tratamiento
si hace falta, es una decisión tuya y de tu DPO — el producto no la toma por
ti (ver [`obligaciones-legales.md`](obligaciones-legales.md)).

**La ventana de mantenimiento semanal.** `ALERT_MAINTENANCE_WEEKDAY`,
`ALERT_MAINTENANCE_START` y `ALERT_MAINTENANCE_END` (domingo 02:00–04:00 de
serie, en la zona horaria del servidor — nunca en el cambio de turno de las
06:00) silencian las alertas de quiosco, API, certificado y disco: un
servidor que se reinicia en su propia ventana no tiene que despertar a
nadie. **Lo que nunca se silencia, ni en esta ventana ni en la automática de
`update.sh`** (que abre la misma marca mientras dura una actualización, con
un **tope de 2 horas** por si el actualizador se cae sin retirarla — una
actualización real se mide en minutos): copia de seguridad, integridad del
dato, auditoría, autenticación e incidencias. Son justo las que hay que
poder ver durante un mantenimiento.

**Anti-fatiga: cinco quioscos callados a la vez llegan como una sola
notificación**, no como cinco. Las alertas de quiosco se agrupan por el
nombre de la alerta (no por dispositivo), así que un corte de red que afecte
a varias tablets a la vez produce un único correo con los cinco dispositivos
dentro, en vez de cinco avisos separados — es la lectura práctica de «un
único quiosco reiniciándose no debe despertar a nadie» del doc 02 §8.4.

**Los umbrales viven en la instalación, no en el repositorio del
fabricante.** `infra/observability/` viaja en tu paquete y lo puedes editar.
Solo un umbral está atado a otra parte del sistema y **tiene que cambiar a
la vez**: `KIOSK_HEALTH_SILENT_AFTER_SECONDS` ([`configuracion.md`](configuracion.md)
§6.14) y el umbral de `QuioscoSinLatido` son el mismo número — si los
separas, la consola (`kiosk:health`) y la alerta dirán cosas distintas del
mismo quiosco.

**Dos límites que conviene conocer.** No hay un Alertmanager común entre
instalaciones de clientes distintos: cada instalación tiene la suya, con sus
propios destinatarios, y eso es intencionado (ADR-016 — el fabricante no
recibe ninguna alerta, ADR-020). Y la serie que alimenta `QuioscoSinLatido`
vive en Redis: si Redis se vacía, un quiosco que ya estaba callado puede
quedarse sin serie —y con ella, sin poder disparar la alerta— hasta su
siguiente latido, que por definición no llegará; `kiosk:health` no depende
de Redis y es la segunda red de seguridad. El detalle completo está en
[`quiosco-no-responde.md`](../runbooks/quiosco-no-responde.md).

Grafana, igual que Alertmanager, **no se expone a internet** — ver el
principio de esta sección.

---

Las obligaciones que van con todo esto —qué informar, qué archivar, quién
autoriza— están en
[`obligaciones-legales.md`](obligaciones-legales.md).

Lo que se puede **cambiar** —umbrales operativos, marca e idiomas— y qué
consecuencias tiene cada cambio está en
[`configuracion.md`](configuracion.md).

---

## 11. Actualizar a una versión nueva

> **Tarea 5.7.** El procedimiento completo, con la vuelta atrás a mano, está en
> [`../runbooks/actualizacion-cliente.md`](../runbooks/actualizacion-cliente.md).
> Aquí, lo que hay que saber cada vez.

**El fichaje no se detiene.** Durante la actualización el panel, el portal y la
API de gestión responden «en mantenimiento» (503), pero los quioscos siguen
confirmando en local y encolando; al terminar sincronizan con la hora real de
cada fichaje. Si la ventana fue larga, la bandeja mostrará incidencias de
sincronización: no son un fallo.

```bash
cd /opt                                   # el paquete nuevo, AL LADO del actual
tar xzf kronoqr-<version>.tar.gz
cd kronoqr-<version>
sudo ./update.sh --check-only             # sin tocar nada: qué falta, si falta algo
sudo ./update.sh                          # actualiza, verifica y vuelve atrás sola si falla
```

Los siete pasos y lo que pasa si falla cada uno:

| Paso | Si falla | Estado en que queda | Qué hacer |
| --- | --- | --- | --- |
| 1 · Precondiciones | Sale `2` | **Nada tocado.** Sigue en su versión | La línea «Que hacer» de cada `[FALLA]`. Si dice que la versión instalada está fuera de la matriz, actualiza primero a la intermedia que indica |
| 2 · Mantenimiento | Sale `4` | Mantenimiento retirado; nada tocado | Reintentar. Si se repite, `docker compose logs app` |
| 3 · Copia previa | Sale `2` | Mantenimiento retirado; nada tocado. **Sin copia no hay actualización** | [`../runbooks/restaurar-backup.md`](../runbooks/restaurar-backup.md) §2 y volver a ejecutar |
| 4 · Migraciones | Vuelta atrás automática → `4` | Copia restaurada, versión anterior en marcha y verificada | Enviar el informe al fabricante antes de reintentar: dice en qué versión intermedia se paró |
| 5 · Arranque y verificación | Vuelta atrás automática → `4` | Igual que arriba. **La versión nueva nunca recibió tráfico**: se verifica sin borde | Igual que arriba |
| 6 · Vuelta atrás | Sale `5` | **Requiere una persona.** El mensaje distingue dos casos: solo quedó el mantenimiento puesto (retirarlo con `artisan up`, **sin restaurar nada**) o la restauración quedó a medias (tres órdenes y la ruta de la copia) | Runbook §5. Los quioscos siguen encolando mientras tanto |
| 7 · Informe | — | `BACKUP_PATH/reports/update-<fecha>.log`, siempre; al lado, `update-<fecha>.detalle.log` (solo root, salida cruda, **puede llevar datos personales**) | Adjuntar el informe al paquete de diagnóstico si se abre un caso; el detalle, solo tras revisarlo y si lo piden |

Los pasos 5 y 6 dejan además su propio asiento en `audit_log` (`system.updated`
o `system.restored_from_backup`): si por lo que sea no se puede escribir, la
actualización no se deshace por eso —el hecho ya ocurrió—, pero una
actualización que por lo demás terminó bien sale con `6` en vez de `0`, y el
informe lo dice en su propia línea. El asiento de la vuelta atrás solo se escribe si la versión a la
que se vuelve ya conoce esa acción (desde la 2.2.0): una anterior no sabría verificar la cadena con
él, así que el informe deja los datos y hay que escribirlo con `compliance:record-system-event`
después de la siguiente actualización.

**Lo que no cambia:** tus secretos (el `.env` se copia tal cual y solo cambia
`IMAGE_TAG`), los datos, la licencia (una licencia caducada **no impide
actualizar**), y el fichaje. **Lo que sí hace falta:** `BACKUP_ENCRYPTION_KEY`
en el `.env` y espacio para la copia y para la migración; el paso 1 lo dice con
cifras.

**Desde qué versiones se puede saltar** a la del paquete, sin tocar nada:
`./update.sh --supported-sources`. La regla es la versión menor vigente y las
dos anteriores; desde una más antigua, el script te dice a cuál ir primero.

**Segunda ejecución** sobre una instalación ya actualizada: sale `3`, «ya está
en la versión», y no toca nada.

---

## 12. Diagnóstico y soporte: `doctor`, el paquete y los accesos

Tres herramientas, y las tres funcionan con la licencia caducada o sin
activar: son justamente lo que hace falta cuando algo va mal.

### 12.1 `doctor`: la revisión de salud en un comando

```bash
docker compose exec app php artisan product:doctor          # informe legible
docker compose exec app php artisan product:doctor --json   # el mismo informe, para máquinas
docker compose exec app php artisan product:doctor --lang=en
```

Comprueba base de datos (conexión, migraciones pendientes, que el usuario de la
aplicación **no** pueda modificar el registro de auditoría, cadena de
auditoría), colas (Redis, atraso, que haya un trabajador vivo), correo
(transporte configurado y servidor alcanzable), certificado TLS (caducidad y
autofirmado), permisos (directorios de trabajo, copias, logotipo), espacio en
disco (aplicación y copias) y ajustes (zona horaria en UTC, modo depuración,
claves no válidas, diferencias entre el `.env` y lo guardado, licencia y marca).
**Cada línea en rojo dice qué hacer**, redactado para quien no conoce el
sistema.

| Código | Significa |
| --- | --- |
| `0` | Todo correcto |
| `1` | **Solo avisos.** Nada está roto; conviene leerlos cuando puedas. El estado de la licencia nunca pasa de aquí |
| `2` | **Al menos un fallo** que hay que corregir. La instalación sigue en pie y se sigue fichando |

`install.sh` lo ejecuta al final y solo el `2` lo detiene (sale con `6`, con
la instalación en pie); el `1` se muestra y no bloquea. `update.sh` también lo
ejecuta, pero **solo informa**: su resultado va al informe de la actualización
y a pantalla, y un fallo ambiental —disco, certificado caducado— no deshace
una actualización que ya ha verificado por otros medios.

Si la aplicación **no arranca** y no puedes ejecutar `artisan`, está
`./doctor.sh` (§8): hace desde fuera lo que puede —Docker, estado de cada
servicio, `.env`, disco, certificados, puertos— y te dice cómo arrancarla.

### 12.2 El paquete de diagnóstico: qué es y cómo se genera

Cuando abras una incidencia con soporte, lo primero que te pedirán es el
paquete. Lo generas tú, con un clic o con un comando, y lo envías por el canal
de tu contrato. **Soporte no entra en tu servidor** (ADR-020).

- **Desde el panel:** «Soporte» → «Paquete de diagnóstico» → «Generar y
  descargar». Solo lo ve la cuenta de administración.
- **Desde la consola:**

  ```bash
  docker compose exec app php artisan product:diagnostics
  ```

  Deja el fichero en `storage/app/diagnostics/` dentro del contenedor y te
  dice la ruta, el tamaño y la huella. Cópialo fuera con
  `docker compose cp app:/var/www/html/storage/app/diagnostics/<fichero> .`
  y **bórralo del servidor cuando lo hayas enviado**: es material desechable.
  Por si se olvida, el propio comando borra al arrancar los paquetes de más
  de `PRODUCT_DIAGNOSTICS_RETENTION_DAYS` días (7 de serie) y lo dice.

Es **un único fichero JSON legible**, `kronoqr-diagnostics-<versión>-<fecha>.json`,
sin cifrar a propósito: **ábrelo antes de enviarlo** y comprueba que no lleva
nada que no quieras enviar. Su cabecera (`manifest`) lleva la huella del resto
del documento; quien lo reciba puede comprobar que no se alteró por el camino
con `php artisan product:diagnostics --verify=<fichero>`.

**Qué lleva:** versión y entorno, configuración **solo de una lista de claves
permitidas** (nunca `LICENSE_KEY`, claves de firma del QR, de cifrado de
copias, de Reverb, ni credenciales de base de datos o de correo), estado de
los servicios y de las colas, el informe de `doctor`, el estado de la licencia
**sin tu razón social**, la salud de cada tablet **sin su nombre**, el
histórico de errores agrupado (a partir de la versión que lo incorpore),
contadores agregados, el informe de la última actualización (solo las líneas
del formato del informe, y nunca su `.detalle.log`) y **solo recuentos** del
registro de auditoría.

**Qué no lleva, por defecto:** nombres, correos, documentos, fichajes ni
jornadas de nadie. Los empleados aparecen solo como identificador. Una prueba
automática del producto lo comprueba sobre un paquete generado con 500
empleados y 90 días de fichajes.

### 12.3 Incluir datos personales es otra acción

Si la incidencia exige ver fichajes concretos —una discrepancia de nómina de
una persona, por ejemplo—, puedes incluirlos. **Es una decisión tuya, explícita
y auditada**, nunca el valor por defecto:

- Panel: marca «Incluir datos personales»; la pantalla te dice qué se va a
  incluir y que queda registrado, y solo entonces te deja generar.
- Consola: `php artisan product:diagnostics --with-personal-data --period-days=7`
  (máximo 31 días).

Añade la plantilla —**solo las personas con actividad en el periodo o con una
incidencia abierta**, nunca la plantilla entera— con código, nombre, estado y
departamento, los fichajes y tramos del periodo, y las incidencias abiertas. El paquete queda marcado como **no
anonimizado** y en tu auditoría aparece `diagnostics.personal_data_included`.
Al enviarlo comunicas datos personales a un tercero: mira
[`obligaciones-legales.md`](obligaciones-legales.md) §8 antes, y ten firmado
el contrato de encargo.

### 12.4 Conceder a soporte un acceso temporal

Es la excepción, no la norma: solo cuando el paquete no basta. Lo concedes tú,
con motivo, alcance y duración, y lo puedes revocar en cualquier momento.

- **Panel:** «Soporte» → «Accesos de soporte» → motivo, alcance, horas →
  «Conceder». El token se muestra **una sola vez**: cópialo y entrégalo a
  soporte por el canal del contrato. Si se pierde, revoca y crea otro.
- **Consola:**

  ```bash
  docker compose exec app php artisan support:grant --hours=24 --reason="Incidencia #123"
  docker compose exec app php artisan support:grant --hours=8 --reason="Incidencia #124" --scope=read_only
  docker compose exec app php artisan support:revoke <uuid>     # una concesión
  docker compose exec app php artisan support:revoke --all      # todas las activas
  ```

| Alcance | Soporte puede | No puede |
| --- | --- | --- |
| `diagnostics` (por defecto) | Generar el paquete **anonimizado** y consultar errores | Ver a nadie |
| `read_only` | Además, **leer** jornadas, plantilla y auditoría | Cambiar nada |
| `configuration` | Además, **cambiar** los ajustes operativos y emparejar o desvincular quioscos | Ver jornadas ni plantilla, ni tocar el perfil de cumplimiento (umbrales legales y años de conservación son tuyos) |

Con ningún alcance puede activar licencias, conceder o revocar accesos,
emitir o revocar tarjetas, corregir fichajes, generar informes de nómina o la
exportación para la Inspección, ni incluir datos personales en un paquete.

**Lo que queda registrado**, y lo ves en la misma pantalla y en tu auditoría:
quién concedió, por qué, con qué alcance, hasta cuándo, **cuándo se usó por
última vez** y cuándo se revocó (`support_grant.granted`, `support_grant.used`,
`support_grant.revoked`). El acceso **caduca solo**: cuando llega la hora, el
token deja de funcionar sin que nadie haga nada. Máximo 72 horas por concesión
(`PRODUCT_SUPPORT_GRANT_MAX_HOURS`).

Durante esa intervención el fabricante es encargado del tratamiento para ese
caso concreto: [`obligaciones-legales.md`](obligaciones-legales.md) §8.

### 12.5 Los parámetros

Ninguno se edita desde el panel: viven en el `.env` y los cambia quien
administra el servidor.

| Variable | De serie | Qué gobierna |
| --- | --- | --- |
| `PRODUCT_DIAGNOSTICS_MAX_BYTES` | `8388608` (8 MiB) | Tamaño máximo del paquete. Por encima se recortan secciones, empezando por los datos personales, y el recorte queda anotado |
| `PRODUCT_DIAGNOSTICS_RATE_LIMIT` | `3` | Paquetes por minuto y por cuenta desde el panel. Generar recorre la instalación entera |
| `PRODUCT_DIAGNOSTICS_PERSONAL_DATA_MAX_PERIOD_DAYS` | `31` | Máximo de días de fichajes que caben con «Incluir datos personales». Subirlo amplía lo que sale de tu servidor en un fichero |
| `PRODUCT_DIAGNOSTICS_RETENTION_DAYS` | `7` | Días que un paquete generado por consola permanece en `storage/app/diagnostics` antes de que el siguiente `product:diagnostics` lo borre |
| `PRODUCT_SUPPORT_GRANT_DEFAULT_HOURS` | `24` | Duración de una concesión si no se indica |
| `PRODUCT_SUPPORT_GRANT_MAX_HOURS` | `72` | Duración máxima admitida; más, se rechaza |
| `PRODUCT_SUPPORT_USE_AUDIT_WINDOW_SECONDS` | `900` | Cada cuánto, como máximo, se anota un nuevo `support_grant.used` por concesión, para que una sesión de soporte no llene tu auditoría |


---

## 13. Llevarte todos tus datos: la exportación íntegra

### 13.1 Qué es

Un único fichero ZIP, `kronoqr-export-<versión>-<fecha UTC>.zip`, con **todo**
lo que hay en tu instalación en formatos abiertos: un CSV por tabla (plantilla,
contratos, tarjetas, quioscos, tramos con todas sus versiones, correcciones con
autor y motivo, totales, incidencias, escaneos, auditoría completa con su
cadena de hash, cuentas de gestión, accesos de soporte), JSON para la
configuración, el perfil de cumplimiento y la licencia, un `manifest.json` con
el número de filas y la huella `sha256` de cada fichero, y un `README.md` que
explica cada fichero y cada columna en el idioma de la instalación. Las fechas
y horas van en **UTC**; el `README` indica la zona horaria del centro y cómo
convertirlas.

**Ningún secreto sale**: ni contraseñas, ni PIN, ni hashes, ni la clave de
licencia. Ningún número interno: las referencias entre ficheros van por `uuid`.

Es tu garantía de continuidad (RL-20): **funciona con la licencia caducada,
ausente o ilegible**, la genera solo el administrador de la instalación, y
**soporte del fabricante no puede generarla nunca**, con ningún alcance. Lo que
implica tener ese fichero en la mano está en
[`obligaciones-legales.md`](obligaciones-legales.md) §7 quater.

### 13.2 Cómo se genera

**Desde el panel:** Licencia → «Tus datos son tuyos» → «Generar exportación
completa». Confirmas el aviso, la exportación se encola y la pantalla la va
siguiendo (`pendiente` → `en curso` → `completada`); cuando termina aparece
«Descargar». Solo puede haber **una en curso** a la vez: si alguien ya la ha
pedido, el panel te enseña esa en lugar de empezar otra.

**Desde la consola**, en el acto y en primer plano:

```
docker compose --env-file .env -f compose.prod.yaml exec app php artisan product:export-all
```

Deja el fichero en `storage/app/exports/` dentro del contenedor, imprime la ruta,
el tamaño, la huella y el número de filas de cada fichero, y registra la
exportación igual que si la hubieras pedido desde el panel (aparece en la misma
lista y se puede descargar desde allí). Para sacarlo del contenedor:

```
docker compose --env-file .env -f compose.prod.yaml cp app:/var/www/html/storage/app/exports/<fichero> .
```

**Cuándo conviene la consola.** El panel descarga el ZIP entero en la memoria
del navegador antes de guardarlo. Por encima de ~1 GB —varios años de una
plantilla grande— genera desde la consola y saca el fichero con `cp`. La
cabecera `X-Kronoqr-Export-Sha256` de la descarga y la huella que imprime el
comando son la misma: sirven para comprobar que el fichero llegó entero.

### 13.3 Cuánto dura y qué queda anotado

El ZIP **caduca** a los `PRODUCT_DATA_EXPORT_RETENTION_DAYS` días (7 de serie):
cada hora se borran los caducados y la exportación pasa a `caducada` en la
lista; la anotación de que existió, con sus recuentos y su huella, no se borra
nunca. Si necesitas el fichero más tarde, genera otro.

En tu auditoría quedan `data_export.requested` (quién la pidió y por dónde),
`data_export.generated` (recuentos, huella y tamaño) y **`data_export.downloaded`
por cada descarga**: ante una brecha puedes responder quién se llevó qué y
cuándo.


**Si se queda en «Generando».** Una exportación que se interrumpe a mitad
—porque paraste los contenedores para actualizar, o porque el trabajador de
cola se reinició— no bloquea nada: pasado el tiempo máximo de generación (una
hora) el sistema la da por **fallida** con el motivo `stale` en cuanto alguien
pide otra o en la purga de la hora siguiente, y puedes generar de nuevo. No
hace falta tocar la base de datos; si de verdad ves una en curso más de una
hora sin que pase a fallida, ejecuta `php artisan product:export-all --purge`
y vuelve a pedirla.

**Si aparece como «fallida».** El motivo que enseña el panel es un código, no
un texto libre, para no sacar nunca un dato de una fila a la pantalla ni al
log: `write_failed` (no se pudo escribir en `PRODUCT_DATA_EXPORT_PATH`: revisa
espacio y permisos del directorio), `database_error` (la base de datos falló a
mitad: mira `product:doctor`), `stale` (interrumpida, ver arriba) o
`unexpected` (cualquier otro: el detalle técnico está en el log de la
aplicación con el `uuid` de la exportación). Corrige la causa y genera otra.

### 13.4 La telemetría, si la activas

Viene **desactivada** y el sistema funciona exactamente igual sin ella. Solo
se envía si se cumplen **tres** condiciones a la vez: `TELEMETRY_ENABLED=true`,
un destino `https://` en `TELEMETRY_ENDPOINT` y una licencia que incluya la
funcionalidad `telemetry` (`php artisan license:show` lo enseña). Si falta
cualquiera de las tres, `product:telemetry` lo dice y no se envía nada. Cuando
se cumplen, los lunes a las 05:40 UTC se envía un informe con
versiones, estado de la licencia, tamaño de la instalación por tramos y
contadores agregados; **nunca** datos de personas ni de jornada. La lista
exacta de campos, y el comando que te enseña el documento que se enviaría
antes de activar nada, están en [`configuracion.md`](configuracion.md)
§3 quinquies. Si el destino no responde, no verás ningún aviso: se anota en
el log técnico y se vuelve a intentar la semana siguiente.

### 13.5 Los parámetros

| Variable | De serie | Qué gobierna |
| --- | --- | --- |
| `PRODUCT_DATA_EXPORT_PATH` | `storage/app/exports` (en el contenedor) | Dónde se escriben los ZIP. Fuera de `BACKUP_PATH` a propósito: es material que caduca |
| `PRODUCT_DATA_EXPORT_RETENTION_DAYS` | `7` | Días que el ZIP se puede descargar antes de purgarse. La anotación se conserva |
| `PRODUCT_DATA_EXPORT_RATE_LIMIT` | `30` | Peticiones por minuto **por cuenta** a la lista y a la descarga; el cubo por dirección IP es cuatro veces mayor (120), para que varios administradores tras la misma salida a internet no se bloqueen entre sí. El panel sondea cada 5 s mientras hay una en curso |
| `PRODUCT_DATA_EXPORT_STALE_AFTER` | `3600` | Segundos tras los que una exportación que se quedó a medias (contenedor parado, cola reiniciada) se da por fallida con motivo `stale`, liberando la siguiente. No lo bajes por debajo de lo que tarda tu exportación más grande |
| `TELEMETRY_ENABLED` | `false` | Si se envía telemetría. Hace falta además `TELEMETRY_ENDPOINT` y que la licencia la incluya |
| `TELEMETRY_ENDPOINT` | vacío | A dónde se envía. Vacío de serie: lo fijas tú |

---

## 14. Lo que no se toca nunca

Cinco cosas que un administrador de sistemas hace a diario en otros productos y
que aquí destruyen el valor legal del registro o dejan la instalación sin
poder recuperarse:

| Nunca | Por qué | Lo que sí |
| --- | --- | --- |
| **Modificar datos por SQL directo** (`UPDATE`, `DELETE` o `INSERT` en las tablas de la aplicación) | Toda corrección conserva la versión anterior con autor, momento y motivo. Un cambio por SQL no deja rastro y convierte el registro en no fiable ante la Inspección | Las correcciones se hacen desde el panel, y quedan trazadas |
| **Borrar o alterar filas de `audit_log`** | Es solo-añadir y cada asiento va encadenado por hash al anterior. El usuario de base de datos de la aplicación **no tiene** `UPDATE` ni `DELETE` sobre esa tabla, a propósito; solo el rol de mantenimiento puede soltar particiones ya vencidas, y solo en la purga confirmada (§3) | Si la cadena no verifica: [`../runbooks/rotura-cadena-auditoria.md`](../runbooks/rotura-cadena-auditoria.md) |
| **Tocar `daily_totals` a mano** | Es una proyección reconstruible: se recalcula entera cada vez que cambia un tramo. Un total corregido a mano vuelve a su valor en el siguiente recálculo, sin que nadie entienda por qué | Si un total no cuadra, recalcúlalo: `docker compose exec app php artisan attendance:reconcile --from=2026-09-01 --to=2026-09-30` |
| **Editar en el `.env` un secreto generado** (`APP_KEY`, `QR_SIGNING_KEY_*`, `BACKUP_ENCRYPTION_KEY`) | Cambiar `APP_KEY` deja ilegible lo cifrado; cambiar la clave QR invalida todas las tarjetas; cambiar la de copias deja las copias anteriores sin poder restaurar | Rotar con su procedimiento: [`../runbooks/rotacion-secretos.md`](../runbooks/rotacion-secretos.md) y [`../runbooks/rotacion-clave-qr.md`](../runbooks/rotacion-clave-qr.md) |
| **`migrate:rollback`, borrar volúmenes o reinstalar encima** | Una vuelta atrás es siempre restaurar la copia verificada previa; el instalador se niega a reinstalar sobre una instalación existente | `update.sh` (§11) y [`../runbooks/restaurar-backup.md`](../runbooks/restaurar-backup.md) |

---

## 15. El histórico de errores: qué falla y desde cuándo

> **Tarea 5.12.** Cómo se lee y qué se hace con cada severidad, paso a paso,
> está en [`../runbooks/errores-en-el-panel.md`](../runbooks/errores-en-el-panel.md):
> este apartado es el resumen para saber qué es y cómo se usa desde el día a
> día, no el procedimiento de diagnóstico.

### 15.1 Qué es

Todo error de la aplicación —de una petición, de un trabajo de cola, de una
tarea programada o de un comando de consola— y todo error que reportan las
tres aplicaciones cliente (quiosco, panel, portal) queda en `error_events`,
**agrupado por huella**: la misma repetición no crea una fila nueva, sube
`occurrences` y actualiza la fecha de la última vez. Se conserva 90 días y se
purga sola (§15.4).

No es tu auditoría (`audit_log`, cuatro años, valor probatorio) ni tu log
técnico (Loki, opcional, puedes no tenerlo). Es lo único que existe siempre,
en la misma base de datos que respaldas a diario, para responder a **«¿qué
está fallando, y desde cuándo?»** sin tener que conocer el sistema por dentro.
**Nunca lleva nombres, correos ni fichajes de nadie**: solo identificadores
técnicos. No es una promesa sin mecanismo — el servidor sanea el mensaje
(correos, DNI, teléfonos, horas y cualquier texto entre comillas, que es donde
una excepción interpola un valor variable), un fallo de base de datos nunca
imprime lo que se intentó guardar, y el contexto solo admite una lista cerrada
de claves técnicas. El detalle completo, mecanismo a mecanismo, está en
[`../runbooks/errores-en-el-panel.md`](../runbooks/errores-en-el-panel.md) §2.

Un dato de nivel: **el nivel `critical` de un error de cliente solo lo produce
un quiosco** — el panel y el portal nunca generan una fila `critical`, y los
códigos posibles son un catálogo cerrado por origen que el servidor valida.

### 15.2 La pantalla del panel

**Errores**, con filtros de origen, severidad, estado y periodo. Cada fila
trae la severidad, el origen, el mensaje, cuántas veces ha ocurrido
(`occurrences`), la primera y la última vez, y un `trace_id` copiable. El
detalle de cada fila incluye un texto de **qué hacer**, escrito para quien no
conoce el sistema. Un botón **«Marcar como resuelto»** la cierra; si el mismo
error vuelve a ocurrir, la fila se reabre sola, sin que tengas que hacer
nada — es la señal de que el arreglo no fue tal. **Quién lo resolvió** se ve
con nombre desde una cuenta de gestión normal, pero no desde un acceso de
soporte concedido al fabricante: ese acceso ve que la fila está resuelta,
nunca quién la resolvió.

### 15.3 Los comandos

Para consultarlo desde la consola, o para que un script pregunte por ti:

```bash
docker compose exec app php artisan product:errors --since=24h --level=critical
```

Sale `0` si no hay ninguno abierto, `1` si hay de nivel `error` y `2` si hay
alguno `critical` — igual criterio que `product:doctor`. Con `--json` da lo
mismo para máquinas; con `--source=` acota a un origen (`api`, `worker`,
`scheduler`, `console`, `kiosk`, `admin`, `portal`).

### 15.4 La purga a 90 días y `ERROR_HISTORY_RETENTION_DAYS`

Cada día, a las 03:35 UTC, se borran las filas cuya última aparición supera
`ERROR_HISTORY_RETENTION_DAYS` días (90 de serie). **No pide confirmación y no
deja asiento**: es una purga de datos técnicos sin valor legal (RL-11), no la
retención del registro horario (RF-PR-03, sección 3, que sí exige la frase de
confirmación). Para comprobar qué borraría sin borrar nada:

```bash
docker compose exec app php artisan product:errors:prune --dry-run
```

Y, como con el resto del registro, **`error_events` no se edita a mano**: ni
por SQL directo ni tocando la fila desde fuera del panel o de estos dos
comandos. Marcar un error como resuelto, o dejar que la purga automática lo
retire a los 90 días, son las dos únicas formas correctas de que una fila
desaparezca de la lista de abiertos.

### 15.5 Los parámetros

| Variable | De serie | Qué gobierna |
| --- | --- | --- |
| `ERROR_HISTORY_RETENTION_DAYS` | `90` | Días que se conserva una fila desde su última aparición. Igual que el log técnico (RL-11) |
| `PRODUCT_CLIENT_ERRORS_RATE_LIMIT` | `12` | Peticiones por minuto y por sesión del panel o del portal para reportar errores; por IP, cuatro veces más |
| `PRODUCT_ERRORS_MAX_OPEN_GROUPS_PER_SOURCE` | `500` | Techo de grupos **abiertos** por origen. Por encima, la siguiente ocurrencia que no encaja en un grupo existente va a un grupo de desbordamiento de ese origen (`overflow`) en vez de crear fila; `product:doctor` avisa del tamaño de la tabla |
