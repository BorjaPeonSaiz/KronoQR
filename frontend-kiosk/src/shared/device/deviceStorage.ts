// El almacenamiento local del dispositivo, sin excepciones.
//
// Modo privado, almacenamiento deshabilitado por politica del dispositivo…:
// nada de eso puede tumbar el quiosco, asi que el acceso devuelve `null` en vez
// de lanzar y cada lector decide su degradacion honesta.

export function deviceStorage(): Storage | null {
  try {
    return globalThis.localStorage
  } catch {
    return null
  }
}
