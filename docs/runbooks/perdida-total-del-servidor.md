# Runbook — pérdida total del servidor (incendio, robo, disco muerto)

**No responde a una alerta: responde a una situación.** Si el servidor no
arranca, no se puede reparar y no hay otro disco con sus datos, las alertas ya
no pueden avisar de nada (las evalúa el propio servidor). Este runbook lo
ejecuta el IT del cliente, con el sistema delante, **en un servidor nuevo**.
Si el servidor sobrevive, no es este: es
[`restaurar-backup.md`](restaurar-backup.md) §6.

**Impacto en el fichaje, que es lo primero que hay que saber.** Mientras no hay
servidor, **nadie se queda sin fichar**: los quioscos confirman en local y
encolan cada fichaje con su `occurred_at` (regla dura 19). Lo que está en juego
es (1) que esa cola sincronice cuando haya servidor, y (2) el registro horario
**anterior** a la pérdida, que hay que conservar cuatro años: solo existe en las
copias.

---

## 0. Lo que tiene que existir ANTES de que ocurra

Si algo de esta lista falta cuando llega el día, el procedimiento de abajo no
puede completarse. Revísala en el simulacro trimestral
([`restaurar-backup.md`](restaurar-backup.md) §7), no el día del incendio.

| Qué | Dónde debe estar | Si falta |
| --- | --- | --- |
| **Las copias** (`BACKUP_PATH`: `daily/`, `base/`, `wal/`) | **Fuera del servidor y fuera de su edificio** (NAS en otra sala o sede, cabina, nube del cliente **dentro de la UE**, RL-14). No basta con otro disco del mismo servidor | **No hay registro que recuperar.** Las copias del propio disco se pierden con él |
| **`BACKUP_ENCRYPTION_KEY`** | Fuera del servidor: gestor de contraseñas de la empresa o sobre cerrado en caja fuerte ([`../cliente/operacion.md`](../cliente/operacion.md) §9). **Nunca** solo en el servidor ni junto a las copias | Las copias son bytes sin valor. El fabricante no la tiene ni puede recuperarla |
| **Los secretos que se reponen**: `APP_KEY`, `QR_SIGNING_KEY_CURRENT` y `QR_SIGNING_KEY_PREVIOUS` con sus `_ID`, e `IDENTITY_PIN_SEALING_SECRET_KEY`. No el `.env` entero: lleva las contraseñas de la base de datos, incluida la del superusuario, que no se reponen (§3.3) | Con la clave anterior, en el mismo sitio y con el mismo cuidado: quien tenga la clave de firma de los QR puede fabricar tarjetas válidas. **Se renueva después de cada rotación** ([`rotacion-secretos.md`](rotacion-secretos.md), [`rotacion-clave-qr.md`](rotacion-clave-qr.md)) | Ver §3: sin `QR_SIGNING_KEY_CURRENT` **hay que reimprimir todas las tarjetas** ([`../cliente/instalacion.md`](../cliente/instalacion.md) §3) |
| **El paquete de entrega de la versión instalada** (el `.tar.gz` y `SHA256SUMS`) | Con el IT o en la release del fabricante | Pídelo al fabricante: sin él no hay `install.sh` de esa versión |
| **El certificado TLS y su clave** del nombre del servidor | Fuera del servidor | Se emite uno nuevo **para el mismo nombre** (§4) |
| **La clave de licencia** | Con quien la recibió | Se pide otra al fabricante; **no es un secreto y no impide fichar** (ADR-019) |
| **Dos datos anotados**: la versión instalada y la URL de los quioscos | Junto al resto | Versión: la dice el nombre de la copia y el paquete; URL: ver §4 |

## 1. Orden de magnitud: lo que hay que prometer

Estimación **no medida en un servidor nuevo** (el simulacro solo mide la
restauración, no el alta de la máquina); se afina cuando se ensaye.

| Paso | Tiempo típico | Depende de |
| --- | --- | --- |
| Conseguir y preparar un servidor (hardware, Docker, red, DNS) | **lo decide el cliente** | Fuera del producto |
| Instalar el mismo paquete (§3) | 3–15 min | Descarga de imágenes ([`../cliente/instalacion.md`](../cliente/instalacion.md) §1.4) |
| Poner las claves y recuperar las copias (§3.3–§3.4) | 15–30 min | Copiar `BACKUP_PATH` por la red |
| Restaurar (§3.5) | 5–20 min + comprobaciones | [`restaurar-backup.md`](restaurar-backup.md) §6.1 |
| Quioscos y comprobaciones (§4–§5) | 30–60 min | Número de tablets |

**RTO (4 h): se cuenta desde que hay servidor**, no desde el incendio.
**Dato que se pierde: todo lo escrito desde la última copia.** La ruta de este
runbook (copia diaria con `restore.sh`) devuelve el estado **de la madrugada de
esa copia**: hasta 24 h de fichajes ya sincronizados. Los que seguían en la cola
de un quiosco **no se pierden** (§4). Recuperar hasta los últimos 15 minutos
con el WAL es la ruta B (§6), más lenta y con un paso sin ensayar.

## 2. Antes de nada

1. **Confirma que es total.** ¿Arranca el disco en otra máquina? ¿Hay otro disco
   con el volumen `postgres-data`? Si hay datos vivos, recupéralos antes de
   empezar de cero: es más rápido y pierdes menos.
2. **Anota la hora y abre el parte de incidente.** Restaurar descarta un
   intervalo del registro y debe constar (regla dura 6): `restore.sh` escribe un
   informe y un asiento `system.restored_from_backup` en `audit_log`.
3. **Avisa a RRHH:** el panel y el portal no estarán, y los fichajes de las horas
   previas a la pérdida pueden faltar hasta restaurar. Los quioscos siguen
   admitiendo fichajes.
4. **No apagues ni limpies los quioscos.** Su cola local es la única copia de lo
   fichado desde que cayó el servidor (RF-AT-10).

## 3. Servidor nuevo: instalar y restaurar

### 3.1 Prepara la máquina

Los mismos requisitos que cualquier instalación
([`../cliente/instalacion.md`](../cliente/instalacion.md) §1): Linux con Docker,
espacio para las imágenes y para `BACKUP_PATH`. **El mismo nombre y la misma
URL** que tenía el servidor perdido (§4).

Monta `BACKUP_PATH` antes de instalar y **copia ahí las copias** que rescataste
(`daily/`, `base/`, `wal/`, `reports/`), conservando propietario y permisos del
árbol que describe [`../cliente/instalacion.md`](../cliente/instalacion.md) §6,
«`BACKUP_PATH`». Comprueba que hay copias:

```bash
ls -lt "${BACKUP_PATH}/daily" | head -5
```

### 3.2 Instala el MISMO paquete

La **misma versión** que corría, no la más reciente: la copia lleva el esquema de
esa versión, y `restore.sh` no migra. Si hay una versión más nueva, se
actualiza después con `update.sh` ([`actualizacion-cliente.md`](actualizacion-cliente.md)).

```bash
sha256sum -c SHA256SUMS --ignore-missing      # el paquete es el que publicó el fabricante
tar -xzf kronoqr-<version>.tar.gz && cd kronoqr-<version>
sudo ./install.sh                              # sigue ../docs/cliente/instalacion.md §1.2
```

El instalador **genera secretos nuevos**. Es lo correcto para una instalación
nueva y **lo incorrecto aquí**: la copia está cifrada con la clave vieja y las
tarjetas impresas con la vieja clave del QR. El paso siguiente los sustituye.

### 3.3 Repón los secretos de la instalación perdida

Con los servicios recién instalados, **para los que escriben**
(`docker compose stop app horizon scheduler reverb`) y pon en el `.env` (0600,
`sudo`) los valores guardados. **Sin imprimirlos ni pegarlos en una orden
que quede en el historial** (edita el fichero):

| Variable | Si la tienes | Si no la tienes |
| --- | --- | --- |
| `BACKUP_ENCRYPTION_KEY` | Imprescindible. Sin ella no se abre ninguna copia (ve a §7) | — |
| `APP_KEY` | Repónla | Las sesiones y los datos cifrados con ella no se leen. En concreto, el secreto del segundo factor de cada cuenta de gestión va cifrado con ella: **ninguna cuenta pasa el 2FA** hasta que se le reinicia con `docker compose exec app php artisan identity:2fa-reset`, cuenta por cuenta, y vuelve a darse de alta |
| `QR_SIGNING_KEY_CURRENT` y `QR_SIGNING_KEY_CURRENT_ID` | Repónlas: las tarjetas impresas siguen valiendo | **Reimprime todas las tarjetas** ([`rotacion-clave-qr.md`](rotacion-clave-qr.md), [`tarjeta-perdida-o-rota.md`](tarjeta-perdida-o-rota.md)) |
| `QR_SIGNING_KEY_PREVIOUS` y `QR_SIGNING_KEY_PREVIOUS_ID` (solo si la pérdida cae en mitad de una rotación de la clave QR) | Repónlas: las tarjetas aún firmadas con la clave anterior siguen valiendo hasta que acabe la rotación | Las tarjetas que no se habían reimpreso con la clave nueva dejan de valer: reimprímelas |
| `IDENTITY_PIN_SEALING_SECRET_KEY` | Repónla | Los fichajes por PIN encolados sin red antes de la pérdida no se podrían abrir |

Las contraseñas de los roles de base de datos (`DB_PASSWORD`,
`DB_MIGRATION_PASSWORD`, `BACKUP_DB_PASSWORD`) **no hay que reponerlas**: la
copia lógica no lleva las contraseñas de los roles y el cluster nuevo ya tiene
las suyas.

Después, recalcula la clave del WAL a partir de la maestra repuesta y recrea los
servicios para que la lean:

```bash
sudo ./backup.sh derive-wal-key --write-env .env
docker compose up -d
```

### 3.4 Comprueba que la copia se abre ANTES de tocar nada

```bash
docker compose run --rm --no-deps restore bash /opt/kronoqr/scripts/restore.sh --list
docker compose run --rm --no-deps restore bash /opt/kronoqr/scripts/restore.sh --dry-run
```

`--dry-run` no cambia nada. Si sale `6` (integridad o clave), ve a §7.

### 3.5 Restaura

```bash
docker compose stop app horizon scheduler reverb
docker compose run --rm --no-deps restore bash /opt/kronoqr/scripts/restore.sh --yes
docker compose up -d
```

La explicación de cada código de salida y qué hacer con `6` (asiento pendiente)
o `7` está en [`restaurar-backup.md`](restaurar-backup.md) §6.2, §6.6 y §6.7.
Adjunta el informe de `BACKUP_PATH/reports/` al parte.

**La base «anterior»** que conserva `restore.sh` es la de la instalación vacía
recién hecha: no hay nada que recuperar de ella.

### 3.6 La licencia

La licencia vive en la base y vuelve con la restauración. Compruébalo; si dice
que no hay licencia, actívala de nuevo con la clave que te dio el fabricante.
**Nada de esto afecta al fichaje** (ADR-019):

```bash
docker compose exec app php artisan license:show
docker compose exec app php artisan license:activate "KQL1...."     # solo si hace falta
```

## 4. Los quioscos: ¿se vuelven a emparejar?

**Normalmente no**, si el servidor nuevo responde **bajo el mismo nombre y con
un certificado que la tablet acepta**:

- Los dispositivos emparejados y sus credenciales están en la base, que acabas
  de restaurar. Las tablets emparejadas **antes** de la última copia siguen
  valiendo y **sincronizan solas su cola** al ver el servidor (RN-15).
- **No repuntes el DNS ni abras la red de los quioscos al servidor nuevo hasta
  terminar §3.5.** Una instalación vacía no conoce sus credenciales y las
  rechazaría, y no queremos que ningún quiosco se encuentre con una base
  distinta a mitad de camino.
- **Mantén la URL.** La PWA y su cola local viven en el origen (nombre y puerto)
  desde el que se instaló. Si cambias de nombre, la tablet abre un origen
  distinto, **sin cola y sin emparejar**, y los fichajes encolados en el origen
  viejo quedan inaccesibles. Si la IP cambia, repunta el DNS al servidor
  nuevo; no cambies el nombre.
- **Certificado:** el mismo nombre con otro certificado emitido por la misma CA
  del hotel se acepta. Un certificado autofirmado nuevo, no: la tablet lo
  rechazará hasta que se confíe de nuevo en él
  ([`renovacion-certificado-tls.md`](renovacion-certificado-tls.md)).
- **Se reemparejan** ([`alta-nuevo-quiosco.md`](alta-nuevo-quiosco.md)) las
  tablets emparejadas **después** de la última copia (no existen en la base
  restaurada) y cualquiera que muestre la pantalla de emparejamiento.

Cuando el servidor responda, mira
[`quiosco-no-responde.md`](quiosco-no-responde.md) y
[`cola-offline-atascada.md`](cola-offline-atascada.md): verás cómo cada quiosco
vacía su cola. **No limpies los datos del navegador de ninguna tablet hasta que
su cola esté a cero.**

## 5. Comprobaciones finales

```bash
./doctor.sh                                                # todo en [ok]; revisa cada [AVISO]
curl -sk https://localhost/api/v1/health                   # status ok, version = la instalada
docker compose exec app php artisan compliance:verify-audit-chain
docker compose exec app php artisan license:show
```

- **La cadena de auditoría tiene que verificar.** El asiento
  `system.restored_from_backup` documenta el intervalo descartado
  ([`restaurar-backup.md`](restaurar-backup.md) §6.7). Si la cadena **no**
  verifica, no es el procedimiento: es
  [`rotura-cadena-auditoria.md`](rotura-cadena-auditoria.md).
- **Los quioscos dan latido** en el panel y su cola baja a cero.
- **Se vuelve a hacer una copia y se verifica**, sin esperar a la noche:
  `docker compose exec scheduler php artisan backup:run --mode=dump` y
  `sudo ./backup.sh verify` (si falla, [`restaurar-backup.md`](restaurar-backup.md) §2).
  Hasta entonces la instalación nueva no tiene copia propia.
- **`doctor.sh` avisará de la clave del WAL y del archivado** si algo de §3.3 quedó
  a medias ([`restaurar-backup.md`](restaurar-backup.md) §4.2).
- **Lo que no vuelve, a propósito** ([`restaurar-backup.md`](restaurar-backup.md)
  §6.2, ADR-045): las exportaciones e informes generados (se piden de nuevo) y
  la historia de métricas y de Grafana. Aparecerán asientos
  `data_export.file_missing`/`report_export.file_missing`: **en una restauración
  no son una brecha**.
- **Avisa a RRHH** del intervalo exacto que se ha perdido (la fecha de la copia
  que dice el informe) para que corrijan lo que haga falta con los
  procedimientos de siempre: las correcciones crean versión nueva (RN-13).

## 6. Ruta B: llegar hasta los últimos 15 minutos con el WAL

Solo si el WAL archivado **también se rescató** y la pérdida de hasta 24 h de
fichajes ya sincronizados no es admisible. La recuperación a un punto concreto
(copia física + WAL cifrado, `kronoqr-extract-base` y `kronoqr-restore-wal`,
ADR-049) está en [`restaurar-backup.md`](restaurar-backup.md) §6.4: con la imagen
de PostgreSQL **del producto** en el servidor nuevo y `BACKUP_ENCRYPTION_KEY` ya
repuesta (§3.3), deja un cluster recuperado en un volumen aparte
(`pgdata-restaurado`), y **ensáyala antes** con
`./restore-drill.sh --mode pitr`.

**Límite conocido, sin resolver:** el procedimiento termina ahí. Poner ese
cluster en servicio **sustituyendo** el volumen de datos de la instalación
nueva (con sus contraseñas de roles y sin perder el asiento de auditoría de la
restauración) **no está escrito ni ensayado**. Mientras no lo esté, hazlo solo
con el fabricante delante, y conserva la restauración de §3.5 como punto de
partida seguro: es mejor un registro completo hasta la madrugada que uno
recuperado a medias.

## 7. Si falta la clave de las copias

Si no tienes `BACKUP_ENCRYPTION_KEY` (o `restore.sh` sale `6` por clave
distinta tras comprobar que es la de esa copia):

1. Mira si alguna **copia anterior** se hizo con otra clave
   (`BACKUP_ENCRYPTION_KEY_PREVIOUS`, [`rotacion-secretos.md`](rotacion-secretos.md)).
2. Busca la clave en **todos** los sitios de custodia (gestor de contraseñas,
   caja fuerte, la persona que instaló).
3. **El fabricante no la tiene ni puede obtenerla** (regla dura 16). Si no
   aparece, las copias no se pueden abrir: el registro horario anterior se ha
   perdido. Afecta a la obligación de conservar el registro cuatro años: informa a
   la dirección y a la asesoría **el mismo día**, y conserva los ficheros cifrados
   por si la clave aparece más tarde. Retoma con la instalación nueva (reempareja
   los quioscos sin limpiar su cola, [`alta-nuevo-quiosco.md`](alta-nuevo-quiosco.md))
   y **no borres** las copias.

## 8. Qué no hacer

- **No ejecutes `install.sh` encima de otra instalación** ni con volúmenes de
  datos de la anterior: se niega (salida `3`) y es lo correcto.
- **No reutilices el `.env` viejo entero como si fuera la instalación nueva sin
  entender qué reponer**: las rutas, los CIDR y los puertos pueden ser otros en
  la máquina nueva. Repón los secretos de §3.3 y revisa lo demás con
  [`../cliente/instalacion.md`](../cliente/instalacion.md) §1.2.
- **No envíes la clave de las copias ni el `.env` al fabricante** para «que lo
  mire»: no los necesita y no los quiere (regla dura 16).
