// La consulta de mi registro de jornada.
//
// Sin libreria de cache: el portal es una unica pantalla que consulta un unico
// recurso a la vez, y una libreria de consultas completa no aporta nada que un
// `watch` no de ya, a cambio de bytes que un movil con datos propios tiene que
// descargar (doc 02 §11, bundle pequeño).
//
// `isFetching` distingue «cargando por primera vez» de «actualizando lo que ya
// se ve»: al cambiar el rango, la pantalla no tiene por que parpadear a vacio.
//
// **Lo que se ve no se queda viejo** (R1-RV-04, R2-PO-03). Una pestaña de movil
// puede quedarse abierta horas, o pasar la medianoche del centro, y «Hoy» y el
// turno abierto no pueden seguir enseñando el dia anterior. La consulta se
// repite al volver a la pestaña (si hace mas de un minuto o ha cambiado el dia
// en el centro) y a las 00:00 del centro, calculadas con la zona del empleado y
// no con la del navegador (regla dura 3). El dia de «hoy» sigue dandolo el
// servidor: el reloj del telefono solo decide CUANDO volver a preguntar.
//
// **Un error no deja datos viejos a la vista.** Si la consulta falla, `data` se
// vacia: la pantalla enseña el error, no un periodo que ya no es el pedido. Y
// una respuesta tardia de una consulta anterior no pisa a la mas reciente.
import { readWallClock, resolveTimeZone, todayInZone } from '@kronoqr/web-kit/datetime'
import { getCurrentScope, onScopeDispose, ref, watch } from 'vue'
import type { Ref } from 'vue'
import type { EmployeeWorkDays } from '@/shared/api/types'
import { listMyWorkDays } from './workdays.api'
import type { WorkDateRange } from './workdays.api'

/** Volver a la pestaña antes de esto no repite la consulta. */
export const STALE_AFTER_MS = 60_000

const MINUTE_MS = 60_000

export interface UseMyWorkDaysOptions {
  /** Zona del centro del empleado, para saber cuando es medianoche alli. */
  timeZone?: Ref<string | null | undefined>
  /** Reloj de la presentacion (solo decide cuando volver a preguntar). */
  now?: () => number
  document?: Document
}

export interface UseMyWorkDaysResult {
  data: Ref<EmployeeWorkDays | undefined>
  error: Ref<unknown>
  /** Sin ningun resultado todavia, ni el anterior: es la primera carga. */
  isPending: Ref<boolean>
  /** Hay una peticion en curso, con o sin datos previos que enseñar mientras tanto. */
  isFetching: Ref<boolean>
  /**
   * «Hoy» en la zona del centro (`today` de la respuesta), o `null` si todavia no
   * se sabe. Solo lo da el servidor, con cualquier periodo: calcularlo aqui usaria
   * el reloj y la zona del navegador (regla dura 3).
   */
  today: Ref<string | null>
  reload: () => Promise<void>
}

/**
 * Cuanto se desplaza la hora de pared de `zone` respecto a UTC en `instantMs`
 * (milisegundos, con la resolucion del minuto que da `readWallClock`).
 */
function zoneOffsetMs(instantMs: number, zone: string): number | null {
  const wall = readWallClock(instantMs, zone)

  if (wall === null) {
    return null
  }

  const asUtc = Date.UTC(wall.year, wall.month - 1, wall.day, wall.hour, wall.minute)

  return asUtc - (instantMs - (instantMs % MINUTE_MS))
}

/**
 * Milisegundos hasta la proxima medianoche en `timeZone`. Se calcula el
 * INSTANTE objetivo -la 00:00 de pared del dia siguiente- y no una cuenta de
 * minutos de pared: la noche de un cambio de hora dura 23 o 25 horas, y asi la
 * del 29-03-2026 en Madrid, a las 00:30, da 22 h 30 min (no 23 h 30 min). El
 * desfase se lee en el instante objetivo y se corrige una segunda vez, por si
 * el salto cae entre ahora y la medianoche. `null` si no hay una zona valida:
 * sin ella no se programa nada.
 */
export function millisecondsUntilMidnight(
  nowMs: number,
  timeZone: string | null | undefined,
): number | null {
  const zone = resolveTimeZone(timeZone)

  if (zone === undefined) {
    return null
  }

  const wall = readWallClock(nowMs, zone)
  const offsetNow = zoneOffsetMs(nowMs, zone)

  if (wall === null || offsetNow === null) {
    return null
  }

  // La 00:00 de pared del dia siguiente, escrita como si fuera UTC.
  const wallMidnight = Date.UTC(wall.year, wall.month - 1, wall.day + 1)
  let target = wallMidnight - offsetNow

  for (let pass = 0; pass < 2; pass += 1) {
    const offsetAtTarget = zoneOffsetMs(target, zone)

    if (offsetAtTarget === null) {
      return null
    }

    target = wallMidnight - offsetAtTarget
  }

  // Un segundo de margen para no despertar antes de que el servidor cambie de dia.
  return target - nowMs + 1000
}

/** El dia civil de `nowMs` en la zona del centro (nunca la del navegador), o `null`. */
function civilDay(nowMs: number, timeZone: string | null | undefined): string | null {
  const zone = resolveTimeZone(timeZone)

  return zone === undefined ? null : todayInZone(zone, new Date(nowMs))
}

export function useMyWorkDays(
  range: Ref<WorkDateRange>,
  options: UseMyWorkDaysOptions = {},
): UseMyWorkDaysResult {
  const now = options.now ?? Date.now
  const doc = options.document ?? globalThis.document

  const data = ref<EmployeeWorkDays | undefined>(undefined)
  const error = ref<unknown>(null)
  const isPending = ref(true)
  const isFetching = ref(false)
  const today = ref<string | null>(null)

  let latest = 0
  let loadedAt = 0
  let loadedDay: string | null = null
  let midnightTimer: ReturnType<typeof setTimeout> | null = null

  async function load(): Promise<void> {
    latest += 1
    const token = latest

    isFetching.value = true
    error.value = null

    try {
      const requested = { ...range.value }
      const result = await listMyWorkDays(requested)

      if (token !== latest) {
        return
      }

      data.value = result
      loadedAt = now()
      loadedDay = civilDay(loadedAt, options.timeZone?.value)

      // «Hoy» sale de la MISMA respuesta, sea cual sea el periodo pedido: una sola
      // peticion por consulta.
      today.value = result.today
    } catch (caught) {
      if (token !== latest) {
        return
      }

      data.value = undefined
      error.value = caught
    } finally {
      if (token === latest) {
        isFetching.value = false
        isPending.value = false
      }
    }
  }

  function scheduleMidnight(): void {
    if (midnightTimer !== null) {
      clearTimeout(midnightTimer)
      midnightTimer = null
    }

    const wait = millisecondsUntilMidnight(now(), options.timeZone?.value)

    if (wait === null) {
      return
    }

    midnightTimer = setTimeout(() => {
      // Si el reloj de pared aun no ha cambiado de dia en el centro (un salto de
      // hora que el calculo no vio), no se consulta en balde: solo se reprograma.
      const changed = civilDay(now(), options.timeZone?.value) !== loadedDay

      if (changed) {
        void load()
      }

      scheduleMidnight()
    }, wait)
  }

  function onVisible(): void {
    if (doc.visibilityState !== 'visible') {
      return
    }

    const stale = now() - loadedAt >= STALE_AFTER_MS
    const newDay = loadedDay !== null && civilDay(now(), options.timeZone?.value) !== loadedDay

    if (stale || newDay) {
      void load()
    }

    // Un temporizador dormido en segundo plano pudo saltarse la medianoche.
    scheduleMidnight()
  }

  watch(range, () => void load(), { immediate: true, deep: true })

  if (options.timeZone !== undefined) {
    watch(options.timeZone, scheduleMidnight, { immediate: true })
  }

  doc.addEventListener('visibilitychange', onVisible)

  if (getCurrentScope() !== undefined) {
    onScopeDispose(() => {
      doc.removeEventListener('visibilitychange', onVisible)

      if (midnightTimer !== null) {
        clearTimeout(midnightTimer)
      }
    })
  }

  return { data, error, isPending, isFetching, today, reload: load }
}
