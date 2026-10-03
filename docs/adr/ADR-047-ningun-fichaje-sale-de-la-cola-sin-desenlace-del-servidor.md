# ADR-047 — Ningún fichaje sale de la cola del quiosco sin un desenlace del servidor

| Campo | Valor |
|---|---|
| **Estado** | Aceptada. Revisión de `seguridad-cumplimiento` pendiente dentro del Bloque 18 |
| **Fecha** | 3 de octubre de 2026 |
| **Decide** | `arquitecto-dominio` (Bloque 18 de la 2.2.0, hallazgos R3-QA-02, R3-BE-03, R4-SC-03, R3-KI-01, R3-QA-05, R2-KI-05 y R17-SC-R2) |
| **Afecta a** | Precisa [ADR-008](ADR-008-offline-first-con-idempotencia-por-scan-id.md) (punto 3: el borrado tras confirmación explícita vale también para lo que el servidor declara inválido) · Complementa [ADR-043](ADR-043-el-pin-rechazado-conserva-a-quien-correspondia-el-codigo.md) (no lo cambia) · `RegisterScanBatchHandler`, `ScanBatchOutcome`, `CredentialResolution`, `HmacSignatureVerifier`, `RegisterScanHandler`, `DetectAttendanceAnomalies` · `syncRunner.ts`, `scanQueue.ts` · tabla nueva `discarded_scan_reports` · `POST /api/v1/scan/discarded` |
| **Requisitos** | RN-20, RN-21 y RN-22 (nuevas), RN-14, RN-15, RF-KI-04, RF-AT-07, RS-03, reglas duras 8, 9, 17 y 19 |

## Contexto

La regla dura 19 promete que el quiosco nunca bloquea al empleado y que, si algo no cuadra, una
persona lo revisa. La verificación de la 2.2.0 encontró tres caminos por los que un fichaje que la
tablet ya había confirmado en pantalla («Entrada registrada») desaparecía del registro sin que nadie lo
viera, o entraba en él con el significado cambiado:

1. **Lote parcial** (R3-QA-02, R3-BE-03). El servidor seguía procesando un lote después de un
   elemento no procesado (`503`), y el quiosco pasaba al tramo siguiente en cuanto un elemento del
   lote salía bien. Con la entrada de las 07:00 aplazada y la salida de las 15:00 procesada, la salida
   abría un turno; el reenvío de la entrada caía en RN-18 y salía de la cola como rechazo terminal.
2. **Descarte por `400`** (R4-SC-03 y grupo). Un `400`, o un `422` que no era el rechazo genérico,
   sacaba el fichaje de la cola con un diagnóstico técnico sin `scan_id` ni hora. El caso real es una
   PWA cacheada que vacía su cola contra una API ya actualizada que rechaza un campo: cuarenta
   fichajes perdidos uno a uno. El propio código lo reconocía: «la revisión humana no tiene canal en el
   contrato».
3. **Tarjeta retirada después del fichaje** (R17-SC-R2). Un fichaje sin red del último día de una
   persona que llega después de registrar su baja se rechaza por RN-14 —correcto— y queda mudo en
   `scan_events`, sin persona y sin incidencia. Lo mismo pasa con una tarjeta reemitida o dada por
   perdida entre el fichaje y la sincronización.

## Decisión

**Todo fichaje que la tablet confirmó termina en uno de tres sitios: registrado, rechazado por una
decisión del servidor que una persona puede revisar, o comunicado como descartado y convertido en
incidencia. Nunca en ningún otro.**

### 1. Orden por quiosco, no por persona (RN-21)

- **Servidor.** En `POST /api/v1/scan/batch`, al primer elemento no procesado los posteriores **del
  mismo lote** no se procesan: se devuelven en su orden con `503` y el problema
  `urn:kronoqr:problem:scan-held-back` («aplazado»). Lo ya decidido no se toca. Un `422` no detiene
  nada.
- **Quiosco.** Un tramo de la cola solo «progresa» si **todos** sus elementos tienen desenlace
  terminal; si uno se conserva para reintento, el drenaje se detiene ahí y aplaza lo que viene detrás.
  El prefijo que reclama la cola corta también en una fila que otro envío tiene en vuelo.
- La clave es el quiosco porque **nadie sabe de quién es el elemento atascado**: el servidor puede
  haber fallado resolviendo la credencial, y el quiosco sin red no puede emparejar un QR con un PIN de
  la misma persona.

### 2. Un canal para lo que el servidor declara inválido (RN-22)

- El quiosco mueve el fichaje a una **lista de descartados**, aparte de la cola de envío, y lo comunica
  por **`POST /api/v1/scan/discarded`** (`scan:write`, hasta 10 por petición) con `scan_id`,
  `occurred_at`, vía, código y tipo de problema recibidos, y el `qr_payload` o el `employee_code` que
  tenía. Solo lo olvida cuando el servidor devuelve su `scan_id` en `acknowledged`. Lo que no consigue
  avisar lo declara en el latido (`unreported_discards`).
- El servidor guarda el aviso en `discarded_scan_reports` (solo `INSERT` y `SELECT`, UNIQUE por
  `scan_id`) **con el resultado de atribuirlo y sin el payload ni el código**. Atribuye solo si la
  tarjeta es auténtica (mismo resolver y mismo suelo de tiempo que el escaneo) o, por PIN, si el código
  es de una persona que puede fichar. La incidencia `discarded_scan` la abre la revisión diaria, una
  por persona y jornada.
- El aviso **no registra el fichaje**: el servidor acaba de decir que esa petición no vale, y
  registrarla por otra puerta sería escribir en el registro legal algo que nadie ha validado.
- Su esquema es **congelado**: solo crece con campos opcionales, para que una PWA anterior pueda avisar
  a una API posterior, que es justo el caso que lo motiva.

### 3. La tarjeta auténtica retirada atribuye (RN-20)

- Cuando el resolver rechaza una tarjeta **con firma válida** porque la credencial está revocada o su
  titular está de baja, devuelve además a su titular y el instante de la retirada
  (`credentials.revoked_at`), que ya tiene cargado. `employeeUuid()` sigue siendo nulo: el tipo sigue
  separando «a quién pertenece» de «quién se autenticó».
- `RegisterScanHandler` escribe la fila con `employee_id` del titular —lo que el doc 01 §5.5 ya
  afirmaba de «la tarjeta revocada de RN-14»— y `flagged_for_review` si `occurred_at` es anterior a la
  retirada (o al `recorded_at`, si la credencial seguía vigente y la persona está de baja). La revisión
  diaria abre `scan_before_revocation`.
- **La respuesta no cambia** (RS-03): mismo cuerpo, mismas consultas, mismo suelo; solo cambian dos
  valores de la misma inserción, como en ADR-043.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| Orden **por persona** en el lote | El servidor no conoce a la persona del elemento que falló; el quiosco sin red tampoco la de un QR |
| Que el servidor siga procesando y el quiosco reordene | Lo procesado no se puede deshacer: la salida ya abrió un turno |
| Avisos de descarte **en el latido** | El latido es telemetría que no gobierna el registro, cae entero si un error de cliente no valida, y no tiene acuse por elemento |
| Que el servidor abra la incidencia **al responder `400`** | No cubre el cuerpo ilegible, abre incidencias por cualquier basura y el quiosco no sabe cuándo puede olvidar el fichaje |
| Atribuir el descarte por el `token_hash` del padrón | Un token de quiosco robado conoce todos los hashes y podría sembrar incidencias contra cualquiera |
| El instante de la baja desde `audit_log` o en una columna nueva de `employees` | La primera cruza a `Compliance` sin arista (ADR-025); la segunda migra la tabla más referenciada y no cubre la reemisión ni la pérdida |
| Ampliar `claimed_employee_id` (ADR-043) a la tarjeta retirada | `employee_id` ya significa «atribuido», y una tarjeta auténtica sí atribuye. ADR-043 se queda como está |

## Consecuencias

- Un `503` que no se cura **atasca toda la cola** del quiosco, no solo la de una persona. Se ve en
  `oldest_pending_at` y en la salud `queue_pending`; es una avería que se arregla en el servidor.
- `scan_events.employee_id` deja de ser nulo en los rechazos de tarjeta auténtica retirada: toda
  consulta por `employee_id` que quiera solo lo aceptado tiene que filtrar por `result`.
- Aparecen dos tipos de incidencia (`scan_before_revocation`, `discarded_scan`) y una tabla con dato
  personal (el dueño atribuido), con la retención de `scan_events`, dentro de la exportación íntegra y
  fuera del paquete de diagnóstico.
- Un token de quiosco robado puede, como mucho, provocar una incidencia `discarded_scan` al día a
  quien tenga su tarjeta en la mano o a quien conozca su código de empleado —el mismo techo que RN-19—.
- **Queda fuera**: el PIN de una persona de baja que llega tarde. Cubrirlo exige ampliar ADR-043
  (el `PinClaim` solo existe para quien puede fichar); hasta que se decida, la guía de RRHH pide
  completar a mano el último día.

## Verificación

- Unitaria: `RegisterScanBatchHandler` con fallo en el 2.º de 4 → un procesado, un no procesado, dos
  aplazados, y el caso de uso no se llama para los aplazados. `WithdrawnCredentialPolicy` en los
  límites. `DiscardedScanAttributionPolicy`.
- Feature: el lote del Contexto (entrada aplazada, salida aplazada) termina, tras el reenvío, en un
  tramo de 07:00 a 15:00. La tarjeta revocada con `occurred_at` anterior y posterior a la baja produce
  respuestas idénticas byte a byte. El aviso de descarte es idempotente y su autorización negativa
  cubre cada rol de gestión.
- Integración: concurrencia del mismo `scan_id` en el aviso (una fila); la revisión abre una incidencia
  por persona y jornada.
- Quiosco: la cola no adelanta un PIN posterior a un QR atascado; un `400` deja el fichaje en la lista
  de descartados hasta el acuse. E2E de 40 fichajes con `400`: la cola de envío se vacía y el servidor
  tiene los 40 avisos.
