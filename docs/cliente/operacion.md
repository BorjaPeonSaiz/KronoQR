# Operación de KronoQR — lo que hay que atender, y cada cuánto

> **Estado.** Las secciones 1 a 6 son de la **tarea 2.10**: la retención y la
> purga, que es la única operación del producto que borra datos. La 7 es de la
> **5.3** (licencia). Las **8, 9 y 10** son de la **5.4**: los códigos de
> salida de los cinco scripts, la custodia de secretos y qué pierdes si apagas
> la observabilidad. La **11** es de la **5.7**: actualizar. La **tarea 5.11** añadirá los quioscos;
> no reescribirá nada de lo que ya está aquí.

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
| Trimestral | — | **Simulacro de restauración** de la copia |

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
| `2` | **Requisitos no cumplidos. NADA escrito.** La máquina está como estaba | `install.sh`: falta Docker, disco, puerto ocupado. `backup.sh`/`restore.sh`: falta `pg_dump`, el destino no es escribible, no hay espacio, o falta `BACKUP_ENCRYPTION_KEY`. `update.sh`: una precondición no se cumple, o **la copia previa ha fallado**; la instalación sigue en su versión |
| `3` | **Estado previo incompatible. NADA escrito** | `install.sh`: ya hay una instalación (usa `update.sh`). `backup.sh`: no hay copia que verificar, o el destino ya existe. `restore.sh`: quedan conexiones abiertas contra la base. `update.sh`: ya está en la versión de destino, o no hay instalación que actualizar |
| `4` | **Falló y se deshizo todo lo hecho** en esa ejecución. Se puede reintentar | `install.sh`: contenedores, volúmenes y `.env` devueltos a su estado. `backup.sh`: los ficheros a medias barridos, la copia anterior intacta. `restore.sh`: base de trabajo eliminada, la de producción sin tocar. `update.sh`: copia previa restaurada y **versión anterior en marcha y verificada** |
| `5` | **Falló y NO se pudo deshacer todo. Hay que intervenir a mano.** El mensaje dice exactamente qué queda y qué orden lo retira | Es el único código que exige a una persona delante |
| `6` | **El trabajo se hizo pero la verificación posterior falló.** No se deshace nada | `install.sh`: los servicios están en pie, revisa certificado y logs. `backup.sh`: la copia existe pero **no verifica: trátala como inexistente**. `restore-drill.sh`: hoy no se podría recuperar el registro. `update.sh`: **no lo usa**, toda verificación fallida deshace |

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

El `.env` trae `COMPOSE_PROFILES=observability`. Levanta cinco servicios más
—Prometheus, node-exporter, Alertmanager, Grafana y Loki— y ocupa unos 700 MiB.

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
