# pin

Fichaje de respaldo con codigo de empleado y PIN de 6 digitos cuando la tarjeta no esta o no lee (RF-AT-11, RS-12). Tarea 1.12.

Carpeta por _feature_, no por tipo de fichero (doc 02 §3.5).

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
