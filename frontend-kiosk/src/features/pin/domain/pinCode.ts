// Formas de entrada del fichaje de respaldo (RF-AT-11).
//
// LO QUE SE COMPRUEBA AQUI Y LO QUE NO. Igual que `credentialPayload.ts` para
// el QR: solo la FORMA. Ni el codigo de empleado ni el PIN se validan contra
// nada del servidor en el quiosco — eso lo decide `POST /api/v1/scan/pin`, y
// con el mismo rechazo generico para cualquier causa (regla dura 17, RS-03).
// Esto solo evita mandar un PIN de 3 digitos o un codigo vacio, que es un
// desperdicio de bateria y de cola, no una comprobacion de identidad.
//
// POR QUE EL TECLADO DEL CODIGO NO SE RESTRINGE AL ALFABETO DE GENERACION.
// `EmployeeCode::generate()` en el servidor usa un alfabeto sin `0`/`O`/`1`/
// `I`/`L`, pero `fromString()` acepta cualquier alfanumerico en mayusculas —
// tiene que poder leer codigos de instalaciones migradas o mas antiguos (ver
// el comentario del propio value object). Restringir el teclado del quiosco al
// alfabeto nuevo dejaria a alguien con un codigo viejo sin forma de escribirlo:
// exactamente lo que la regla dura 19 prohibe. Por eso el codigo se teclea con
// el teclado nativo del dispositivo (`<input>`), que tiene todos los caracteres,
// y el numerico dedicado es solo para el PIN.

// ADR-050, punto 1: el servidor valida con la longitud del PIN emitido (6 u 8 segun el
// ajuste del hotel), pero conviven PIN antiguos de 6 y nuevos de 8, y el teclado
// no sabe cual toca a quien: acepta de 6 a 8 y envia con «Aceptar», nunca solo
// al llegar a una longitud. El rechazo sigue siendo generico (regla dura 17).
export const PIN_MIN_LENGTH = 6
export const PIN_MAX_LENGTH = 8

/** Techo de `PinScanRequest.employee_code` en el contrato. */
export const MAX_EMPLOYEE_CODE_LENGTH = 32

export function isValidPinShape(value: string): boolean {
  return /^[0-9]{6,8}$/.test(value)
}

export function hasEmployeeCodeShape(value: string): boolean {
  const trimmed = value.trim()
  return trimmed.length > 0 && trimmed.length <= MAX_EMPLOYEE_CODE_LENGTH
}

export function normalizeEmployeeCode(value: string): string {
  return value.trim().toUpperCase()
}
