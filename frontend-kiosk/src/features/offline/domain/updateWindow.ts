// La PUERTA de la actualizacion del quiosco (RF-KI-07, tarea 3.12).
//
// Aplicar una version nueva del service worker recarga la pagina. Si eso
// ocurre a las 06:00 con quince personas esperando, el quiosco esta muerto
// justo en el minuto que existe para cubrir. Y si ocurre con fichajes sin
// sincronizar, la recarga sucede sobre una cola que todavia no se ha
// vaciado: IndexedDB sobrevive a la recarga, pero cualquier problema de
// arranque de la version nueva deja esos fichajes retenidos y sin nadie
// mirandolos.
//
// YA NO HAY VENTANAS DE CAMBIO DE TURNO FIJAS EN CODIGO (revision de esta
// tarea: la version anterior de este fichero traia tres franjas —05:30-06:30,
// 13:30-14:30, 21:30-22:30— con nombre de constante, que contradecia RF-KI-07
// («ventana configurable») y la regla dura 13 («nada de cliente en el
// codigo»). La franja la declara el CENTRO en `installation_settings` y viaja
// en cada latido (`KioskHeartbeat.update_window`, cacheada por
// `shared/telemetry/heartbeat.ts` en `shared/telemetry/deviceIdentity.ts`).
// Sin configuracion recibida todavia (tablet que nunca ha latido en esta
// sesion, o en ninguna anterior), la ventana DE SERIE es `03:00-05:00` con 10
// minutos de silencio: el mismo valor por defecto que declara el ajuste
// `KIOSK_UPDATE_WINDOW`/`KIOSK_UPDATE_QUIET_MINUTES` de la instalacion
// (decision 9 de la tarea 3.12), nunca un valor inventado distinto.
//
// ESTA ES UNA VENTANA DE PERMISO, NO DE BLOQUEO: `canApplyUpdate` es
// verdadero solo si las TRES condiciones se cumplen a la vez.
//
//   1. La cola offline esta VACIA: ninguna actualizacion puede perder un
//      fichaje que todavia no ha llegado al servidor.
//   2. No hubo NINGUN escaneo en los ultimos `quietMinutes` minutos: cubre el
//      turno que empieza antes de lo previsto, sin necesidad de acertar la
//      hora exacta que declaro el centro. `lastScanAt` en `null` cuenta como
//      «sin escaneo» (tablet que nunca ha fichado en esta sesion): no hay
//      motivo para inventar un escaneo reciente que no ha ocurrido.
//   3. La hora LOCAL de la tablet cae dentro de la ventana declarada, que
//      puede cruzar la medianoche (`23:00-02:00`).
//
// HORA LOCAL A PROPOSITO. El resto del sistema trabaja en UTC (regla dura 3)
// porque son instantes con valor legal; esto no es un instante, es «la hora
// del reloj de la pared del centro», que es la que declaro quien configuro
// la ventana y la que decide cuando hay cola de gente.

/** Franja horaria LOCAL del centro, en formato `"HH:MM"`. Puede cruzar la medianoche (`start` > `end`). */
export interface UpdateWindow {
  readonly start: string
  readonly end: string
}

/** `KIOSK_UPDATE_WINDOW` de serie (decision 9 de la tarea 3.12). */
export const DEFAULT_UPDATE_WINDOW: UpdateWindow = { start: '03:00', end: '05:00' }

/** `KIOSK_UPDATE_QUIET_MINUTES` de serie (decision 9 de la tarea 3.12). */
export const DEFAULT_UPDATE_QUIET_MINUTES = 10

const HH_MM_PATTERN = /^([01]\d|2[0-3]):([0-5]\d)$/

/** `null` si `value` no tiene la forma `"HH:MM"` valida. */
function parseHHMM(value: string): number | null {
  const match = HH_MM_PATTERN.exec(value)
  if (match === null) return null
  const hour = Number(match[1])
  const minute = Number(match[2])
  return hour * 60 + minute
}

/** `true` si `value` es una `UpdateWindow` con dos horas `"HH:MM"` validas. */
export function isValidUpdateWindow(value: unknown): value is UpdateWindow {
  if (typeof value !== 'object' || value === null) return false
  const { start, end } = value as Record<string, unknown>
  return (
    typeof start === 'string' &&
    typeof end === 'string' &&
    HH_MM_PATTERN.test(start) &&
    HH_MM_PATTERN.test(end)
  )
}

/**
 * `true` si la hora LOCAL `localNow` cae dentro de `window`. Admite una
 * ventana que cruza la medianoche (`start > end`, p. ej. `23:00-02:00`). Una
 * ventana con horas mal formadas nunca esta «abierta»: es mas seguro no
 * aplicar nada que adivinar.
 *
 * `start === end` NUNCA esta abierta -no «todo el dia»-: con `<` estricto en
 * el lado de `end`, ningun `current` puede ser a la vez `>= start` y `< start`.
 * Una ventana de cero minutos declarada por error en el centro se trata como
 * «nunca», la lectura mas segura, en vez de como «siempre».
 */
export function isWithinUpdateWindow(
  localNow: Date,
  window: UpdateWindow = DEFAULT_UPDATE_WINDOW,
): boolean {
  const start = parseHHMM(window.start)
  const end = parseHHMM(window.end)
  if (start === null || end === null) return false

  const current = localNow.getHours() * 60 + localNow.getMinutes()
  return start <= end
    ? current >= start && current < end
    : // Ventana que cruza la medianoche (23:00 -> 02:00).
      current >= start || current < end
}

export interface UpdateGateInput {
  /** Hora LOCAL de la tablet en el instante de decidir. */
  readonly now: Date
  readonly pendingScans: number
  /** Instante del ultimo escaneo conocido por esta tablet. `null` = ninguno: cuenta como «sin escaneo». */
  readonly lastScanAt: Date | null
  readonly window?: UpdateWindow
  readonly quietMinutes?: number
}

/** `true` solo si aplicar la version nueva AHORA no puede dejar a nadie sin fichar. */
export function canApplyUpdate(input: UpdateGateInput): boolean {
  if (input.pendingScans > 0) return false

  const quietMinutes = input.quietMinutes ?? DEFAULT_UPDATE_QUIET_MINUTES
  if (input.lastScanAt !== null) {
    const elapsedMs = input.now.getTime() - input.lastScanAt.getTime()
    // `elapsedMs < 0` = el ultimo escaneo quedo registrado en el FUTURO segun
    // el reloj actual de la tablet: un reloj que se ha adelantado entre medias
    // (RF-AT-10, la tablet no tiene la culpa de perder la hora) o que se ha
    // corregido hacia atras. Tratarlo como «escaneo reciente» dejaria la
    // puerta cerrada PARA SIEMPRE -ningun tiempo futuro real la abriria-, que
    // es justo lo que la regla dura 19 prohibe: un reloj desviado no puede
    // impedir fichar, y tampoco puede impedir actualizar sin fichajes que perder.
    if (elapsedMs >= 0 && elapsedMs < quietMinutes * 60_000) return false
  }

  return isWithinUpdateWindow(input.now, input.window ?? DEFAULT_UPDATE_WINDOW)
}
