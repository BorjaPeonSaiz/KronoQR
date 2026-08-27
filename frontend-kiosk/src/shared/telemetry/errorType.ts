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
