# ADR-045 — Los ficheros que genera el producto viven en un volumen compartido, y su copia legible de la constancia vive junto a las copias

| Campo | Valor |
|---|---|
| **Estado** | Aceptada — **aprobada con condiciones** por `seguridad-cumplimiento` el 2 de octubre de 2026. Las condiciones C1-C10 del dictamen están incorporadas a este texto y son parte de la decisión |
| **Fecha** | 2 de octubre de 2026 |
| **Decide** | `arquitecto-dominio` (Bloque 16 de la 2.2.0, hallazgo R6-AR-03) · `seguridad-cumplimiento` (revisión y condiciones) |
| **Afecta a** | `infra/compose.prod.yaml` (ancla `x-app-image`, volúmenes de primer nivel) · `infra/docker/php/Dockerfile` · `install.sh`, `update.sh`, `doctor.sh`, `restore.sh` · `config/compliance.php` (`retention.report_path`), `config/product.php` · `ZipDataExportArchiveWriter`, `FilesystemReportExportStorage`, `LegalExportController`, `LegalExportCommand`, `PurgeOrphanedLegalExportTempFilesCommand` · `product:export-all --purge`, `reporting:purge-expired-exports`, `product:diagnostics` · `product:doctor` · `AuditAction` · `docs/cliente/operacion.md`, `configuracion.md`, `solicitud-derechos-rgpd.md`, `obligaciones-legales.md` (+EN) |
| **Requisitos** | RF-PD-14, RL-20, RF-IN-06, RF-PR-03, RF-PR-04, RF-PD-11, RF-PD-12, RL-02, RL-12, RL-14, RL-15, RL-19, reglas duras 5, 6, 13, 16 y 21 |
| **Precisa** | [ADR-041](ADR-041-descarga-de-ficheros-diferidos-con-enlace-de-un-solo-uso.md) (decide **cómo** se descarga un fichero diferido; este decide **dónde vive**, y en su §6 `purged` pasa a significar también «el fichero desapareció»), [ADR-020](ADR-020-soporte-con-paquete-de-diagnostico.md) (dónde queda el paquete y cuándo se barre), [ADR-042](ADR-042-el-runtime-no-tiene-credencial-que-pueda-alterar-el-registro.md) (qué monta cada servicio; ver «Encaje con ADR-042») |
| **Hallazgos** | R3-PL-01, R3-PL-02, R3-BE-04, R4-SC-02, R5-DV-03, R6-AR-03, R6-PL-05 · se cruza con A3-R2 y R5-DV-04 |

## Contexto

En producción, `app`, `horizon` y `scheduler` son **tres contenedores de la misma imagen** que solo
comparten dos montajes del ancla `x-app-image`: el destino de las copias (`BACKUP_PATH`, lectura y
escritura) y la carpeta de marca (solo lectura). **Nada monta `storage/`.** Cada contenedor tiene su
propio `storage/app` en su capa de escritura, y esa capa desaparece cada vez que `update.sh` —o
cualquier `docker compose up` que cambie la imagen— recrea el contenedor.

Las pruebas no lo ven porque corren en un solo proceso, y el entorno de desarrollo tampoco, porque
`compose.dev.yaml` monta el código entero. En una instalación real, reproducido en docker-in-docker
(R3-PL-01, R3-PL-02):

1. **La exportación íntegra pedida desde el panel no se puede descargar.** El ZIP lo escribe
   `GenerateDataExportJob` en `horizon`; la descarga la sirve `app`, que no lo ve y responde `404`. Es
   RF-PD-14 y RL-20, y el doc 05 §12 promete que el hotel exporta «por sí mismo».
2. **Lo mismo con los informes en diferido** (RF-IN-06, `GenerateReportExportJob`), por código.
3. **Las purgas no ven lo que deben borrar.** `product:export-all --purge` y
   `reporting:purge-expired-exports` corren en `scheduler`; los ficheros están en `horizon`. Una copia
   completa de la plantilla y de su registro se queda en disco más allá de los 7 días declarados
   (R4-SC-02), y la fila dice `purged` sin que el fichero se haya borrado. Igual con los temporales
   huérfanos de la exportación legal por HTTP, que escribe `app` y purga `scheduler`.
4. **Los informes de retención se pierden**: la propuesta semanal queda en `scheduler`, la purga real
   se lanza con `run --rm app` y su informe desaparece con el contenedor, y cualquier actualización
   borra el resto.
5. **El identificador de la telemetría cambia** en cada recreación de `scheduler`.

Ningún ADR decidía dónde viven estos ficheros. Cada comentario de `config/` razonaba bien **qué** no
debía mezclarse con `BACKUP_PATH`, pero daba por supuesto que `storage/app` era un único sitio
compartido y persistente, y en producción no lo es. Y el borrado accidental que provocaba cada
recreación tapaba otro problema: **los restos de una generación interrumpida** (`.work-<uuid>/` de la
exportación íntegra, con todos los datos en claro) y el último paquete de diagnóstico desaparecían «de
rebote». Con un volumen persistente dejan de hacerlo, y por eso esta decisión los trata a propósito.

## Inventario

Lo que el producto escribe en disco fuera de PostgreSQL y Redis, buscado en el código
(`storage_path`, `config/*.php`, `sys_get_temp_dir`, `tempnam`, variables `*_PATH`), no supuesto:

| Fichero | Ruta por defecto | Escribe | Lee o borra | Datos personales | Clase |
|---|---|---|---|---|---|
| Exportación íntegra (ZIP) `kronoqr-export-*.zip` | `storage/app/exports` | `horizon` (panel) · `app` (consola) | `app` (descarga) · `scheduler` (purga horaria) | **Todos** | Efímero con caducidad (7 d) |
| Espacio de trabajo de la exportación íntegra `.work-<uuid>/` | `storage/app/exports` | `horizon` · `app` | El mismo trabajo (`discard()` en el `finally`) | **Todos, sin comprimir** | Efímero de generación |
| Temporales de `ZipArchive` (`<destino>.zip.<sufijo>`) | `storage/app/exports` | `horizon` · `app` al sellar | `ZipArchive` al cerrar | **Todos** | Efímero de generación |
| Informes en diferido `<uuid>/<fichero>` | `storage/app/reports` | `horizon` | `app` (descarga) · `scheduler` (purga diaria) | Sí | Efímero con caducidad (7 d) |
| Temporal de la exportación legal por HTTP | `storage/framework/legal-exports` | `app` | `app` (lo borra al terminar) · `scheduler` (huérfanos > 6 h) | Sí | Efímero con caducidad (6 h) |
| Exportación legal por consola (para Inspección) | `storage/app/legal-exports` | `app` (`exec`) | Una persona, que la saca y la borra | Sí | Efímero bajo custodia humana |
| Paquete de diagnóstico (consola) | `storage/app/diagnostics` | `app` (`exec`) | `app` (purga al generar el siguiente) | Solo sin anonimizar | Efímero con caducidad (7 d) |
| Estado de la telemetría | `storage/app/telemetry/state.json` | `scheduler` (`--send`) · `app` (consola) | Los mismos | No | Estado reconstruible |
| Informes de retención (propuesta y purga) | `storage/app/retention-reports` | `scheduler` (propuesta semanal) · `app` (`run --rm`, purga real). **`horizon` no** | Una persona, años después | No (ámbitos, tablas, recuentos, fechas, centro y token) | Copia legible de una constancia que vive en `audit_log` |
| Discos `local` y `public` de `config/filesystems.php` | `storage/app/private`, `storage/app/public` | Nadie hoy (ningún `Storage::` en `app/`) | — | — | Sin uso; si se usan, quedan en el volumen y la prueba de inventario obliga a clasificarlos |
| Raíz de marca por defecto (`config/branding.php`) | `storage/app/branding` | Nadie en producción (`BRANDING_LOGO_ROOT=/var/kronoqr/branding`) | — | No | Solo desarrollo |
| Informes de `update.sh`, `restore.sh`, simulacros | `BACKUP_PATH/reports` | Scripts del anfitrión | Una persona | No | Duradero (ya resuelto) |
| Copias, WAL, textfile de métricas | `BACKUP_PATH/{daily,base,wal,metrics}` | `scheduler`, `postgres` | `restore`, `node-exporter` | Sí, cifrado | Duradero (ya resuelto) |
| Logotipo | `BRANDING_PATH` → `/var/kronoqr/branding:ro` | El IT del hotel | `app` | No | Configuración (ya resuelto) |
| Cachés del framework | `storage/framework/{cache,sessions,views}`, `bootstrap/cache` | Todos | El mismo contenedor | No (caché y sesión van a Redis) | Desechable |
| Log técnico | `storage/logs` | Todos | El mismo contenedor | — | Desechable: en producción `LOG_STACK=stderr` y el log vive en Docker y Loki |
| Temporal del informe de adopción (`tempnam(sys_get_temp_dir())`) | `/tmp` | `app`, dentro de la petición | El mismo | No | Desechable |

## Decisión

**Hay tres clases de fichero, y cada una vive en un sitio distinto.**

### 1. Lo efímero que cruza contenedores: volumen con nombre `app-storage`

- Montado en `/var/www/html/storage/app` desde el ancla `x-app-image`, así que lo heredan `app`,
  `horizon`, `scheduler` y cualquier `run --rm app`. **No** lo montan `reverb` (ya lleva
  `volumes: []`), `nginx`, `migrate` ni `restore`. Lectura y escritura en los tres: los tres escriben
  o borran (descarga y consola en `app`, generación en `horizon`, purgas y telemetría en `scheduler`),
  así que no cabe solo lectura en ninguno.
- **Permisos fijados de verdad (C6).** El Dockerfile crea la raíz con
  `install -d -m 0700 -o app -g app /var/www/html/storage/app`. Hoy la línea que la crea no lleva
  `-m`, y la copia inicial que Docker hace al montar un volumen vacío heredaría ese modo. Los
  escritores siguen creando sus subdirectorios con `0700` y sus ficheros con `0600`. `doctor.sh`
  comprueba el montaje **y** el dueño y el modo de la raíz del volumen.
- **El temporal de la exportación legal por HTTP sale de `storage/framework`** y pasa a
  `storage/app/tmp/legal-exports`, dentro del volumen, para que su purga de huérfanos, que corre en
  `scheduler`, lo vea. Sigue sin servirse por HTTP: `nginx` no monta el volumen y la imagen de
  producción no hace `storage:link`.
- **Cada clase tiene una raíz propia y distinta**: `exports`, `reports`, `tmp/legal-exports`,
  `legal-exports`, `diagnostics`, `telemetry`. Las variables `PRODUCT_DATA_EXPORT_PATH`,
  `REPORTING_EXPORT_PATH`, `PRODUCT_DIAGNOSTICS_PATH` y `TELEMETRY_STATE_PATH` se conservan y siguen en
  el ancla común `runtime-env`, con dos condiciones escritas en `.env.example`: tienen que quedar
  dentro de `/var/www/html/storage/app` (una ruta del servidor sin montaje no sirve de nada), y **no
  pueden coincidir ni solaparse entre sí**. `product:doctor` **falla** si dos raíces coinciden o una
  contiene a otra, o si alguna es `storage/app` o `BACKUP_PATH` (C3).

### 2. La copia legible de la constancia: `BACKUP_PATH/reports/retention`

- **La constancia con valor de una purga es el asiento `retention.purge_executed` de `audit_log`**,
  encadenado y anclado en `audit_chain_anchors` (ADR-010). Lleva `cutoff_date`, `retention_years`,
  `tables`, `rows` y `confirmation`. **El informe en fichero es su copia legible**: lo que lee el
  responsable que autoriza y lo que se adjunta a una reclamación. Se contrasta con el asiento por el
  token de confirmación que llevan los dos, y la guía del cliente explica cómo (C2). Alterar una
  propuesta no cambia lo que se purga: `ApplyRetention` recalcula el token sobre el plan vigente.
- Los informes de retención pasan a `${BACKUP_PATH}/reports/retention/`, junto a los de `update.sh` y
  `restore.sh`. El valor por defecto de `compliance.retention.report_path` se deriva de `BACKUP_PATH`,
  como ya hace `observability` con el textfile de métricas; `COMPLIANCE_RETENTION_REPORT_PATH` sigue
  pudiendo sobrescribirlo, y `product:doctor` avisa si apunta dentro de `storage/app`. No llevan datos
  personales, así que pueden vivir sin cifrar. La poda de copias solo toca `daily/` y `base/`, y
  `reports/` no se poda nunca: **no se limpian solos**.
- **Lo escriben `scheduler` (propuesta semanal) y `app` (purga real con `run --rm`). `horizon` no lo
  necesita.**

**Encaje con ADR-042 §Integridad y con A3-R2 / R5-DV-04.** Hoy `app`, `horizon` y `scheduler` montan
`BACKUP_PATH` **entero en escritura** desde el ancla, así que el runtime puede editar o borrar un
informe sin rastro. Ese choque es anterior a este ADR, pero este ADR añade un escritor (`run --rm app`)
y lo reconoce: por eso el fichero **no** es la prueba y el asiento sí. El diseño de A3-R2 —raíz de
`BACKUP_PATH` en solo lectura para el runtime y escritura solo en subdirectorios concretos— **incluye
`reports/retention` junto con `metrics/`** como subdirectorios en escritura: `reports/retention` en
`scheduler` y `app`, nunca en `horizon`. Lo cierra `devops-observabilidad` al cerrar A3-R2; hasta
entonces el riesgo queda aceptado en el doc 07.

### 3. Lo desechable sigue en la capa del contenedor

`storage/framework`, `storage/logs`, `bootstrap/cache` y los temporales de proceso. Perderlos al
recrear el contenedor es correcto.

### Volumen con nombre, y no una ruta del servidor

- **Nace con el dueño y los permisos correctos** por la copia inicial de Docker, sin `chown` en
  `install.sh` ni el aviso de dueño de reserva que hoy tiene `BACKUP_PATH`.
- **No expone los ZIP al usuario 1000 del anfitrión**, que en muchos servidores es la primera cuenta
  humana. Un volumen vive bajo la raíz de datos de Docker, legible solo por `root`.
- **No invita a que la copia de ficheros del anfitrión se lleve la exportación íntegra** y la conserve
  meses, que es justo lo que el plazo de 7 días quiere evitar.
- **No añade configuración**: nada que decidir en la instalación, nada distinto entre clientes
  (ADR-017). Es el mismo tratamiento que ya tiene `postgres-data`, que guarda los mismos datos de los
  que sale la exportación.

Lo que cuesta: un fichero del volumen se saca con `docker compose cp app:<ruta> .`, que **no deja
asiento de descarga**. El camino auditado es la descarga desde el panel, que con este ADR funciona, y
la guía lo dice así (C7).

### Copia y restauración

- **El volumen `app-storage` no entra en la copia, y `restore.sh` no lo repone.** Todo lo que contiene
  es efímero o reconstruible: la exportación íntegra y los informes en diferido son **una vista de
  datos que ya están en el volcado cifrado** (RL-12 solo exige cifrar las copias). Meterlos en la
  copia alargaría de 7 a 30 días la vida de una copia completa de los datos personales. Tras
  restaurar, el administrador vuelve a pedirla y se genera de los datos restaurados.
- **Los informes de retención están en `BACKUP_PATH`** y viajan con lo que el cliente haga con su
  destino de copias. **La restauración no los toca**: un informe de purga describe un hecho que
  ocurrió, aunque la base vuelva a un momento anterior.
- **El estado de la telemetría no se copia.** Restaurar en un servidor nuevo estrena un identificador
  de instalación. Ese identificador solo lo usa la telemetría, no la licencia: cambiarlo es neutro.
- `backup.sh` y `restore.sh` no cambian de comportamiento. Su cabecera dice qué **no** se repone y por
  qué, y **el informe de `restore.sh` anuncia** lo que cabe esperar después. Las exportaciones que
  figuraban en la copia como disponibles y cuyo fichero ya no existe —lo habitual al restaurar en un
  servidor nuevo o tras un `down -v`; en el mismo servidor el volumen no se toca— aparecerán como
  `purged` con un asiento `*.file_missing` esperado. Las generadas después de la copia no tienen fila
  en la base restaurada: sus ficheros quedan como huérfanos y se borran al cumplir su plazo, sin
  asiento.

### Caducidad y purga: la fila manda en el registro y el directorio manda en el borrado

Las purgas siguen donde están (`product:export-all --purge` cada hora y
`reporting:purge-expired-exports` a diario, en `scheduler`), y con el volumen **ven lo mismo que vio
quien generó el fichero**. Además, en la misma pasada:

**a) Confinamiento (C3).** Ninguna purga borra fuera de la raíz de su clase:

- Se lista **un solo nivel** de la raíz, con `realpath` resuelto bajo esa raíz y **patrón exacto de
  nombre** por clase. Nunca se sigue un enlace simbólico.
- Lo que no casa con el patrón no se toca.
- Cuando el resto es un directorio (`.work-<uuid>/`, `<uuid>/` de reporting) se borran sus ficheros
  regulares de un nivel y luego el directorio. **Si aparece un subdirectorio o un enlace, se aborta ese
  resto** con log técnico y métrica.
- **Una fila cuyo `file_path` resuelve fuera de la raíz de su clase se marca `purged` y no se borra
  nada.** Hoy `ZipDataExportArchiveWriter::delete()` y `FilesystemReportExportStorage::delete()` hacen
  `unlink` de la ruta de la fila sin confinarla. `storedUuids()` toma por `uuid` cualquier
  subdirectorio: con `REPORTING_EXPORT_PATH` apuntado a `storage/app`, vaciaría las demás clases.

**b) Qué es huérfano, por clase (C1).** Un resto está **protegido** solo si lo nombra una fila viva:
`pending`/`running` para los restos de generación, `completed` con `expires_at` futuro para el fichero
final. Todo lo demás que case con el patrón de su clase es huérfano:

| Clase | Raíz | Patrón | Huérfano cuando… | Edad mínima para borrar |
|---|---|---|---|---|
| ZIP de exportación íntegra | `exports` | `kronoqr-export-*.zip` | ninguna fila `completed` vigente lo nombra | El plazo de la clase (`PRODUCT_DATA_EXPORT_RETENTION_DAYS`) |
| Espacio de trabajo | `exports` | `.work-<uuid v7>` (directorio) | su `uuid` no tiene fila `pending`/`running` | 2 × `stale_after` (`failStale` ya ha cerrado la fila antes, en la misma pasada) |
| Temporal de `ZipArchive` | `exports` | `kronoqr-export-*.zip.*` | siempre | 2 × `stale_after` |
| Informe en diferido | `reports` | `<uuid v7>` (directorio completo) | su `uuid` no tiene fila `pending`/`running` ni `completed` vigente | `REPORTING_EXPORT_RETENTION_DAYS`, o 2 × `stale_after` si su fila no llegó a `completed` |
| Temporal de exportación legal HTTP | `tmp/legal-exports` | `registro-horario-*.csv` | siempre | `compliance.legal_export_temp_retention_hours` (6 h) |
| Paquete de diagnóstico | `diagnostics` | el patrón exacto que produce su escritor | siempre (no tiene fila) | `PRODUCT_DIAGNOSTICS_RETENTION_DAYS` (7 d) |

La edad mínima nunca es una ventana corta: no se compite jamás con un trabajo en curso. **Así es como
se sostiene la garantía de este ADR: ningún fichero con datos personales del volumen sobrevive a su
plazo, tenga fila o no.** La única excepción es la exportación legal por consola (apartado f), que es
custodia humana declarada.

**c) Cómo se mide la edad (C9).** La edad de un fichero es `max(mtime, ctime)`; si `mtime` está en
el futuro, se usa `ctime`. La de un directorio es la de su entrada más reciente, él incluido.

**d) Fila sin fichero (C5).** Una fila `completed` cuyo fichero no existe se marca `purged`, con
`purged_at` y sin borrar la fila (regla dura 5). Se distinguen dos casos:

- **Si `expires_at` ya pasó**, es caducidad normal.
- **Si el fichero desaparece antes de `expires_at`, es un evento de seguridad.** Puede ser un borrado
  manual o una exfiltración con `mv`, y RL-15 pide poder acotar el alcance. Deja asiento en
  `audit_log` (`data_export.file_missing` o `report_export.file_missing`, con el `uuid` de la
  exportación y **sin ruta**) e incrementa una métrica propia con alerta.
- Tras una restauración es lo esperado, y el informe de `restore.sh` lo anuncia.

Esto **precisa ADR-041 §6**: `purged` significa «el fichero ya no existe», por caducidad o por
desaparición, y el asiento dice cuál de las dos.

**e) Huérfano borrado.** Va al log técnico y a una métrica, sin asiento: la fila, si existió, ya tiene
los asientos de su generación y su descarga. Ni el log ni la métrica llevan `uuid`, nombre ni ruta
absoluta (regla dura 21).

**f) Exportación legal por consola.** **No se borra sola**: es una copia entregada a la Inspección
bajo custodia de quien la generó (runbook `requerimiento-inspeccion.md` §7). Pero ya no desaparece de
rebote, así que:

- `product:doctor` avisa cuando hay ficheros con más de 30 días en `storage/app/legal-exports`.
- Una métrica con alerta dice cuántos hay.
- El comando termina diciendo «bórralo en cuanto lo hayas entregado».

**g) Paquete de diagnóstico (C4).** Se barre **por antigüedad, a los 7 días, en la pasada horaria**,
además de al generar el siguiente. Esto enmienda la decisión del comentario de `config/product.php`
(«una tarea que borrase ficheros del cliente por su cuenta sería una sorpresa»). Con el volumen
persistente, un paquete sin anonimizar viviría para siempre si nadie generara otro (art. 5.1.e RGPD,
RL-19).

**h) Métricas (C10).** Cada métrica lleva **una sola etiqueta, `class`, con un catálogo cerrado**
(`data_export`, `data_export_work`, `report_export`, `legal_export_tmp`, `legal_export_console`,
`diagnostics`). Nunca `uuid` ni ruta. `backend-laravel` fija los nombres en el catálogo de métricas.

Los informes de retención **no se purgan nunca**: ni el producto ni la poda de copias.

### Actualización desde la 2.1.0

- El volumen se crea solo en el primer `up` de la 2.2.0, con la copia de la imagen ya en `0700`.
- **Se rescatan los informes de retención, y solo ellos (C8).** `update.sh`, entre la parada de
  `horizon` y `scheduler` (paso 2) y la recreación de los contenedores, copia con `docker compose cp`
  lo que haya en `storage/app/retention-reports` (o en la ruta de `COMPLIANCE_RETENTION_REPORT_PATH`
  del `.env`) de `app`, `horizon` y `scheduler` de la versión actual a
  `${BACKUP_PATH}/reports/retention/`, con estas restricciones:
  - **solo ficheros regulares que casen con `retencion-(propuesta|purga)-*.txt`**, sin recursión ni
    enlaces;
  - **sin sobrescribir**;
  - **con modo `0640`**.

  Avisa si el `.env` fija `COMPLIANCE_RETENTION_REPORT_PATH` dentro de `storage/app`. El paso no
  depende de la versión: en una instalación que ya escribe en `BACKUP_PATH` no encuentra nada. Si
  falla, avisa y continúa: no aborta una actualización por un informe.
- **No se rescatan, y se documenta**: los ZIP de exportación íntegra y los informes en diferido (datos
  personales caducos; sus filas pasan a `purged` en la primera pasada), los paquetes de diagnóstico y
  el estado de la telemetría (el identificador cambia una vez más).
- **Lo que ya se perdió no se recupera**: los informes de las purgas lanzadas con `run --rm`, y todo lo
  que borraron actualizaciones anteriores. El asiento `retention.purge_executed` de cada purga sigue
  en `audit_log`, y la guía explica cómo citarlo.
- **Vuelta atrás a la 2.1.0**: su `compose` no monta el volumen, que queda intacto y sin usar hasta la
  siguiente actualización. Los informes rescatados se quedan en `BACKUP_PATH`.

### Operación

- **`install.sh`** crea `${BACKUP_PATH}/reports/retention` con `1000:1000` y `0750`, como ya hace con
  `BACKUP_PATH`. El volumen lo crea Compose. Si la instalación se deshace, el `down -v` de la vuelta
  atrás lo retira con el resto, y la comprobación de «instalación previa» (volúmenes `kronoqr_*`) lo
  cuenta como uno más.
- **`update.sh`** crea el mismo directorio y añade el rescate.
- **`doctor.sh`** comprueba:
  - que el volumen existe;
  - que `app`, `horizon` y `scheduler` lo tienen montado en la misma ruta;
  - que un fichero escrito desde `horizon` se lee desde `app`;
  - que la raíz del volumen es `app:app` y `0700`;
  - y además informa del tamaño del volumen.
- **`product:doctor`** añade sondas para:
  - comprobar que `storage/app` es un punto de montaje (dispositivo distinto del de `/var/www/html`) y
    escribible;
  - comprobar que `retention.report_path` es escribible;
  - fallar si las raíces de clase coinciden o se solapan (C3);
  - avisar de exportaciones legales de consola con más de 30 días.

## Decisiones de la revisión

`seguridad-cumplimiento` y el hilo principal han cerrado las preguntas que dejó abiertas la propuesta:

1. **La exportación íntegra no entra en la copia ni se repone al restaurar.** Avalado: todo se
   regenera desde el volcado cifrado.
2. **Permisos y plazo de 7 días bastan** a nivel de aplicación, con C6 y C7. Cifrar el ZIP con una
   clave del mismo servidor no protege frente a quien ya lee `postgres-data`, y rompe el «abrir sin
   depender de nada» de RL-20. El plazo se puede bajar a 1 día.
3. **El paquete de diagnóstico se barre por antigüedad** (apartado g).
4. **La exportación legal por consola no se borra sola**: aviso en `product:doctor` y métrica con
   alerta (apartado f).
5. **Un huérfano deja log técnico y métrica; un fichero con fila que desaparece antes de tiempo deja
   asiento** (apartados d y e).
6. **El cambio del `installation_id`** al actualizar desde la 2.1.0 y al restaurar en otro servidor se
   acepta y se documenta.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Ruta del servidor configurable (`FILES_PATH`), como `BACKUP_PATH`** | Obliga a `install.sh` a crear y `chown` un directorio más, con su fallo de permisos sobre recursos de red; deja los ZIP legibles por el uid 1000 del anfitrión, y la copia de ficheros del cliente se los llevaría durante meses. Añade una variable que no aporta nada a ningún cliente |
| **Todo dentro de `BACKUP_PATH`** (exportaciones incluidas) | Ya descartado con motivo en `config/product.php`: la retención de copias conservaría durante meses un fichero que caduca a los 7 días, y `BACKUP_PATH` puede ser un recurso de red compartido |
| **Montar `storage/` entero** | Arrastraría cachés, vistas compiladas y logs entre versiones. Una vista compilada de la 2.1.0 sobreviviendo a la 2.2.0 es un fallo esperando a ocurrir, y lo desechable debe poder desecharse |
| **Un volumen por clase** (`exports`, `reports`, `diagnostics`…) | Cinco montajes en el ancla y cinco cosas que comprobar, para separar ficheros con el mismo dueño, los mismos permisos y la misma vida. La separación que importa —que una purga no salga de su clase— la da el confinamiento por raíz (C3), no el montaje |
| **Generar la exportación íntegra en `app`, de forma síncrona o con la cola `sync`** | Arregla la descarga y nada más: los informes en diferido siguen igual, las purgas siguen sin ver nada, y una exportación de cuatro años no cabe en una petición HTTP |
| **Guardar los ficheros en PostgreSQL (`bytea`) o en Redis** | Infla las copias con datos que ya están en ellas y que caducan a los 7 días, y mete cientos de megas en la memoria de Redis. Un almacén de objetos (S3, MinIO) sería otro servicio que instalar y vigilar en cada cliente para un problema de un solo servidor |
| **Incluir el volumen en la copia y reponerlo al restaurar** | Alarga a 30 días la vida de una copia completa de los datos personales que se quiso que viviera 7, para restaurar una vista de datos que el volcado ya contiene. Y reponer un ZIP generado antes de la copia que se restaura no garantiza que cuadre con la base restaurada |
| **Cifrar el ZIP en reposo con una clave del servidor** | No protege frente a quien ya puede leer `postgres-data` y rompe el «abrir sin depender de nada» de RL-20. La medida proporcionada es el cifrado del disco, a cargo del cliente (C7) |
| **Tratar el informe de retención como la prueba de la purga** | El runtime puede reescribir `BACKUP_PATH` (A3-R2). La prueba tiene que estar donde nadie puede reescribirla sin romper la cadena: `audit_log` |
| **Rescatar al actualizar todo lo que hay en las capas de la 2.1.0** | Movería al volumen nuevo ZIP con datos personales que ya han caducado, y daría vida a ficheros que la reconciliación tendría que borrar después |

## Consecuencias

- **Enmienda el criterio de terminado del Bloque 16** del plan de correcciones. «El simulacro de
  restauración la repone» se sustituye por: «tras restaurar, las exportaciones cuyo fichero no existe
  aparecen como `purged` con su asiento `file_missing` anunciado por `restore.sh`, y se puede pedir y
  descargar una nueva; los informes de retención siguen en `BACKUP_PATH/reports/retention`».
  R5-DV-03 se resuelve así, y no metiendo el volumen en la copia.
- **El doc 01 gana texto antes de implementar**:
  - en §5.5 (`data_exports`, `report_exports`), la conciliación en los dos sentidos y el significado
    de `purged`;
  - en el catálogo de acciones de auditoría, `data_export.file_missing` y `report_export.file_missing`.
- **Las guías del cliente cambian de verdad (C7, R6-PL-05)**. Tienen que decir:
  - dónde se lee la propuesta semanal (en el servidor, en `BACKUP_PATH/reports/retention`, sin `exec`);
  - que el informe de una purga con `run --rm` ya queda, y cómo se contrasta con el asiento de
    `audit_log`;
  - que la exportación del panel se descarga, que no entra en la copia y qué hacer tras una
    restauración;
  - que los ficheros del volumen están **en claro, igual que `postgres-data`**, y que se recomienda
    cifrar el disco del servidor (raíz de Docker y `BACKUP_PATH`, en línea con R5-DV-02);
  - que **pertenecer al grupo `docker` equivale a acceso a todos los datos**;
  - que `docker compose cp` no deja asiento de descarga (matiza `obligaciones-legales.md` §7 quater);
  - y qué se pierde al actualizar desde la 2.1.0.
- **El doc 07 cambia al cerrar el bloque**:
  - Gestión operativa, con la evidencia de docker-in-docker (el nivel solo sube con ella);
  - las filas de diagnóstico, exportación íntegra y telemetría;
  - la fila A3-R2, con el nuevo escritor de `reports/retention`;
  - **dos riesgos aceptados nuevos**: «ficheros del volumen en claro; el cifrado de disco corre a
    cargo del cliente» e «informes de retención no autenticados; la constancia es `audit_log`».
- **`docker compose down -v` borra el volumen**, como borra la base de datos. La guía de instalación ya
  advierte de esa orden, y la vuelta atrás de `install.sh`, que la usa, retira también este volumen.
- **El volumen ocupa disco en la raíz de datos de Docker**, que es el disco que `install.sh` ya
  comprueba. Una exportación de cuatro años puede pesar cientos de megas; `doctor.sh` informa del
  tamaño.
- **Toda escritura nueva en disco tiene que clasificarse** en una de las tres clases de este ADR, con
  su raíz y su patrón. Lo vigila la prueba de inventario, no la memoria de quien revise.

## Verificación

- **Arquitectura, sobre el `compose` ya resuelto** (`GeneratedFilesComposeTest`, con
  `ComposeEnvironment::service()`, que resuelve anclas y `<<`; un `grep` por bloque de servicio no ve
  el ancla):
  - `app`, `horizon` y `scheduler` montan `app-storage` en `/var/www/html/storage/app` sin `:ro`;
  - `reverb`, `nginx`, `migrate` y `restore` no lo montan;
  - `app-storage` está declarado en los volúmenes de primer nivel;
  - ningún servicio monta `storage/` entero ni `storage/framework`;
  - las variables `*_PATH` de las clases están en `runtime-env`.
- **Arquitectura, inventario cerrado** (`GeneratedFilesInventoryTest`). Cada `storage_path(…)`,
  `sys_get_temp_dir()` y `tempnam()` de `backend/app` y `backend/config`, los discos de
  `config/filesystems.php` y el valor por defecto de `config/branding.php` figuran en una lista con su
  clase. Hacen fallar la prueba:
  - una ruta nueva sin clasificar;
  - una ruta efímera que cruza contenedores fuera de `storage/app`;
  - dos raíces de clase que coinciden o se solapan.

  Además, `compliance.retention.report_path` debe resolver bajo `BACKUP_PATH`.
- **Dockerfile**: la raíz `storage/app` de la imagen es `app:app 0700`, comprobado en la imagen
  construida en la CI.
- **Feature, conciliación y confinamiento**:
  - una fila `completed` sin fichero, vencida, pasa a `purged` sin asiento;
  - la misma sin vencer pasa a `purged` con asiento `*.file_missing` sin ruta y sube la métrica;
  - un `.work-<uuid>` viejo sin fila viva desaparece;
  - un temporal de `ZipArchive` viejo desaparece;
  - un `<uuid>/` de reporting sin fila desaparece entero;
  - los restos recientes y los de filas en curso no se tocan;
  - un enlace simbólico o un subdirectorio dentro de un resto aborta ese resto;
  - una fila que apunta fuera de su raíz queda `purged` y el fichero de fuera sigue ahí;
  - con `REPORTING_EXPORT_PATH` igual a `storage/app`, la purga no borra nada de las otras clases;
  - la edad usa `max(mtime, ctime)` y `ctime` con `mtime` en el futuro;
  - el paquete de diagnóstico de más de 7 días se barre en la pasada horaria;
  - el temporal de la exportación legal se escribe bajo `storage/app/tmp/legal-exports` y su purga lo
    encuentra;
  - las métricas solo llevan `class` del catálogo cerrado.
- **`product:doctor`**: falla con raíces solapadas o iguales a `storage/app` o `BACKUP_PATH`, y avisa
  de exportaciones legales de consola de más de 30 días.
- **Scripts**:
  - `install.sh` crea `reports/retention` con su dueño y modo;
  - `update.sh` rescata solo `retencion-(propuesta|purga)-*.txt` regulares, sin sobrescribir, en
    `0640`, ignora enlaces y otros nombres, y sigue si no hay ninguno;
  - `doctor.sh` falla si a uno de los tres servicios le falta el montaje o si la raíz no es
    `app:app 0700`.
- **Extremo a extremo en docker-in-docker** (`qa-testing`, etapa ⑧):
  1. Instalación limpia, exportación íntegra pedida por la API como la pide el panel (cuenta `admin`
     con 2FA), descarga `200` y huella igual a la de la fila.
  2. `compliance:apply-retention --dry-run` desde `scheduler`; el informe se lee en el anfitrión en
     `BACKUP_PATH/reports/retention`.
  3. `update.sh` a la versión siguiente: la exportación sigue descargándose con un enlace nuevo y el
     informe sigue ahí.
  4. Actualización desde la 2.1.0 con un informe sembrado en `scheduler`: aparece en
     `BACKUP_PATH/reports/retention` con modo `0640`.
  5. `restore.sh` de una copia anterior a la exportación. El informe de restauración anuncia el
     `file_missing`; tras la pasada, la fila restaurada sin fichero queda `purged` con su asiento, el
     ZIP sin fila desaparece al vencer su plazo, y una exportación nueva se pide y se descarga.
  6. Un `docker compose kill horizon` durante una exportación íntegra deja un `.work-<uuid>`, y la
     pasada posterior a 2 × `stale_after` lo elimina.
