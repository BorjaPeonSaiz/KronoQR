# ADR-049 — Las copias (volcado, copia física y WAL) se guardan en un formato cifrado y autenticado, KQE1, con una subclave propia para el WAL

| Campo | Valor |
|---|---|
| **Estado** | Aceptada. Revisión de `seguridad-cumplimiento` del diseño: aprobada con condiciones C1–C20, incorporadas; implementación revisada en el cierre del bloque 20 (nota del 09-10-2026) |
| **Fecha** | 3 de octubre de 2026 |
| **Decide** | `devops-observabilidad` (Bloque 20 de la 2.2.0, hallazgos R5-DV-02, R5-DV-04, R5-DV-01) · `seguridad-cumplimiento` (revisión y condiciones) |
| **Afecta a** | `infra/scripts/lib/kqe.sh` (nuevo), `backup-common.sh`, `backup.sh`, `restore.sh`, `restore-drill.sh`, `update.sh`, `install.sh`, `doctor.sh`, `wal-metrics.sh` (nuevo) · `infra/docker/postgres/` (`archive-wal.sh`, `kronoqr-restore-wal`, `kronoqr-wal-migrate`) · `infra/compose.prod.yaml` y `compose.dev.yaml` · `infra/observability/prometheus/rules/backup.yml` · `docs/runbooks/restaurar-backup.md`, `rotacion-secretos.md` · `docs/cliente/{instalacion,operacion,obligaciones-legales}.md` (+EN) |
| **Requisitos** | RL-12, RL-04, RNF-D-02, RNF-D-05, RS-07, RS-08, reglas duras 6, 16 y 21 |
| **Precisa** | [ADR-042](ADR-042-el-runtime-no-tiene-credencial-que-pueda-alterar-el-registro.md) (A3-R1 **no cambia**: el `scheduler` conserva la clave y escribe las copias; esto cierra a quien no la tiene) y [ADR-045](ADR-045-los-ficheros-generados-viven-en-un-volumen-compartido.md) (reparto de `BACKUP_PATH`, cierre de A3-R2) |
| **Hallazgos** | R5-DV-01, R5-DV-02, R5-DV-04, A3-R2 y su familia |

## Contexto

La verificación de la 2.2.0 encontró en las copias tres problemas con una misma raíz:

1. **El WAL archivado estaba en claro** (R5-DV-02). `archive-wal.sh` solo comprimía: un segmento contiene fichajes,
   empleados y `audit_log` legibles. Choca con RL-12 y con la promesa «copia diaria, cifrada, verificada» (doc 05:208), y
   las copias a un recurso de red (`instalacion.md`) lo agravan. Un volcado y una copia física sí iban cifrados.
2. **El cifrado no autenticaba** (R5-DV-04). `openssl enc` AES-256-CBC no detecta que un bit haya cambiado
   (`exit=0`), y la defensa era un `.sha256` sin clave, junto a la copia y opcional: quien podía escribir en
   `BACKUP_PATH` modificaba el `.enc` y borraba el `.sha256`.
3. **El RPO real no se medía** (R5-DV-01): la alerta se calculaba al terminar la copia nocturna, no con una serie
   continua; un archivado parado a las 10:00 se veía a las 03:15 del día siguiente, y el segmento en curso no se medía.

Restricciones comprobadas, no supuestas:

- `openssl enc` **no admite AEAD**: `openssl enc -aes-256-gcm` responde `enc: AEAD ciphers not supported` (OpenSSL 3.5).
- Todos los caminos del CLI para un MAC con clave la ponen en la **línea de órdenes** (`openssl dgst` con clave en
  `argv`, `openssl mac`, `openssl kdf` HKDF), visible en `ps` del anfitrión: la regla de `backup-common.sh`.
  `openssl dgst -sha3-256` sí existe (1.1.1+) y lee de la entrada estándar.
- La imagen de PostgreSQL **no trae el CLI de `openssl`** (hay `libcrypto3`): se añade el paquete `openssl`, que va con
  la misma versión que `libcrypto3`.
- `BACKUP_ENCRYPTION_KEY` solo está en `scheduler` y `restore`; `postgres` no la tiene.

## Decisión

### 1. Un formato único, KQE1, para volcado, copia física y WAL

```
línea 1   KQE1 kind=<dump|base|wal> kid=<8 hex> iter=<N> created=<UTC ISO> name=<nombre> [src=legacy]
cuerpo    salida de `openssl enc` AES-256-CBC (Salted__ + sal de 8 B + datos), PBKDF2-SHA512, idéntica a la de la 2.1.0
trailer   <64 hex minúsculas> + salto de línea
```

- **Cifrar y después autenticar** (encrypt-then-MAC). `MAC = SHA3-256("KQE1-MAC" NUL K_mac_hex ‖ cabecera ‖ cuerpo)`.
  SHA3 no sufre extensión de longitud: con la clave, de longitud fija, al principio, `SHA3(K‖M)` es un MAC sólido
  (la esponja de Keccak; KMAC es su forma normalizada, y no está en el `openssl` de los anfitriones Ubuntu 20.04/RHEL 8).
  La clave del MAC entra por la **entrada estándar** de `openssl dgst`, nunca por `argv`.
- **Se verifica antes de descifrar** (sin oráculo de relleno de CBC) **y sobre los mismos bytes que se descifran**:
  `kqe_open` copia el fichero **una sola vez** a un directorio privado `0700` y la verificación y el descifrado operan
  solo sobre esa copia. Quien administra el recurso de red no puede cambiar el cuerpo entre la comprobación y el uso.
  En el WAL: `%f.gz.enc` → copia a `%p.kqe` en `pg_wal` → verifica → descifra a `%p.tmp` → `mv` a `%p`.
- **La cabecera es parte de lo autenticado y se interpreta después del MAC** (C2): regex cerrada que rechaza claves
  desconocidas; `kind` igual al esperado; `name` igual al fichero (volcado y copia física) o a `%f` (WAL, con la regex
  de nombres de segmento, `.history`, `.backup` y `.partial`); `iter` de una lista permitida (600 000 para
  volcado y copia física, 10 000 para WAL); trailer exacto. **`created` y `name` también en volcado y copia física**
  (hallazgo MEDIO del dictamen): renombrar una copia antigua y reescribir `LATEST` ya no pasa, y las herramientas
  **enseñan la fecha autenticada** de la cabecera en la confirmación, el informe y el asiento.
- **El manifiesto de un volcado se autentica** con su propio MAC (`kronoqr-<UTC>.manifest.mac`, obligatorio para KQE1):
  `SHA3-256("KQE1-MANIFEST" NUL K_mac ‖ nombre ‖ LF ‖ manifiesto)`. Un manifiesto alterado no puede decidir qué conteos
  «cuadran».
- **`kid`** (8 hex) = `SHA3-256("KQE1-KID" NUL K_mac)[0:8]`: sale de la **clave del MAC ya derivada**, no de la
  maestra, para no ser un oráculo rápido que se salte los 600 000 pasos de PBKDF2. Distingue «clave distinta o cabecera
  alterada» (kid distinto) de «fichero alterado o dañado» (kid igual, MAC mal), y permite probar la clave anterior.
- **Nombres de fichero.** Volcado y copia física conservan `.dump.enc` y `.tar.gz.enc` (listado, `LATEST`, poda y alertas no
  cambian; el formato se distingue por la cabecera, `KQE1` frente a `Salted__`). El WAL es `<segmento>.gz.enc` (gzip y
  después cifra).

### 2. Jerarquía de claves y registro de etiquetas (versionado)

```
BACKUP_ENCRYPTION_KEY (maestra, la custodia el cliente)
 ├─ cifra volcado y copia física (openssl enc, sal por fichero, PBKDF2-SHA512 600000)
 ├─ K_mac(dump/base) = PBKDF2-SHA512(maestra, sal "kqe1-mac", 600000)[0:32]
 └─ BACKUP_WAL_KEY = PBKDF2-SHA512(maestra, sal "kqwal-v1", 600000)[0:32]     (subclave, solo la recibe `postgres`)
      ├─ cifra el WAL (openssl enc, PBKDF2-SHA512 10000: la clave ya es aleatoria de 256 bit)
      └─ K_mac(wal) = SHA3-256("KQE1-MAC-KEY" NUL WAL_KEY_hex)
```

**Registro v1 de etiquetas.** Cambiar una etiqueta es romper todas las copias hechas con ella: solo se cambia con otra
versión de formato (`KQE2`), nunca editando esta tabla. Los vectores de prueba (`BackupKeyDerivationTest`) fijan los valores:

| Uso | Etiqueta | Parámetros |
|---|---|---|
| Sal de la subclave del WAL | `kqwal-v1` (hex `6b7177616c2d7631`) | PBKDF2, SHA-512, 600 000 |
| Sal de `K_mac` de volcado y copia física | `kqe1-mac` (hex `6b7165312d6d6163`) | PBKDF2, SHA-512, 600 000 |
| Prefijo del MAC | `KQE1-MAC` NUL | SHA3-256 |
| `K_mac` del WAL | `KQE1-MAC-KEY` NUL | SHA3-256 sobre la subclave en hex |
| `kid` | `KQE1-KID` NUL | SHA3-256, 8 primeros hex |
| MAC del manifiesto | `KQE1-MANIFEST` NUL | SHA3-256 |

Vectores con la clave de relleno de desarrollo (`kronoqr_local_dev_only_backup_key`): `BACKUP_WAL_KEY =
4deec0e109388ff8c0ed3ca348abba952eef5a59c6e87906f052e8453eff69ec` y `K_mac(dump) =
41ce46041f316587adc9a6ff6dc0f7acb18a9f51b0924e21e41e91c10e72ecf4`, comprobados contra una derivación PBKDF2 independiente
(`openssl kdf`).

**Por qué una subclave.** `postgres` recibe la derivada y **no abre volcados ni copias físicas**: un PostgreSQL
comprometido (o quien lea `docker inspect`, que ya es grupo `docker` = root) abre WAL, no 30 días de volcados. La
derivación es unidireccional; el cliente sigue custodiando **una sola** clave y la derivada se recalcula
(`backup.sh derive-wal-key`). `install.sh` y `update.sh` la escriben en el `.env`, **antes de parar la versión anterior**
(`update.sh` aborta sin tocar nada si no puede), y `install.sh`, `update.sh` y `doctor.sh` **comprueban que deriva de la
maestra** y que coincide con el `kid` de la cabecera del `.gz.enc` más reciente. `archive-wal.sh` rechaza la clave de
desarrollo y **falla cerrado** sin clave válida: nunca archiva en claro.

**Lo que esto no da, dicho aquí.** Con la subclave, un `postgres` comprometido puede **fabricar o reescribir segmentos
archivados válidos**, incluidos los anteriores al compromiso. Solo lo arregla un almacenamiento inmutable externo (WORM) o
un servicio de copias fuera del runtime (2.3.0). Tras un incidente en ese contenedor, una recuperación a un punto en el tiempo
sobre el WAL archivado no es de fiar sin una copia externa.

### 3. `restore_command`: `exit 200` y nunca `exit 1` ante un hueco

`kronoqr-restore-wal %f %p` (imagen de PostgreSQL):

- **PostgreSQL trata `exit 1` como «fin del archivo» y promociona.** Un `exit 1` por un fallo cualquiera trunca la recuperación
  **en silencio** y pierde datos sin avisar (RL-04). Un estado de salida **mayor de 125** es fatal y **aborta** la recuperación.
- **`exit 1` solo si, tras una segunda comprobación, no existe ni `%f.gz.enc` ni `%f.gz` Y no existe ningún segmento posterior
  del mismo timeline** (los 8 primeros hex). Cualquier otra cosa (MAC o `kid` malo, hueco con segmento posterior, fallo de
  lectura, fichero que desaparece entre la comprobación y la lectura) → **`exit 200`**, con el nombre del segmento, el motivo y
  qué hacer, por `stderr` (al log del servidor y, desde `restore.sh` y `restore-drill --mode pitr`, al informe y a la métrica).
- **Orden**: `%f.gz.enc`; si no, `%f.gz`; si ha desaparecido entre medias (la migración lo acaba de cifrar), otra vez `%f.gz.enc`.
- Una recuperación completa deja `wal_integrity=authenticated` y `legacy_wal=N` en el asiento; una abortada, `wal_integrity=aborted_at:<segmento>`.
- La clave llega a ese proceso **solo por `-e BACKUP_WAL_KEY`** (sin valor en la línea de órdenes), nunca en el `restore_command`
  ni en `postgresql.auto.conf`.

### 4. Segmentos antiguos en claro y copias de la 2.1.0

- **Segmentos `.gz` heredados**: se **cifran en sitio** como uid 70 dentro del contenedor de PostgreSQL (nada de root del
  anfitrión por ruta en `wal/`), con cabecera `src=legacy` bajo el MAC y **conservando el `mtime`** (`touch -r`) para que la
  purga por antigüedad no los retenga 8 días más. Orden: cifrar a `.part`, verificar, `mv` a `.gz.enc` y **solo entonces** `rm`
  del `.gz`. Lo lanza `update.sh` y lo continúa `archive-wal.sh` de forma oportuna. La purga recorre `*.gz` **y** `*.gz.enc`.
- **Copias de la 2.1.0** (`Salted__`, sin MAC): se restauran solo con **`--accept-unauthenticated`**, que se pasa
  **por invocación**: la opción de línea de órdenes en `restore.sh` y `restore-drill.sh` (los scripts del anfitrión ignoran la
  variable `KRONOQR_ACCEPT_UNAUTHENTICATED` con un aviso, para que no llegue heredada del perfil de root o de una crontab);
  la variable solo la lee `kronoqr-extract-base`, dentro de la imagen de PostgreSQL, con `docker run -e`. Nunca en el `.env`
  ni en el compose; `doctor.sh` falla si está en el `.env` o en las tablas de cron. **El `.sha256` sigue siendo obligatorio** (y mientras la bandera exista no autentica nada: quien escribe en
  `BACKUP_PATH` lo recalcula). El asiento `system.restored_from_backup` lleva `integrity=legacy_accepted`.
- **La vuelta atrás de `update.sh`** pasa la bandera **solo** para la copia previa que acaba de crear, comparando con el
  SHA-256 que **él mismo calculó** y guardó en `/var/log/kronoqr` (`root:root 0600`), no con el `.sha256` de `BACKUP_PATH`.
- **Retirada de la bandera**: cuando la versión mínima desde la que `update.sh` actualiza sea **≥ 2.2.0** (no «hasta la 2.3.0»):
  entonces ninguna copia heredada puede ser la copia previa de una actualización.

### 5. Lo que se mide (R5-DV-01)

`wal-metrics.sh` cada minuto desde el `scheduler`, con el rol `fichaje_backup` **sin privilegios añadidos** (no se concede
`pg_monitor`): `pg_stat_archiver`, `pg_current_wal_insert_lsn()` y `SHOW archive_timeout` son públicos. La métrica central es
la **exposición real**, `kronoqr_wal_unarchived_age_seconds` (edad del dato más antiguo sin archivar, incluido el segmento en
curso), no la edad del último archivado, que crece legítimamente de madrugada. Reglas y umbrales en
`infra/observability/prometheus/rules/backup.yml`.

## Alternativas descartadas

| Alternativa | Motivo |
|---|---|
| La misma `BACKUP_ENCRYPTION_KEY` en `postgres` | Un PostgreSQL comprometido abriría los volcados y las copias físicas; la subclave cuesta una línea en el instalador |
| `openssl enc -aes-256-gcm` | No existe en el CLI (probado) |
| MAC con la clave en la línea de órdenes (`dgst`, `mac`, `kdf`) | Visible en `ps`; rompe la regla de `backup-common.sh` |
| `openssl cms -encrypt` (AuthEnvelopedData) | Carga el fichero en memoria (copias físicas de GB) y el soporte de GCM en CMS no está garantizado en los anfitriones |
| **`age`** con destinatario X25519 | Es la mejor opción criptográfica y la **evolución** cuando el servicio de copias salga del runtime (A3-R1, 2.3.0, ADR-042). Hoy cuesta un binario Go nuevo en dos imágenes (la de PostgreSQL acaba de retirar `gosu` por el ruido de CVE de la biblioteca estándar de Go), una identidad más que custodiar, no está en los anfitriones donde el IT restaura, y **quien tiene la clave pública puede fabricar un segmento válido**: no autentica al emisor |
| `gpg --symmetric` | MDC autentica, pero no es un MAC con clave y el `gpg-agent`/`GNUPGHOME` en un contenedor invocado cientos de veces al día es frágil |
| Fichero `.mac` aparte (para copias) | Se puede borrar; dobla los ficheros del archivo de WAL. Solo se usa para el manifiesto, donde no hay otra opción, y su ausencia **falla** |
| Cifrar el disco (LUKS, recurso cifrado) | Lo recomienda la guía, pero no lo hace ni lo verifica el producto; RL-12 pide cifrar **las copias** |
| Dejar el WAL en claro y purgarlo a los 8 días | Mantiene RL-12 roto 8 días más y las copias del recurso de red que el cliente ya hizo |
| Eliminar la bandera de copias heredadas | Impide restaurar una copia 2.1.0 el día de la actualización, justo cuando más se necesita |
| Servidor de claves o KMS | Dependencia externa en cada cliente, contra ADR-016 (una instalación, sin servicios compartidos) |

## Residuos que se aceptan (doc 07 §6)

1. `BACKUP_WAL_KEY` viaja en el entorno de `postgres` y la ve quien lee `docker inspect` (grupo `docker` = root). Con ella
   un `postgres` comprometido descifra **y fabrica o reescribe** segmentos válidos (§2).
2. Las copias de `BACKUP_PATH/wal` hechas **antes de la 2.2.0** en otros soportes contienen WAL en claro con datos
   personales: las notas de versión y `instalacion.md` ordenan destruirlas; si constituyen una brecha lo valora el DPO.
3. Las copias de la 2.1.0 (hasta `BACKUP_RETENTION_DAYS`) no están autenticadas; ver §4.
4. El MAC no impide **borrar** copias ni segmentos. Contra la **sustitución** por una copia anterior válida, la cabecera fija
   `name` y `created` y la herramienta enseña esa fecha; un hueco en el WAL aborta la recuperación, pero el borrado de los
   **últimos** segmentos no se distingue del final del archivo.
5. `metrics/` es escribible por `app` y `horizon`: un proceso comprometido puede falsear las métricas del RPO y de las
   copias. `wal-metrics.sh` valida su fichero de estado con expresiones regulares de enteros, sin `source` ni `eval`.
6. `SHA3(K‖M)` es un MAC sólido pero no el de catálogo (HMAC/KMAC): se eligió para no pasar la clave por `argv`.
7. A3-R1 no cambia (ADR-042): el `scheduler` tiene la clave y escribe `daily/` y `base/`; con ejecución de código allí se
   puede fabricar una copia KQE1 válida. El MAC protege frente a quien **no** tiene la clave (`app`, `horizon`, el
   administrador del recurso de red).

## Consecuencias

- **Actualización desde la 2.1.0:** `update.sh` añade `BACKUP_WAL_KEY` al `.env` (derivada), cifra los segmentos antiguos en
  minutos y no requiere parada adicional (la actualización ya recrea `postgres`; el archivado queda en cola ese rato). Lo
  escrito por la 2.2.0 (`.gz.enc`, volcados KQE1) **no lo lee el `restore.sh` de la 2.1.0**: tras una vuelta atrás se restaura
  con el paquete 2.2.0. La guía y el runbook lo dicen.
- **Dev:** `compose.dev.yaml` fija `archive_timeout=900` como producción y lleva la clave derivada de la de relleno;
  `archive-wal.sh` solo la acepta con `KRONOQR_WAL_ALLOW_DEV_KEY=1`.
- **Rotación de la maestra** (`rotacion-secretos.md` §5): se recalcula `BACKUP_WAL_KEY`, se reinicia `postgres` y se conserva la
  anterior (`BACKUP_ENCRYPTION_KEY_PREVIOUS`, solo para restaurar) mientras haya copias y WAL cifrados con ella.
- **Conservación** (`obligaciones-legales.md` §4): fila del archivo de WAL (8 días, todos los datos personales, cifrado) y del
  detalle técnico de `update.sh` (30 días).

## Verificación

- `KqeFormatTest` y `BackupKeyDerivationTest` (vectores del registro); prueba de arquitectura de que `kqe.sh`, `archive-wal.sh` y
  `kronoqr-restore-wal` no contienen `set -x`, `-K`, `-hmac` ni `hexkey:`; el compose de producción resuelto no da valor a
  `BACKUP_WAL_KEY` ni a `KRONOQR_ACCEPT_UNAUTHENTICATED`.
- `backup-drill.yml` (PostgreSQL 17 real): un segmento archivado no empieza por `1f8b` y da 0 coincidencias de
  `scan_id|employee|kiosk`; sin clave el archivado falla cerrado; un bit cambiado en cabecera, cuerpo o trailer, un `.sha256`
  borrado, un fichero renombrado, un segmento intermedio borrado o un cambio del origen **después** de verificar hacen fallar
  la restauración sin tocar la base; `--mode pitr`: recuperación abortada con `exit 200` (proceso FATAL, `pg_is_in_recovery()`
  nunca llega a `f`) y recuperación completa con `.gz.enc` y un `.gz` heredado.
- Etapas ⑧ y ⑧b: matriz de montajes, ningún `.gz` en claro tras actualizar (≤ 120 s), vuelta atrás con la copia 2.1.0,
  `BACKUP_WAL_KEY` ausente de la salida de los instaladores y de los logs.

## Nota 09-10-2026 (2.2.0 publicada): estado

**Aceptada, implementada en la 2.2.0 y con la implementación revisada.** La decisión no cambia; la cabecera decía «implementación pendiente de revisión» porque se escribió antes del cierre del bloque 20.

- **Implementación:** bloque 20 de la 2.2.0, PR #112, integrada en `main` el 06-10-2026 (`security(copias): formato autenticado KQE1…`, 03-10-2026, y sus correcciones hasta el 06-10-2026). Entrada del `CHANGELOG.md` de la 2.2.0: «formato autenticado KQE1 para volcados, copias físicas y WAL, subclave del WAL, RPO continuo y BACKUP_PATH en solo lectura para el runtime».
- **Revisión de la implementación:** el bloque 20 cerró con las dos revisiones del plan, `revisor-codigo` y `seguridad-cumplimiento`, y sus hallazgos se corrigieron en la misma rama antes de integrarla (la pasada de seguridad terminó sin ningún crítico ni alto abierto). Lo que la revisión de seguridad dejó como residuo está en el [doc 07](../07-seguridad-madurez-y-amenazas.md) §6, fila «Residuos de ADR-049», y aquí en «Residuos que se aceptan».
- **Verificación final:** la de seguridad da R5-DV-02 por corregido (KQE1 en `archive-wal.sh`) y la integridad autenticada como parte del cierre de AUD-1 ([2.2.0-verificacion-final-tanda-4.md](../verificacion/2.2.0-verificacion-final-tanda-4.md)); la funcional comprueba que `restore.sh` rechaza con salida `6` una copia con un byte cambiado, también recalculando el `.sha256` ([2.2.0-verificacion-final-tanda-3.md](../verificacion/2.2.0-verificacion-final-tanda-3.md)).
- **V3-PL-04** (la orden impresa para reparar la clave del WAL no funcionaba), hallado en la verificación final, quedó corregido antes de publicar la 2.2.0 (PR #130): `doctor.sh` y `update.sh` imprimen `sudo bash ./backup.sh derive-wal-key --write-env .env` desde el directorio vigente.
- La cabecera se actualiza por esta nota.
