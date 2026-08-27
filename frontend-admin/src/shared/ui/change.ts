/**
 * Un cambio a punto de confirmarse: que campo, desde que valor y hacia cual.
 *
 * Los tres datos son obligatorios a proposito. «Se va a cambiar el
 * departamento» no permite a nadie detectar que el valor de partida ya era
 * incorrecto, que es justo lo que se revisa antes de confirmar una correccion.
 */
export interface Change {
  label: string
  from: string
  to: string
}
