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
import { readWallClock, resolveTimeZone } from '@kronoqr/web-kit/datetime'
import { getCurrentScope, onScopeDispose, ref, watch } from 'vue'
import type { Ref } from 'vue'
import type { EmployeeWorkDays } from '@/shared/api/types'
import { listMyWorkDays, UNBOUNDED_RANGE } from './workdays.api'
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
   * «Hoy» en la zona del centro, o `null` si todavia no se sabe. Solo lo da el
   * servidor: cuando se le pide el rango por omision, su `to` ES hoy en el
   * centro. Calcularlo aqui usaria el reloj y la zona del navegador (regla dura 3).
   */
  today: Ref<string | null>
  reload: () => Promise<void>
}

function isUnbounded(range: WorkDateRange): boolean {
  return range.from === '' && range.to === ''
}

/**
 * Milisegundos hasta la proxima medianoche en `timeZone`, leyendo la hora de
 * pared de esa zona (y no sumando 24 h, que se equivoca la noche de un cambio
 * de hora). `null` si no hay una zona valida: sin ella no se programa nada.
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

  if (wall === null) {
    return null
  }

  const minutesLeft = (23 - wall.hour) * 60 + (59 - wall.minute) + 1
  // Un segundo de margen para no despertar antes de que el servidor cambie de dia.
  const sinceMinuteStart = nowMs % MINUTE_MS

  return minutesLeft * MINUTE_MS - sinceMinuteStart + 1000
}

function civilDay(nowMs: number, timeZone: string | null | undefined): string | null {
  const wall = readWallClock(nowMs, resolveTimeZone(timeZone))

  return wall === null
    ? null
    : `${wall.year}-${String(wall.month).padStart(2, '0')}-${String(wall.day).padStart(2, '0')}`
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

  /** Pregunta al servidor que dia es hoy en el centro, sin tocar lo que se ve. */
  async function refreshToday(token: number): Promise<void> {
    try {
      const result = await listMyWorkDays(UNBOUNDED_RANGE)

      if (token === latest) {
        today.value = result.to
      }
    } catch {
      // Es solo la tarjeta «Hoy»: si falla, se queda el ultimo dia conocido y
      // el error de la consulta principal, si lo hubo, ya se ha enseñado.
    }
  }

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

      if (isUnbounded(requested)) {
        today.value = result.to
      } else {
        // Con un periodo explicito el servidor no dice que dia es hoy: se le
        // pregunta aparte, para que la tarjeta «Hoy» no dependa del rango.
        await refreshToday(token)
      }
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
      void load()
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
