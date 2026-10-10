// «HAY ALGUIEN USANDO EL QUIOSCO AHORA» para la puerta de actualizacion
// (RF-KI-07, regla dura 19).
//
// `lastScanAt` solo sabe de escaneos YA hechos. Hay momentos en los que alguien
// esta a media interaccion y la tablet aun no ha escrito nada: un envio en
// vuelo, un PIN a medio teclear, la pausa armada o una confirmacion en
// pantalla. Recargar ahi se lleva por delante lo que la persona esta haciendo.
// Cada fuente se marca con un nombre; mientras haya alguna activa, la puerta
// esta cerrada SIEMPRE (urgente o no). Estado de modulo: una tablet, una
// interaccion a la vez (mismo patron que el controlador de la cola).

const flags = new Set<string>()
const counters = new Map<string, number>()

/** Estado: la fuente esta activa o no (PIN con digitos, pausa armada, confirmacion visible). */
export function markInteraction(source: string, active: boolean): void {
  if (active) flags.add(source)
  else flags.delete(source)
}

/** Contador: sube al entrar en una operacion y baja con el `release` devuelto (idempotente). */
export function holdInteraction(source: string): () => void {
  counters.set(source, (counters.get(source) ?? 0) + 1)
  let released = false
  return () => {
    if (released) return
    released = true
    const next = (counters.get(source) ?? 1) - 1
    if (next <= 0) counters.delete(source)
    else counters.set(source, next)
  }
}

export function interactionInProgress(): boolean {
  return flags.size > 0 || counters.size > 0
}

/** Solo pruebas. */
export function resetInteractions(): void {
  flags.clear()
  counters.clear()
}
