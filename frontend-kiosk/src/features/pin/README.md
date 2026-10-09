# pin

Fichaje de respaldo con codigo de empleado y PIN cuando la tarjeta no esta o no lee (RF-AT-11, RS-12). Tarea 1.12.

Carpeta por _feature_, no por tipo de fichero (doc 02 §3.5).

- `domain/pinCode.ts` — solo la FORMA de la entrada. El PIN tiene 6 u 8 cifras segun
  `IDENTITY_PIN_LENGTH` (ADR-050), pero conviven PIN antiguos de 6 y nuevos de 8 y el quiosco no
  sabe cual toca a quien: el teclado acepta de 6 a 8 y envia con «Aceptar», nunca al llegar a una
  longitud. El rechazo del servidor sigue siendo generico (regla dura 17).
- `ui/PinView.vue` + `ui/PinNumericKeypad.vue` + `composables/usePinKeypad.ts` — la pantalla y el
  teclado numerico; los puntos 7 y 8 se pintan como opcionales.
- `infrastructure/pinSealing.ts` — sobre cerrado de libsodium con la clave publica del padron: el
  PIN nunca viaja ni se guarda en claro, ni siquiera con red.
- `composables/usePinSealingStatus.ts` — si se PUEDE sellar (libsodium carga con `import()` para
  no entrar en el JS critico). Si no, el boton «Ficha con tu codigo y PIN» no se ofrece y la
  pantalla dice «PIN no disponible» en vez de un rechazo que no es del empleado (PIN-03).
- `application/pinPipeline.ts` — sella, encola y envia a `POST /api/v1/scan/pin` con la misma
  cola y la misma idempotencia por `scan_id` que la tarjeta.

## Fichaje de pausa (tarea 3.5, ADR-024)

`PinView.vue` usa el MISMO controlador singleton que `ScanView.vue`
(`features/scan/composables/useBreakIntent.ts`), pero **el arme NO sobrevive a la navegacion**
desde `ScanView` (revision de la segunda vuelta, seguridad: ver `features/scan/README.md`
→ «La intencion es de la tablet, no de la persona»). Quien llega aqui con la tarjeta olvidada
tiene que volver a armar el boton, ya en esta pantalla. El boton solo aparece con
`KioskHeartbeat.break_clocking_enabled`, y `pinPipeline.submit()` recibe
`resolveIntent`/`clockSkewToleranceSeconds` con la misma semantica que `scanPipeline.ts`. El
texto del aviso armado es propio de esta via («Introduce tu código para empezar la pausa»),
distinto del de la tarjeta; el de desarmado («Pausa desarmada») es compartido.

**Excepcion: un PIN rechazado vuelve a armar.** La intencion se consume al encolar (decision
5), asi que un PIN mal tecleado con la pausa armada dejaria el reintento como `auto` si nadie
la volviera a armar. `pinPipeline.ts` expone `onIntentRejected` —llamado solo cuando la
intencion usada era `'break_start'` y el servidor responde `rejected`, nunca con `auto`—, y
`PinView.vue` responde con `breakIntent.arm()`: el reintento con el PIN correcto conserva la
pausa.
