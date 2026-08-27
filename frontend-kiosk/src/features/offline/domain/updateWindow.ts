// Cuando NO se puede aplicar una version nueva del quiosco.
//
// Aplicar una actualizacion del service worker recarga la pagina. Si eso ocurre
// a las 06:00 con quince personas esperando, el quiosco esta muerto justo en el
// minuto que existe para cubrir. Y si ocurre con fichajes sin sincronizar, la
// recarga sucede sobre una cola que todavia no se ha vaciado: IndexedDB
// sobrevive a la recarga, pero cualquier problema de arranque de la version
// nueva deja esos fichajes retenidos y sin nadie mirandolos.
//
// ALCANCE. La VENTANA CONFIGURABLE por cliente es RF-KI-07, tarea 3.12. Lo de
// aqui es el paso 11 de la tarea 1.9: que no ocurra A CIEGAS. Por eso los
// valores por defecto son constantes con nombre y la funcion recibe las
// ventanas como parametro — la 3.12 solo tendra que pasarle otras, leidas de la
// configuracion del centro (regla dura 13: nada de cliente en el codigo).

/** Franja horaria local, en minutos desde medianoche. */
export interface ShiftChangeWindow {
  readonly startMinute: number
  readonly endMinute: number
}

const minutes = (hour: number, minute: number): number => hour * 60 + minute

/**
 * Cambios de turno tipicos de un hotel: manana, tarde y noche. Media hora antes
 * y media despues de la hora en punto, que es cuando se concentra la cola.
 */
export const DEFAULT_SHIFT_CHANGE_WINDOWS: readonly ShiftChangeWindow[] = [
  { startMinute: minutes(5, 30), endMinute: minutes(6, 30) },
  { startMinute: minutes(13, 30), endMinute: minutes(14, 30) },
  { startMinute: minutes(21, 30), endMinute: minutes(22, 30) },
]

/**
 * Se evalua en la hora LOCAL de la tablet a proposito. El resto del sistema
 * trabaja en UTC (regla dura 3) porque son instantes con valor legal; esto no
 * es un instante, es «la hora del reloj de la pared», que es la que decide
 * cuando hay cola de gente.
 */
export function isWithinShiftChange(
  localNow: Date,
  windows: readonly ShiftChangeWindow[] = DEFAULT_SHIFT_CHANGE_WINDOWS,
): boolean {
  const current = localNow.getHours() * 60 + localNow.getMinutes()
  return windows.some((window) =>
    window.startMinute <= window.endMinute
      ? current >= window.startMinute && current < window.endMinute
      : // Ventana que cruza la medianoche (22:30 → 00:30).
        current >= window.startMinute || current < window.endMinute,
  )
}

export interface UpdateGateInput {
  readonly now: Date
  readonly pendingScans: number
  readonly windows?: readonly ShiftChangeWindow[]
}

/** `true` solo si aplicar la version nueva ahora no puede dejar a nadie sin fichar. */
export function canApplyUpdate(input: UpdateGateInput): boolean {
  if (input.pendingScans > 0) return false
  return !isWithinShiftChange(input.now, input.windows ?? DEFAULT_SHIFT_CHANGE_WINDOWS)
}
