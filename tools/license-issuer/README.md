# Emisor de licencias de KronoQR

Herramienta **del fabricante**. Emite las claves de licencia que los clientes
activan en su instalación (RF-PD-04, [ADR-018](../../docs/adr/ADR-018-licencia-firmada-con-verificacion-local.md)).

> **Esto no se entrega a ningún cliente y no viaja en ninguna imagen.** El
> `Dockerfile` de PHP copia `backend/` y este directorio queda fuera. En el
> servidor del cliente solo vive la **clave pública** y el verificador
> (`backend/app/Modules/Product/Infrastructure/Adapter/Ed25519LicenseVerifier.php`).
> Es lo que exigen el §7.7 del documento 02 y RS-08.

## La clave privada

**Nunca entra en el repositorio, en ninguna forma.** Ni en un fichero, ni en un
`.env.example`, ni en una prueba. Las pruebas del producto generan su propio par
en tiempo de ejecución con `sodium_crypto_sign_keypair()`, precisamente para que
no exista la tentación de guardar uno fijo.

Quien tenga la privada puede emitir licencias válidas para cualquier cliente,
con cualquier plan y cualquier vigencia. Se guarda en el gestor de secretos del
fabricante y llega a esta herramienta **por variable de entorno**, nunca por
argumento: los argumentos quedan en el histórico del shell y en `ps`.

## Puesta en marcha, una sola vez

```bash
php tools/license-issuer/generate-keypair.php
```

Sale **la pública por la salida estándar** y **la privada por la salida de
error**, separadas a propósito: quien ejecute esto dentro de una tubería, un
`tee` o un job de CI acabaría con la clave privada del fabricante en un fichero
de log si compartieran canal.

- La **pública** se pega en `backend/config/license.php`, **sustituyendo la cadena
  vacía del segundo argumento** de `env('LICENSE_PUBLIC_KEY', '')`. No hay
  ninguna constante que tocar: es esa línea. Se compila con la imagen de release.
- La **privada** va al gestor de secretos y no vuelve a salir de ahí. Con
  `--secret-out=RUTA` se escribe en un fichero creado con permisos `0600` en vez
  de imprimirse.

```bash
# La pública a un fichero, la privada solo a la terminal:
php tools/license-issuer/generate-keypair.php > publica.hex

# O las dos a su sitio, sin que la privada pase por la terminal:
php tools/license-issuer/generate-keypair.php --secret-out=~/.kronoqr/emision.key > publica.hex
```

`make release-gate` falla si la imagen de entrega se fuera a construir sin esa
sustitución hecha, para que un cliente no reciba un producto que rechaza su
licencia recién pagada.

Mientras `public_key` esté vacía, el producto no puede verificar **ninguna**
licencia: `license:show` lo dice con esas palabras (`no_public_key`) y todo el
registro horario sigue funcionando con normalidad (regla dura 15).

### Rotar el par

La clave pública viaja dentro del producto, así que rotarla es **publicar una
versión nueva** y que cada cliente actualice, no mandar un correo. Mientras un
cliente no actualice, las claves emitidas con el par nuevo no le verificarán y su
instalación quedará degradada en lo accesorio — nunca detenida. Conviene tenerlo
previsto antes de necesitarlo.

## Emitir una licencia

```bash
KRONOQR_LICENSE_SECRET_KEY="$(pass kronoqr/license-secret)" \
php tools/license-issuer/issue.php \
    --customer="Hotel Ejemplo, S.L." \
    --plan=estandar \
    --max-employees=80 \
    --max-devices=3 \
    --valid-from=2026-09-01 \
    --valid-until=2027-08-31 \
    --features=advanced_reports,realtime_presence
```

La clave sale por la salida estándar (para poder canalizarla a un fichero) y el
resumen por la salida de error.

**Se valida todo antes de firmar**, porque una clave mal emitida se descubre en
casa del cliente con la factura ya mandada: los límites tienen que ser enteros
positivos (`--max-employees=ochenta` produciría un `0` que el producto rechaza
como `invalid_payload`), la vigencia no puede terminar antes de empezar, y los
nombres de `--features` tienen que estar en el catálogo de ADR-023 —una errata
como `advanced_report` sin la ese produce una clave que verifica y **no concede
nada**—. Con `--force` se puede emitir una funcionalidad que esta herramienta
todavía no conozca, para cuando el producto vaya por delante de ella.

### Campos de la carga útil

| Campo | Obligatorio | Qué es |
|---|---|---|
| `license_id` | se genera si no se indica | Identificador de la licencia. Aparece en el asiento de auditoría del cliente y sirve para hablar de ella por teléfono |
| `customer_name` | sí | Razón social del hotel. Se enseña en el panel y en `license:show` |
| `plan` | sí | Nombre comercial del plan. Texto libre: no gobierna nada, se enseña |
| `max_employees` | sí | Personas en plantilla activa contratadas |
| `max_devices` | sí | Quioscos contratados |
| `features` | sí (puede ir vacío) | Funcionalidades **accesorias** habilitadas |
| `valid_from` | sí | Primer día de vigencia, a las 00:00:00 UTC |
| `valid_until` | sí | Último día de vigencia, a las 23:59:59 UTC |
| `issued_at` | automático | Cuándo se emitió |

**No hay `max_sites`.** Una licencia es un centro
([ADR-040](../../docs/adr/ADR-040-un-centro-por-instalacion-y-por-licencia.md)).
Una cadena de tres hoteles compra tres licencias. Si una clave lo trajera, el
producto lo ignoraría: la lectura tolera campos desconocidos para que una
instalación en la 1.2 pueda activar una clave emitida con la 1.6.

### Qué puede ir en `features`

Solo funcionalidades **accesorias**, las de la columna «Degradable» de
[ADR-023](../../docs/adr/ADR-023-frontera-registro-legal-y-funcionalidad-accesoria.md):

| Valor | Qué habilita |
|---|---|
| `advanced_reports` | Informes por periodo y comparativa con lo contratado |
| `impact_dashboard` | Cuadro de impacto y adopción (llega en la Fase 3) |
| `payroll_export` | Exportación configurable para nómina (Fase 3) |
| `weekly_email_summary` | Resumen semanal por correo (Fase 3) |
| `realtime_presence` | Presencia en tiempo real; sin ella, la vista **degrada a sondeo** |
| `white_label` | Marca propia del cliente (llega en la 5.8) |
| `telemetry` | Telemetría opcional (llega en la 5.10) |

**El registro legal no se puede escribir aquí y no existe forma de hacerlo.** El
fichaje, la sincronización de la cola offline, la consulta de jornadas, el portal
del empleado, la exportación para la Inspección, la auditoría, las correcciones,
las copias y las sondas de salud **no son licenciables**: no tienen nombre en el
catálogo `Feature` del producto, así que una clave no puede desactivarlos ni por
error ni a propósito. Un valor desconocido en `features` se ignora al leer la
clave.

## Formato de la clave

```
KQL1.<carga útil en base64url>.<firma ed25519 en base64url>
```

- `KQL1` es la versión del **formato**, no del producto: permite cambiar de
  formato algún día sin reemitir las licencias vivas.
- La carga útil es el JSON de los campos de arriba.
- La firma es detached, ed25519, sobre **el texto codificado tal y como viaja**
  —no sobre el JSON reserializado—, porque dos codificadores JSON ordenan las
  claves distinto y eso produciría firmas que verifican en una máquina y no en
  otra.
- Base64url y sin relleno para que la clave se pueda pegar en un correo, en un
  `.env` y en una URL sin que nada la reescriba.

## Cómo se comprueba que esto funciona

`backend/tests/Integration/Product/LicenseIssuerRoundTripTest.php`, que forma
parte de la suite del producto: genera un par al vuelo, emite una clave con
**este** emisor, la verifica con el verificador **del producto** y comprueba
además que alterar un solo byte la invalida. Si el emisor y el verificador dejan
de entenderse, esa prueba falla antes de que se emita una licencia real.
