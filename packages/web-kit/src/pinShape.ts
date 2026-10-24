// La forma del PIN que teclean el quiosco (fichaje de respaldo, RF-AT-11) y el
// portal (acceso personal, ADR-015). Antes estaba copiada a mano en los dos,
// y ademas la regex del quiosco no usaba sus propias constantes (V1-RV-02).
//
// Sale del contrato: es `PortalLoginRequest.pin.pattern` de
// `docs/api/openapi.yaml` (`^[0-9]{6,8}$`), y `pinShape.spec.ts` falla si el
// contrato y esta copia se separan. El quiosco no tiene un patron propio que
// leer, porque manda el PIN cerrado (`PinScanRequest.pin_sealed`), pero el
// mensaje que cierra es esta misma forma.
//
// De 6 a 8 y no la longitud configurada (ADR-050): conviven PIN emitidos con
// las dos longitudes y ni el teclado ni el formulario saben cual toca a quien.
// Siete cifras pasan la forma y el servidor responde el rechazo generico, como
// a cualquier PIN incorrecto (regla dura 17). Solo es FORMA: evita mandar un
// PIN de 3 cifras, no comprueba nada de nadie.

export const PIN_MIN_LENGTH = 6
export const PIN_MAX_LENGTH = 8

export const PIN_PATTERN = new RegExp(`^[0-9]{${PIN_MIN_LENGTH},${PIN_MAX_LENGTH}}$`)

export function isValidPinShape(value: string): boolean {
  return PIN_PATTERN.test(value)
}
