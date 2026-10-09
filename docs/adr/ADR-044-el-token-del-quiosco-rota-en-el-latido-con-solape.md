# ADR-044 — El token del quiosco rota en el latido, con solape y reentrega

| Campo | Valor |
|---|---|
| **Estado** | Aceptada. Implementada en la 2.2.0 y con el visto bueno de `seguridad-cumplimiento` (nota del 09-10-2026) |
| **Fecha** | 1 de octubre de 2026 |
| **Decide** | `backend-laravel` (Bloque 8 de la 2.2.0, hallazgo F1-1). Revisión de `seguridad-cumplimiento` hecha (nota del 09-10-2026) |
| **Afecta a** | Precisa el [documento 02](../02-stack-tecnologico-y-plan-implementacion.md) §7.3 («rotación automática al 80 % de vida») · `POST /api/v1/kiosk/heartbeat` (`KioskHeartbeat.rotated_token`) · `Identity\Domain\Policy\DeviceTokenRotationPolicy`, `RotateDeviceTokenIfDue`, `SanctumDeviceTokenIssuer` · `Kiosk\Application\Port\DeviceTokenRenewal` · `IDENTITY_DEVICE_TOKEN_OVERLAP_HOURS` |
| **Requisitos** | RF-ID-04, RS-04, RF-KI-04, reglas duras 6, 13, 14, 19 y 21 |

## Contexto

El documento 02 §7.3 promete que el token del quiosco dura 90 días y **rota solo al 80 % de su
vida**. La verificación de la 2.1.0 encontró que no rotaba nunca (F1-1): `RotateDeviceTokenIfDue`
existía pero nadie lo llamaba, la respuesta del latido no tenía campo para entregar un token, y a los
90 días de emparejarlas todas las tablets recibían `401` y dejaban los fichajes en su cola local.

Conectar la rotación al latido no bastaba. El emisor **borraba el token anterior en la misma
transacción** en que creaba el nuevo («una tablet tiene un token, no una colección»). Si la respuesta
del latido se perdía —un corte de wifi justo en ese segundo—, la tablet se quedaba con un token que
ya no existía y volvía a la pantalla de emparejamiento: el mismo fallo, un día cualquiera en lugar del
día 90.

## Decisión

**La rotación ocurre en el latido y deja el token anterior en solape.**

1. **Quién decide.** El servidor, al recibir un latido, por el **token que lo firma** —no por el
   dispositivo—. La regla es pura y vive en `DeviceTokenRotationPolicy`:
   - Si el firmante es el token más reciente del dispositivo y ha pasado el umbral
     (`IDENTITY_DEVICE_TOKEN_ROTATION_THRESHOLD`, 0,8), se emite el relevo —90 días,
     `IDENTITY_DEVICE_TOKEN_DAYS`— y el firmante **entra en solape**.
   - Si el firmante está en solape (ya existe uno más reciente), su relevo **no se ha usado nunca**:
     la respuesta que lo llevaba se perdió. Se retira ese relevo y se emite otro (**reentrega**).
   - En otro caso no se emite nada.
2. **El solape.** El token relevado sigue valiendo hasta `min(su caducidad, ahora +
   IDENTITY_DEVICE_TOKEN_OVERLAP_HOURS)` —24 h de serie, acotado a 1-168— **o hasta que el nuevo se
   use por primera vez**, lo que ocurra antes. El primer uso lo detecta la comprobación de cada
   petición autenticada (`last_used_at` vacío) y retira los anteriores; el latido lo vuelve a hacer
   como red de seguridad.
3. **Un token en solape no rota otra vez.** La reentrega **no toca** la fecha del solape: no se alarga
   ni se abre otro. Como mucho conviven dos tokens —el vigente y el relevado— y nunca una cadena.
4. **El valor sale una sola vez y no se guarda en claro.** Solo existe su hash, como el de cualquier
   token de Sanctum. Reentregar es reemitir, no reenviar.
5. **La revocación no tiene solape.** Desvincular un quiosco borra todos sus tokens en el acto, y
   volver a emparejarlo también (RS-04).
6. **Nunca bloquea el latido.** La rotación va por un puerto de `Kiosk` que no lanza
   (`DeviceTokenRenewal`); si falla, la transacción de `Identity` se deshace entera, el latido
   responde `200` sin `rotated_token` y se reintenta en el siguiente. El fallo se ve en el log
   técnico, en `error_events` y en `kiosk_token_rotations_total{result="failed"}` (regla dura 19).
7. **Queda constancia.** Cada relevo —rotación o reentrega— escribe `device.paired` en `audit_log` con
   `rotation: true`, `superseded_until` y `redelivery`: el UUID del dispositivo y fechas, **nunca** el
   token ni su hash (reglas duras 6 y 21).

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Borrar el anterior al rotar** (lo que había) | Una respuesta perdida deja a la tablet sin token. Convierte un corte de red de un segundo en una tablet fuera de servicio hasta que alguien la empareje |
| **Guardar el valor del relevo para reenviarlo** | Un token en claro en la base de datos. Cualquiera con lectura de la tabla —una copia de seguridad, un volcado de soporte— tendría una credencial viva de cada quiosco |
| **Rotar desde una tarea programada** | Emitiría un token que una tablet desconectada nunca recibiría. El latido es el único momento en que el servidor sabe que la tablet está ahí para recogerlo |
| **Solape sin tope, o que se renueve en cada reentrega** | Un token robado en el solape podría encadenar relevos indefinidamente. Con la fecha fija, su vida máxima es la que ya tenía |
| **Umbral en días («faltan menos de N días»)** | El §7.3 lo enuncia como fracción y la configuración ya existía así: cambiar `IDENTITY_DEVICE_TOKEN_DAYS` no obliga a recalcular el umbral a mano |
| **Ajuste en el panel (`installation_settings`)** | Es un parámetro de seguridad de la instalación, no de operación del hotel, y vive con el resto de `IDENTITY_*` en el entorno del contenedor ([ADR-029](ADR-029-configuracion-en-el-entorno-del-contenedor.md)) |

## Consecuencias

- **La tablet tiene que guardar el relevo de forma atómica**, adoptar siempre el último recibido y
  —como su padrón cacheado deriva la clave del token— volver a cifrarlo. Es trabajo del cliente
  (`frontend-quiosco`) sobre este contrato.
- **Una petición en vuelo firmada con el token viejo recibe `401`** si llega después del primer uso
  del nuevo. El quiosco ya exige dos `401` seguidos antes de volver a emparejar; la repite con el
  nuevo.
- Una tablet que pase **más de 18 días** sin un latido completo (con 90 días y 0,8) sigue cayendo a la
  pantalla de emparejamiento. Es un límite, no un fallo: su cola local se conserva.
- El asiento `device.paired` pasa a ser frecuente: uno por tablet cada 72 días, más las reentregas.
- `docs/cliente` puede volver a prometer que el token «se renueva solo» (DC1), una vez desplegado el
  cliente del quiosco que lo recoge.

## Verificación

- Unitaria: `DeviceTokenRotationPolicyTest` —umbral, solape, tope por la caducidad propia,
  reentrega sin alargar, firmante desconocido o sin caducidad—.
- Integración: `DeviceTokenTest` —dos tokens en solape, expiración del viejo, primer uso que lo
  retira, reentrega, revocación y reemparejamiento sin solape, asientos sin el valor—.
- Feature y contrato: `HeartbeatTokenRotationTest` —con y sin relevo contra `openapi.yaml`, respuesta
  perdida, fallo que no tumba el latido y 200 días simulados con dos rotaciones y un fichaje al final—
  y `OpenApiContractTest` sobre la forma de `rotated_token`.

## Nota 09-10-2026 (2.2.0 publicada): estado

**Aceptada e implementada en la 2.2.0.** La decisión no cambia; cambia el estado, que se había quedado en «Propuesta» con el código ya integrado (R3-AR-06 de la [re-verificación](../verificacion/2.2.0-reverificacion-tanda-3.md)).

- **Implementación** (bloque 8 de la 2.2.0, F1-1, commits del 01-10-2026): política pura `Identity/Domain/Policy/DeviceTokenRotationPolicy.php`; caso de uso `Identity/Application/UseCase/RotateDeviceTokenIfDue.php`, que el latido alcanza por el puerto `Kiosk/Application/Port/DeviceTokenRenewal.php` (`RecordHeartbeat` → `Kiosk/Infrastructure/Adapter/IdentityDeviceTokenRenewal.php`); `rotated_token` en la respuesta de `POST /api/v1/kiosk/heartbeat` del contrato; solape de 24 h (`identity.devices.token_overlap_hours`, `IDENTITY_DEVICE_TOKEN_OVERLAP_HOURS`). En el quiosco, `frontend-kiosk/src/shared/telemetry/tokenRotation.ts` adopta el relevo y vuelve a cifrar el padrón.
- **Pruebas:** `backend/tests/Feature/Kiosk/HeartbeatTokenRotationTest.php`, `HeartbeatTokenRotationConcurrencyTest.php` (dos latidos simultáneos, un solo relevo), `backend/tests/Integration/Identity/DeviceTokenTest.php`; en el quiosco, `tests/unit/tokenRotation.spec.ts` y el E2E `tests/e2e/token-rotation.spec.ts`.
- **Visto bueno de seguridad:** la pasada final de `seguridad-cumplimiento` de la re-verificación da F1-1 por corregido y el solape por aceptable ([2.2.0-reverificacion-tandas-5-6-7.md](../verificacion/2.2.0-reverificacion-tandas-5-6-7.md), §1.a, fila F1-1). La verificación final de seguridad lo confirma ([2.2.0-verificacion-final-tanda-4.md](../verificacion/2.2.0-verificacion-final-tanda-4.md), «el token rota con solape (ADR-044)»).
- La cabecera pasa a «Aceptada» por esta nota.
