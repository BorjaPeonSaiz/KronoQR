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
>
> **¿Tienes un problema ahora mismo?** Ve directamente al **§18, «Qué hacer
> si…»**: tablets que vuelven a emparejarse, Redis que no arranca, un `402`,
> informes que no terminan, la copia nocturna fallida, exportaciones
> «Caducadas» tras restaurar y una purga cuyo informe no está.

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
| 04:35 UTC, a diario | Detección de patrones anómalos de uso de credencial sobre los fichajes de quiosco de los últimos 30 días (§6) | Nada: las incidencias le llegan al responsable del departamento, no a IT |
| Lunes 05:10 UTC | **Propuesta de retención**: informe de lo que se purgaría | Leerlo cuando haya algo vencido |
| Cada minuto | Se mide cuánto lleva sin archivarse el WAL más antiguo, que es la pérdida máxima real si el servidor cayera ahora (§10.4, alertas del WAL) | Nada, salvo que avise |
| Cada hora | Métricas de credenciales y limpieza de temporales | Nada |
| Cada hora | Se purgan las exportaciones íntegras caducadas (se borra el ZIP, la anotación queda), los paquetes de diagnóstico de más de 7 días y los restos de las generaciones que se interrumpieron (§12.2, §13 y §13.6) | Nada |
| 04:25 UTC, a diario | Se purgan los informes generados en segundo plano que han caducado: se borra el fichero, la anotación queda (§6 y §13) | Nada |
| Lunes 05:40 UTC | **Telemetría**, solo si la has activado (§13.4): se envía el informe semanal al destino que fijaste | Nada |
| Lunes 06:00 UTC | **Resumen semanal por correo** a cada responsable de departamento, solo si está activado en el panel (§6) | Nada: le llega al responsable, no a IT |
| Trimestral | — | **Simulacro de restauración** de la copia |
| Trimestral | — | **Repasar la lista de comprobación de endurecimiento** ([`endurecimiento.md`](endurecimiento.md), último apartado): red, certificado, cuentas, tablets, copias fuera del servidor |

---

## 2. La propuesta de retención (semanal, no borra nada)

Cada lunes queda un informe **en el servidor, junto a las copias**:

```text
BACKUP_PATH/reports/retention/retencion-propuesta-AAAAMMDD-HHMMSS.txt
```

`BACKUP_PATH` es el destino de copias de tu `.env` (`/var/backups/fichaje` de
serie), y los contenedores lo montan **en la misma ruta** que tiene en el
servidor. Así que el informe se lee desde el propio servidor, sin entrar en
ningún contenedor (cambia la ruta si tu `BACKUP_PATH` es otra):

```bash
sudo ls -lt /var/backups/fichaje/reports/retention/
sudo less /var/backups/fichaje/reports/retention/retencion-propuesta-AAAAMMDD-HHMMSS.txt
```

Se puede pedir a mano en cualquier momento, y **es seguro**: no modifica ni una
fila. El informe cae en la misma carpeta.

```bash
docker compose exec app php artisan compliance:apply-retention --dry-run
```

**Los informes no se limpian solos**: ni el producto ni la poda de copias los
borra nunca, y ocupan unos pocos kilobytes cada uno. Si sacas el contenido de
`BACKUP_PATH` a otro sitio, llévate también `reports/`.

> **Hasta la 2.1.0 el informe quedaba dentro de un contenedor** y se perdía al
> actualizar. Al pasar a la 2.2.0, `update.sh` rescata los que encuentre y los
> deja en esta carpeta (§11, «Al actualizar desde la 2.1.0: los ficheros
> generados»).

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
   `BACKUP_PATH/reports/retention/retencion-purga-AAAAMMDD-HHMMSS.txt`, en el
   servidor. Aunque la orden se lance con `run --rm` —un contenedor que
   desaparece al terminar—, el informe se queda: se escribe en la carpeta de
   copias, no dentro del contenedor.

**Después:**

- **Archiva el informe** junto a la autorización escrita.
- **Contrasta el informe con su asiento de auditoría** (§3.1). Es un minuto, y
  es lo que hace que el informe valga algo dentro de dos años.
- Lanza `docker compose exec app php artisan compliance:verify-audit-chain`. Tiene que terminar en verde
  y decir «Purga sellada reconocida: particion AAAA». Si dijera otra cosa, es un
  incidente de seguridad.

### 3.1 El informe es la copia legible; la constancia es el asiento

**Lo que acredita una purga es el asiento `retention.purge_executed` del
registro de auditoría**, no el fichero. El asiento está encadenado por hash con
todos los demás y se verifica cada madrugada: nadie puede cambiarlo sin que la
verificación lo delate. El fichero, en cambio, es texto en una carpeta del
servidor: quien administra la máquina puede editarlo o borrarlo sin dejar
rastro. Por eso el fichero es lo que se lee y se adjunta, y el asiento es lo que
se cita si alguien discute la purga.

Los dos llevan **el mismo token de confirmación** (`PURGAR-AAAA-MM-DD-xxxxxx`).
En el fichero está en la línea «Purga ejecutada con la confirmacion …». Para ver
los asientos:

```bash
docker compose exec -T postgres psql -U fichaje_app -d fichaje -c \
  "SELECT occurred_at, payload->>'confirmation' AS confirmacion, payload->>'cutoff_date' AS corte, payload->>'retention_years' AS anos, payload->>'rows' AS filas, payload->'tables' AS tablas FROM audit_log WHERE action = 'retention.purge_executed' ORDER BY occurred_at;"
```

**Cómo se contrasta:** busca la fila cuya `confirmacion` es la del informe, y
comprueba que el corte («anterior a AAAA-MM-DD»), los años y los recuentos por
tabla del apartado «Registro de jornada» del informe son los del asiento.

- **Si coinciden**, el fichero es fiel a lo que pasó. Archívalo con la
  autorización.
- **Si no coinciden**, manda el asiento. Un informe que dice otra cosa que su
  asiento lo ha modificado alguien con acceso al servidor: trátalo como un
  incidente de seguridad
  ([`../runbooks/brecha-de-seguridad.md`](../runbooks/brecha-de-seguridad.md)).
- **Si el fichero no está** (se perdió en una actualización anterior a la 2.2.0,
  o alguien lo borró), el asiento basta: cita su fecha (`occurred_at`), la acción
  `retention.purge_executed` y el token. Cómo redactarlo está en §18, «…necesitas
  acreditar una purga y su informe no está».

Si una purga solo soltó particiones de auditoría y no borró ninguna fila del
registro de jornada, no hay asiento `retention.purge_executed`: lo que queda es
un `retention.partition_sealed` y un `retention.partition_dropped` por año, con
el número de filas y los hashes de los extremos, y el sello en
`audit_chain_anchors`, que es lo que reconoce `compliance:verify-audit-chain`.

---

## 4. Si algo sale mal

| Síntoma | Qué significa | Qué hacer |
| --- | --- | --- |
| «La frase de confirmación no corresponde…» | El informe caducó, o cambió el perfil de cumplimiento | Vuelve a lanzar `--dry-run` y usa la frase nueva |
| «La cadena de la partición audit_log_AAAA NO verifica» | Alguien tocó la auditoría | **Incidente de seguridad.** `rotura-cadena-auditoria.md`. No repitas la purga |
| «La purga no ha podido completarse contra la base de datos» | Falta la credencial de `fichaje_maintenance`, o el rol no la tiene puesta | El rol nace sin contraseña a propósito: asígnasela solo para esta operación como explica el §9 («`fichaje_maintenance`: el rol que nace sin contraseña») y repite |
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
| `COMPLIANCE_RETENTION_REPORT_PATH` | `BACKUP_PATH/reports/retention` | Dónde quedan los informes de propuesta y de purga. **No se limpian solos**: son la copia legible de la constancia, que es el asiento de auditoría (§3.1). Déjala vacía para usar el valor de serie; si la cambias, nunca dentro de `storage/app`, que es de ficheros que caducan: `product:doctor` avisa |
| `DB_MAINTENANCE_USERNAME` | `fichaje_maintenance` | Rol que ejecuta la purga de auditoría |
| `DB_MAINTENANCE_PASSWORD` | *(vacía)* | **No se pone en el `.env`.** Se aporta al ejecutar la purga |

**La detección de patrones anómalos de uso de credencial** es una pasada
aparte de la revisión de las 04:30: corre a las **04:35 UTC**, entre esa
revisión y el cálculo de métricas de cumplimiento, y abre las incidencias
«Patrón anómalo de uso de la credencial» que ve el responsable del departamento
en su bandeja ([`guia-rrhh.md`](guia-rrhh.md) §4.5). **A IT no le llega ninguna
por lo que encuentra**: un indicio sobre dos personas concretas se revisa en la
bandeja, no en un canal de guardia. Lo que sí vigilan dos alertas (§10.4) es
que la pasada corra y termine: `DeteccionDePatronesAusente` (más de 26 horas
sin ejecutarse) y `DeteccionDePatronesConFallos` (la última pasada dejó algún
hallazgo sin convertir en incidencia), las dos sobre las series
`pattern_detection_last_run_timestamp_seconds` y
`pattern_detection_last_failures` del fichero
`BACKUP_PATH/metrics/kronoqr_pattern_detection.prom`. Los umbrales de lo que se
busca —ventana de segundos, días mínimos y tránsito entre quioscos— se cambian
en el panel, no aquí ([`configuracion.md`](configuracion.md) §2.1). Si necesitas
lanzarla a mano (es idempotente: no duplica lo que ya abrió):

```bash
docker compose exec app php artisan attendance:detect-patterns
```

| Variable | De serie | Qué hace |
| --- | --- | --- |
| `COMPLIANCE_PATTERN_LOOKBACK_DAYS` | `30` | Días hacia atrás que revisa esa pasada. Es más larga que los 7 de la revisión de incidencias porque «sistemático» necesita más de una semana. Se puede acotar en una ejecución concreta con `--days=` |

El procedimiento cuando una de las dos alertas suena, y el de revisión de la
propia incidencia, es
[`../runbooks/patron-anomalo-credencial.md`](../runbooks/patron-anomalo-credencial.md).

**Los informes generados en segundo plano** (RRHH los pide desde el panel:
[`guia-rrhh.md`](guia-rrhh.md) §6.3) tienen sus propios seis parámetros y su
propia purga. Los ficheros son **por persona solicitante**, viven en
`REPORTING_EXPORT_PATH` —dentro del volumen de ficheros generados, §13.6—, se
descargan desde el panel con un enlace de un solo uso y los borra el
planificador a las 04:25 UTC en cuanto caducan; la anotación de que existieron
se conserva siempre. **No entran en la copia de seguridad ni se reponen al
restaurar**: tras una restauración, quien necesite el informe lo vuelve a pedir
(§18, «…después de restaurar una copia»). Si alguna vez necesitas adelantar la
purga:

```bash
docker compose exec app php artisan reporting:purge-expired-exports
```

| Variable | De serie | Qué hace |
| --- | --- | --- |
| `REPORTING_EXPORT_PATH` | `storage/app/reports` (en el volumen `app-storage`) | Dónde se escriben esos ficheros. Déjala vacía. Si la cambias, tiene que quedar **dentro de `/var/www/html/storage/app`** y no coincidir ni solaparse con las otras rutas de ficheros generados (§13.5); `product:doctor` falla si se solapan, y si quedan fuera falla en producción y avisa en las demás instalaciones. **Nunca dentro de `BACKUP_PATH`**: caducan solos y no deben entrar en la copia |
| `REPORTING_EXPORT_RETENTION_DAYS` | `7` | Días que el fichero se puede descargar antes de que la purga diaria lo borre |
| `REPORTING_EXPORT_LINK_TTL_MINUTES` | `15` | Minutos que vale el enlace de descarga, que además es **de un solo uso** |
| `REPORTING_EXPORT_TIMEOUT_SECONDS` | `600` | Tope de la consulta del informe en diferido. Súbelo si una exportación grande falla por tiempo |
| `REPORTING_EXPORT_STALE_AFTER` | `3600` | Segundos tras los que una generación interrumpida se da por fallida y deja pedir otra. No lo bajes por debajo de lo que tarda tu informe más grande |
| `REPORTING_EXPORT_DOWNLOAD_RATE_LIMIT` | `30` | Descargas por minuto y por dirección IP en la ruta de descarga, que se abre sin sesión |

**El resumen semanal por correo** ([`guia-rrhh.md`](guia-rrhh.md) §6.5) es la
otra pasada de los lunes: a las **06:00 UTC**, y solo si el panel lo tiene en
`enabled` (`WEEKLY_SUMMARY_EMAIL`, [`configuracion.md`](configuracion.md)
§2.1), cada responsable de departamento activo y con correo recibe la semana
anterior —de lunes a domingo, en el calendario del centro— de su ámbito. **No
tiene ningún parámetro en el `.env`**: el interruptor está en el panel y el
correo sale por el mismo SMTP de la sección 6.21 de esa guía. Tres cosas que
conviene saber antes de que alguien pregunte por qué no le ha llegado:

- **La pasada nunca falla por no poder enviar, pero sí lo cuenta.** Deja en el
  registro técnico (`reporting.weekly_summary`) los recuentos —enviados,
  omitidos, fallidos— y el motivo cuando no manda nada: `disabled` (apagado en
  el panel), `mailer_silent` (el correo está en un transporte que no envía,
  `MAIL_MAILER=log` o `array`), `not_in_plan` (la licencia no incluye
  `weekly_email_summary`; el resto del producto sigue igual) o `no_recipients`
  (ningún responsable activo con correo). Un fallo del SMTP con un responsable
  se anota como `reporting.weekly_summary_not_delivered`, cuenta como fallido,
  **no aborta a los demás** y hace que el comando salga con código `1`; esa
  semana le queda pendiente y **el lunes siguiente no la recupera**: la pasada
  solo mira la semana anterior. El reintento es el comando de abajo con
  `--week`.
- **Repetirla no reenvía, y el orden del envío importa.** Para cada
  responsable: (1) una transacción corta **reclama** la semana en la tabla
  `weekly_summary_deliveries` (responsable y semana; el índice único cierra la
  carrera); (2) se compone el informe y se envía el correo **fuera de toda
  transacción**; (3) si salió, otra transacción corta escribe el asiento de
  auditoría; (4) si no salió, se retira la reclamación y la semana queda libre
  para `--week`. Consecuencias: una segunda ejecución salta lo ya entregado;
  dos pasadas simultáneas no duplican —la segunda ve la reclamación y omite—; y
  **un envío fallido no deja asiento**, a propósito: el asiento describe una
  divulgación que ocurrió, y contar intentos fallidos inflaría el alcance de
  una brecha. Puedes lanzarla cuantas veces quieras.
- **Cada correo deja asiento en la auditoría** (`personal_data.accessed`,
  conjunto `weekly_summary`) con el destinatario, la semana y los
  identificadores de las personas incluidas, nunca sus nombres: es el rastro
  que responde a «a quién le salieron los datos de quién»
  ([`../runbooks/brecha-de-seguridad.md`](../runbooks/brecha-de-seguridad.md)
  §4). El correo en sí sí lleva nombres, como el aviso diario de incidencias, y
  valen para él las mismas cautelas sobre el relevo SMTP
  ([`endurecimiento.md`](endurecimiento.md) §7).

Para lanzar la de la semana pasada sin esperar al lunes, o reenviar una semana
concreta (en formato ISO, año y número de semana):

```bash
docker compose exec app php artisan reporting:weekly-summary
docker compose exec app php artisan reporting:weekly-summary --week=2026-W37
```

La pasada escribe dos series por fichero de texto —cuándo corrió por última vez
y cuántos correos envió— en `BACKUP_PATH/metrics/kronoqr_weekly_summary.prom`,
**sin alerta a propósito**: es una funcionalidad accesoria y opcional. Si la
pasada en sí revienta, sale en el histórico de errores (§15) como cualquier otro
comando programado.

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
| `6` | **El trabajo se hizo pero la verificación posterior falló.** No se deshace nada | `install.sh`: los servicios están en pie, revisa certificado y logs. `backup.sh`: la copia existe pero **no verifica: trátala como inexistente**. `restore-drill.sh`: hoy no se podría recuperar el registro. `update.sh`: **casi nunca** (toda verificación de la versión nueva que falla deshace); la única excepción es que el asiento `system.updated` de `audit_log` no se pudiera escribir tras una actualización que sí terminó — el trabajo se hizo y no se deshace por eso. `restore.sh`: **la base está restaurada y en servicio, pero el asiento `system.restored_from_backup` de `audit_log` no se pudo escribir**; no repitas la restauración y escribe el asiento con la orden del mensaje (`restaurar-backup.md` §6.7). `restore.sh`, `restore-drill.sh` y `backup.sh verify`, **desde la 2.2.0, también por integridad**: falta el `.sha256`, el MAC no cuadra, el nombre del fichero no es el de su cabecera, falta o no cuadra el manifiesto autenticado (`.manifest.mac`), o la copia es de la 2.1.0 y no se ha pedido expresamente; en ese caso **no se ha tocado nada** (§18, «…la restauración se niega por integridad»). `doctor.sh`: **el diagnóstico ha encontrado al menos un fallo** — con la aplicación en marcha, en su propio informe (`product:doctor`); con la aplicación parada, en una de las comprobaciones externas. El mensaje dice qué leer |
| `7` | **Garantía de seguridad rota. NADA aplicado.** Un rol de la base de datos tiene más privilegios de los permitidos, o una copia ha intentado cambiarlos (AUD-1). No es una avería: es una garantía que el producto se niega a saltarse | `backup.sh`: el rol con el que se copia es superusuario, o puede crear roles o bases, o saltarse RLS; no se ha escrito ninguna copia y salta la alerta de copia fallida. `restore.sh` y `restore-drill.sh --mode database`: la copia ha cambiado roles del clúster al restaurarse; no se ha intercambiado ninguna base y se ha intentado devolver los roles a su estado. Sigue `rotacion-secretos.md` («El rol de las copias es privilegiado») o `restaurar-backup.md` §6.6 |

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

**Desde la 2.2.0 los contenedores no reciben el `.env` entero**, solo las
variables que necesitan ([`configuracion.md`](configuracion.md) §6.2), y estas
órdenes siguen valiendo tal cual: `-e DB_MAINTENANCE_PASSWORD=…` entrega la
contraseña **solo** al contenedor efímero de esa orden, que desaparece al
terminar (`--rm`); el `app` que está en marcha nunca la ve. Y el
`ALTER ROLE` se hace dentro del contenedor `postgres`, no en el de la
aplicación.

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
| Semanal | `docker compose exec scheduler php artisan backup:verify` — que la última copia existe y verifica |
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
| `KioskQueueStorageDegraded` | La tablet ha perdido el almacenamiento de su cola, `for: 10m` | Alta | IT | [`cola-offline-atascada.md`](../runbooks/cola-offline-atascada.md#7-almacenamiento-de-la-cola-degradado-kioskqueuestoragedegraded) | Lo que se fiche ahora **se pierde si la tablet se reinicia**: recarga la aplicación o libera espacio en la tablet. **No la reinicies** mientras pueda tener fichajes solo en memoria. Con esto, las dos alertas de cola de arriba callan: no hay tamaño que medir (§16.3 bis) |
| `KioskUnreportedDiscards` | Fichajes descartados sin avisar al servidor, `for: 30m` | Media | IT | [`cola-offline-atascada.md`](../runbooks/cola-offline-atascada.md#8-descartes-sin-avisar-kioskunreporteddiscards) | Comprueba la red de esa tablet y su versión de la aplicación. Hasta que avise, RRHH no ve la incidencia «Fichaje descartado por el quiosco» ([`guia-rrhh.md`](guia-rrhh.md) §4.7) |
| `ScanBatchItemNotProcessed` | 3 o más elementos de lote sin procesar en 30 min en un mismo quiosco, `for: 5m` | Media | IT | [`cola-offline-atascada.md`](../runbooks/cola-offline-atascada.md#9-un-elemento-del-lote-que-no-se-procesa-scanbatchitemnotprocessed) | Un mismo fichaje de esa tablet lleva varias sincronizaciones sin que el servidor consiga procesarlo, y **los demás de ese quiosco esperan detrás**: no se pierden, pero no llegan al registro. Corrige la causa en el servidor (busca `attendance.batch_scan_failed` en el registro de `app`). **Nunca borres la cola de la tablet ni la desvincules** (§16.3 bis) |
| `KioskDiscardedScansAttributed` | Avisos de fichaje descartado atribuidos a personas desde un mismo quiosco en la última hora, `for: 0m` | Media | IT | [`cola-offline-atascada.md`](../runbooks/cola-offline-atascada.md#10-descartes-atribuidos-en-un-quiosco-kioskdiscardedscansattributed) | Esa tablet está enviando fichajes que el servidor no acepta y que son de personas reales: lo normal es una aplicación desfasada tras una actualización (recárgala con la cola vacía); si el quiosco no debería estar enviando nada, desvincúlalo. RRHH verá las incidencias «Fichaje descartado por el quiosco» de ese día |
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
| `DeteccionDePatronesAusente` | Más de 26 h sin ejecutarse la detección de patrones anómalos de uso de credencial (04:35 UTC) | Media | IT | [`patron-anomalo-credencial.md`](../runbooks/patron-anomalo-credencial.md) §5 | Comprueba que el `scheduler` sigue vivo y lanza `attendance:detect-patterns` a mano. **No es sobre ninguna persona**: es que nadie está mirando |
| `DeteccionDePatronesConFallos` | La pasada de patrones de anoche dejó algún hallazgo sin convertir en incidencia | Alta | IT | [`patron-anomalo-credencial.md`](../runbooks/patron-anomalo-credencial.md) §5 | `product:doctor`, corrige la causa y repite el comando: es idempotente |
| `ErroresCriticosNuevos` | Grupo `critical` nuevo o reabierto en 5 min | Alta | IT | [`errores-en-el-panel.md`](../runbooks/errores-en-el-panel.md) | Abre «Errores» en el panel y sigue la columna «Qué hacer» de esa fila |
| `DivergenciaEnReconciliacionNocturna` | Cualquiera | Crítica | IT | [`divergencia-proyeccion.md`](../runbooks/divergencia-proyeccion.md) | La corrección ya está hecha; averigua quién escribió fuera del recálculo |
| `ReconciliacionConFallos` | La pasada de anoche falló | Alta | IT | [`divergencia-proyeccion.md`](../runbooks/divergencia-proyeccion.md) | Ejecuta `attendance:reconcile` a mano y mira el motivo del fallo |
| `ReconciliacionDeProyeccionAusente` | > 26 h sin reconciliar | Media | IT | [`divergencia-proyeccion.md`](../runbooks/divergencia-proyeccion.md) | Comprueba que el `scheduler` sigue vivo y ejecuta `attendance:reconcile` a mano |
| `RoturaDeCadenaDeAuditoria` | Cualquiera | Crítica | Seguridad | [`rotura-cadena-auditoria.md`](../runbooks/rotura-cadena-auditoria.md) | Preserva la evidencia (§2 de ese runbook) antes de tocar nada |
| `VerificacionDeAuditoriaAusente` | > 26 h sin verificar | Crítica | Seguridad | [`rotura-cadena-auditoria.md`](../runbooks/rotura-cadena-auditoria.md) | Comprueba que el `scheduler` sigue vivo |
| `ParticionDeAuditoriaAusente` | Falta la partición del año en curso | Crítica | IT | [`rotura-cadena-auditoria.md`](../runbooks/rotura-cadena-auditoria.md) | **El fichaje está caído**: `compliance:ensure-audit-partitions` ya |
| `ParticionDeAuditoriaDelProximoAnoSinPreparar` | Falta la del año próximo, desde noviembre | Media | IT | [`rotura-cadena-auditoria.md`](../runbooks/rotura-cadena-auditoria.md) | Comprueba que el `scheduler` corre y que la migración `2026_09_29_100000` está aplicada (`migrate:status` por el servicio `migrate`, ver el runbook §5). Ya no depende de `DB_MIGRATION_USERNAME`: la partición la pide la aplicación a una función de la base |
| `CopiaDeSeguridadFallida` / `CopiaDeSeguridadSinVerificar` | Cualquiera | Crítica | IT | [`restaurar-backup.md`](../runbooks/restaurar-backup.md) | `backup:verify` y reintenta con `backup:run`, los dos en el contenedor `scheduler` (no en `app`) |
| `CopiaDeSeguridadAusente` | Sin métrica en 30 min | Crítica | IT | [`restaurar-backup.md`](../runbooks/restaurar-backup.md) | Comprueba el `scheduler` y que `BACKUP_PATH` está montado |
| `ArchivadoDeWalDetenido` | El dato sin archivar más antiguo lleva más de 25 min (`archive_timeout` + 10 min), o hay 3 segmentos completos sin archivar; `for: 3m`. **No espera a la copia nocturna** | Crítica | IT | [`restaurar-backup.md`](../runbooks/restaurar-backup.md) §4 | Urgente: mientras dure, la pérdida máxima deja de ser 15 min y, sin espacio, PostgreSQL termina parándose entero. `docker compose logs --tail=100 postgres` dice la causa |
| `ArchivadoDeWalFallando` | El último intento de archivado falló y no se ha recuperado; `for: 10m` | Crítica | IT | [`restaurar-backup.md`](../runbooks/restaurar-backup.md) §4 | Destino no montado o sin permisos, disco lleno, o falta `BACKUP_WAL_KEY`: el archivado nunca escribe en claro. El log de `postgres` dice cuál |
| `MedicionDeWalAusente` | La medida del WAL lleva más de 5 min sin publicarse; `for: 5m` | Crítica | IT | [`restaurar-backup.md`](../runbooks/restaurar-backup.md) §4.3 | Sin medida no se sabe si hay RPO: comprueba el `scheduler`, que es también el que hace las copias |
| `ArchiveTimeoutFueraDeRango` | `archive_timeout` vale 0 o más de 900 s; `for: 10m` | Crítica | IT | [`restaurar-backup.md`](../runbooks/restaurar-backup.md) §4.4 | Alguien ha cambiado la configuración de PostgreSQL: devuélvela a la del paquete y recrea `postgres` |
| `SlotDeReplicacionParado` | ≥ 1 slot de replicación sin consumidor durante 15 min | Crítica | IT | [`slot-replicacion-parado.md`](../runbooks/slot-replicacion-parado.md) | KronoQR no usa ninguno: uno solo ya es anómalo. Retiene registro de transacciones y puede llenar el disco de datos |
| `DiscoDeCopiasCasiLleno` | < 20 % libre en el volumen de copias | Media | IT | [`restaurar-backup.md`](../runbooks/restaurar-backup.md) | Amplía el disco o baja `BACKUP_RETENTION_DAYS` |
| `SimulacroDeRestauracionNuncaEjecutado` | Ninguno registrado todavía | Media | IT | [`restaurar-backup.md`](../runbooks/restaurar-backup.md) | Ejecútalo: sin él, nadie ha comprobado que las copias restauran de verdad |
| `SimulacroDeRestauracionCaducado` | Fallido o > 100 días | Media | IT | [`restaurar-backup.md`](../runbooks/restaurar-backup.md) | Repite el simulacro con la copia anterior si la última falla |
| `KronoqrAuthFailureBurst` | > 20 fallos en 5 min, un canal | Media | Seguridad | [`ataque-a-credenciales.md`](../runbooks/ataque-a-credenciales.md) | Determina si es una persona equivocándose o un intento automatizado |
| `KronoqrAuthLockouts` | ≥ 3 bloqueos distintos en 15 min, un canal | Media | Seguridad | [`ataque-a-credenciales.md`](../runbooks/ataque-a-credenciales.md) | Acota cuántas cuentas y si alguna llegó a entrar antes del bloqueo |
| `KronoqrAuthFailureSpike` | > 100 fallos en 5 min, un canal | Crítica | Seguridad | [`ataque-a-credenciales.md`](../runbooks/ataque-a-credenciales.md) | Preserva la evidencia antes de bloquear el origen en el borde |
| `KronoqrPortalOriginLockouts` | > 5 bloqueos de origen del portal en 1 h; `for: 1m` | Media | Seguridad | [`bloqueo-por-origen.md`](../runbooks/bloqueo-por-origen.md) | Distingue un ataque repartido de toda la plantilla detrás de una misma IP (hairpin NAT, `TRUSTED_PROXY_CIDR`); fichar no se ve afectado |
| `KronoqrManagementTwoFactorReset` | Cada restablecimiento de 2FA de una cuenta de gestión (sin umbral) | Crítica | Seguridad | [`ataque-a-credenciales.md`](../runbooks/ataque-a-credenciales.md) §9 | Confirma con el administrador y con el titular que fue intencionado; si nadie lo reconoce es un incidente de cuentas |
| `KronoqrManagementAdminAccountCreated` | Cada alta de una cuenta de gestión con rol admin (sin umbral) | Crítica | Seguridad | [`ataque-a-credenciales.md`](../runbooks/ataque-a-credenciales.md) §9 | Confirma con quien la pidió; si nadie la reconoce, desactívala |
| `KronoqrPortalOriginLockouts` | > 5 bloqueos de origen del portal en 1 h; `for: 1m` | Media | Seguridad | [`bloqueo-por-origen.md`](../runbooks/bloqueo-por-origen.md) | Distingue un ataque repartido de toda la plantilla detrás de una misma IP (hairpin NAT, `TRUSTED_PROXY_CIDR`); fichar no se ve afectado |
| `FicheroGeneradoDesaparecidoAntesDeCaducar` | Sube `generated_files_missing_total` (en 30 min, o serie nueva), durante 1 min | Alta | Seguridad | [`ficheros-generados.md`](../runbooks/ficheros-generados.md) §2 | Una exportación o un informe perdió su fichero antes de caducar. Tras restaurar una copia o actualizar desde la 2.1.0 es lo esperado (§18); si no, lee el asiento `*.file_missing` y trátalo como posible brecha |
| `FicheroGeneradoSinRetirarPasadoSuPlazo` | `generated_files_overdue > 0` durante 1 h: una exportación para la Inspección lleva > 30 días en el servidor | Media | IT | [`ficheros-generados.md`](../runbooks/ficheros-generados.md) §4 | No se borra sola: confirma que se entregó y bórrala ([`requerimiento-inspeccion.md`](../runbooks/requerimiento-inspeccion.md) §7) |
| `PurgaDeFicherosGeneradosSeHaNegadoATocarAlgo` | Sube `generated_files_refused_total` (en 1 h, o serie nueva), durante 1 min | Media | IT | [`ficheros-generados.md`](../runbooks/ficheros-generados.md) §5 | Hay un enlace, un subdirectorio o un nombre ajeno en una carpeta del volumen, o rutas `*_PATH` solapadas: `product:doctor` (§13.5) |
| `PurgaDeFicherosGeneradosNoPuedeBorrar` | Sube `generated_files_remove_failed_total` (en 1 h, o serie nueva), durante 1 min | Media | IT | [`ficheros-generados.md`](../runbooks/ficheros-generados.md) §6 | El sistema de ficheros ha negado un borrado y un fichero con datos personales sigue pasado su plazo; la causa habitual son los permisos (una exportación lanzada con `exec -u root`). Corrige dueño y modo de la carpeta; la siguiente pasada horaria lo retira |
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
| 6 · Vuelta atrás | Sale `5` | **Requiere una persona.** El mensaje distingue dos casos: solo quedó el mantenimiento puesto (retirarlo con `docker compose exec app php artisan up`, **sin restaurar nada**) o la restauración quedó a medias (tres órdenes y la ruta de la copia) | Runbook §5. Los quioscos siguen encolando mientras tanto |
| 7 · Informe | — | El **informe** en `BACKUP_PATH/reports/update-<fecha>.log` (uid 1000, `0640`), siempre. El **detalle** (`update-<fecha>.detalle.log`: salida cruda, **puede llevar datos personales**) **no está ahí**: vive solo en `/var/log/kronoqr/` (`root:root 0600`, directorio `0700`), junto a una copia local del informe. El informe aparece en `reports/` **al terminar, no mientras corre**; si no se puede publicar, el script avisa y dice que queda a salvo en `/var/log/kronoqr/`. Hace falta `setpriv` (paquete `util-linux`). **Cuánto se guardan** (desde la 2.2.0): el detalle, **30 días** (`KRONOQR_LOG_RETENTION_DAYS` en el entorno de quien ejecuta el script, mínimo 7); el resumen local `update-<fecha>.log`, sin datos personales, **90 días**. Los borra `update.sh` al arrancar y `./doctor.sh` si lo ejecutas con `sudo`; si no actualizas ni ejecutas `doctor.sh` en meses, el borrado espera a la siguiente vez | Adjuntar el informe al paquete de diagnóstico si se abre un caso; el detalle, solo tras revisarlo y si lo piden |

Los pasos 5 y 6 dejan además su propio asiento en `audit_log` (`system.updated`
o `system.restored_from_backup`): si por lo que sea no se puede escribir, la
actualización no se deshace por eso —el hecho ya ocurrió—, pero una
actualización que por lo demás terminó bien sale con `6` en vez de `0`, y el
informe lo dice en su propia línea. El asiento de la vuelta atrás solo se escribe si la versión a la
que se vuelve ya conoce esa acción (desde la 2.2.0): una anterior no sabría verificar la cadena con
él, así que el informe deja los datos y hay que escribirlo con `compliance:record-system-event`
después de la siguiente actualización.

**Lo que no cambia:** tus secretos (el `.env` se copia tal cual y solo cambia
`IMAGE_TAG`; la única excepción es la de abajo, al pasar de la 2.1.0), los
datos, la licencia (una licencia caducada **no impide actualizar**), y el
fichaje. **Lo que sí hace falta:** `BACKUP_ENCRYPTION_KEY` en el `.env` y
espacio para la copia y para la migración; el paso 1 lo dice con cifras. Y,
desde la 2.2.0, **`setpriv`** en el servidor (paquete `util-linux`, de serie en
Debian, Ubuntu y RHEL 7 o posterior; compruébalo con `command -v setpriv`):
`update.sh` lo usa para escribir en `BACKUP_PATH` su informe, los informes de
retención rescatados y sus métricas como el usuario de la aplicación y sin
seguir enlaces. Sin él no se detiene: avisa, no rescata los informes de
retención y deja su informe en un directorio temporal, diciendo cuál.

**Al actualizar desde la 2.1.0: cada contenedor recibe solo lo suyo.** Hasta la
2.1.0 todos los contenedores de la aplicación recibían el `.env` entero,
incluida la contraseña del rol de migración, que es superusuario de la base.
Desde la 2.2.0, cada servicio recibe **solo las variables que necesita** (la
tabla está en [`configuracion.md`](configuracion.md) §6.2). Tres consecuencias
que tienes que conocer:

- **Si añadiste al `.env` una variable propia**, ya no llega a ningún
  contenedor. El paso 1 (y `--check-only`) te avisa con la lista de las claves
  de tu `.env` que ningún servicio recibirá. Es un aviso, no un fallo: casi
  siempre son restos que no hacían nada. Si alguna sí te importa, dilo al
  fabricante; no edites el `docker-compose.yml`, que la siguiente
  actualización sustituye.
- **Las copias pasan a un rol de solo lectura.** El actualizador crea el rol
  `fichaje_backup` y escribe `BACKUP_DB_USERNAME` y una `BACKUP_DB_PASSWORD`
  nueva **en el `.env` de la versión nueva**. El `.env` de la versión anterior
  conserva las credenciales antiguas a propósito: es con lo que arranca la
  vuelta atrás.
- **Una vuelta atrás a la 2.1.0 vuelve a la 2.1.0 entera**, con su forma de
  repartir credenciales: los contenedores vuelven a recibir el `.env` completo
  hasta que actualices otra vez. Si la actualización se deshizo, no lo dejes
  para meses: el motivo está en el informe.

**Al actualizar desde la 2.1.0: los ficheros generados.** Hasta la 2.1.0, lo
que el producto escribía en disco fuera de la base de datos —la exportación
íntegra, los informes en segundo plano, los informes de retención, el paquete de
diagnóstico, el estado de la telemetría— quedaba **dentro de cada contenedor**,
y se perdía cada vez que una actualización lo recreaba. Desde la 2.2.0 vive en
el volumen `app-storage`, compartido por los contenedores de la aplicación
(§13.6), y los informes de retención en `BACKUP_PATH/reports/retention` (§2).
No tienes que hacer nada: el volumen se crea solo en el primer arranque. Lo que
conviene saber:

- **Se rescatan los informes de retención, y solo ellos.** Antes de recrear los
  contenedores, `update.sh` copia los `retencion-propuesta-*.txt` y
  `retencion-purga-*.txt` que encuentre en los de la 2.1.0 a
  `BACKUP_PATH/reports/retention/`, sin sobrescribir ninguno y con permisos
  `0640`. El informe de la actualización dice cuántos ha rescatado y de dónde.
  Si el rescate falla, lo avisa con la orden para hacerlo a mano y **la
  actualización sigue**: no se deshace una actualización por un informe.
- **Si tu `.env` fija `COMPLIANCE_RETENTION_REPORT_PATH` dentro de
  `storage/app`**, el rescate lo avisa (y después `product:doctor`): quita esa
  línea del `.env` para usar el valor de serie.
- **No se rescatan, y se pierden**: los ZIP de exportación íntegra, los
  informes en segundo plano y los paquetes de diagnóstico que hubiera en los
  contenedores. Son datos personales que caducan a los 7 días o material
  desechable, y no tiene sentido darles vida en el volumen nuevo. Sus
  anotaciones pasan a **«Caducada»** en la primera pasada de purga. Si alguna
  no había caducado todavía, además queda el asiento `data_export.file_missing`
  o `report_export.file_missing` y puede sonar la alerta
  `FicheroGeneradoDesaparecidoAntesDeCaducar`: **tras esta actualización es lo
  esperado**. Si necesitas una
  exportación, pídela de nuevo.
- **El identificador de la telemetría cambia una vez**, si la tenías activada
  (§13.4). A partir de aquí se conserva entre actualizaciones.
- **Lo que ya se perdió no se recupera**: los informes de las purgas lanzadas
  con `run --rm`, que desaparecían al terminar la orden, y todo lo que borraron
  actualizaciones anteriores. **La constancia de cada purga sigue en el registro
  de auditoría**, que es la que vale: cómo localizarla y citarla está en §3.1 y
  en §18, «…necesitas acreditar una purga y su informe no está».
- **Si vuelves atrás a la 2.1.0**, el volumen se queda intacto y sin usar hasta
  la siguiente actualización, y los informes rescatados siguen en
  `BACKUP_PATH/reports/retention`.

**Al actualizar desde la 2.1.0: bajas con fecha de cese futura.** La 2.1.0
admitía registrar una baja con una fecha de cese posterior al día en que se
registraba, y la aplicaba en el acto: desde ese momento la persona no podía
fichar y su tarjeta quedaba revocada. Desde la 2.2.0 una fecha de cese posterior
a hoy se rechaza (la baja se registra cuando la persona ha terminado su último
turno: [`guia-rrhh.md`](guia-rrhh.md) §8, «…una persona causa baja»). Las que
ya se registraron así **no se migran ni se reactivan**: siguen de baja, con su
fecha. Lo que tiene que saber RRHH:

- **Los informes por periodo las cuentan de alta hasta su fecha de cese**, así
  que los días entre el registro de la baja y la fecha de cese salen como días
  de alta **sin actividad**. No es un fallo del informe: es que esa persona no
  pudo fichar esos días.
- **Si trabajó esos días, se pueden completar ahora a mano**: desde la 2.2.0
  se admite añadir un tramo a una persona de baja en cualquier jornada entre
  su fecha de alta y su fecha de cese, las dos incluidas, con su motivo y su
  asiento, como cualquier tramo manual. Nunca un día que todavía no ha llegado.
  Cómo se hace: guía de RRHH, «Después de la baja: completar los días que
  falten».

Cómo localizarlas, sin tocar la base de datos: en el panel, **Plantilla**, filtro
**«Situación laboral»** en **«De baja»**, y abre la ficha de cada persona:
**«Fecha de cese»** está en sus datos. Son las que tienen una fecha de cese
posterior al día en que actualizaste a la 2.2.0. Pásale la lista a RRHH: que
complete los días que esas personas sí trabajaron y que sepa leer los días sin
actividad de esos informes. En el sistema no hay que hacer nada más.

**Al actualizar a la 2.2.0: el aviso de privacidad del quiosco se configura
desde el panel.** El aviso de protección de datos que la tablet enseña al fichar
(art. 13 RGPD) nombra al responsable del tratamiento y enlaza la política
completa. Hasta la 2.1.0 esos dos datos solo se podían fijar al construir la
aplicación de la tablet, así que en la práctica salía el texto genérico. Desde
la 2.2.0 se ponen en Panel → **Marca** → «Aviso de privacidad del quiosco», sin
tocar el servidor ([`configuracion.md`](configuracion.md) §2.2). Lo que
conviene saber:

- **Tras actualizar no cambia nada que se vea**: los dos campos nacen vacíos y
  el aviso sigue con su redacción genérica («la empresa titular de este centro
  de trabajo», «política completa disponible en recepción»). El aviso **no
  desaparece** en ningún caso.
- **Pásale el encargo a quien lleve la protección de datos**: que te dé la
  razón social exacta y la dirección `https://` de la política aprobada para la
  plantilla, y ponlas tú. Con dirección, la tablet enseña además un código QR
  para abrirla en el móvil.
- **Las tablets lo recogen** al cargar la pantalla de fichaje o al recuperar la
  red; una tablet con la aplicación anterior a la 2.2.0 sigue con el genérico
  hasta que se actualice (abajo, «las tablets»).

**Al actualizar a la 2.2.0: tres incidencias que RRHH no había visto nunca.**
Aparecen en la bandeja sin que haya que activar nada; RRHH tiene la explicación
de cada una en [`guia-rrhh.md`](guia-rrhh.md) §4.1:

- **«Fichaje anterior a la retirada de la credencial»** (§4.6 de esa guía): una
  tarjeta que valía cuando se pasó y llegó al servidor después de retirarla
  —típicamente, el último día de alguien que se dio de baja con la tablet sin
  red—. **El fichaje no se registra**; RRHH completa ese día a mano.
- **«Fichaje descartado por el quiosco»** (§4.7): la tablet envió un fichaje que
  el servidor no aceptó como válido, lo apartó y lo avisó. **No se registra**;
  RRHH lo revisa y lo corrige a mano. Si se repite en una tablet, esa tablet
  tiene la aplicación atrasada: actualízala.
- **«Fichaje por PIN no registrado»**: un intento de fichar por PIN que no se
  aceptó y que la persona no repitió.

**Las incidencias se abren a partir de lo que llega desde la actualización**,
no sobre el histórico: lo que ocurrió antes no se reprocesa. **Lo que no
cambia**: un fichaje por PIN de una persona ya dada de baja que llega tarde
**sigue sin abrir incidencia**, así que RRHH tiene que seguir revisando a mano
el último día de las bajas registradas con alguna tablet sin red.

**Al actualizar a la 2.2.0: las tablets, después del servidor y con la cola
vacía.** La 2.2.0 cambia **cómo vacía su cola la aplicación de la tablet**
(§16.3 bis): registra los fichajes estrictamente en orden y, si el servidor
rechaza uno por inválido, lo guarda aparte y lo avisa en vez de descartarlo
sin más. **Eso lo hace la aplicación nueva, no el servidor**: una tablet que
siga con la de la 2.1.0 contra un servidor 2.2.0 no tiene esa red. Por eso,
en este orden:

1. **Antes de empezar**, mira en Panel → **Quioscos** que la columna
   «Pendientes» está a cero en todas las tablets que puedas, o que al menos
   tienen red. No es obligatorio —la 2.2.0 está hecha para aceptar lo que
   envían las tablets de la 2.1.0—, pero cuanto menos haya en vuelo durante el
   cambio, menos hay que revisar después.
2. **Actualiza el servidor** como siempre (arriba).
3. **Pasa las tablets a la 2.2.0 ese mismo día, no en semanas.** Lo más
   sencillo es dejar que lo hagan solas: pon `KIOSK_UPDATE_WINDOW` en una franja
   que empiece ahora (§11.1); cada tablet se recarga sola en cuanto su cola está
   vacía y nadie ha fichado en los últimos minutos, que es justo la condición
   segura. **Devuelve la ventana a su valor** cuando todas estén al día. Si
   prefieres hacerlo a mano en una tablet concreta, sal del modo quiosco con el
   PIN de IT y recarga la aplicación **solo cuando su «Pendientes» esté a
   cero**.
4. **Compruébalo** en la columna «Versión de la aplicación» de **Quioscos**, o
   con `docker compose exec app php artisan kiosk:health`.

**Nunca borres los datos de la aplicación ni desvincules una tablet para
«forzar» la actualización**: su cola vive ahí, y con ella se irían los
fichajes que aún no ha enviado.

**Al actualizar a la 2.2.0: el histórico de errores se vuelve a filtrar.**
Hasta la 2.1.0, el texto de un error se limpiaba solo de correos, documentos,
teléfonos y lo que fuera entre comillas, así que un nombre sin comillas podía
quedarse en el histórico y, con él, en el paquete de diagnóstico. La 2.2.0
filtra ese texto con un vocabulario técnico cerrado (§12.2 y §15.1), y la
actualización pasa ese filtro también por las filas que ya tenías. Lo que
conviene saber:

- **Los mensajes antiguos se reescriben y sus huellas cambian.** Es
  irreversible: lo que se quita no se recupera. Los errores que solo se
  distinguían por el nombre de una persona pasan a ser un solo grupo, que
  suma las veces y queda abierto si alguno lo estaba. Si en una incidencia
  abierta con soporte, o en tus notas, citabas la huella de un error, ya no
  la encontrarás: búscalo por su código y su origen.
- **Los paquetes de diagnóstico generados antes de actualizar pueden llevar
  nombres.** No los envíes. Si queda alguno en el servidor, bórralo
  (`docker compose exec app rm -f storage/app/diagnostics/<fichero>`); si se
  te olvida, la purga horaria lo retira a los 7 días (§12.2). Si sacaste
  alguno del servidor, bórralo también de donde lo guardaras.
- **Una copia de seguridad anterior a la actualización conserva el texto
  antiguo** y, al restaurarla, el panel vuelve a mostrarlo hasta que pase la
  siguiente actualización (o un `migrate`), que lo filtra otra vez. El paquete
  de diagnóstico, en cambio, lo filtra siempre al generarse, también sobre una
  copia restaurada.
- **Lo que ya enviaste al fabricante**: trata los paquetes de versiones
  anteriores que haya recibido como paquetes con datos personales y los
  borra.

**Al actualizar a la 2.2.0: las copias y el WAL, cifrados y autenticados.**

- **Qué cambia.** Las copias y el WAL archivado van ahora **cifrados y
  autenticados** (ADR-049): un fichero alterado, renombrado o sustituido se
  detecta al restaurar, y la restauración se niega. **Hasta la 2.1.0 el WAL
  archivado no iba cifrado.**
- **Qué hace `update.sh` solo.** Calcula `BACKUP_WAL_KEY` a partir de
  `BACKUP_ENCRYPTION_KEY` y la escribe en el `.env`: sigues custodiando **una
  sola** clave. Si no puede calcularla, **se detiene antes de tocar nada**, con
  la causa y la orden. En los minutos siguientes cifra en sitio los segmentos de
  WAL antiguos, sin parada adicional (`./doctor.sh` dice cuántos quedan).
- **Qué tienes que hacer tú: destruir las copias del WAL en claro.** Cualquier
  copia de `BACKUP_PATH/wal` que hicieras antes de actualizar (otro disco, otra
  carpeta de red, otra copia de seguridad del servidor) contiene datos
  personales **sin cifrar**. Destrúyela. Si estuvo al alcance de personas que no
  debían verla, valora con tu DPO si es una brecha
  ([`obligaciones-legales.md`](obligaciones-legales.md) §4 y §6).
- **Las copias de la 2.1.0** que sigan en `daily/` y `base/` (hasta
  `BACKUP_RETENTION_DAYS`, 30 días de serie) se pueden restaurar, pero solo
  pidiéndolo expresamente (§18, «…la restauración se niega por integridad»).
  Caducan solas.
- **Montajes.** La aplicación ya no escribe en la raíz de `BACKUP_PATH`. Si tu
  destino es un recurso de red con permisos especiales, comprueba que `daily/`,
  `base/`, `metrics/`, `reports/` y `reports/retention/` existen y son del
  usuario 1000 ([`instalacion.md`](instalacion.md) §6, «`BACKUP_PATH`»).
  `update.sh` los crea si faltan.
- **El candado de la actualización vive ahora en `/var/log/kronoqr/update.lock`**,
  fuera del alcance de la aplicación (antes, en `BACKUP_PATH`; uno antiguo ahí se
  ignora). Si una actualización interrumpida lo deja puesto, el paso 1 lo dice,
  con el proceso que lo creó. Comprueba que no hay ningún `update.sh` en marcha
  (`pgrep -af update.sh`) y retíralo con `sudo rm -r /var/log/kronoqr/update.lock`.
- **La vuelta atrás a la 2.1.0** usa la copia previa que `update.sh` acaba de
  hacer, que todavía tiene el formato de la 2.1.0. La acepta **solo** si coincide
  con la huella que el propio script calculó y guardó en `/var/log/kronoqr/`,
  no con el `.sha256` que hay junto a la copia. Tras volver, lo escrito por la
  2.2.0 (WAL `.gz.enc`, copias nuevas) **no lo lee el `restore.sh` de la
  2.1.0**: si necesitas restaurar una de esas, hazlo con el paquete de la 2.2.0.
- **Alertas.** El archivado detenido se detecta ahora en unos 25 minutos, sin
  esperar a la copia nocturna, y hay tres alertas más (§10.4). Si tienes
  silencios o reglas propias sobre `kronoqr_backup_wal_*` o
  `kronoqr_backup_replication_slot*`, **cambian de nombre** a `kronoqr_wal_*`.
- **Parada:** ninguna adicional.

**Al actualizar a la 2.2.0: el portal y el panel, mejor cerrados (sobre todo si
están abiertos a internet).** Cuatro cambios; los dos primeros no exigen nada,
los dos últimos piden una revisión el mismo día:

- **El PIN puede ser de 6 u 8 cifras.** Sigue siendo de 6 de serie. Si el portal
  es accesible desde fuera de la red del hotel, pásalo a 8 en el panel, con la
  cuenta de administración: **Ajustes operativos → Acceso → Longitud del PIN**
  (queda en el registro de auditoría). **No se anula ningún PIN**: los de 6 ya
  entregados siguen valiendo y el portal y la tablet admiten de 6 a 8 cifras;
  solo los que se emitan desde entonces —altas y restablecimientos— salen con
  8. RRHH los va restableciendo y entregando en mano a medida que cada persona
  pasa por la oficina ([`guia-rrhh.md`](guia-rrhh.md) §2.2). Cuántos quedan
  —solo el número, nunca quiénes— lo dice la comprobación `access.short_pins`
  de `product:doctor` (§12.1).
- **Bloqueo por conexión en el portal.** 20 accesos fallidos al portal desde una
  misma dirección en 15 minutos, con cualquier código, cierran el portal **a esa
  dirección** durante una hora, también con el PIN correcto. La persona ve
  «Demasiados intentos desde esta conexión» y los minutos que faltan. No afecta
  al fichaje. Si cierra la wifi del hotel entera y no se puede esperar, levántalo
  desde el directorio de la instalación con la dirección que aparece en el
  asiento `auth.origin_locked` del registro de auditoría (el ejemplo es una
  dirección de documentación; pon la tuya):

  ```bash
  docker compose exec app php artisan identity:origin-unlock 198.51.100.23
  ```

  Si hay un proxy delante del servidor y no has definido `TRUSTED_PROXY_CIDR`,
  toda la plantilla llega con la IP del proxy y un solo bloqueo la deja fuera
  entera: defínela ([`instalacion.md`](instalacion.md) §6). El procedimiento
  completo, con qué hacer si se repite, está en
  [`../runbooks/bloqueo-por-origen.md`](../runbooks/bloqueo-por-origen.md).
- **Los responsables de departamento necesitan segundo factor.** Desde la 2.2.0
  es obligatorio para los cuatro roles de gestión, porque el responsable corrige
  jornadas. Nadie se queda fuera: quien no lo tenga lo da de alta en su
  **primer acceso** al panel tras actualizar, con la aplicación de su teléfono.
  **Mientras no entre, quien tenga solo su contraseña podría darlo de alta en su
  lugar**, así que **pide a cada responsable que entre el mismo día** y, después,
  revisa las altas: cada una deja un asiento `auth.two_factor_enabled` con la
  hora y la IP desde la que se hizo.

  ```bash
  docker compose exec -T postgres psql -U fichaje_app -d fichaje -c \
    "SELECT a.occurred_at, a.ip, u.uuid, u.email FROM audit_log a JOIN users u ON u.id = a.subject_id WHERE a.action = 'auth.two_factor_enabled' AND a.occurred_at > now() - interval '30 days' ORDER BY a.occurred_at;"
  ```

  Si su titular no reconoce un alta (otra hora, otra IP), retira ese segundo
  factor con el `uuid` de la fila y dale una contraseña nueva; en su siguiente
  acceso lo vuelve a dar de alta él:

  ```bash
  docker compose exec app php artisan identity:2fa-reset 0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90 --reason="2FA no reconocido / 2FA not recognised"
  docker compose exec app php artisan identity:reset-password responsable@tuhotel.example
  ```

  La comprobación `access.two_factor_pending` de `product:doctor` dice cuántas
  cuentas de esos roles siguen sin segundo factor; cuando llegue a cero, la
  ventana está cerrada. Si tu `.env` fija `IDENTITY_2FA_REQUIRED_ROLES` con
  la lista antigua, la 2.2.0 la respeta y `access.two_factor_roles` avisa del
  rol que falta.
- **`ADMIN_INTERNAL_CIDR`, nueva y vacía.** Cierra el panel de gestión a la red
  que pongas; vacía, no filtra nada, como hasta ahora. Si el portal está abierto
  a internet, el panel también lo está mientras la dejes vacía:
  [`configuracion.md`](configuracion.md) §6. `product:doctor` lo recuerda con
  el aviso `network.admin`.

**Desde qué versiones se puede saltar** a la del paquete, sin tocar nada:
`./update.sh --supported-sources`. La regla es la versión menor vigente y las
dos anteriores; desde una más antigua, el script te dice a cuál ir primero.

**Segunda ejecución** sobre una instalación ya actualizada: sale `3`, «ya está
en la versión», y no toca nada.

### 11.1 La app de la tablet: cuándo cambia de versión

Lo de arriba es el servidor. La app del quiosco es otra pieza: la tablet la
descarga del servidor, y **la versión nueva no entra en la tablet en el momento
de actualizar el servidor**. Cada tablet comprueba **cada hora** si hay versión
nueva, se la descarga en segundo plano y la deja esperando; mientras espera,
sigue fichando con la de siempre. Solo se recarga con la nueva cuando se cumplen
**tres condiciones a la vez**, y las comprueba cada minuto:

1. **La hora local del centro está dentro de la ventana** `KIOSK_UPDATE_WINDOW`
   (`03:00-05:00` de serie; puede cruzar la medianoche).
2. **La cola de fichajes sin enviar está vacía.** Una actualización con
   fichajes pendientes de subir sería la única forma de perder uno, así que no
   se hace: cuando la tablet se actualiza, no tiene nada que perder, por
   construcción. La cola vive además en el almacenamiento duradero de la tablet
   y un fichaje solo se borra de ella cuando el servidor lo ha confirmado.
3. **No ha habido ningún fichaje en los últimos `KIOSK_UPDATE_QUIET_MINUTES`
   minutos** (10 de serie). Es la guarda contra el turno que empieza antes de lo
   previsto: la tablet no sabe tu horario, pero sabe si alguien acaba de pasar
   la tarjeta.

Si la ventana se cierra sin que se hayan dado las tres, espera a la de mañana.
Es lo que hace que una actualización sea invisible para la plantilla: **la
tablet nunca se actualiza con gente delante**, y cuando lo hace, la cola está
vacía.

**Dónde se ajusta.** Las dos claves están en Panel → **Ajustes operativos**
(`/settings`), rol administrador ([`configuracion.md`](configuracion.md) §2.1),
y llegan a las tablets en el latido siguiente —en menos de un minuto—; cada
tablet las guarda, así que valen sin red, y una que aún no ha recibido ninguna
usa las de serie. **Es una sola ventana para toda la instalación**: no hay una
por quiosco, ni forma de forzar la actualización de una tablet desde el panel.
Si necesitas que cambie ya, mueve la ventana temporalmente a la hora actual: en
cuanto pasen los minutos de calma con la cola vacía, se actualiza sola.

**Qué enseña la tablet.** La pantalla de diagnóstico (§16.5), junto a la versión
instalada, dice «al día» o «actualización pendiente: se aplicará en la ventana
03:00–05:00», con la ventana vigente. Es lo que hay que mirar cuando una tablet
lleva días en una versión anterior a la del resto: si dice pendiente, es que en
su ventana nunca se han dado las tres condiciones —normalmente porque siempre
hay algún fichaje en esa franja, o porque la cola no llega a vaciarse por falta
de red—, y la solución es mover la ventana o arreglar la red, no reiniciar la
tablet.

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
autofirmado), permisos (directorios de trabajo, copias, logotipo), ficheros
generados (que el volumen `app-storage` está montado y se puede escribir, que
sus rutas no coinciden entre sí, que la carpeta de los informes de retención se
puede escribir, y exportaciones para la Inspección olvidadas en el servidor
desde hace más de 30 días; §13.6), espacio en disco (aplicación y copias) y
ajustes (zona horaria en UTC, modo depuración,
claves no válidas, diferencias entre el `.env` y lo guardado, licencia y marca),
redes del borde (desde dónde se abren el portal, el panel, los quioscos y
`/metrics`) y acceso (desde la 2.2.0: PIN de 6 cifras con el portal abierto a
internet, cuántas personas conservan un PIN de 6 con el ajuste en 8, roles de
gestión sin segundo factor obligatorio y cuántas cuentas aún no lo han dado de
alta; **solo cifras, nunca quiénes**).
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
servicio, `.env`, disco, certificados, puertos, el volumen de ficheros
generados y su tamaño— y te dice cómo arrancarla.

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

  Deja el fichero en `storage/app/diagnostics/`, dentro del volumen de
  ficheros generados (§13.6), y te dice la ruta, el tamaño y la huella.
  Cópialo fuera con
  `docker compose cp app:/var/www/html/storage/app/diagnostics/<fichero> .`
  y **bórralo del servidor cuando lo hayas enviado**
  (`docker compose exec app rm -f storage/app/diagnostics/<fichero>`): es
  material desechable. Por si se olvida, **el planificador borra cada hora
  los paquetes de más de `PRODUCT_DIAGNOSTICS_RETENTION_DAYS` días** (7 de
  serie), y el propio comando hace lo mismo al arrancar y lo dice. El volumen
  persiste entre actualizaciones, así que ese barrido es lo que impide que un
  paquete olvidado —quizá con datos personales (§12.3)— se quede en el
  servidor para siempre.

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

**Qué lleva del histórico de errores.** Cada error aparece con su código, su
origen, su clase, cuántas veces ha ocurrido y cuándo fue la primera y la
última vez. De su texto solo se conservan las palabras de un vocabulario
técnico cerrado que forma parte del producto; cualquier otra —un nombre, un
apellido, una calle— aparece como «…». Los números largos y los que tienen
forma de documento, teléfono, tarjeta, cuenta bancaria, número de afiliación,
código de empleado, fecha, hora o dirección IP aparecen como un marcador
(`[n]`, `[id]`, `[time]`…). **En el paquete anonimizado no aparece ningún
identificador de empleado**, ni siquiera el interno. Sí van dos
identificadores técnicos que no corresponden a una persona: el de la tablet
(`device_id`) y el de la petición (`trace_id`), que solo tu instalación sabe
relacionar con su log.

**Lo que no se puede descartar del todo.** Un nombre que coincida exactamente
con una palabra del vocabulario técnico y aparezca solo, sin apellido, se
conservaría. El producto comprueba en cada versión que el vocabulario no
contiene ninguno de los nombres y apellidos más frecuentes en España ni de las
nacionalidades más habituales en hostelería, pero no puede comprobarlo contra
todos los nombres posibles. **Ábrelo antes de enviarlo**: es un JSON legible.

**Cómo se comprueba.** Una prueba automática del producto genera un paquete
real después de introducir —por las dos vías por las que llegan los errores de
las aplicaciones y por un error del servidor; en el mensaje, en los valores,
en las claves y en datos anidados— nombres, apellidos, correos, DNI, NIE,
pasaportes, números de afiliación, cuentas bancarias, tarjetas, teléfonos y
códigos de empleado en todas sus formas, y comprueba que no aparece ninguno,
ni el identificador interno de ningún empleado.

#### Lo que lleva de cada tablet, de tus ajustes y del volumen (desde la 2.2.0)

Para que soporte pueda responder a «una tablet no sincroniza» o «la nómina
sale vacía» sin pedirte una segunda ronda de capturas, el paquete añade:

| Dónde (en el JSON) | Campo | Qué es | Si sale `null` |
| --- | --- | --- | --- |
| `kiosks[]` | `token_expires_on` | **Solo el día** (UTC) en que caduca la credencial de esa tablet. Nunca la credencial | La tablet no tiene credencial: sin vincular o desvinculada |
| `kiosks[]` | `paired_at` | Cuándo se vinculó | Se vinculó antes de que el producto lo anotara |
| `kiosks[]` | `oldest_pending_at` | Hora del fichaje más antiguo que la tablet aún no ha enviado. **Solo la hora**: ni de quién es ni su identificador | No tiene nada pendiente, o no ha enviado ningún latido desde que se actualizó |
| `kiosks[]` | `battery_level`, `battery_charging` | Batería en % y si está cargando, según su último latido | El navegador de la tablet no informa de la batería (normal en algunos modelos) |
| `configuration.installation_settings` | una entrada por ajuste | Los ajustes guardados desde el panel (tolerancias de fichaje, idiomas, formato del fichero de nómina, ventana de actualización de las tablets, resumen semanal sí/no), con `source: stored` si lo cambiaste y `default` si rige el de serie | — (siempre tiene valor) |
| `installation.volume` | `active_employees`, `scan_events_last_30_days`, `shift_entries_last_30_days`, `open_incidents` | **Solo números**: personas en activo, escaneos y tramos de los últimos 30 días, incidencias abiertas | — (siempre tiene valor) |

De tus ajustes **no** viajan el nombre comercial, el logotipo ni el color de
marca, el código de servicio de las tablets ni las horas de consolidación
manual que declaraste. Del formato de nómina viaja **qué columnas** salen y en
qué orden, pero **no los rótulos** que les pusiste.

### 12.3 Incluir datos personales es otra acción

Si la incidencia exige ver fichajes concretos —una discrepancia de nómina de
una persona, por ejemplo—, puedes incluirlos. **Es una decisión tuya, explícita
y auditada**, nunca el valor por defecto:

- Panel: marca «Incluir datos personales»; la pantalla te dice qué se va a
  incluir y que queda registrado, y solo entonces te deja generar.
- Consola: `docker compose exec app php artisan product:diagnostics --with-personal-data --period-days=7`
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
| `read_only` | Además, **leer la plantilla y el registro horario de una persona** (su ficha y sus jornadas), **cada lectura auditada** como divulgación de datos personales, y la auditoría | Cambiar nada. **Ni el registro de ausencias** —dato de salud: el tipo «Baja médica» y su nota—, **ni la presencia en vivo, ni el resumen de cumplimiento por persona**: no hacen falta para diagnosticar un cálculo de horas, y ninguno lo alcanza ningún acceso de soporte (403 con cualquier alcance; ausencias cerradas en el cierre de la Fase 3, presencia y cumplimiento el 24-09-2026 por decisión de producto del fabricante, igual para todas las instalaciones) |
| `configuration` | Además, **cambiar** los ajustes operativos y emparejar o desvincular quioscos | Ver jornadas ni plantilla, ni tocar el perfil de cumplimiento (umbrales legales y años de conservación son tuyos), **ni activar o desactivar el fichaje de pausa** (`ATTENDANCE_BREAK_CLOCKING`: decide qué se considera incidencia, igual que el perfil; si lo intenta obtiene un 403), **ni tocar las dos claves de detección de patrones** (`ATTENDANCE_PATTERN_WINDOW_SECONDS` y `ATTENDANCE_PATTERN_MIN_REPEATS`: con `0` en la primera se apaga la detección de coincidencias de RF-PR-06, la mitigación que compensa no tener biometría, y esa decisión es del hotel; también 403), **ni activar o desactivar el resumen semanal por correo** (`WEEKLY_SUMMARY_EMAIL`: decide que cada lunes salgan por correo nombres y horas de tu plantilla; también 403), **ni declarar ni cambiar las horas de hojas anteriores al sistema** (`BASELINE_MANUAL_HOURS_PER_MONTH`: es el denominador declarado del objetivo comercial del cuadro de impacto y describe tu proceso anterior a la instalación, que solo tú conoces; también 403), **ni cambiar la longitud del PIN** (`IDENTITY_PIN_LENGTH`: decide cuánta fuerza tiene la llave del registro de tu plantilla; también 403), **ni ver ni cambiar el código de servicio del quiosco**: lo recibe vacío y marcado como redactado, y si intenta cambiarlo obtiene un 403 (§16.5) |

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
| `PRODUCT_DIAGNOSTICS_RETENTION_DAYS` | `7` | Días que un paquete generado por consola permanece en `storage/app/diagnostics` antes de que la pasada horaria del planificador lo borre (también lo borra el siguiente `product:diagnostics`) |
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
escaneos, informes generados en segundo plano, resúmenes semanales enviados
por correo, auditoría completa con su cadena de hash, cuentas de gestión,
accesos de soporte), JSON para la
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
pedido, el panel te enseña esa en lugar de empezar otra. **La descarga desde
el panel es el camino recomendado**: es el único que deja asiento de quién se
llevó el fichero (§13.3).

**Desde la consola**, en el acto y en primer plano:

```bash
docker compose exec app php artisan product:export-all
```

Deja el fichero en `storage/app/exports/`, dentro del volumen de ficheros
generados (§13.6), imprime la ruta, el tamaño, la huella y el número de filas
de cada fichero, y registra la exportación igual que si la hubieras pedido
desde el panel: aparece en la misma lista y **se descarga desde allí**. Si
prefieres sacarlo por consola:

```bash
docker compose cp app:/var/www/html/storage/app/exports/<fichero> .
```

> **`docker compose cp` no deja asiento de descarga.** Lo que sale del servidor
> por esa vía no aparece como `data_export.downloaded` en tu auditoría, y ante
> una brecha no podrás responder con el producto quién se llevó esa copia.
> Si usas la consola, anota tú quién la sacó, cuándo y a dónde, y guarda esa
> nota con el resto de tu registro de actividades.

**Cuándo conviene la consola.** El panel descarga el ZIP entero en la memoria
del navegador antes de guardarlo. Por encima de ~1 GB —varios años de una
plantilla grande— genera desde la consola y saca el fichero con `cp`. La
cabecera `X-Kronoqr-Export-Sha256` de la descarga y la huella que imprime el
comando son la misma: sirven para comprobar que el fichero llegó entero.

### 13.3 Cuánto dura y qué queda anotado

El ZIP **caduca** a los `PRODUCT_DATA_EXPORT_RETENTION_DAYS` días (7 de serie):
cada hora se borran los caducados y la exportación pasa a «Caducada» en la
lista; la anotación de que existió, con sus recuentos y su huella, no se borra
nunca. Si necesitas el fichero más tarde, genera otro.

**No entra en la copia de seguridad, y una restauración no lo repone.** Es una
vista de datos que ya están en la copia cifrada; meterlo en ella alargaría de 7
a 30 días la vida de un fichero con todos tus datos personales. Tras restaurar
una copia, pide una exportación nueva: se genera de los datos restaurados (§18,
«…después de restaurar una copia»).

En tu auditoría quedan `data_export.requested` (quién la pidió y por dónde),
`data_export.generated` (recuentos, huella y tamaño) y **`data_export.downloaded`
por cada descarga desde el panel**: ante una brecha puedes responder quién se
llevó qué y cuándo. Lo que se saca con `docker compose cp` no deja ese asiento
(§13.2).

**Si el fichero desaparece antes de caducar**, la exportación pasa igualmente a
«Caducada», pero además queda el asiento `data_export.file_missing` (con el
identificador de la exportación, sin ruta) y suena la alerta
`FicheroGeneradoDesaparecidoAntesDeCaducar`, que va al responsable de
seguridad (§10.4). Fuera de los dos
casos en que es lo esperado —justo después de restaurar una copia, o de
actualizar desde la 2.1.0—, **trátalo como un evento de seguridad**: alguien con
acceso al servidor ha borrado o movido un fichero con todos los datos de la
plantilla ([`../runbooks/brecha-de-seguridad.md`](../runbooks/brecha-de-seguridad.md)).
Los informes en segundo plano hacen lo mismo con `report_export.file_missing`.

**Si se queda en «Generando».** Una exportación que se interrumpe a mitad
—porque paraste los contenedores para actualizar, o porque el trabajador de
cola se reinició— no bloquea nada: pasado el tiempo máximo de generación (una
hora) el sistema la da por **fallida** con el motivo `stale` en cuanto alguien
pide otra o en la purga de la hora siguiente, y puedes generar de nuevo. Lo que
dejó escrito a medias se borra solo en una purga horaria posterior, pasadas dos
veces ese tiempo máximo. No hace falta tocar la base de datos ni el disco; si de
verdad ves una en curso más de una hora sin que pase a fallida, ejecuta
`docker compose exec app php artisan product:export-all --purge`
y vuelve a pedirla.

**Si aparece como «fallida».** El motivo que enseña el panel es un código, no
un texto libre, para no sacar nunca un dato de una fila a la pantalla ni al
log: `write_failed` (no se pudo escribir en `PRODUCT_DATA_EXPORT_PATH`: casi
siempre es falta de espacio en el disco de Docker; `./doctor.sh` dice cuánto
ocupa el volumen de ficheros generados y `product:doctor` si se puede
escribir en él), `database_error` (la base de datos falló a
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

El identificador aleatorio de la instalación vive en el volumen de ficheros
generados (§13.6) y se conserva entre actualizaciones. **Cambia una sola vez al
actualizar desde la 2.1.0**, y cambia al restaurar una copia en un servidor
nuevo, porque el volumen no viaja en la copia. Solo lo usa la telemetría, no la
licencia: que cambie no afecta a nada más.

### 13.5 Los parámetros

| Variable | De serie | Qué gobierna |
| --- | --- | --- |
| `PRODUCT_DATA_EXPORT_PATH` | `storage/app/exports` (en el volumen `app-storage`) | Dónde se escriben los ZIP. Fuera de `BACKUP_PATH` a propósito: es material que caduca. Ver la nota de debajo |
| `PRODUCT_DATA_EXPORT_RETENTION_DAYS` | `7` | Días que el ZIP se puede descargar antes de purgarse. La anotación se conserva. Se puede bajar a `1` si prefieres que una copia completa de tus datos no pase más de un día en el servidor |
| `PRODUCT_DATA_EXPORT_RATE_LIMIT` | `30` | Peticiones por minuto **por cuenta** a la lista y a la descarga; el cubo por dirección IP es cuatro veces mayor (120), para que varios administradores tras la misma salida a internet no se bloqueen entre sí. El panel sondea cada 5 s mientras hay una en curso |
| `PRODUCT_DATA_EXPORT_STALE_AFTER` | `3600` | Segundos tras los que una exportación que se quedó a medias (contenedor parado, cola reiniciada) se da por fallida con motivo `stale`, liberando la siguiente. No lo bajes por debajo de lo que tarda tu exportación más grande |
| `TELEMETRY_ENABLED` | `false` | Si se envía telemetría. Hace falta además `TELEMETRY_ENDPOINT` y que la licencia la incluya |
| `TELEMETRY_ENDPOINT` | vacío | A dónde se envía. Vacío de serie: lo fijas tú |

**Las cuatro rutas de ficheros generados** —`PRODUCT_DATA_EXPORT_PATH`,
`REPORTING_EXPORT_PATH`, `PRODUCT_DIAGNOSTICS_PATH` y `TELEMETRY_STATE_PATH`—
se dejan vacías, y casi nadie tiene motivo para cambiarlas. Si lo haces, dos
condiciones: **tienen que quedar dentro de `/var/www/html/storage/app`**, que
es donde está montado el volumen (una ruta fuera de él no la ven los demás
contenedores y se pierde en la siguiente actualización), y **no pueden
coincidir ni contenerse unas a otras**, porque cada purga borra en su carpeta y
solo en la suya. `product:doctor` falla si dos coinciden, si una contiene a
otra, si alguna es `storage/app` (o la contiene) o si se pisa con `BACKUP_PATH`; y si alguna queda fuera de `storage/app` falla en producción (es el fallo que el volumen corrige) y avisa en las demás instalaciones. Avisa también si encuentra ficheros generados en `storage/app` fuera de las rutas configuradas, que es lo que queda al cambiar una de ellas: vacía la carpeta anterior.

### 13.6 Dónde viven los ficheros que genera el producto, y quién puede leerlos

Casi todo lo que el producto guarda está en PostgreSQL. Lo que escribe en
disco aparte son estos ficheros:

| Fichero | Dónde | Cuánto vive | ¿Entra en la copia? |
| --- | --- | --- | --- |
| Exportación íntegra (ZIP, §13) | Volumen `app-storage`, carpeta `exports/` | 7 días; lo borra la purga horaria | No |
| Informes generados en segundo plano (§6) | Volumen `app-storage`, carpeta `reports/` | 7 días; los borra la purga diaria de las 04:25 UTC | No |
| Paquete de diagnóstico generado por consola (§12.2) | Volumen `app-storage`, carpeta `diagnostics/` | 7 días; lo borra la purga horaria | No |
| Exportación para la Inspección generada por consola | Volumen `app-storage`, carpeta `legal-exports/` | **Hasta que la borres tú.** Pasados 30 días avisan `product:doctor` y la alerta `FicheroGeneradoSinRetirarPasadoSuPlazo` (§10.4) | No |
| Temporal de la exportación para la Inspección pedida desde el panel | Volumen `app-storage`, carpeta `tmp/legal-exports/` | Se borra al terminar la descarga; si la descarga se cortó, lo borra la purga horaria pasadas 6 horas | No |
| Estado de la telemetría (§13.4) | Volumen `app-storage`, carpeta `telemetry/` | Se conserva; no lleva datos personales | No |
| Informes de retención (§2 y §3) | `BACKUP_PATH/reports/retention` | **Siempre**: no se limpian solos | Viven en la carpeta de copias |

**El volumen `app-storage`** (en Docker aparece como `kronoqr_app-storage`) lo
crea Docker en el primer arranque y lo montan los tres contenedores de la
aplicación —`app`, `horizon` y `scheduler`— en `/var/www/html/storage/app`. Por
eso lo que genera uno lo ve otro: `horizon` genera la exportación, `app` la
sirve al panel y `scheduler` la purga. No hay nada que configurar, y
`./doctor.sh` comprueba que los tres lo montan, que lo que escribe uno lo lee
otro, que su raíz es del usuario `app` con modo `0700` y cuánto ocupa. Para
verlo tú:

```bash
docker compose exec app sh -c 'du -sh storage/app storage/app/*'
docker compose exec app sh -c 'ls -l storage/app/legal-exports/ 2>/dev/null'
```

La primera dice cuánto ocupa el volumen y cada carpeta; la segunda, qué
exportaciones para la Inspección siguen en el servidor (si no sale nada, no
hay ninguna).

**No entra en la copia de seguridad, y `restore.sh` no lo repone.** Todo lo
que contiene caduca en días o se puede volver a generar desde la base de datos,
que sí está en la copia. Tras restaurar, lo que la base recuerda y el volumen ya
no tiene aparece como «Caducada»; la exportación o el informe se vuelven a
pedir (§18, «…después de restaurar una copia»).

**Ocupa disco en el de Docker**, el mismo de la base de datos. Una exportación
íntegra de cuatro años de una plantilla grande puede pesar cientos de
megabytes; mientras no caduca, cuenta.

**Los ficheros del volumen están en claro en el servidor, igual que los datos
de PostgreSQL.** La exportación íntegra lleva todos los datos personales de la
plantilla, sin cifrar a propósito: es la que tienes que poder abrir sin
depender de nada ([`obligaciones-legales.md`](obligaciones-legales.md) §7
quater), y cifrarla con una clave guardada en el mismo servidor no protegería
de nada a quien ya puede leer la base de datos. Lo que sí protege es **cifrar
el disco del servidor**, y lo recomendamos: el disco donde Docker guarda sus
datos (normalmente `/var/lib/docker`, que tiene la base de datos y este
volumen) y el de `BACKUP_PATH`. Es una medida del sistema operativo o de la
plataforma de virtualización, y la decides tú.

**Pertenecer al grupo `docker` equivale a tener acceso a todos los datos.**
Quien está en ese grupo puede entrar en cualquier contenedor, copiar cualquier
fichero del volumen y leer la base de datos, sin pasar por el panel ni dejar
asiento. Trátalo como el acceso de administrador
([`endurecimiento.md`](endurecimiento.md) §3).

**El único camino auditado para llevarse un fichero es el panel.** Una
descarga desde el panel deja asiento (`data_export.downloaded`,
`report_export.downloaded`); sacar el mismo fichero con `docker compose cp`
no deja ninguno. Si lo haces, anótalo tú (§13.2).

**Tres alertas vigilan estos ficheros** (§10.4): uno que desaparece antes de
caducar, una exportación para la Inspección olvidada más de 30 días y una purga
que se ha negado a tocar algo. Qué hacer con cada una:
[`../runbooks/ficheros-generados.md`](../runbooks/ficheros-generados.md).

**`docker compose down -v` borra el volumen**, igual que borra la base de
datos. No la uses nunca en una instalación en producción.

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
**No guarda fichajes de nadie, y está hecho para no guardar nombres ni
correos.** Desde la 2.2.0 el servidor filtra el texto de cada error al
guardarlo, con el mismo vocabulario técnico cerrado que describe §12.2: una
palabra que no esté en él —un nombre, un apellido— se guarda como «…», y los
números con forma de documento, teléfono, cuenta, código de empleado, fecha,
hora o dirección IP, como un marcador (`[n]`, `[id]`, `[time]`…). Además, un
fallo de base de datos nunca imprime lo que se intentó guardar, y el contexto
solo admite una lista cerrada de claves técnicas. Una persona solo puede
aparecer por su identificador interno (`employee_uuid`), que en tu
instalación se conserva para poder relacionar el error con lo que pasó y que
**no viaja en el paquete anonimizado**. Lo que no se puede descartar del todo
es lo mismo que en §12.2: un nombre que coincida con una palabra del
vocabulario y aparezca solo. El detalle, mecanismo a mecanismo, está en
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
| **Pendientes** | Cuántos fichajes tiene la tablet en su cola local sin enviar, y **de cuándo es el más antiguo**. Desde la 2.2.0, también **«Desconocido»** cuando la tablet ha perdido el almacenamiento de su cola, y **cuántos descartes tiene sin avisar** al servidor | «37 pendientes, el más antiguo de hace 3 h» es una tablet que lleva tres horas sin red, no un error. Los fichajes están a salvo mientras la tablet no se desvincule ni se le borren los datos del sitio. **«Desconocido» no es cero**: lee §16.3 bis antes de tocar esa tablet |
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
| **Fallo** | *Cola solo en memoria* | La tablet **ha perdido el almacenamiento de su cola** y guarda los fichajes en memoria, o no tiene dónde guardarlos. Sigue fichando, pero lo que encole **se pierde si se reinicia**, y el número de pendientes es «Desconocido». Desde la 2.2.0; ver §16.3 bis |
| **Aviso** | *Descartes sin avisar* | La tablet apartó fichajes que el servidor no aceptó como válidos y **todavía no ha conseguido avisar** de ellos: hasta que lo haga, RRHH no los ve. Desde la 2.2.0; ver §16.3 bis |
| **Desvinculado** | *Desvinculado* | Ya no es un origen de fichajes. No cuenta para ninguna alerta |

Cuando hay más de un motivo, la fila muestra **el más grave**, en este orden:
desvinculado, nunca ha dado señal, esperando el primer latido, sin señal, cola
solo en memoria, latido tardío, descartes sin avisar, batería baja, fichajes
pendientes, latiendo.

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

### 16.3 bis La cola de la tablet: en orden, y lo que se ve cuando algo no cuadra

Cuando la tablet recupera la red, envía su cola **en el orden en que se
ficharon**, y desde la 2.2.0 lo respeta de forma estricta: **un fichaje no se
registra mientras otro anterior de la misma tablet siga sin respuesta del
servidor**. No es un capricho: la entrada de las 07:00 y la salida de las 15:00
de la misma persona tienen que llegar en ese orden, o la salida se tomaría por
una entrada.

**Si uno se queda atascado, los demás esperan detrás. No se pierden.** Si el
servidor no puede procesar un fichaje en ese momento —está arrancando, la base
de datos tarda—, la tablet lo reintenta más tarde, y todo lo que vino detrás se
queda en la cola hasta que ese pase. Esto vale para la tablet entera, no por
persona: la tablet no sabe de quién es cada tarjeta sin red, así que no puede
dejar pasar a unos y no a otros. En el panel se ve como **«Fichajes
pendientes»** con el más antiguo clavado en la misma hora, y si el servidor
falla una y otra vez con el mismo fichaje, suena `ScanBatchItemNotProcessed` (§10.4).
Es una avería del servidor, no de la tablet: **no borres la cola de la tablet**,
corrige la causa en el servidor y sigue
[`../runbooks/cola-offline-atascada.md`](../runbooks/cola-offline-atascada.md).
Un fichaje que el servidor **rechaza** —una tarjeta revocada, uno que no cabe en
la jornada («Fichaje fuera de orden», [`guia-rrhh.md`](guia-rrhh.md) §4.4)— no
atasca nada: tiene respuesta, y la cola sigue.

**Dos situaciones nuevas en la pantalla «Quioscos»** (desde la 2.2.0):

- **«Pendientes: Desconocido»**, con el veredicto en **fallo**. La tablet **ha
  perdido su almacenamiento** —el del navegador, donde guarda la cola— y ha
  pasado a guardar en memoria. Sigue aceptando tarjetas, pero no sabe cuántos
  fichajes quedaron en el disco, y por eso no inventa un cero. Lo que se fiche
  ahora **se pierde si la tablet se reinicia o se apaga**. Qué hacer:
  1. **Ni reinicies la tablet, ni recargues la aplicación, ni borres sus datos
     mientras pueda tener fichajes solo en memoria**: cualquiera de las tres
     cosas los borra. Si hay red, espera a que sincronice; lo que tenga en
     memoria se envía como siempre. Además, la tablet intenta recuperar su
     almacenamiento por sí sola cada poco, y si lo consigue pasa al disco lo
     que tenía en memoria y la fila vuelve a mostrar un número.
  2. **Cuando ya no le quede nada por enviar** —compruébalo en su pantalla de
     diagnóstico (§16.5, bloque «Cola»)— y siga en «Desconocido», **recarga la
     aplicación** (saliendo del modo quiosco con el PIN de IT).
  3. Si no vuelve, **libera espacio en la tablet** (descargas y aplicaciones
     ajenas) y comprueba que el navegador no está en modo invitado o privado.
     Recarga otra vez.
  4. Si sigue igual, sustituye la tablet
     ([`../runbooks/alta-nuevo-quiosco.md`](../runbooks/alta-nuevo-quiosco.md))
     **después** de que haya sincronizado.

  Mientras dure suena la alerta `KioskQueueStorageDegraded` (§10.4), y las dos
  alertas de cola atascada callan porque no hay tamaño que medir.
- **«N descartes sin avisar al servidor»**, con el veredicto en **aviso**. La
  tablet envió fichajes que el servidor **no aceptó como válidos** —casi
  siempre, una aplicación de la tablet más antigua que el servidor tras una
  actualización—. Para no atascar la cola los ha apartado, pero los conserva y
  tiene que **avisar** de ellos al servidor, que es lo que abre la incidencia
  «Fichaje descartado por el quiosco» para RRHH
  ([`guia-rrhh.md`](guia-rrhh.md) §4.7). Mientras el número no baje a cero,
  RRHH no sabe de esos fichajes. Qué hacer: comprueba que la tablet tiene red
  (el aviso sale solo en cuanto la tiene), y **actualiza su aplicación**
  recargándola con la cola vacía (§11, «las tablets»). **No la desvincules ni
  le borres los datos**: esos avisos solo existen en ella. Si pasa de 30
  minutos suena la alerta `KioskUnreportedDiscards` (§10.4).

### 16.4 Qué hacer cuando una fila no está al día

**Cuando —y solo cuando— el veredicto no es «al día»**, la fila trae un bloque
**«Qué hacer»** escrito para quien no conoce el sistema, con el paso siguiente
según la razón. Un quiosco que va bien no pide nada. El resumen:

| Lo que ves | Por dónde empezar |
| --- | --- |
| **Fallo, sin señal** | [`../runbooks/quiosco-no-responde.md`](../runbooks/quiosco-no-responde.md) §2, que arranca en esta misma pantalla |
| **Aviso, fichajes pendientes** — la tablet **tiene red y sigue con fichajes sin enviar** | [`../runbooks/cola-offline-atascada.md`](../runbooks/cola-offline-atascada.md). **No desvincules esa tablet**: perderías la cola |
| **Fallo, cola solo en memoria** — «Pendientes: Desconocido» | §16.3 bis y [`../runbooks/cola-offline-atascada.md`](../runbooks/cola-offline-atascada.md) §7. **No reinicies la tablet** hasta que haya sincronizado |
| **Aviso, descartes sin avisar** | §16.3 bis y [`../runbooks/cola-offline-atascada.md`](../runbooks/cola-offline-atascada.md) §8. Red primero; después, actualizar la aplicación de esa tablet |
| **Aviso, batería baja** | Ve al punto de montaje: cargador desenchufado, regleta apagada o cable partido |
| **Aviso, latido tardío** | Nada todavía. Si no vuelve a «al día» en diez minutos pasará a fallo y sonará la alerta |
| **La tablet volvió sola a la pantalla de emparejamiento** | Alguien la desvinculó, o pasó más de unos 18 días sin latido y su token caducó sin llegar a renovarse (§18): [`../runbooks/alta-nuevo-quiosco.md`](../runbooks/alta-nuevo-quiosco.md) §6 |

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
| **Cola** | Fichajes pendientes, de cuándo es el más antiguo, si el almacenamiento de la tablet es duradero y si está sincronizando ahora. Desde la 2.2.0, también **cuántos fichajes descartados tiene sin avisar** al servidor (§16.3 bis) |
| **Padrón** | De cuándo es la copia local de la plantilla y cuántas entradas tiene |
| **Token** | Si la tablet está vinculada, cuándo caduca su credencial, su identificador de dispositivo y el nombre del quiosco. **El token no se muestra nunca**: solo ocho caracteres de su huella, para poder compararlo con el panel |
| **Versión** | La versión de la PWA y el estado de su actualización: «al día» o «actualización pendiente: se aplicará en la ventana HH:MM–HH:MM», con la ventana vigente (§11.1) |
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
| `KIOSK_UPDATE_WINDOW` | `03:00-05:00` | Franja, en hora local del centro, en la que la tablet **puede** instalar una versión nueva de la app (§11.1). **No es una variable del `.env`**: se cambia en el panel |
| `KIOSK_UPDATE_QUIET_MINUTES` | `10` | Minutos sin ningún fichaje que la tablet exige, además de la ventana y la cola vacía, antes de actualizarse (§11.1). Íd.: en el panel |

---

## 17. Dimensionado del servidor y prueba de carga

> **Para qué es este apartado.** Para responder con una medida tuya, y no con
> una promesa nuestra, a dos preguntas que solo se hacen una vez: «¿este
> servidor aguanta mi cambio de turno?» y «¿qué toco si no aguanta?».

### 17.1 Qué promete el producto, y qué significa en un hotel

El producto se diseña contra un umbral escrito: **50 fichajes por segundo
sostenidos en el servidor, con el 95 % de las respuestas por debajo de 150 ms**
(`RNF-P-06` y `RNF-P-02`). **Hoy es un objetivo de diseño, no una cifra medida
que podamos entregarte**: todavía no se ha medido en el hardware de referencia
(4 núcleos y 8 GB) con un servidor dedicado, así que ninguna versión viaja con
esa medición. Cuando exista, las notas de la versión lo dirán con la cifra y la
máquina.

**Mídelo tú con `make load-test`** (§17.2): es la misma prueba, da un veredicto
requisito a requisito y es la única cifra que vale para tu servidor, porque sale
de tu hardware, tu disco y tu red.

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
| **En el servidor, en total** | **50 fichajes por segundo sostenidos** sumando todos los orígenes, con p95 < 150 ms | Es el objetivo de diseño y lo que juzga la prueba de carga cuando la ejecutas sobre tu servidor |

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
§0). El mínimo está dimensionado para una plantilla de hasta 100 personas con
el pool de serie —es el objetivo de diseño; confírmalo en tu servidor con
`make load-test`—; a partir de ahí la conversación es de núcleos y de RAM antes
que de parámetros.

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

---

## 18. Qué hacer si…

Los fallos previsibles de una instalación en marcha, con el síntoma que ve
alguien del hotel y las órdenes para salir de él. **Antes de nada, en
cualquiera de ellos:**

```bash
./doctor.sh
```

Dice qué está en rojo y qué hacer con cada cosa (§12.1). Lo que sigue es para
cuando ya sabes cuál de estos casos es el tuyo.

### …una tablet vuelve a la pantalla de emparejamiento tras muchos días apagada o sin red

**Qué pasa.** Cada tablet recibe al vincularla un token que vive **90 días**
(`IDENTITY_DEVICE_TOKEN_DAYS`). **No tienes que renovarlo tú**: mientras la
tablet esté encendida y con red, el servidor le entrega uno nuevo en su latido
cuando ese token ha consumido el 80 % de su vida
(`IDENTITY_DEVICE_TOKEN_ROTATION_THRESHOLD`) —con los valores de serie, hacia
el **día 72**—, y la tablet lo adopta sin que nadie haga nada y sin
interrumpir el fichaje. El token anterior sigue valiendo **24 horas más**
(`IDENTITY_DEVICE_TOKEN_OVERLAP_HOURS`) o hasta que la tablet use el nuevo, lo
que ocurra antes: si un corte de wifi se come justo esa respuesta, el latido
siguiente le entrega otro. Cada renovación queda en la auditoría como
`device.paired`.

**El límite, que es lo único que tienes que vigilar.** La renovación solo
puede ocurrir en un latido. Entre el día 72 y el día 90 la tablet tiene **18
días** para dar al menos uno. Una tablet que pasa **más de unos 18 días
seguidos sin latido** —apagada en un almacén, guardada fuera de temporada, o
encendida pero sin red todo ese tiempo— puede llegar al día 90 sin haber
recogido el relevo. Entonces su token caduca: al volver, la tablet deja de ser
aceptada, va sola a la pantalla de emparejamiento y **en ella no se puede
fichar hasta volver a vincularla**. No es un fallo ni se arregla solo.

La renovación la hace **la app de la tablet desde la 2.2.0**. Tras actualizar
el servidor, cada tablet carga la app nueva en su ventana de actualización
(§11.1), normalmente esa misma noche. Comprueba en **Quioscos** que la columna
**«Versión de la aplicación»** de todas se ha puesto al día: una tablet que
siguiera con una app anterior no recogería el relevo y volvería a la pantalla de
emparejamiento al día siguiente de cumplir su día 72.

**Lo que no se pierde:** los fichajes que la tablet tuviera en su cola local. Se
conservan y se envían en cuanto vuelve a estar vinculada.

**Prevención.** En el panel, **Quioscos**, la columna **«Último contacto»** dice
cuándo latió cada tablet por última vez (§16.1). Una tablet en **fallo, sin
señal** no es urgente por el token durante los primeros días, pero no la dejes
así más de **dos semanas**: enciéndela y conéctala para que dé un latido. Si
vas a guardar una tablet más tiempo (cierre de temporada, puesto que no se usa),
lo limpio es **desvincularla** al guardarla y vincularla de nuevo al sacarla,
con el mismo nombre.

**Si ya ha pasado.** El panel sigue mostrando ese quiosco como **activo** (su
veredicto será *Sin señal*): el servidor no sabe que la tablet se ha quedado
fuera hasta que alguien lo resuelve. Por eso **no te dejará vincularla con el
mismo nombre** —dirá que ese nombre ya está en uso— **hasta que la desvincules
primero**. Con la tablet delante y fuera del cambio de turno:

1. **Desvincúlala** (Quioscos › el quiosco › **Desvincular**). En un par de
   minutos la tablet muestra un código nuevo.
2. **Vincúlala con el mismo nombre exacto.** Se reactiva el mismo quiosco, con
   su historia, y recibe un token nuevo de 90 días que a partir de ahí vuelve a
   renovarse solo.
3. Comprueba que su cola baja a `0`:

```bash
docker compose exec app php artisan kiosk:health
```

Son dos minutos y quedan en la auditoría. El detalle, con capturas, está en
[`../runbooks/alta-nuevo-quiosco.md`](../runbooks/alta-nuevo-quiosco.md) §5.2 y
§5.3.

**Si una tablet que sí latía a diario vuelve a la pantalla de emparejamiento**,
no es el token caducado: alguien la desvinculó. Trátalo como dice
[`../runbooks/alta-nuevo-quiosco.md`](../runbooks/alta-nuevo-quiosco.md) §6,
«…la tablet vuelve sola a la pantalla de emparejamiento».

### …Redis se reinicia una y otra vez, casi siempre tras un corte de luz

**Síntoma.** `docker compose ps` muestra `redis` como `Restarting`, y su
registro habla del fichero de persistencia (`Bad file format reading the append
only file`, `AOF ... is not valid`). Un apagado brusco dejó ese fichero a medias
y Redis se niega a arrancar con él.

**Impacto.** **El fichaje sigue funcionando**: las tablets registran contra la
base de datos, que no depende de Redis. Lo que sí falla mientras tanto:

| Qué | Cómo se nota |
| --- | --- |
| El acceso al panel y al portal | Errores al entrar o pantallas que no cargan |
| Los trabajos en segundo plano (informes, exportaciones, avisos) | Los que se pidan ahora salen **«Fallida»**: hay que repetirlos cuando Redis vuelva |
| La pantalla de presencia en tiempo real | Deja de actualizarse al instante |

**Qué hacer.** `./doctor.sh` lo detecta y te da estas mismas órdenes. Para
Redis, repara su fichero y vuelve a levantarlo. La reparación pregunta antes de
recortar; el `echo y` de la segunda orden contesta por ti. Termina con `All AOF
files and manifest are valid`.

```bash
docker compose logs --tail 30 redis
docker compose stop redis
echo y | docker compose run --rm -T --no-deps --entrypoint redis-check-aof redis --fix /data/appendonlydir/appendonly.aof.manifest
docker compose up -d redis
./doctor.sh
```

Si `./doctor.sh` sigue viendo algún servicio parado, `docker compose up -d` lo
levanta todo. El mismo procedimiento, visto desde la alerta que lo dispara, está
en [`../runbooks/errores-en-el-panel.md`](../runbooks/errores-en-el-panel.md)
§1.1.

La reparación recorta lo último que se escribió antes del corte. **En Redis no
hay nada del registro horario**: lo que se pierde son, como mucho, trabajos que
estaban en cola en ese instante. Si un informe sale «Fallido», vuelve a pedirlo.

**Si `redis-check-aof` no consigue repararlo**, se puede arrancar Redis vacío:
por la misma razón, no se pierde ningún fichaje ni ninguna corrección. Se
pierden los trabajos en cola y los contadores de intentos fallidos, que vuelven
a cero. Cambia `NOMBRE` por el que devuelva la segunda orden:

```bash
docker compose rm -sf redis
docker volume ls --filter name=redis-data
docker volume rm NOMBRE
docker compose up -d
```

### …una pantalla del panel dice que esa función no está incluida en la licencia (`402`)

**No es una avería ni un permiso.** Es la respuesta `402` del producto: la
funcionalidad **accesoria** que se ha pedido —informes por periodo, exportación
para nómina, cuadro de adopción, exportaciones en segundo plano— no está en la
licencia activa, o la licencia ha caducado. El propio mensaje dice qué sigue
disponible.

**Lo que nunca responde `402`:** el fichaje, la sincronización de las tablets, la
consulta de jornadas, el portal del empleado, la exportación para la Inspección,
las correcciones, la auditoría y las copias. Eso no se toca con ninguna licencia
(§7).

**Qué hacer:**

```bash
docker compose exec app php artisan license:show
```

Si dice que no hay licencia, que caducó o que esa funcionalidad no está en el
plan, es una conversación con el fabricante, no una tarea de IT. Con la clave
nueva, `license:activate` (§7). No reinicies nada: no sirve de nada.

### …los informes se quedan «En cola» y no terminan nunca

**Qué pasa.** Los informes y exportaciones que no caben en una respuesta al
momento los genera el servicio **`horizon`**, el trabajador de colas. Si está
parado, se quedan **«En cola»**. **No se pierden**: se generan en cuanto vuelve.
Hoy ninguna alerta avisa de que `horizon` está parado; el síntoma es este.

```bash
docker compose ps horizon
docker compose logs --tail 50 horizon
docker compose up -d horizon
```

Si `horizon` no arranca, mira su registro: casi siempre es que Redis tampoco
está (sección anterior de este mismo apartado) o que la base de datos no
responde (`./doctor.sh`). El fichaje no depende de `horizon`.

### …la copia nocturna ha fallado

**Síntoma.** Suena `CopiaDeSeguridadFallida`, `CopiaDeSeguridadSinVerificar` o
`CopiaDeSeguridadAusente` (§10.4), o `./doctor.sh` avisa de que no puede escribir
en el directorio de copias.

**Impacto.** El fichaje no se entera. Pero **sin una copia verificada no hay
actualización** (`update.sh` se niega en su paso 3) y, si hubiera que restaurar,
volverías a la última copia buena. Resuélvelo el mismo día.

Las órdenes de copia van por el contenedor **`scheduler`**, no por `app`: es el
que tiene la clave de cifrado y el rol de copias.

```bash
docker compose ps scheduler
docker compose logs --since 24h scheduler | grep -i backup
docker compose exec scheduler php artisan backup:verify
docker compose exec scheduler php artisan backup:run
```

La última termina con un código de la tabla común (§8), y el mensaje dice la
causa:

| Código | Causa más frecuente | Qué hacer |
| --- | --- | --- |
| `2` | `BACKUP_PATH` sin montar o sin espacio, o falta la clave de cifrado | Monta el destino o libera espacio y repite. La copia anterior sigue intacta |
| `6` | La copia se escribió pero **no verifica** | Trátala como inexistente y repite. Si se repite, [`../runbooks/restaurar-backup.md`](../runbooks/restaurar-backup.md) §2 |
| `7` | El rol de las copias tiene más privilegios de los permitidos | No es una avería: [`../runbooks/rotacion-secretos.md`](../runbooks/rotacion-secretos.md), «El rol de las copias es privilegiado» |

El diagnóstico completo, código a código, está en
[`../runbooks/restaurar-backup.md`](../runbooks/restaurar-backup.md) §2.

### …la restauración se niega por integridad (salida `6`, «copia no autenticada»)

**Qué pasa.** Desde la 2.2.0 cada copia lleva dentro una firma con clave (un
MAC) sobre su contenido, su nombre y su fecha de creación. `restore.sh`,
`restore-drill.sh` y `backup.sh verify` la comprueban **antes** de descifrar, y
se niegan, **sin tocar la base**, si:

- falta el `.sha256` de la copia (es obligatorio);
- el MAC no cuadra: el fichero se ha alterado o está dañado;
- el nombre del fichero no es el que dice su cabecera: la han renombrado o
  puesto en lugar de otra;
- falta o no cuadra el manifiesto autenticado (`.manifest.mac`) del volcado;
- la copia es **de la 2.1.0**, que no lleva MAC: es una «copia no autenticada»,
  y no se usa si no lo pides expresamente.

**Antes de confirmar una restauración, lee la fecha que enseña `restore.sh`**
(«copia creada el …»). Sale de la cabecera autenticada, no del nombre del
fichero: si no es la que esperas, alguien ha puesto una copia anterior en su
lugar. No sigas.

**Qué hacer**, según el mensaje:

| Mensaje | Qué hacer |
| --- | --- |
| `el MAC no cuadra` | No uses esa copia: prueba la anterior (`restore.sh --list`). Si no hay una avería de almacenamiento que lo explique, trátalo como incidente de seguridad ([`brecha-de-seguridad.md`](../runbooks/brecha-de-seguridad.md)) |
| `clave distinta o cabecera alterada` | Casi siempre, una rotación de `BACKUP_ENCRYPTION_KEY`: pasa la anterior con `-e BACKUP_ENCRYPTION_KEY_PREVIOUS` a `docker compose run --rm restore` (solo para restaurar) y repite |
| `sin .sha256`, o falta el manifiesto | La copia está incompleta o la han tocado. No la uses |
| `copia heredada de la 2.1.0` | Ver abajo |

**Restaurar una copia de la 2.1.0.** Está cifrada pero **no autenticada**: su
`.sha256` no prueba nada frente a quien pueda escribir en el destino. Si es la
que necesitas, pídelo **en la propia orden** con `--accept-unauthenticated`
(primero con `--dry-run`):

```bash
docker compose run --rm --no-deps restore bash /opt/kronoqr/scripts/restore.sh --accept-unauthenticated --file <copia>.dump.enc --dry-run
```

- Solo sirve para copias de la 2.1.0, y el `.sha256` **sigue siendo
  obligatorio** y tiene que coincidir.
- Se pasa **en cada orden**. Nunca pongas `KRONOQR_ACCEPT_UNAUTHENTICATED` en el
  `.env` ni en una tabla de cron: se ignora (solo vale la opción `--accept-unauthenticated`), y `./doctor.sh` lo señala como fallo.
- **Queda constancia**: el informe de la restauración lo anota en su primera
  línea y el asiento `system.restored_from_backup` del registro de auditoría
  lleva `integrity=legacy_accepted`.
- Desaparecerá cuando ya no se pueda actualizar desde ninguna versión anterior a
  la 2.2.0. Las copias de la 2.1.0 caducan solas a los `BACKUP_RETENTION_DAYS`.

El detalle está en
[`../runbooks/restaurar-backup.md`](../runbooks/restaurar-backup.md) §6.8.

### …la recuperación a un punto en el tiempo se detiene («restore_command failed», `exit 200`)

**Qué pasa.** Al reproducir el WAL archivado sobre una copia física,
`kronoqr-restore-wal` ha encontrado un segmento que **no es de fiar**: el MAC no
cuadra, está cifrado con otra clave, falta un segmento intermedio o no se ha
podido leer. Devuelve `200` y PostgreSQL **aborta la recuperación a propósito**:
si siguiera, la base «recuperaría con éxito» hasta el segmento anterior y se
perderían datos sin que nadie lo viera. El error nombra el segmento y el motivo.

**Qué hacer:**

1. **No uses ni promociones esa base.** Se queda en recuperación, y es lo
   correcto.
2. Resuelve el motivo con la tabla del runbook (§4.1): la clave anterior si se
   rotó `BACKUP_ENCRYPTION_KEY`, o el segmento desde otro soporte que lo
   conserve.
3. Si no se puede, repite con un `recovery_target_time` **anterior** a ese
   segmento, y anota en el parte hasta dónde llegaste.

El procedimiento completo, con las órdenes, está en
[`../runbooks/restaurar-backup.md`](../runbooks/restaurar-backup.md) §6.4.
Ensáyalo antes con `restore-drill.sh --mode pitr`, que lo hace en un contenedor
limpio sin tocar nada (runbook §7).

### …después de restaurar una copia, una exportación o un informe sale «Caducada»

**Qué pasa.** Lo has restaurado todo bien. La copia de seguridad lleva la base
de datos, pero **no lleva los ficheros generados** (§13.6): la exportación
íntegra y los informes en segundo plano caducan en 7 días y se pueden volver a
generar desde la base, así que no se copian. Al restaurar pueden quedar
desparejados en los dos sentidos:

- **La base recuerda una exportación o un informe cuyo fichero ya no está**
  —se purgó después de la copia, o has restaurado en un servidor nuevo, con el
  volumen vacío—. En la primera pasada de purga (la de cada hora para las
  exportaciones íntegras, la de las 04:25 UTC para los informes) pasa a
  **«Caducada»** y deja el asiento `data_export.file_missing` o
  `report_export.file_missing`; si no había caducado aún, puede sonar además
  la alerta `FicheroGeneradoDesaparecidoAntesDeCaducar`. **Tras una
  restauración es lo esperado**, y
  el informe de `restore.sh` (en `BACKUP_PATH/reports/`) lo anuncia.
- **El volumen tiene un fichero que la base restaurada no conoce** —se generó
  después de la copia—. Nadie lo puede descargar desde el panel, y la purga lo
  borra sola cuando cumple su plazo. No hay que hacer nada.

**Qué hacer.** Pide de nuevo lo que necesites: la exportación íntegra desde
Licencia → «Tus datos son tuyos» (§13.2), y cada informe desde el panel, quien
lo necesite. Se generan de los datos restaurados, que es lo que quieres. Si
`FicheroGeneradoDesaparecidoAntesDeCaducar` suena **sin** que haya habido restauración ni
actualización desde la 2.1.0, no es esto: lee §13.3, «Si el fichero desaparece
antes de caducar».

**Lo que no se ha perdido.** Los informes de retención están en
`BACKUP_PATH/reports/retention` y la restauración no los toca: un informe de
purga describe algo que pasó, aunque la base vuelva a un momento anterior.

### …necesitas acreditar una purga y su informe no está

**Cuándo pasa.** Te piden demostrar que una purga de retención fue regular
—una reclamación, una auditoría de protección de datos— y el fichero
`retencion-purga-*.txt` no está en `BACKUP_PATH/reports/retention`. Hasta la
2.1.0 los informes de las purgas lanzadas con `run --rm` desaparecían al
terminar la orden, y los demás con cada actualización; `update.sh` rescata al
pasar a la 2.2.0 los que aún quedaban (§11), pero no los que ya se habían
perdido.

**Lo que vale es el asiento, y sigue ahí.** Cada purga que borró registros de
jornada dejó en el registro de auditoría un asiento `retention.purge_executed`
con la fecha, la fecha de corte, los años de retención, los recuentos por tabla
y el token de confirmación. El registro de auditoría se conserva cuatro años y
está encadenado por hash: es una prueba más fuerte que el fichero, que nunca
fue más que su copia legible (§3.1).

**Qué hacer:**

1. Comprueba que la cadena de auditoría está íntegra:

   ```bash
   docker compose exec app php artisan compliance:verify-audit-chain
   ```

2. Saca el asiento con la consulta de §3.1 y localiza la purga por su fecha.
3. Cítalo así en tu respuesta o en tu expediente: *«Purga de retención
   ejecutada el AAAA-MM-DD a las HH:MM UTC, registrada en el registro de
   auditoría del sistema como `retention.purge_executed` con la confirmación
   PURGAR-AAAA-MM-DD-xxxxxx: registros de jornada anteriores al AAAA-MM-DD,
   conservados N años; N filas, desglosadas por tabla en el propio asiento. La
   integridad del registro de auditoría se ha verificado el AAAA-MM-DD.»*
4. Adjunta la salida de las dos órdenes y la autorización escrita que se firmó
   entonces.

Si la purga solo soltó particiones de auditoría, lo que hay que citar son sus
asientos `retention.partition_sealed` y `retention.partition_dropped` (§3.1).
