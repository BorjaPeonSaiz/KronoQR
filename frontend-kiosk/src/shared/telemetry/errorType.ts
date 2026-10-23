// Nombre tecnico de un error, para el contexto de telemetria.
//
// No vale `error instanceof Error`: `DOMException` —que es lo que lanzan
// `getUserMedia`, `wakeLock.request()` y `AbortController`, o sea justo lo que
// mas falla en un quiosco— NO hereda de `Error` en todos los entornos. Un
// `instanceof Error` a secas convierte «NotAllowedError» en «unknown», que es
// exactamente el dato que hacia falta para saber si a la tablet le falta el
// permiso de camara o le falta la camara.

export function errorTypeOf(error: unknown): string {
  if (typeof error === 'object' && error !== null && 'name' in error) {
    const { name } = error as { name: unknown }
    if (typeof name === 'string' && name !== '') return name
  }
  return 'unknown'
}

/**
 * Texto del error, para la columna `message` de `error_events` (RF-PD-15).
 * Mismo motivo de duck-typing que `errorTypeOf`: un `DOMException` lleva
 * `.message` igual que un `Error`, pero no siempre hereda de el. Si no hay
 * texto que sacar, cadena vacia -nunca `'unknown'`, que ya lo dice
 * `error_type` y repetirlo en `message` no anadiria nada-. Sale ya saneado
 * (`scrubErrorMessage`); el servidor repite el saneado (`ErrorMessageSanitizer`).
 */
export function errorMessageOf(error: unknown): string {
  if (typeof error === 'object' && error !== null && 'message' in error) {
    const { message } = error as { message: unknown }
    if (typeof message === 'string' && message !== '') return scrubErrorMessage(message)
  }
  if (typeof error === 'string' && error !== '') return scrubErrorMessage(error)
  return ''
}

/**
 * Quita de un texto libre lo que puede llevar datos personales (R3-KI-02, regla
 * dura 21). Es defensa en profundidad: ningun camino del cliente interpola hoy un
 * nombre en un error, pero una excepcion de manana con texto libre viajaria tal
 * cual a `error_events`, que acaba en el paquete de diagnostico del fabricante.
 *
 * Se sustituye: lo entrecomillado (donde los motores ponen los valores), correos,
 * UUID, cargas de tarjeta (`FH1.…`), cadenas largas de aspecto de token (no los codigos tecnicos en snake_case
 * que usa el propio cliente, como `confirm_not_persisted`) y las
 * secuencias de cifras de un DNI o un telefono. Un NOMBRE suelto («Ana Ruiz»)
 * ninguna regla lo atrapa: por eso los errores NO capturados no envian su texto
 * (`main.ts` manda solo el tipo) y el servidor repite el saneado
 * (`ErrorMessageSanitizer`).
 */
export function scrubErrorMessage(text: string): string {
  return text
    .replace(/"[^"]*"|'[^']*'|`[^`]*`|«[^»]*»|“[^”]*”|‘[^’]*’/g, '…')
    .replace(/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/g, '[email]')
    .replace(/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/gi, '[id]')
    .replace(/\bFH1\.\S+/g, '[token]')
    .replace(/[A-Za-z0-9_-]{20,}/g, (run) => (/^[a-z_]+$/.test(run) ? run : '[token]'))
    .replace(/\d[\d\s.-]{4,}\d/g, '[num]')
}
