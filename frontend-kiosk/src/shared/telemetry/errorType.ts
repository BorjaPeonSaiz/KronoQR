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
 * `error_type` y repetirlo en `message` no anadiria nada-. El saneado final
 * (PII, longitud) lo hace el servidor (`ErrorMessageSanitizer`); aqui solo se
 * extrae el texto, sin decorar.
 */
export function errorMessageOf(error: unknown): string {
  if (typeof error === 'object' && error !== null && 'message' in error) {
    const { message } = error as { message: unknown }
    if (typeof message === 'string' && message !== '') return message
  }
  if (typeof error === 'string' && error !== '') return error
  return ''
}
