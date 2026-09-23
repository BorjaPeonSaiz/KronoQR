# Operación de KronoQR — lo que hay que atender, y cada cuánto

> **Estado.** Las secciones 1 a 6 son de la **tarea 2.10**: la retención y la
> purga, que es la única operación del producto que borra datos. La 7 es de la
> **5.3** (licencia). Las **8, 9 y 10** son de la **5.4**: los códigos de
> salida de los cinco scripts, la custodia de secretos y qué pierdes si apagas
> la observabilidad. La **11** es de la **5.7**: actualizar. Las **12 y 13**
> son de la **5.9** y la **5.10**: diagnóstico, soporte y exportación íntegra.
> La **15** es de la **5.12**: el histórico de `error_events`. La **16** es de
> la **3.3**: la pantalla de quioscos del panel, el código de servicio y la
> pantalla de diagnóstico de la tablet.

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
| 04:25 UTC, a diario | Se purgan los informes generados en segundo plano que han caducado: se borra el fichero, la anotación queda (§6 y §13) | Nada |
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

**Los informes generados en segundo plano** (RRHH los pide desde el panel:
[`guia-rrhh.md`](guia-rrhh.md) §6.3) tienen sus propios seis parámetros y su
propia purga. Los ficheros son **por persona solicitante**, viven en
`REPORTING_EXPORT_PATH` y los borra el planificador a las 04:25 UTC en cuanto
caducan; la anotación de que existieron se conserva siempre. Si alguna vez
necesitas adelantarla:

```bash
docker compose exec app php artisan reporting:purge-expired-exports
```

| Variable | De serie | Qué hace |
| --- | --- | --- |
| `REPORTING_EXPORT_PATH` | `storage/app/reports` | Dónde se escriben esos ficheros. **Nunca dentro de `BACKUP_PATH`**: caducan solos y no deben entrar en la copia |
| `REPORTING_EXPORT_RETENTION_DAYS` | `7` | Días que el fichero se puede descargar antes de que la purga diaria lo borre |
| `REPORTING_EXPORT_LINK_TTL_MINUTES` | `15` | Minutos que vale el enlace de descarga, que además es **de un solo uso** |
| `REPORTING_EXPORT_TIMEOUT_SECONDS` | `600` | Tope de la consulta del informe en diferido. Súbelo si una exportación grande falla por tiempo |
| `REPORTING_EXPORT_STALE_AFTER` | `3600` | Segundos tras los que una generación interrumpida se da por fallida y deja pedir otra. No lo bajes por debajo de lo que tarda tu informe más grande |
| `REPORTING_EXPORT_DOWNLOAD_RATE_LIMIT` | `30` | Descargas por minuto y por dirección IP en la ruta de descarga, que se abre sin sesión |

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

### Las cuentas del panel: alta, baja y contraseña

Las cuentas de gestión se crean por consola y **se retiran por consola**. En esta
versión **no hay pantalla de cuentas de gestión en el panel**, y por eso estas
cuatro órdenes son todo el ciclo de vida de una cuenta. Las cuatro dejan
constancia en el registro de auditoría, con su autor, su fecha y su motivo.

```bash
# Alta de una cuenta con su rol. Pide la contraseña por consola, sin eco
docker compose exec app php artisan identity:create-user --role=rrhh

# Baja. Deja de poder entrar, y sus sesiones abiertas dejan de valer al instante
docker compose exec app php artisan identity:deactivate-user persona@tuhotel.example --reason="Baja del hotel"

# Contraseña nueva, generada y mostrada UNA sola vez
docker compose exec app php artisan identity:reset-password persona@tuhotel.example

# Retirar el segundo factor a quien perdió el móvil, para que lo dé de alta otra vez
docker compose exec app php artisan identity:2fa-reset 0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90
```

Tres cosas que conviene saber de la baja:

- **No borra nada.** La cuenta se queda con todo su historial, que es justo lo
  que permite responder meses después a «¿quién corrigió esta jornada?». Lo único
  que pierde es la capacidad de entrar.
- **Tiene efecto en la petición siguiente**, no cuando caduque la sesión: si esa
  persona tenía el panel abierto en una tablet, deja de funcionar al instante.
- **No reabre la creación del primer administrador.** Aunque des de baja a la
  última cuenta que queda, esa puerta sigue cerrada — si se reabriera, dar de
  baja a alguien sería una forma de crear un administrador sin credenciales.

La contraseña que genera `identity:reset-password` **no se puede volver a
consultar**: el producto guarda su huella, no la contraseña. Anótala al
ejecutarlo y entrégala en mano, nunca por correo ni por mensajería.

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
| `CertificadoTlsNoVerificable` | No verifica contra `APP_URL` (cadena, nombre o emisor), `for: 15m` | Crítica | IT | [`renovacion-certificado-tls.md`](../runbooks/renovacion-certificado-tls.md) §3.4 | Revisa cadena, nombre y emisor con `openssl -verify_return_error`; no dispara si declaraste `TLS_ALLOW_SELF_SIGNED=true` |
| `SaturacionDelBordeEnElFichaje` | > 20 respuestas `429` en 5 min en las rutas de fichaje | Alta | IT | [`saturacion-del-borde.md`](../runbooks/saturacion-del-borde.md) | Mide solo la capa de aplicación (sin exportador de Nginx todavía); revisa si es un dispositivo concreto o un origen fuera de la VLAN |
| `RechazoDeFirmaQr` | > 20 escaneos con firma inválida en 15 min | Crítica | Seguridad | [`ataque-a-credenciales.md`](../runbooks/ataque-a-credenciales.md) §8 | Es un incidente, no una avería: preserva evidencia antes de tocar nada |
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

**Lo que las alertas de certificado vigilan, y desde cuándo.**
`CertificadoTlsProximoACaducar` y `CertificadoTlsCaducado` miran únicamente
la **fecha de caducidad** — es lo único que mide
`probe_ssl_earliest_cert_expiry`. La sonda que la alimenta sondea con
`insecure_skip_verify: true` (tarea 3.1, deliberado: el certificado del
servidor de un hotel puede ser autofirmado o de una CA propia, y verificar la
cadena o el nombre desde **dentro** de la red de contenedores daría siempre
un falso negativo), así que esas dos alertas nunca ven la cadena, el emisor
ni el nombre.

**Desde la tarea 3.8, una tercera alerta sí vigila eso: `CertificadoTlsNoVerificable`.**
Sondea contra `APP_URL` — el dominio público real, por el que llegan tus
clientes — con `insecure_skip_verify: false`: exige cadena completa, emisor
de confianza y nombre coincidente, igual que un navegador de verdad. Si
alguien sustituye tu certificado por uno que no corresponde, o falta un
intermedio en la cadena, ahora **sí** recibes una alerta por ello, sin
esperar a que las tablets dejen de conectar (`QuioscoSinLatido`, y con el
tiempo `ColaOfflineAtascada` — eso seguía siendo la única señal antes de la
3.8). Esta tercera alerta necesita que `APP_URL` esté configurada: si tu
instalación declaró `TLS_ALLOW_SELF_SIGNED=true` a propósito (solo válido en
entornos de prueba), la sonda ni se levanta — un certificado autofirmado
elegido a conciencia nunca pasaría esta verificación, así que sondearlo
igual solo produciría una alerta permanente por algo ya conocido y aceptado.
Revisar la cadena a mano sigue formando parte de la lista trimestral de
endurecimiento ([`endurecimiento.md`](endurecimiento.md)), como red de
seguridad adicional.

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
| `configuration` | Además, **cambiar** los ajustes operativos y emparejar o desvincular quioscos | Ver jornadas ni plantilla, ni tocar el perfil de cumplimiento (umbrales legales y años de conservación son tuyos), **ni activar o desactivar el fichaje de pausa** (`ATTENDANCE_BREAK_CLOCKING`: decide qué se considera incidencia, igual que el perfil; si lo intenta obtiene un 403), **ni ver ni cambiar el código de servicio del quiosco**: lo recibe vacío y marcado como redactado, y si intenta cambiarlo obtiene un 403 (§16.5) |

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

> **Esto no son los informes que RRHH genera en segundo plano**, y conviene no
> mezclarlos cuando alguien pregunte por «una exportación que no llega». Son dos
> mecanismos distintos, con dos carpetas distintas y dos purgas distintas:
>
> | | **Exportación íntegra** (este apartado) | **Informe en segundo plano** (§6) |
> | --- | --- | --- |
> | Qué es | Un ZIP con **toda** la instalación | Un CSV, Excel o PDF de un informe o de la salida a nómina |
> | Quién la pide | Solo el administrador de instalación | Quien genera informes: RRHH, administración y responsables |
> | Cuántas a la vez | Una **en toda la instalación** | Una **por persona solicitante**: nadie estorba a nadie |
> | Quién la descarga | El administrador, con su sesión del panel | **Solo quien la pidió**, con un enlace **de un solo uso** que caduca en minutos y no lleva sesión |
> | Cuánto dura el fichero | `PRODUCT_DATA_EXPORT_RETENTION_DAYS` (7 días) | `REPORTING_EXPORT_RETENTION_DAYS` (7 días) |
> | Quién la purga | La tarea horaria | La tarea diaria |
>
> Lo que sí comparten: **el fichero se borra y la anotación de que existió no**,
> y cada descarga queda registrada.
>
> Con un matiz propio del informe en segundo plano: al borrar el fichero, la
> anotación **se queda además sin el empleado ni el alcance que se consultaron**.
> Ese detalle no se pierde —vive en el registro de auditoría, que es donde tiene
> plazo legal y protección contra escritura—, pero deja de estar en una tabla
> operativa donde nadie lo necesita ya.

### 13.1 Qué es

Un único fichero ZIP, `kronoqr-export-<versión>-<fecha UTC>.zip`, con **todo**
lo que hay en tu instalación en formatos abiertos: un CSV por tabla (plantilla,
contratos, ausencias con todas sus versiones, tarjetas, quioscos, tramos con
todas sus versiones, correcciones con autor y motivo, totales, incidencias,
escaneos, informes generados en segundo plano, auditoría completa con su
cadena de hash, cuentas de gestión, accesos de soporte), JSON para la
configuración, el perfil de cumplimiento y la licencia, un `manifest.json` con
el número de filas y la huella `sha256` de cada fichero, y un `README.md` que
explica cada fichero y cada columna en el idioma de la instalación. Las fechas
y horas van en **UTC**; el `README` indica la zona horaria del centro y cómo
convertirlas.

**Ningún secreto sale**: ni contraseñas, ni PIN, ni hashes, ni la clave de
licencia. Ningún número interno: las referencias entre ficheros van por `uuid`.
Las claves de configuración confidenciales viajan **marcadas como redactadas y
sin valor** (`value_redacted`), igual que en el asiento de auditoría que
registra su cambio: **el código de servicio del quiosco viaja marcado como
redactado, no en claro** (§16.5).

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

Seis cosas que un administrador de sistemas hace a diario en otros productos y
que aquí destruyen el valor legal del registro o dejan la instalación sin
poder recuperarse:

| Nunca | Por qué | Lo que sí |
| --- | --- | --- |
| **Modificar datos por SQL directo** (`UPDATE`, `DELETE` o `INSERT` en las tablas de la aplicación) | Toda corrección conserva la versión anterior con autor, momento y motivo. Un cambio por SQL no deja rastro y convierte el registro en no fiable ante la Inspección | Las correcciones se hacen desde el panel, y quedan trazadas |
| **Borrar o alterar filas de `audit_log`** | Es solo-añadir y cada asiento va encadenado por hash al anterior. El usuario de base de datos de la aplicación **no tiene** `UPDATE` ni `DELETE` sobre esa tabla, a propósito; solo el rol de mantenimiento puede soltar particiones ya vencidas, y solo en la purga confirmada (§3) | Si la cadena no verifica: [`../runbooks/rotura-cadena-auditoria.md`](../runbooks/rotura-cadena-auditoria.md) |
| **Tocar `daily_totals` a mano** | Es una proyección reconstruible: se recalcula entera cada vez que cambia un tramo. Un total corregido a mano vuelve a su valor en el siguiente recálculo, sin que nadie entienda por qué | Si un total no cuadra, recalcúlalo: `docker compose exec app php artisan attendance:reconcile --from=2026-09-01 --to=2026-09-30` |
| **Editar en el `.env` un secreto generado** (`APP_KEY`, `QR_SIGNING_KEY_*`, `BACKUP_ENCRYPTION_KEY`) | Cambiar `APP_KEY` deja ilegible lo cifrado; cambiar la clave QR invalida todas las tarjetas; cambiar la de copias deja las copias anteriores sin poder restaurar | Rotar con su procedimiento: [`../runbooks/rotacion-secretos.md`](../runbooks/rotacion-secretos.md) y [`../runbooks/rotacion-clave-qr.md`](../runbooks/rotacion-clave-qr.md) |
| **`migrate:rollback`, borrar volúmenes o reinstalar encima** | Una vuelta atrás es siempre restaurar la copia verificada previa; el instalador se niega a reinstalar sobre una instalación existente | `update.sh` (§11) y [`../runbooks/restaurar-backup.md`](../runbooks/restaurar-backup.md) |
| **Desvincular un quiosco con fichajes pendientes, o borrarle los datos del sitio**, para que deje de sonar la alerta | Los fichajes de la cola local viven solo en esa tablet hasta que se envían: desvincularla o limpiarla los pierde, y son jornadas de personas que sí ficharon | Vacía la cola primero (§16.4) y comprueba que está a 0. Si la tablet se ha extraviado y no hay alternativa, desvincula y avisa a RRHH: esas horas hay que reconstruirlas por corrección manual con `FALLO_TECNICO_QUIOSCO` |

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

---

## 16. La pantalla «Quioscos» del panel

> **Tarea 3.3.** Qué hacer, paso a paso, cuando un quiosco deja de dar señales
> está en [`../runbooks/quiosco-no-responde.md`](../runbooks/quiosco-no-responde.md);
> el alta y la sustitución de una tablet, en
> [`../runbooks/alta-nuevo-quiosco.md`](../runbooks/alta-nuevo-quiosco.md).
> Este apartado es lo que hace falta para **leer** la pantalla y para
> **custodiar el código de servicio**, no el procedimiento de diagnóstico.

**Panel → «Quioscos»** (`/devices`). La abre quien tiene el ámbito
`settings:*` —el mismo del perfil de cumplimiento y de la marca— y la
autorización real la pone el servidor: **solo el rol administrador** puede
listar, emparejar o desvincular. Dar de alta un quiosco es crear un origen de
fichajes, y desvincularlo es retirarlo; no es una pantalla de consulta.

### 16.1 Qué muestra cada fila

| Columna | Qué dice | Cómo se lee |
| --- | --- | --- |
| **Nombre y estado** | El nombre con el que se emparejó la tablet, y si sigue vinculada o está desvinculada | Un quiosco desvinculado se queda en la lista con su historia: la tablet de repuesto se vincula **con el mismo nombre** para conservarla |
| **Versión de la aplicación** | La versión de la PWA que esa tablet tiene cargada | Si una tablet se queda atrás tras una actualización, aquí se ve. Se pone al día al recargar la PWA |
| **Último contacto** | El instante del último latido, **en la zona horaria del centro**, y su antigüedad («hace 40 s») | El latido llega **cada 60 segundos**. La antigüedad se mide contra el **reloj del servidor**, que viaja en la respuesta, nunca contra el del ordenador desde el que miras: un panel con la hora mal puesta no inventa quioscos caídos |
| **Pendientes** | Cuántos fichajes tiene la tablet en su cola local sin enviar, y **de cuándo es el más antiguo** | «37 pendientes, el más antiguo de hace 3 h» es una tablet que lleva tres horas sin red, no un error. Los fichajes están a salvo mientras la tablet no se desvincule ni se le borren los datos del sitio |
| **Batería** | El nivel y si está cargando; **«no informa»** cuando la tablet no publica el dato | Solo Chrome sobre Android ofrece el nivel de batería al navegador: una tablet que no lo informa **no está averiada**, simplemente no lo cuenta. Una que se descarga **sin cargar** es casi siempre un cargador desenchufado |
| **Estado** | El **veredicto** (al día, aviso, fallo, desvinculado) y **su razón** | Nunca se distingue solo por el color: cada fila lleva su texto y su icono (§16.2) |

La lista no pagina: una instalación es un hotel con unos pocos quioscos y
caben todos en la pantalla.

### 16.2 El veredicto: la misma regla en el panel, en la consola y en la alerta

El veredicto **lo calcula el servidor**, con la misma regla y los mismos
umbrales que usan `php artisan kiosk:health` y la alerta `QuioscoSinLatido`
(§10.4). No hay tres criterios: hay uno. Si el panel dice «fallo», la consola
dice `FALLO` y la alerta suena, del mismo quiosco y a la vez.

| Veredicto | Razón que muestra | Qué significa |
| --- | --- | --- |
| **Al día** | *Latiendo* | Latido de hace menos de `KIOSK_HEALTH_FRESH_WITHIN_SECONDS` (120 s) y nada pendiente |
| **Aviso** | *Latido tardío* | Más de 120 s sin latido, pero menos de 10 minutos. Un latido perdido no es una avería |
| **Aviso** | *Fichajes pendientes* | **Tiene red y sigue con fichajes sin enviar.** Ver [`../runbooks/cola-offline-atascada.md`](../runbooks/cola-offline-atascada.md) |
| **Aviso** | *Batería baja* | Nivel igual o por debajo de `KIOSK_HEALTH_BATTERY_LOW_PERCENT` (15 %) **y sin cargar**. Una tablet de pared que se descarga es una tablet a la que alguien quitó el cargador |
| **Aviso** | *Esperando el primer latido* | Recién emparejada y todavía sin hablar. Se resuelve sola en un minuto |
| **Fallo** | *Sin señal* | Más de `KIOSK_HEALTH_SILENT_AFTER_SECONDS` (600 s, 10 minutos) sin latido |
| **Fallo** | *Nunca ha dado señal* | Emparejada hace más de diez minutos y sin un solo latido |
| **Desvinculado** | *Desvinculado* | Ya no es un origen de fichajes. No cuenta para ninguna alerta |

Cuando hay más de un motivo, la fila muestra **el más grave**, en este orden:
desvinculado, nunca ha dado señal, sin señal, latido tardío, batería baja,
fichajes pendientes, latiendo.

La leyenda al pie de la pantalla **dice los umbrales reales de tu
instalación**, no unos supuestos: si cambias uno, la leyenda cambia con él. Y
si cambias `KIOSK_HEALTH_SILENT_AFTER_SECONDS`, tienes que cambiar **a la vez**
el umbral de la alerta `QuioscoSinLatido` en `infra/observability/` (§10.4):
son el mismo número, y separarlos es justamente lo que rompe la coherencia que
esta pantalla existe para dar.

### 16.3 «Sin latido» no es «sin fichar»

Es lo primero que hay que saber al mirar una fila en fallo. Que un quiosco no
hable con el servidor **no quiere decir que nadie pueda fichar en él**: el
quiosco nunca bloquea al empleado. Si la tablet está encendida, sigue
aceptando tarjetas, confirmando en pantalla y guardando cada fichaje en su
cola local con su hora real; cuando recupera la red, lo envía todo con la hora
a la que ocurrió de verdad.

Lo que sí deja a la gente sin poder fichar es una tablet **apagada, sin
corriente o rota**. Por eso la primera pregunta del diagnóstico no es de red:
es «¿está encendida la pantalla?».

Las horas de quien no pudo fichar se corrigen después desde el panel con el
motivo `FALLO_TECNICO_QUIOSCO`, preguntando a la persona. **Nunca se inventa
una hora.**

### 16.4 Qué hacer cuando una fila no está al día

**Cuando —y solo cuando— el veredicto no es «al día»**, la fila trae un bloque
**«Qué hacer»** escrito para quien no conoce el sistema, con el paso siguiente
según la razón. Un quiosco que va bien no pide nada. El resumen:

| Lo que ves | Por dónde empezar |
| --- | --- |
| **Fallo, sin señal** | [`../runbooks/quiosco-no-responde.md`](../runbooks/quiosco-no-responde.md) §2, que arranca en esta misma pantalla |
| **Aviso, fichajes pendientes** — la tablet **tiene red y sigue con fichajes sin enviar** | [`../runbooks/cola-offline-atascada.md`](../runbooks/cola-offline-atascada.md). **No desvincules esa tablet**: perderías la cola |
| **Aviso, batería baja** | Ve al punto de montaje: cargador desenchufado, regleta apagada o cable partido |
| **Aviso, latido tardío** | Nada todavía. Si no vuelve a «al día» en diez minutos pasará a fallo y sonará la alerta |
| **La tablet volvió sola a la pantalla de emparejamiento** | Alguien la desvinculó o rotó su token: [`../runbooks/alta-nuevo-quiosco.md`](../runbooks/alta-nuevo-quiosco.md) §6 |

**Si la cola no baja aunque la red de ese punto vaya bien**, y el «más antiguo»
de la columna «Pendientes» se queda clavado en la misma hora día tras día,
puede haber en ella un fichaje que ya nunca podrá cuadrar: lo cubre el §4 de
[`../runbooks/cola-offline-atascada.md`](../runbooks/cola-offline-atascada.md),
«Un elemento que jamás podrá cuadrar». No hay nada que tocar en la tablet.

La misma información, desde la consola y sin abrir el panel —y la única que
sigue funcionando si Redis se ha vaciado (§10.4)—:

```bash
docker compose exec app php artisan kiosk:health
```

Sale `0` si todo está al día, `1` con avisos y `2` con algún fallo; `--json`
devuelve lo mismo para un script y `--lang=es|en` fija el idioma del informe.

### 16.5 El código de servicio y la pantalla de diagnóstico de la tablet

Cuando el problema está **en la tablet**, la tablet lo cuenta por sí misma.
Tiene una pantalla de diagnóstico que se abre con una **pulsación larga de
tres segundos sobre el reloj** de la pantalla de fichaje (y de la de
emparejamiento, para una tablet que todavía no es quiosco). Se protege con el
**código de servicio** de la instalación.

**Dónde se pone el código.** Panel → **Ajustes operativos** (`/settings`),
campo «Código de servicio del quiosco» (`KIOSK_SERVICE_CODE`), rol
administrador. **De 8 a 12 dígitos** —solo cifras, porque la tablet únicamente
tiene el teclado numérico en pantalla—. **Nace vacío**: mientras no pongas
uno, la pantalla se abre sin código y lo dice en su cabecera; `product:doctor`
(§12.1) te lo recuerda como aviso, nunca como fallo.

**Cómo llega a las tablets.** El código **no viaja nunca en claro**: el
servidor manda su huella dentro del latido y la tablet comprueba el código
contra ella **en local**. Dos consecuencias prácticas: la pantalla de
diagnóstico **funciona sin red** —que es justo cuando hace falta— y un código
cambiado en el panel está en todas las tablets **en menos de un minuto**, sin
tocar ninguna. Cinco intentos fallidos seguidos bloquean el teclado 60
segundos, y el bloqueo **se guarda en la tablet**: salir de la pantalla o
recargar la aplicación no lo salta.

**Una tablet que todavía no ha recibido ningún latido posterior a la
configuración del código abre la pantalla sin él.** Es el caso de una tablet
emparejada que lleva sin red desde antes de que pusieras el código: no hay
forma de que lo conozca, y la alternativa —negarle el diagnóstico— sería dejar
sin diagnosticar justamente a la tablet que peor está. Se ve en la cabecera de
la propia pantalla, que dice si está abierta con código o sin él.

**Custodia.** El código lo guarda tu IT, como cualquier otra credencial de
mantenimiento: no se pega en una etiqueta detrás de la tablet ni se comparte
con recepción. **Solo lo ve quien puede editarlo**: el producto no lo enseña en
ningún sitio salvo en su propio campo de «Ajustes operativos», que solo abre
el rol `admin`; si nadie lo recuerda, se pone otro. Tampoco aparece en la
auditoría —queda registrado **que** cambió, nunca el valor—, ni en el paquete
de diagnóstico, ni en los registros técnicos, y en la exportación íntegra sale
**marcado como redactado y sin valor** (§13.1).

**Un acceso de soporte del fabricante no lo ve ni lo cambia**, con ningún
alcance —tampoco con `configuration`, que sí puede tocar el resto de ajustes
operativos—: lo recibe vacío y marcado como redactado, y un intento de
cambiarlo se rechaza con un 403 (§12.4). El código es tuyo, como el perfil de
cumplimiento.

**Qué enseña la pantalla**, y qué no:

| Bloque | Qué dice |
| --- | --- |
| **Cámara** | Permiso, cámara elegida, resolución real, enfoque y zoom. **Avisa sin bloquear** si el fondo sale difuminado, si el enfoque no es continuo o si la resolución es menor de 1280×720: las tres causas de «el código no se lee» |
| **Red** | Si hay conexión, si el servidor responde, cuándo fue el último latido correcto y **el desfase de reloj** de la tablet en segundos, con su signo. La pantalla manda un latido nada más abrirse y sigue latiendo mientras está abierta, así que «servidor alcanzable» es una señal **viva**, no el recuerdo del último latido |
| **Cola** | Fichajes pendientes, de cuándo es el más antiguo, si el almacenamiento de la tablet es duradero y si está sincronizando ahora |
| **Padrón** | De cuándo es la copia local de la plantilla y cuántas entradas tiene |
| **Token** | Si la tablet está vinculada, cuándo caduca su credencial, su identificador de dispositivo y el nombre del quiosco. **El token no se muestra nunca**: solo ocho caracteres de su huella, para poder compararlo con el panel |
| **Versión** | La versión de la PWA y el estado de su actualización en segundo plano |
| **Batería y pantalla** | Nivel, si carga y si la pantalla se mantiene encendida |
| **Errores por enviar** | Cuántos errores tiene la tablet guardados sin poder reportar |

**Ni un nombre, ni un fichaje, ni el token en claro**: la pantalla identifica
por dispositivo y muestra recuentos. Es información tuya y no sale de la
instalación por sí sola; al fabricante solo llega dentro del paquete de
diagnóstico anonimizado, y solo si tú lo envías (§12.2).

**No se interpone en el fichaje.** Mientras está abierta no se escanea, así
que tiene un botón «Volver a fichar» grande y, si nadie la toca, **vuelve sola
a la pantalla de fichaje a los dos minutos**. Abrirla no desvincula nada, no
borra la cola, no interrumpe el envío de lo pendiente y **no deja de latir**:
en el panel, un quiosco cuyo IT está mirando su pantalla de diagnóstico sigue
apareciendo al día.

### 16.6 Los parámetros

| Variable | De serie | Qué gobierna |
| --- | --- | --- |
| `KIOSK_HEALTH_FRESH_WITHIN_SECONDS` | `120` | Hasta cuántos segundos sin latido un quiosco está **al día** |
| `KIOSK_HEALTH_SILENT_AFTER_SECONDS` | `600` | A partir de cuántos segundos sin latido está en **fallo**. **Es el mismo número que la alerta `QuioscoSinLatido`**: los dos se cambian a la vez, o no se cambia ninguno |
| `KIOSK_HEALTH_BATTERY_LOW_PERCENT` | `15` | Nivel por debajo del cual, **y sin cargar**, el quiosco avisa por batería |
| `KIOSK_SERVICE_CODE` | *(vacío)* | Código de 8 a 12 dígitos de la pantalla de diagnóstico. **No es una variable del `.env`**: se cambia en el panel, en «Ajustes operativos», y surte efecto en el latido siguiente |

---

## 17. Dimensionado del servidor y prueba de carga

> **Para qué es este apartado.** Para responder con una medida tuya, y no con
> una promesa nuestra, a dos preguntas que solo se hacen una vez: «¿este
> servidor aguanta mi cambio de turno?» y «¿qué toco si no aguanta?».

### 17.1 Qué promete el producto, y qué significa en un hotel

El producto se publica con un umbral escrito: **50 fichajes por segundo
sostenidos en el servidor, con el 95 % de las respuestas por debajo de 150 ms**
(`RNF-P-06` y `RNF-P-02`). **Se mide antes de cada versión mayor sobre hardware
de referencia y con esta misma orden, `make load-test`, como paso del
procedimiento de publicación**, y el resultado viaja con la versión. Es el mismo
procedimiento que puedes repetir tú sobre tu servidor.

Al etiquetar la versión hay además una comprobación automática en la
infraestructura del fabricante, pero **esa no juzga el umbral y no debe leerse
como si lo hiciera**: corre en una máquina compartida donde el servidor y los
generadores de carga se reparten la misma CPU, así que su latencia no es
comparable con la de un servidor dedicado. Lo que sí comprueba ahí, que es lo
que aporta, son dos cosas distintas y las dos necesarias: que la versión nueva
**no ha empeorado** respecto de la anterior medida en la misma máquina, y que
**bajo sobrecarga el registro sigue siendo correcto** —ningún tramo duplicado,
totales cuadrados y cadena de auditoría intacta—.

Traducido a tu hotel son **dos límites distintos**, y conviene no confundirlos:

| Dónde | Qué límite hay | Por qué |
| --- | --- | --- |
| **En el borde, por origen** (el servidor web) | Desde `KIOSK_VLAN_CIDR`: **una ráfaga de 50 fichajes en el acto** y después **10 por segundo** (600 por minuto). Desde cualquier otro origen, 30 por minuto con ráfaga de 10 | Todos los quioscos de un hotel salen por la misma IP. Ver [`instalacion.md`](instalacion.md) §6 |
| **En el servidor, en total** | **50 fichajes por segundo sostenidos** sumando todos los orígenes, con p95 < 150 ms | Es lo que mide la prueba de carga y lo que decide si una versión mayor sale |

**El cambio de turno de una plantilla entera cabe en la ráfaga.** Las primeras
50 tarjetas pasan de golpe; a partir de ahí el borde deja pasar diez fichajes
por segundo y por origen, que es más de lo que escanea una fila de gente. El
límite del borde no está para frenarte: está para que un equipo comprometido
enchufado a la VLAN de quioscos no quede sin techo.

**Lo que la prueba demuestra**, y lo dice requisito a requisito en su salida:
que el servidor sostiene 50 fichajes por segundo **desde varios orígenes a la
vez** con el p95 por debajo de 150 ms; que **ningún tramo se duplica** aunque el
quiosco reenvíe el mismo fichaje; que los totales diarios cuadran con los
fichajes que los originaron; y que **un rechazo no revela nada** —una tarjeta
desconocida, una revocada y una con la firma alterada tardan lo mismo y
responden lo mismo—.

> **Si alguien te dice «el quiosco va lento a las 06:00», no empieces por
> aquí.** Empieza por `KIOSK_VLAN_CIDR`: es el fallo silencioso más frecuente y
> se comprueba en un minuto ([`instalacion.md`](instalacion.md) §6). Un quiosco
> fuera de ese rango cae bajo el límite pensado para internet, y no hay cantidad
> de CPU que lo arregle.

### 17.2 Cómo se ejecuta

**Dónde: en un entorno de pruebas, nunca en producción.** La prueba **crea
plantilla sintética** —empleados «Carga k6 NNNN» en un departamento llamado
«Carga k6», sus tarjetas y varios quioscos— y ficha con ella miles de veces.
Mide sobre una copia del entorno: la misma máquina que vas a comprar, o una
equivalente.

**Tres cierres, y ninguno es un obstáculo que haya que rodear:**

1. El aprovisionamiento **se niega a ejecutarse contra una instalación de
   producción**.
2. Te obliga a **decir en voz alta que esa base de datos es de pruebas**, con
   una variable que no tiene valor por omisión.
3. Solo trata como suyos a los empleados **de ese departamento y con código
   `K6…`**. Si encuentra un código `K6…` fuera del departamento «Carga k6», se
   para: eso significa que la base no es la que cree, y prefiere no tocar nada.

**Qué hace falta.** La prueba de carga **no viaja en el paquete de entrega**
—el paquete lleva el producto, no el banco de pruebas—: se ejecuta desde una
copia del repositorio del producto, que el fabricante te facilita si quieres
medir tu propio hardware. En esa máquina hacen falta **Docker con Compose v2**,
**Node 20 o superior** y la pila del entorno de pruebas levantada.

```bash
make load-test K6_ACKNOWLEDGE_TEST_DATABASE=yes
```

Eso levanta **once generadores de carga: diez de quiosco y uno de panel**. Cada
uno es un origen distinto —una IP—, porque el borde limita por IP. Diez orígenes
a **6 fichajes por segundo** son 60 por segundo, un 20 % por encima de los 50
del umbral: ese margen no sobra. El borde es un cubo con fuga —deja salir un
permiso cada 100 ms, con una ráfaga inicial de 50— y sin holgura la prueba
mediría el límite del borde en lugar de la capacidad del servidor.

La duración y el número de orígenes se cambian sin tocar nada:

```bash
INSTANCES=10 DURATION=120s make load-test K6_ACKNOWLEDGE_TEST_DATABASE=yes
```

Con menos orígenes el pico baja en proporción: **diez de quiosco es lo que hace
falta** para llegar con margen a los 50 fichajes por segundo.

**Si el entorno de pruebas usa un certificado autofirmado**, hay que decírselo;
contra un entorno con certificado real no hace falta nada:

```bash
K6_INSECURE_TLS=1 make load-test K6_ACKNOWLEDGE_TEST_DATABASE=yes
```

**Qué imprime.** Un veredicto por requisito —cumple o no cumple, con la cifra
medida al lado— y el detalle completo en un fichero legible por una máquina,
por si quieres guardarlo con el acta de la puesta en marcha:

```bash
cat load-tests/k6/.results/summary.json
```

Ese fichero anota además **con qué se midió**: `git_sha` de la versión,
`runner` (la máquina), `k6_version` e `instances`. Sin esos cuatro datos, una
cifra no se puede comparar con otra.

**Códigos de salida**, para encadenarla en un script tuyo:

| Código | Qué significa |
| --- | --- |
| `0` | Todos los veredictos cumplen |
| `1` | **Algún requisito no se cumple.** Cuál, y con qué cifra, está en la salida y en `summary.json`. Es el caso que hay que mirar |
| `2` | **La medida no es fiable, y por eso no se da veredicto.** La pila no está levantada, falta `node`, la carga realmente ofrecida se quedó por debajo de 50 fichajes/s, no hubo muestras comparables suficientes, o k6 no pudo escribir sus resultados. **Un `2` no dice que tu servidor vaya mal**: dice que esta ejecución no sirve y hay que repetirla |

### 17.3 Cómo leer el veredicto y qué tocar

Lo primero: **no hay ninguna cifra de latencia tuya escrita en esta guía, y no
la va a haber.** Depende de tu hardware, de tu disco y de tu red. La cifra de tu
instalación sale de tu propia ejecución de `make load-test` sobre tu servidor.

**Qué es —y qué no es— la «línea base» del fabricante.**
`load-tests/k6/baseline.json` guarda el resultado de una pasada en la máquina de
integración continua del fabricante: p95 y tramos por segundo, junto al `git_sha`
de la versión medida, la máquina y la versión de la herramienta. Sirve para una
sola cosa: comparar la versión siguiente **consigo misma en esa misma máquina**
y detectar que ha empeorado —se admite hasta un 25 % más de p95 y hasta un 20 %
menos de tramos por segundo—. **No es una promesa de latencia ni el p95 que
debería dar tu servidor**: en esa máquina el servidor comparte CPU con los once
generadores de carga, de modo que sus milisegundos no significan nada fuera de
ella. Si el fichero no está, la herramienta se limita a aplicar el presupuesto
—los 150 ms y los 50 fichajes/s— y no compara con nada.

| Lo que ves | Qué está pasando | Qué hacer |
| --- | --- | --- |
| **p95 alto y CPU del servidor con margen**, con peticiones esperando su turno en PHP-FPM | Faltan trabajadores: hay CPU libre y nadie que la use | Sube `PHP_FPM_MAX_CHILDREN` (§17.7). De serie son **20**, el pool del servidor mínimo; con 4 núcleos y 8 GB, **40** es el valor recomendado |
| **p95 alto y CPU al límite** | El servidor está saturado de verdad | **No subas `PHP_FPM_MAX_CHILDREN`**: más trabajadores sobre la misma CPU empeoran el p95. Lo que falta son núcleos |
| **RAM al límite** | Cada trabajador de PHP-FPM ocupa unos **60 MB** | **No subas `PHP_FPM_MAX_CHILDREN`.** Cuarenta trabajadores son unos 2,4 GB solo de aplicación, y hay que dejar sitio a PostgreSQL y a Redis. Si lo que falta es RAM, el mando no es este |
| **No sabes por dónde se está atascando** | Hay que mirarlo mientras ocurre | **Durante la pasada**, abre en Grafana el cuadro **«Salud de la API»** (`kronoqr-api`, §10.4) y mira tres cosas: `db_query_duration_seconds{operation}` (¿es la base de datos?), `scan_processing_duration_seconds` (¿es el fichaje en sí?) y `queue_jobs_pending{queue}` (¿se está acumulando trabajo en segundo plano?). Si la herramienta pudo leer `/metrics`, esas mismas series salen ya restadas —antes y después de la carga— en el bloque `server_metrics` de `summary.json` |
| **Fichajes sueltos que fallan con un error de servidor**, mientras el resto va bien | Una transacción se quedó colgada y los demás esperaban por ella; los topes de la base de datos (§17.4) la cortan | Nada urgente: el quiosco **encola y reenvía**, y nadie se queda sin fichar. Si se repite, sigue el `trace_id` de una de esas peticiones en el registro técnico (§10.3) |
| **Respuestas `429` sobre fichajes válidos** | El límite del borde o el de por dispositivo están frenando | Comprueba que los quioscos caen dentro de `KIOSK_VLAN_CIDR` ([`instalacion.md`](instalacion.md) §6). Es la causa en la inmensa mayoría de los casos |
| **Un rechazo tarda claramente más o menos que otro** | Es un fallo del producto, no de tu servidor | Abre incidencia con el fabricante y adjunta `summary.json`: un rechazo que se distingue por el tiempo permitiría averiguar desde fuera qué tarjetas existen |
| **`reject_out_of_order` sale con una separación grande** (veredicto `RS-03-RN-18`) | **Es información, no un veredicto que bloquee.** Esa clave mide cuánto se aparta un fichaje rechazado por llegar fuera de orden de los tres rechazos de tarjeta —firma, tarjeta desconocida y tarjeta revocada—, con la diferencia firmada: positiva si el primero tarda más | Nada. Guárdalo con el acta. Esa diferencia está aceptada a propósito: un fichaje fuera de orden no dice nada sobre qué tarjetas existen, que es lo que el tiempo constante protege. La cifra está ahí para poder revisar esa decisión con datos el día que haga falta |

**Subir `PHP_FPM_MAX_CHILDREN` no mueve la cifra si la CPU está saturada**, y
conviene verlo con números antes de gastar una tarde en ello. Estas medidas son
de una máquina de cuatro núcleos que además ejecutaba los once generadores de
carga —es decir, con el servidor y la carga peleándose por la misma CPU—, y por
eso mismo ilustran bien el caso:

| Carga ofrecida | Pool | Lo que se sostuvo | p95 |
| --- | --- | --- | --- |
| 12 fichajes/s | 20 trabajadores | 12 fichajes/s | 157 ms (p50: 86 ms) |
| 60 fichajes/s | 20 trabajadores | 29 tramos/s | 26 s |
| 60 fichajes/s | **40 trabajadores** | **27,8 tramos/s** | sin cambio apreciable |

Doblar el pool no mejoró nada: no faltaban trabajadores, faltaba CPU. Con la
carga suave, la misma máquina daba un p95 de 157 ms. Y en la saturación, los
rechazos no vinieron del límite de fichajes por minuto sino del **límite de
conexiones simultáneas** del servidor web, que es el síntoma típico de un
servidor que ya no da abasto.

**Lo importante: en todas esas pasadas la comprobación posterior salió
íntegra** —ningún tramo duplicado, totales cuadrados y cadena de auditoría
intacta—. Un servidor al límite **responde tarde; no escribe mal**. Y lo que el
empleado ve es lo de siempre: el quiosco confirma, encola y reenvía.

**El hardware, como referencia.** Los mínimos publicados son **2 núcleos y
4 GB**; el recomendado, **4 núcleos y 8 GB** ([`instalacion.md`](instalacion.md)
§0). El mínimo sostiene una plantilla de hasta 100 personas con el pool de
serie; a partir de ahí la conversación es de núcleos y de RAM antes que de
parámetros.

**Un `429` no deja a nadie sin fichar.** El quiosco no bloquea nunca al
empleado: confirma en pantalla, guarda el fichaje en su cola local con la hora
real y lo reenvía cuando el servidor respira. Por eso la prueba tolera un
porcentaje pequeño de `429` sobre fichajes válidos —es degradación encolable— y
**falla** ante cualquier rechazo que sí llegaría al empleado.

### 17.4 Los dos topes de la base de datos

La instalación los aplica de serie y casi nadie tendrá que cambiarlos. Están
aquí porque, cuando saltan, el síntoma se ve en esta prueba.

- **`DB_LOCK_TIMEOUT` (5 s).** Cuánto espera una consulta a que se libere un
  candado antes de rendirse. Sin él, un fichaje puede esperar **para siempre**
  detrás de una transacción colgada, y con él esperan todos los demás: el cambio
  de turno entero se para sin un solo error en el registro.
- **`DB_IDLE_IN_TRANSACTION_TIMEOUT` (60 s).** Cuánto se tolera una transacción
  abierta que no hace nada. Corta justamente a la sesión que dejó el candado
  puesto.

**A qué alcanzan, y a qué no.** Los dos topes se aplican **solo al servicio que
atiende peticiones** (`app`): el fichaje, el panel, el portal y las migraciones.
**No** alcanzan a los trabajos en segundo plano, al planificador de tareas
nocturnas ni a la presencia en vivo, y **no alcanzan a la copia de seguridad**.
Es deliberado: un `pg_dump` de una base grande tarda legítimamente mucho más de
un minuto con una transacción abierta, y no puede abortarse por un tope pensado
para que nadie se quede esperando delante de un quiosco. **Una copia lenta no se
corta por estos dos valores.**

**Qué pasa cuando uno salta:** esa petición concreta falla, el quiosco la encola
y la reenvía, y el empleado no se entera. Ese es el cambio que importa: **de
«todo el hotel deja de fichar» a «unos cuantos fichajes llegan unos segundos más
tarde»**.

Bajarlos hace que salten antes y más a menudo; subirlos devuelve el sistema a la
espera indefinida. Si los cambias, mide antes y después con esta misma prueba.

### 17.5 Qué NO hace esta prueba

Dicho para que nadie lea de más en su veredicto:

- **No mide la tablet.** Ni el tiempo desde que se acerca la tarjeta hasta que
  aparece el saludo en pantalla, ni el arranque de la aplicación del quiosco.
  Eso son otros requisitos y los comprueban las pruebas de recorrido de usuario
  del fabricante, en un navegador real.
- **No sustituye a la revisión nocturna.** La reconciliación del registro (§1,
  04:30 UTC) y las alertas de divergencia (§10.4) siguen siendo lo que vigila
  que los totales cuadran día tras día. La prueba comprueba que cuadran
  **después de la carga**, una vez.
- **No se ejecuta con empleados reales, ni contra tu base de datos de
  producción, ni contra una copia restaurada de producción.** Esto último no es
  una precaución de más: la prueba **emite y revoca tarjetas** y **escribe
  fichajes** de su población sintética. Sobre una restauración de tus datos
  reales estarías mezclando fichajes inventados con los de tu plantilla, en una
  base que algún día podrías tomar por buena. Si necesitas volumen realista,
  usa una base de pruebas y deja que la herramienta cree la suya.
- **No es una prueba de seguridad.** Comprueba que los rechazos tardan lo mismo
  entre sí; el resto del endurecimiento está en
  [`endurecimiento.md`](endurecimiento.md).

### 17.6 Qué queda en la base después de medir

Conviene saberlo antes de lanzarla, no después.

**Se limpia solo, al terminar:**

- Las **tarjetas** que emitió quedan revocadas.
- Los **tokens de los quioscos** sintéticos quedan revocados.
- La cuenta de gestión «Responsable carga k6» queda **desactivada**.
- El fichero de trabajo `load-tests/k6/.fixtures/` **se borra**. Mientras dura
  la pasada contiene **tarjetas y tokens vivos**: se escribe con permisos
  `0600`, no se sube a ningún sitio y no se adjunta a ningún tique. Si una
  ejecución se corta a la mitad y el fichero sigue ahí, bórralo tú.

**Se queda, y es lo correcto:**

- Los **empleados sintéticos** («Carga k6 NNNN») y su departamento «Carga k6».
- Los **fichajes** que generó y el histórico que sembró para que las consultas
  trabajen con volumen realista.

Se quedan porque borrarlos exigiría que el producto supiera borrar registros de
jornada, y **el producto no borra nada**: las correcciones crean versiones
nuevas. Por eso la prueba no se lanza sobre una base que vayas a conservar. En
un entorno de pruebas la respuesta es la de siempre: se vuelve a sembrar.

### 17.7 Los parámetros

| Variable | De serie | Qué gobierna |
| --- | --- | --- |
| `PHP_FPM_MAX_CHILDREN` | `20` | Cuántas peticiones se atienden a la vez. **20** es el pool del servidor mínimo (2 núcleos, 4 GB); **40**, el recomendado con 4 núcleos y 8 GB. Cada trabajador ocupa unos **60 MB**: el techo lo pone la RAM |
| `DB_LOCK_TIMEOUT` | `5s` | Cuánto espera una consulta a un candado antes de rendirse. **Solo en el servicio que atiende peticiones**, nunca en la copia de seguridad |
| `DB_IDLE_IN_TRANSACTION_TIMEOUT` | `60s` | Cuánto se tolera una transacción abierta sin actividad. Corta a la sesión que tiene el candado, no a quien lo espera. **Solo en el servicio que atiende peticiones** |
| `KIOSK_VLAN_CIDR` | `10.0.20.0/24` | Rango desde el que el borde permite la ráfaga de 50 y los 600 fichajes por minuto. **Se rellena al instalar, siempre** ([`instalacion.md`](instalacion.md) §6) |

Las tres primeras se cambian en el `.env` y **exigen reiniciar los servicios**;
su ficha completa está en [`configuracion.md`](configuracion.md) §6.24 y §6.15.
