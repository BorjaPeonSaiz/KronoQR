# scan

Camara, decodificacion del QR y confirmacion con color, texto grande y sonido diferenciado
(RF-KI-01, RF-KI-02, RF-KI-06, RF-AT-05). Tarea 1.8, con el fichaje de pausa y el aviso de
desfase de la tarea 3.5 (RF-AT-12, RF-AT-10, ADR-024).

Carpeta por _feature_, no por tipo de fichero (doc 02 §3.5).

## Como esta repartido

```text
domain/        reglas sin dependencias: formato FH1, saludo, hora, acumulado, desenlaces,
                minutos/direccion del desfase de reloj (clockSkewMessage.ts)
application/   la tuberia del escaneo, sus PUERTOS (cola y padron los enchufa la 1.9) y el
                controlador del boton «Pausa» (breakIntent.ts, singleton por tablet)
composables/   camara, bucle de decodificacion, wake lock, sonido, sesion, useBreakIntent
ui/            la pantalla y el panel de confirmacion
```

## El camino critico, en una linea

`useQrScanner` decodifica → `scanPipeline.handleDecoded()` verifica el formato `FH1`,
resuelve el nombre en el padron cacheado, **resuelve la intencion armada** (`resolveIntent`,
`'break_start'` o `'auto'`), encola y **devuelve la confirmacion de forma sincrona** →
`useScanSession` la pinta y hace sonar el tono → el envio al servidor sale despues, en
segundo plano.

`handleDecoded` no tiene ni un `await`: eso es lo que garantiza los 300 ms de RNF-P-03 por
construccion y no por suerte. El empleado nunca espera a la red.

## Fichaje de pausa (tarea 3.5, ADR-024)

- **`application/breakIntent.ts`** es el controlador PURO (sin Vue) del boton «Pausa»:
  `arm()`, `disarm()`, `consumeIntent()` (desarma como efecto de leerla) y un temporizador de
  10 s. Es un **singleton por tablet** —igual que `errorReporter.ts` o el controlador de la
  cola offline—, para que dos pantallas montadas a la vez vean el mismo estado; **no** para
  que el arme sobreviva a la navegacion (ver mas abajo, revision de la segunda vuelta).
- **`composables/useBreakIntent.ts`** es el enchufe de Vue: reactividad, region viva
  (`announcement`, ver abajo) y **desarma el controlador al desmontar**.
- `ScanView.vue`/`PinView.vue` pasan `resolveIntent: () => breakIntent.consumeIntent()` a la
  tuberia. `intent` se escribe **al encolar**, nunca despues: un reintento reenvia la misma
  intencion (ADR-024).
- El botón solo aparece con `KioskHeartbeat.break_clocking_enabled` (cacheado en
  `localStorage` por `shared/telemetry/heartbeat.ts`, mismo patron que `service_code_hash`).
  **El servidor honra la intencion siempre**, este o no activado el ajuste: gobierna la
  pantalla y RN-12, no la verdad de lo declarado.
- Volver de la pausa es **siempre** `auto` (no hay boton «Fin de pausa»): el servidor
  (`ScanIntentPolicy`) decide `break_end` si el ultimo escaneo aceptado fue `break_start`.

### La intencion es de la tablet, no de la persona (revision de la segunda vuelta, seguridad)

En una cola de cambio de turno, otro empleado puede pasar SU tarjeta dentro de los 10 s de
armado. El boton se desarma, ademas de por uso o por los 10 s, en tres casos mas:

- **Cambio de pantalla.** `useBreakIntent()` desarma el controlador en `onUnmounted`: pasar de
  `ScanView` a `PinView` o al diagnostico (o al reves) NO conserva el arme, aunque el
  controlador sea el mismo singleton.
- **Un escaneo que no llega a fichar nada.** `scanPipeline.ts` acepta `onUnqueuedRead`,
  llamado en cada payload ilegible o repetido (tarjeta sostenida, o dentro de
  `LOCAL_REPEAT_WINDOW_MS`) que NO se encola. `ScanView.vue` lo conecta a `breakIntent.disarm()`.
- **Un PIN rechazado, al reves: se vuelve a armar.** Es la unica excepcion (revision de QA):
  la intencion ya se consumio al encolar, y un PIN mal tecleado con la pausa armada no debe
  hacer perder la intencion de quien la tecleo mal, solo de quien tecleo bien un codigo ajeno.
  `pinPipeline.ts` expone `onIntentRejected`, llamado solo si la intencion usada era
  `'break_start'` y el desenlace es `rejected`; `PinView.vue` responde con `breakIntent.arm()`.

**Region viva del boton, SIEMPRE montada** (`data-testid="break-armed-hint"`, nunca
`v-if`/`v-show` sobre el nodo): `useBreakIntent()` devuelve `announcement`, que cambia de
texto al armar y al desarmar («Pausa desarmada»). Retirar el nodo al desarmar —version
anterior— no lo anuncian la mayoria de lectores de pantalla; cambiar el texto de un nodo que
ya estaba ahi, si.

**Peso visual.** El boton lleva acento (`border-kq-kiosk-primary`, texto
`text-kq-kiosk-primary-strong`) incluso en reposo, para diferenciarse del enlace de PIN
(borde neutro); armado, se rellena (`bg-kq-kiosk-primary-strong`, `text-kq-kiosk-on-primary`).
El pictograma ⏸ va siempre junto al texto, y `ScanConfirmationPanel` lo reutiliza para
`break_start`/`break_end` en vez de la flecha de entrada/salida normal —mismo icono que el
panel y el portal—, para que se reconozca a dos metros sin leer la palabra.

## Aviso de desfase de reloj (tarea 3.5, RF-AT-10)

Dos fuentes, un mismo umbral —`clock_skew_tolerance_seconds` del latido, **no** una
constante propia (la vieja `CLOCK_SKEW_WARNING_SECONDS` desaparecio)—:

1. **Banda persistente en `ScanView`** cuando el ultimo latido mide un desfase por encima
   de la tolerancia.
2. **Linea en la confirmacion** (`ScanConfirmationPanel`) cuando `settleFrom` mide
   `|recorded_at - occurred_at|` por encima de la tolerancia, en un escaneo respondido EN
   LINEA (nunca el de un lote consolidado desde la cola: `settleFrom` solo se invoca con
   respuestas directas, ver el comentario de cabecera de `settleFrom.ts`).

Nunca cambia el camino de fichaje: **el aviso no bloquea nada** (regla dura 19).
`domain/clockSkewMessage.ts` traduce segundos a minutos/direccion para las dos.

## Lo que aporta la tarea 1.9

`application/ports.ts` declara `ScanSubmissionPort` y `RosterLookupPort`, que enchufa la
cola de Dexie —transaccional, con retroceso exponencial, lotes de 50 ordenados por
`occurred_at` y borrado solo tras confirmacion del servidor— y el padron cacheado y cifrado.

## Lo que NO se hace aqui, a proposito

- **No se verifica la firma HMAC.** Exige la clave, que no sale del servidor (regla dura
  10). Solo se comprueba el formato.
- **No se decide si es entrada, salida, pausa o vuelta.** Lo decide el agregado `WorkDay`
  (via `ScanIntentPolicy`) en el servidor. Mientras no conteste, la pantalla dice «pendiente
  de validar» y no se inventa nada.
- **No se distingue una causa de rechazo de otra.** Mensaje unico y generico (regla dura 17).
- **No hay boton «Fin de pausa».** La vuelta es siempre `auto` (decision 5 de la tarea 3.5).
