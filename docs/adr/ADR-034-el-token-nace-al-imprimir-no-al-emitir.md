# ADR-034 — El token de la credencial nace al imprimir, no al emitir

| Campo | Valor |
|---|---|
| **Estado** | Aceptada |
| **Fecha** | 20 de agosto de 2026 |
| **Decide** | `arquitecto-dominio`, a petición del implementador de la tarea 1.5, que dejó la pregunta abierta por sus implicaciones legales y de seguridad |
| **Afecta a** | [ADR-005](ADR-005-payload-qr-firmado-con-hmac.md) · [ADR-014](ADR-014-la-credencial-es-una-tarjeta-fisica.md) · Regla dura 10 de `CLAUDE.md` · `docs/api/openapi.yaml` (`POST /api/v1/credentials`) · Tareas **1.5** (implementada) y **1.10** (siguiente) |
| **Requisitos** | RF-QR-01, RF-QR-03, RF-QR-04, RF-QR-06, RF-QR-08, RS-01, RS-03 |

## Contexto

El payload impreso en la tarjeta es `FH1.<key_id>.<token>.<sig>` (ADR-005). De sus cuatro partes, la base de datos guarda dos: el `key_id` y el **hash** del token. El token en claro **no se almacena nunca** — es la mitad de la protección de RS-01, y lo que hace que una copia de seguridad robada o un volcado de soporte no sirvan para fabricar la tarjeta de nadie.

La tarea 1.5 implementó la emisión acuñando el token en el mismo acto que crea la fila: `POST /api/v1/credentials` devolvía `qr_payload` en su `201`, y `php artisan credentials:issue` lo escribía en la terminal. Ese valor existía **una sola vez** y después era irrecuperable.

Al preparar la tarea 1.10 apareció el problema. El plan y el doc 02 §5.5 describen la impresión como un acto **posterior y separado**:

- `php artisan credentials:print {employee}` genera el PDF de una credencial **ya emitida**, en cualquier momento posterior.
- `php artisan credentials:print-batch --site= --pending` imprime en A4 todas las que estén **pendientes de imprimir** de un centro. `--pending` presupone que existe un conjunto de credenciales emitidas y sin imprimir.
- El panel de RF-QR-08 tiene «pendiente de imprimir» como uno de sus estados, y la métrica `credentials_pending_print{site}` lo cuenta.

Con el modelo de 1.5, esos tres comandos **no pueden funcionar**: la credencial existe, pero no queda nada con lo que dibujar su QR. La contradicción es directa entre la irreversibilidad del secreto (no negociable) y un flujo de impresión diferida que cuatro documentos dan por hecho.

## Decisión

**Emitir no acuña ningún token. El token, su firma y su hash nacen en el acto de imprimir, dentro de la misma operación que dibuja el PDF.**

El ciclo de vida de `Credential` pasa a tener tres actos con tres momentos propios:

| Acto | Qué escribe | Estado resultante | Puede fichar |
|---|---|---|---|
| **Emitir** (`POST /credentials`, `credentials:issue`) | `uuid`, `employee_id`, `issued_at` | Pendiente de imprimir | No |
| **Imprimir** (`POST /credentials/{uuid}/print`, `credentials:print`) | `key_id`, `secret_hash`, `printed_at` | Pendiente de entregar | Sí |
| **Entregar** (`POST /credentials/{uuid}/deliver`, `credentials:deliver`) | `delivered_at`, `delivered_by_user_id` | Entregada | Sí |

De ahí se siguen cinco consecuencias que son la decisión, no un detalle de ella:

1. **`key_id` y `secret_hash` son nulos hasta la impresión**, y las tres marcas van juntas o no va ninguna (CHECK `credentials_chk_minted_at_print`). Una credencial pendiente de imprimir **no es escaneable**: no hay hash por el que resolverla, así que ningún escaneo la alcanza. Esa es exactamente la definición operativa de «todavía no puede fichar».

2. **La clave de firma es la vigente al imprimir, no la de la emisión.** Es una mejora frente al modelo anterior: una tarjeta emitida antes de una rotación e impresa después sale firmada con la clave nueva, en vez de con una que esa misma semana se retira (§5.3).

3. **Imprimir es irrepetible.** «Reimprimir» solo puede significar acuñar otro token, y eso mata la tarjeta que quizá ya está en un bolsillo. `printedWith()` lanza `CredentialAlreadyPrinted`, el endpoint devuelve `409` y **no existe ningún `--force`**. Reponer una tarjeta es **revocar, reemitir e imprimir la nueva** —tres actos, los tres en `audit_log`—, que es literalmente lo que el runbook `tarjeta-perdida-o-rota.md` describe: *«revocación, reemisión y reimpresión en el día»*.

4. **La impresión por lotes es idempotente por construcción.** `print-batch --pending` selecciona solo las pendientes; una segunda pasada no encuentra ninguna y no imprime nada. Es la garantía que el modelo anterior no podía dar: dos ejecuciones del mismo lote no producen dos juegos de tarjetas con QR distinto.

5. **Ninguna respuesta de la API ni ninguna salida de consola contiene un QR.** El único canal por el que el payload sale del servidor es el PDF, que es el único que ADR-014 contempla. El esquema `QrPayload` desaparece del contrato.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Guardar el token cifrado de forma reversible hasta imprimir** | Introduce un secreto reversible vivo en la base de datos. El cifrado necesita su propia clave, y esa clave pasa a ser el punto único de fallo: quien la tenga puede leer **todos** los tokens y fichar por cualquiera. Es exactamente el riesgo que RS-01 y la regla dura 10 existen para eliminar, reintroducido por comodidad |
| **Que `print` reemita: cada impresión revoca y crea una credencial nueva** | Deja `POST /credentials` devolviendo un payload válido que después se descarta en silencio. Quien use ese payload para fabricar una tarjeta —cosa que el contrato de 1.5 invitaba a hacer— se encuentra con que una impresión posterior la mata sin avisar. Además multiplica las filas revocadas sin ningún hecho de negocio detrás: la auditoría deja de distinguir «se perdió» de «se imprimió otra vez» |
| **Reinterpretar `--pending` como «emitidas hace minutos», con emisión e impresión atómicas** | Contradice el doc 02 §5.5 («emisión e impresión con días de margen respecto al primer día de trabajo»), el estado «pendiente de imprimir» de RF-QR-08, la métrica `credentials_pending_print` y el caso de uso real que el plan describe: dar de alta a cuarenta personas de temporada una tarde e imprimirlas al día siguiente. Sería adaptar el producto al esquema en lugar de al revés |
| **No crear fila hasta imprimir: «pendiente» sería un estado del empleado, no de la credencial** | Elimina el hecho auditable «se emitió la credencial», colapsa emisión e impresión en un acto, y traslada a `Workforce` la pregunta de quién tiene derecho a tarjeta, que es de `Identity`. Vacía de sentido `printed_at`, que es columna del esquema del doc 01 §5.5 |

## Consecuencias

- **Cambia trabajo ya entregado por la tarea 1.5**, que fue revisada contra el plan y no se equivocó: el plan no contenía esta decisión. `Credential::issue()` pierde la clave y el secreto; `IssueCredential` pierde los puertos `QrKeyProvider` y `CredentialSecretFactory` —que pasan al caso de uso de impresión, tarea 1.10—; `IssuedCredential` pierde el payload; `credentials:issue` pierde su volcado en pantalla y la opción `--quiet-payload`.
- **El contrato cambia** (ADR-013: primero el contrato). `POST /api/v1/credentials` ya no devuelve `qr_payload`; `key_id` pasa a ser nulo mientras la credencial esté pendiente de imprimir; el esquema `QrPayload` se elimina de `components`. `Cache-Control: no-store` se conserva, ahora por privacidad y no porque el cuerpo lleve un secreto.
- **El asiento de `credential.issued` en `audit_log` ya no lleva `key_id`**, porque en ese momento no existe. Lo llevará el asiento de `credential.printed`, que añade la tarea 1.10.
- **`credentials:issue` deja de filtrar un secreto por la terminal.** El aviso que llevaba no impedía que el payload quedara en el historial del intérprete, en el buffer de la sesión SSH y en el registro de cualquier guion que lo llamara. La ventana de existencia del token pasa de días —dentro de una respuesta HTTP, un portapapeles o un log— a los milisegundos que se tarda en renderizar un PDF.
- **El estado «emitida» deja de significar «puede fichar»**, y el panel de RF-QR-08 y la métrica `employees_without_delivered_credential` ganan precisión: cuentan a quien de verdad no puede fichar todavía.
- **`ADR-005` no se toca**: el formato del payload, la firma HMAC y la rotación con solape siguen exactamente igual. Lo único que cambia es *cuándo* se calcula.
- **Queda una tensión abierta, anterior a esta decisión y no resuelta aquí**: la rotación de clave con solape del §5.3 («las tarjetas se reimprimen progresivamente») necesita que la tarjeta vieja siga siendo válida mientras se imprime la nueva, y eso choca con el índice `one_active_credential_per_employee`. Esta ADR deja el índice como estaba y **no pre-construye** la solución. Cuando la tarea **2.12** implemente `credentials:rotate-key` tendrá que decidirlo, y este modelo le abre una vía que antes no existía: una credencial pendiente de imprimir no es escaneable, así que podría convivir con la activa sin ambigüedad si el índice pasara a ser parcial sobre `printed_at IS NOT NULL`.

## Verificación

- `Tests\Unit\Identity\Domain\CredentialTest`: nace sin clave ni hash; la impresión los acuña; no se imprime dos veces; no se imprime una revocada; no se entrega una sin imprimir; ninguna combinación parcial de las tres marcas se puede construir.
- `Tests\Integration\Identity\CredentialSchemaTest`: PostgreSQL acepta credenciales pendientes con `key_id` y `secret_hash` nulos, admite **varias a la vez** pese al índice único de la pareja (`NULLS DISTINCT`), y rechaza toda media impresión y toda media entrega.
- `Tests\Feature\Identity\CredentialEndpointsTest`: el `201` no trae `qr_payload` y lleva `key_id: null`; una credencial pendiente de imprimir **no la resuelve el quiosco**; una impresa sí; el asiento de auditoría de la emisión no lleva `key_id`.
- `make quality`: Pint, PHPStan 9 y Deptrac en verde; el contrato pasa `redocly lint`.
