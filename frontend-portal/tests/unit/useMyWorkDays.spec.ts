// «Hoy» y el listado no se quedan viejos (R1-RV-04, R2-PO-03).
//
// El reloj solo decide CUANDO volver a preguntar; el dia de hoy lo dice el
// servidor. La medianoche se calcula en la zona del centro, no en la del
// navegador: las pruebas fijan el proceso en una zona que no es ni la del
// centro ni UTC (vitest.config.ts).
import { effectScope, ref } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  millisecondsUntilMidnight,
  STALE_AFTER_MS,
  useMyWorkDays,
} from '@/features/my-records/useMyWorkDays'
import type { WorkDateRange } from '@/features/my-records/workdays.api'
import { employeeWorkDays } from './support/fixtures'
import { jsonResponse, stubFetch } from './support/harness'

const ZONE = 'Europe/Madrid'

afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

beforeEach(() => {
  vi.useFakeTimers({ now: new Date('2026-03-14T10:00:00Z') })
})

function workdaysFor(today: string) {
  return { ...employeeWorkDays(), from: '2026-02-12', to: today, today }
}

async function flush(): Promise<void> {
  await vi.advanceTimersByTimeAsync(0)
}

function start(range: WorkDateRange = { from: '', to: '' }) {
  const scope = effectScope()
  const urls: string[] = []
  let today = '2026-03-14'

  stubFetch((url) => {
    urls.push(url)

    return jsonResponse(workdaysFor(today))
  })

  const result = scope.run(() => useMyWorkDays(ref(range), { timeZone: ref(ZONE) }))

  if (result === undefined) {
    throw new Error('sin ambito')
  }

  return {
    result,
    urls,
    setToday: (value: string) => {
      today = value
    },
    stop: () => scope.stop(),
  }
}

describe('millisecondsUntilMidnight', () => {
  it('cuenta hasta la medianoche del centro, no la del navegador ni la UTC', () => {
    // 22:50 UTC = 23:50 en Madrid (UTC+1): quedan 10 minutos (+1 s de margen).
    const nowMs = Date.parse('2026-03-14T22:50:00Z')

    expect(millisecondsUntilMidnight(nowMs, ZONE)).toBe(10 * 60_000 + 1000)
  })

  it('primavera: la noche del 29-03-2026 en Madrid dura 23 h, asi que a las 00:30 faltan 22 h 30 min', () => {
    // 00:30 (UTC+1) -> 00:00 del dia 30 (UTC+2): la hora 02:xx no existe.
    const nowMs = Date.parse('2026-03-28T23:30:00Z')

    expect(millisecondsUntilMidnight(nowMs, ZONE)).toBe((22 * 60 + 30) * 60_000 + 1000)
  })

  it('otono: la noche del 25-10-2026 en Madrid dura 25 h, asi que a las 00:30 faltan 24 h 30 min', () => {
    // 00:30 (UTC+2) -> 00:00 del dia 26 (UTC+1): la hora 02:xx se repite.
    const nowMs = Date.parse('2026-10-24T22:30:00Z')

    expect(millisecondsUntilMidnight(nowMs, ZONE)).toBe((24 * 60 + 30) * 60_000 + 1000)
  })

  it('despues del salto el calculo vuelve a ser el de un dia normal', () => {
    // 2026-03-29 12:00 (UTC+2) -> 12 h hasta la medianoche.
    const nowMs = Date.parse('2026-03-29T10:00:00Z')

    expect(millisecondsUntilMidnight(nowMs, ZONE)).toBe(12 * 3_600_000 + 1000)
  })

  it('el temporizador de la noche del cambio de primavera dispara a la medianoche real', async () => {
    vi.setSystemTime(new Date('2026-03-28T23:30:00Z'))
    const view = start()

    await flush()
    expect(view.urls).toHaveLength(1)

    view.setToday('2026-03-30')
    // Una hora antes de lo que diria una cuenta de minutos de pared: no hay llamada.
    await vi.advanceTimersByTimeAsync(22 * 3_600_000)
    expect(view.urls).toHaveLength(1)

    await vi.advanceTimersByTimeAsync(30 * 60_000 + 1000)
    expect(view.urls).toHaveLength(2)
    expect(view.result.today.value).toBe('2026-03-30')
    view.stop()
  })

  it('sin zona valida no programa nada', () => {
    expect(millisecondsUntilMidnight(0, null)).toBeNull()
    expect(millisecondsUntilMidnight(0, 'No/Existe')).toBeNull()
  })
})

describe('useMyWorkDays, refresco', () => {
  it('pasada la medianoche del centro vuelve a preguntar y «hoy» cambia de dia', async () => {
    vi.setSystemTime(new Date('2026-03-14T22:50:00Z'))
    const view = start()

    await flush()
    expect(view.result.today.value).toBe('2026-03-14')
    expect(view.urls).toHaveLength(1)

    view.setToday('2026-03-15')
    await vi.advanceTimersByTimeAsync(10 * 60_000 + 1000)

    expect(view.urls).toHaveLength(2)
    expect(view.result.today.value).toBe('2026-03-15')
    view.stop()
  })

  it('al volver a la pestana tras mas de un minuto repite la consulta', async () => {
    const view = start()

    await flush()
    view.setToday('2026-03-14')
    await vi.advanceTimersByTimeAsync(STALE_AFTER_MS + 1)
    document.dispatchEvent(new Event('visibilitychange'))
    await flush()

    expect(view.urls).toHaveLength(2)
    view.stop()
  })

  it('al volver enseguida no repite la consulta', async () => {
    const view = start()

    await flush()
    await vi.advanceTimersByTimeAsync(5000)
    document.dispatchEvent(new Event('visibilitychange'))
    await flush()

    expect(view.urls).toHaveLength(1)
    view.stop()
  })

  it('con un periodo explicito, «hoy» sale de la misma respuesta: una sola peticion', async () => {
    const view = start({ from: '2026-03-01', to: '2026-03-10' })

    await flush()

    expect(view.urls).toEqual(['/api/v1/me/workdays?from=2026-03-01&to=2026-03-10'])
    // `today` no depende del periodo pedido: aqui el periodo termina antes de hoy.
    expect(view.result.today.value).toBe('2026-03-14')
    view.stop()
  })

  it('el refresco de la pestana y el de medianoche hacen una sola peticion cada uno', async () => {
    const view = start({ from: '2026-03-01', to: '2026-03-10' })

    await flush()
    await vi.advanceTimersByTimeAsync(STALE_AFTER_MS + 1)
    document.dispatchEvent(new Event('visibilitychange'))
    await flush()

    expect(view.urls).toHaveLength(2)
    view.stop()
  })

  it('al desmontar deja de escuchar la pestana y de programar la medianoche', async () => {
    const view = start()

    await flush()
    view.stop()
    await vi.advanceTimersByTimeAsync(48 * 3_600_000)
    document.dispatchEvent(new Event('visibilitychange'))
    await flush()

    expect(view.urls).toHaveLength(1)
  })

  it('una respuesta tardia de una consulta anterior no pisa a la mas reciente', async () => {
    const releases: Array<(response: Response) => void> = []

    stubFetch(
      () =>
        new Promise<Response>((resolve) => {
          releases.push(resolve)
        }),
    )

    const range = ref<WorkDateRange>({ from: '', to: '' })
    const scope = effectScope()
    const result = scope.run(() => useMyWorkDays(range, { timeZone: ref(ZONE) }))

    range.value = { from: '2026-01-01', to: '2026-01-02' }
    await flush()

    // Responde primero la segunda y despues, tarde, la primera.
    releases[1]?.(jsonResponse({ ...workdaysFor('2026-01-02'), from: '2026-01-01' }))
    await flush()
    releases[0]?.(jsonResponse(workdaysFor('2026-03-14')))
    await flush()

    expect(result?.data.value?.to).toBe('2026-01-02')
    scope.stop()
  })
})
