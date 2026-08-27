// Retroceso exponencial de la sincronizacion (doc 02 §6: «1 s, 2 s, 4 s … max 5 min»).
//
// POR QUE UN TECHO Y NO UN ABANDONO. Nunca se deja de reintentar. Un fichaje
// encolado es registro legal sin escribir (RL-01) y no hay ningun numero de
// intentos que justifique tirarlo. El techo de 5 minutos es lo que convierte
// «reintentar para siempre» en algo que una tablet al 8 % de bateria puede
// sostener toda una tarde: doce peticiones a la hora en lugar de miles.
//
// POR QUE NO HAY FLUCTUACION ALEATORIA. La habria si cada elemento reintentara
// por su cuenta, pero el drenaje es UNO, secuencial y por lotes de 50: un
// quiosco con 40 pendientes hace una peticion, no cuarenta. Entre quioscos
// distintos tampoco hay estampida porque cada uno perdio la red en un momento
// distinto, y la zona de fichaje de Nginx admite 600 r/m con rafaga de 50
// (doc 02 §7.1). Anadir aleatoriedad aqui solo haria el reintento imposible de
// predecir en una prueba, a cambio de nada.

export const FIRST_RETRY_DELAY_MS = 1_000
export const MAX_RETRY_DELAY_MS = 5 * 60 * 1_000

/** Tope del exponente para que `2 ** n` no desborde con una cola muy vieja. */
const MAX_EXPONENT = 30

/**
 * Espera antes del intento numero `attempts + 1`.
 *
 * @param attempts intentos ya fallidos. `0` significa «nunca se ha intentado»,
 *                 y entonces no hay espera: se envia en cuanto se encola.
 */
export function retryDelayMs(attempts: number): number {
  if (attempts <= 0) return 0
  const exponent = Math.min(attempts - 1, MAX_EXPONENT)
  return Math.min(FIRST_RETRY_DELAY_MS * 2 ** exponent, MAX_RETRY_DELAY_MS)
}

/** Instante en el que un elemento con `attempts` fallos vuelve a ser elegible. */
export function nextAttemptAt(attempts: number, nowMs: number): number {
  return nowMs + retryDelayMs(attempts)
}
