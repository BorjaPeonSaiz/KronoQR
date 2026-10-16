// La hora y la ventana de actualizacion se leen en el reloj de pared DEL CENTRO
// (`site_time_zone` del latido), no en la zona de la tablet (V2-KI-01, RF-AT-05,
// RF-KI-07). Todas las pruebas pasan la zona explicita para no depender de la
// zona de la maquina que las ejecuta. La logica de la hora de pared vive en
// `packages/web-kit/tests/unit/datetime.spec.ts`; aqui se prueba lo que hacen
// con ella el saludo, el reloj y la ventana de actualizacion.

import { afterEach, describe, expect, it, vi } from 'vitest'
import type { ApiClient } from '@/shared/api/client'
import type { KioskHeartbeat } from '@/shared/api/types'
import { isWithinUpdateWindow, canApplyUpdate } from '@/features/offline/domain/updateWindow'
import { formatClockTime } from '@/features/scan/domain/clockTime'
import { greetingSlotFor } from '@/features/scan/domain/greeting'
import { readSiteTimeZone, storeSiteTimeZone } from '@/shared/telemetry/deviceIdentity'
import { createErrorReporter } from '@/shared/telemetry/errorReporter'
import { createHeartbeatScheduler } from '@/shared/telemetry/heartbeat'
import { fixedClock } from '@/shared/time/clock'

const CANARIAS = 'Atlantic/Canary'
const MADRID = 'Europe/Madrid'
const SITE_TIME_ZONE_KEY = 'kronoqr.kiosk.site_time_zone'

afterEach(() => {
  localStorage.removeItem(SITE_TIME_ZONE_KEY)
})

describe('RF-AT-05: la hora de la confirmacion es la de la pared del centro', () => {
  it('el mismo instante se lee distinto en Canarias y en Madrid (verano)', () => {
    const instant = new Date('2026-07-15T05:30:00.000Z')
    expect(formatClockTime(instant, 'es', CANARIAS)).toBe('06:30')
    expect(formatClockTime(instant, 'es', MADRID)).toBe('07:30')
  })

  it('y en invierno (WET/CET)', () => {
    const instant = new Date('2026-01-15T05:30:00.000Z')
    expect(formatClockTime(instant, 'en', CANARIAS)).toBe('05:30')
    expect(formatClockTime(instant, 'en', MADRID)).toBe('06:30')
  })

  it('sigue en 24 horas en los dos idiomas con zona', () => {
    const instant = new Date('2026-07-15T17:02:00.000Z')
    expect(formatClockTime(instant, 'es', MADRID)).toBe('19:02')
    expect(formatClockTime(instant, 'en', MADRID)).toBe('19:02')
  })

  it('el dia del cambio de hora salta la que no existe (Madrid, 29-03-2026)', () => {
    expect(formatClockTime(new Date('2026-03-29T00:59:00.000Z'), 'es', MADRID)).toBe('01:59')
    expect(formatClockTime(new Date('2026-03-29T01:00:00.000Z'), 'es', MADRID)).toBe('03:00')
  })

  it('y repite la hora al retrasar (Madrid, 25-10-2026)', () => {
    expect(formatClockTime(new Date('2026-10-25T00:30:00.000Z'), 'es', MADRID)).toBe('02:30')
    expect(formatClockTime(new Date('2026-10-25T01:30:00.000Z'), 'es', MADRID)).toBe('02:30')
  })

  it('sin zona, o con un nombre que Intl no reconoce, usa la de la tablet y pinta igual', () => {
    const instant = new Date('2026-07-15T05:30:00.000Z')
    const device = formatClockTime(instant, 'es')
    expect(formatClockTime(instant, 'es', null)).toBe(device)
    expect(formatClockTime(instant, 'es', 'Marte/Olympus')).toBe(device)
    expect(formatClockTime(instant, 'es', '')).toBe(device)
  })

  it('una fecha invalida sigue sin reventar', () => {
    expect(formatClockTime(new Date('nope'), 'es', MADRID)).toBe('--:--')
  })

  it('el saludo usa la misma pared: 04:30Z es «buenos dias» en Madrid y «noche» en Canarias', () => {
    const instant = new Date('2026-07-15T04:30:00.000Z')
    expect(greetingSlotFor(instant, MADRID)).toBe('morning')
    expect(greetingSlotFor(instant, CANARIAS)).toBe('night')
  })
})

describe('RF-KI-07: la ventana de actualizacion se lee en la pared del centro', () => {
  const window = { start: '06:00', end: '07:00' }

  it('el mismo instante abre la ventana en Canarias y no en Madrid', () => {
    // 05:30Z = 06:30 en Canarias (dentro) y 07:30 en Madrid (fuera).
    const instant = new Date('2026-07-15T05:30:00.000Z')
    expect(isWithinUpdateWindow(instant, window, CANARIAS)).toBe(true)
    expect(isWithinUpdateWindow(instant, window, MADRID)).toBe(false)
  })

  it('una tablet con la zona equivocada no cambia el resultado: manda la zona del centro', () => {
    // Misma lectura que arriba, independiente de la zona del aparato.
    const instant = new Date('2026-07-15T04:00:00.000Z')
    expect(isWithinUpdateWindow(instant, window, MADRID)).toBe(true) // 06:00 en Madrid
    expect(isWithinUpdateWindow(instant, window, CANARIAS)).toBe(false) // 05:00 en Canarias
  })

  it('limites exactos con zona, y ventana que cruza la medianoche', () => {
    expect(isWithinUpdateWindow(new Date('2026-07-15T03:59:00.000Z'), window, MADRID)).toBe(false)
    expect(isWithinUpdateWindow(new Date('2026-07-15T04:00:00.000Z'), window, MADRID)).toBe(true)
    expect(isWithinUpdateWindow(new Date('2026-07-15T04:59:00.000Z'), window, MADRID)).toBe(true)
    expect(isWithinUpdateWindow(new Date('2026-07-15T05:00:00.000Z'), window, MADRID)).toBe(false)

    const night = { start: '23:00', end: '02:00' }
    expect(isWithinUpdateWindow(new Date('2026-07-15T21:30:00.000Z'), night, MADRID)).toBe(true) // 23:30
    expect(isWithinUpdateWindow(new Date('2026-07-14T23:30:00.000Z'), night, MADRID)).toBe(true) // 01:30
    expect(isWithinUpdateWindow(new Date('2026-07-15T00:30:00.000Z'), night, MADRID)).toBe(false) // 02:30
  })

  it('sin zona (o desconocida) lee la pared de la tablet, como antes', () => {
    const instant = new Date(2026, 6, 15, 6, 30, 0) // 06:30 locales del aparato
    expect(isWithinUpdateWindow(instant, window)).toBe(true)
    expect(isWithinUpdateWindow(instant, window, null)).toBe(true)
    expect(isWithinUpdateWindow(instant, window, 'Marte/Olympus')).toBe(true)
  })

  it('dia del cambio de hora (adelanto): una ventana que cae en la hora inexistente no abre', () => {
    // Madrid, 29-03-2026: a las 02:00 locales (01:00Z) pasan a ser las 03:00.
    const missing = { start: '02:00', end: '03:00' }
    expect(isWithinUpdateWindow(new Date('2026-03-29T00:59:00.000Z'), missing, MADRID)).toBe(false) // 01:59
    expect(isWithinUpdateWindow(new Date('2026-03-29T01:00:00.000Z'), missing, MADRID)).toBe(false) // 03:00
    expect(isWithinUpdateWindow(new Date('2026-03-29T01:30:00.000Z'), missing, MADRID)).toBe(false) // 03:30
  })

  it('dia del cambio de hora (adelanto): una ventana que lo cruza abre en cuanto la pared la alcanza', () => {
    const across = { start: '02:30', end: '04:00' }
    expect(isWithinUpdateWindow(new Date('2026-03-29T00:59:00.000Z'), across, MADRID)).toBe(false) // 01:59
    expect(isWithinUpdateWindow(new Date('2026-03-29T01:00:00.000Z'), across, MADRID)).toBe(true) // 03:00
  })

  it('dia del cambio de hora (retraso): la hora repetida se lee dos veces y la ventana abre en las dos', () => {
    // Madrid, 25-10-2026: 02:30 ocurre a las 00:30Z (CEST) y otra vez a las 01:30Z (CET).
    const repeated = { start: '02:15', end: '02:45' }
    expect(isWithinUpdateWindow(new Date('2026-10-25T00:30:00.000Z'), repeated, MADRID)).toBe(true)
    expect(isWithinUpdateWindow(new Date('2026-10-25T01:00:00.000Z'), repeated, MADRID)).toBe(false) // 02:00 CET
    expect(isWithinUpdateWindow(new Date('2026-10-25T01:30:00.000Z'), repeated, MADRID)).toBe(true)
  })

  it('la puerta completa lee la ventana en la zona del centro y sigue exigiendo cola vacia', () => {
    const now = new Date('2026-07-15T05:30:00.000Z')
    const base = { now, lastScanAt: null, window }
    expect(canApplyUpdate({ ...base, pendingScans: 0, timeZone: CANARIAS })).toBe(true)
    expect(canApplyUpdate({ ...base, pendingScans: 0, timeZone: MADRID })).toBe(false)
    expect(canApplyUpdate({ ...base, pendingScans: 1, timeZone: CANARIAS })).toBe(false)
  })

  it('el modo urgente NO consulta la ventana ni la zona (solo cola, silencio e interaccion)', () => {
    const now = new Date('2026-07-15T12:00:00.000Z') // fuera de la franja en cualquier zona
    const base = { now, pendingScans: 0, lastScanAt: null, window, urgent: true }
    expect(canApplyUpdate({ ...base, timeZone: MADRID })).toBe(true)
    expect(canApplyUpdate({ ...base, timeZone: CANARIAS })).toBe(true)
    expect(canApplyUpdate({ ...base, pendingScans: 1, timeZone: MADRID })).toBe(false)
    expect(canApplyUpdate({ ...base, interactionInProgress: true })).toBe(false)
  })
})

describe('deviceIdentity: la zona del centro viaja en el latido y se cachea', () => {
  it('sin latido previo no hay zona: se usa la de la tablet', () => {
    expect(readSiteTimeZone()).toBeNull()
  })

  it('guarda y lee una zona valida', () => {
    storeSiteTimeZone(CANARIAS)
    expect(readSiteTimeZone()).toBe(CANARIAS)
  })

  it('un latido que no la trae conserva la cacheada (el servidor la omite, nunca la manda a null)', () => {
    storeSiteTimeZone(CANARIAS)
    storeSiteTimeZone(undefined)
    expect(readSiteTimeZone()).toBe(CANARIAS)
  })

  it('un nombre que Intl no reconoce borra la cacheada: mejor la zona de la tablet', () => {
    storeSiteTimeZone(CANARIAS)
    storeSiteTimeZone('Marte/Olympus')
    expect(readSiteTimeZone()).toBeNull()
  })

  it('un valor manipulado en localStorage tampoco se usa', () => {
    localStorage.setItem(SITE_TIME_ZONE_KEY, 'Marte/Olympus')
    expect(readSiteTimeZone()).toBeNull()
  })
})

function apiReturning(extra: Record<string, unknown>): ApiClient {
  const data = {
    server_time: '2026-09-23T06:00:00.000Z',
    client_errors_accepted: 0,
    service_code_hash: null,
    break_clocking_enabled: false,
    clock_skew_tolerance_seconds: 900,
    ...extra,
  }
  return {
    recordScan: vi.fn(),
    recordPinScan: vi.fn(),
    syncScanBatch: vi.fn(),
    fetchRoster: vi.fn(),
    sendHeartbeat: vi.fn(async () => ({ outcome: 'ok' as const, data: data as KioskHeartbeat })),
    requestPairing: vi.fn(),
    claimPairing: vi.fn(),
    reportDiscardedScans: vi.fn(),
    fetchBranding: vi.fn(),
  }
}

async function beat(extra: Record<string, unknown>): Promise<void> {
  const scheduler = createHeartbeatScheduler({
    api: apiReturning(extra),
    reporter: createErrorReporter({ appVersion: '2.2.1', deviceId: 'd' }),
    snapshot: () => ({ appVersion: '2.2.1', pendingQueueSize: 0 }),
    clock: fixedClock(new Date('2026-09-23T06:00:00.000Z')),
  })
  await scheduler.beat()
}

describe('latido: site_time_zone (V2-KI-01)', () => {
  it('cachea la zona de cada 200 que la traiga', async () => {
    await beat({ site_time_zone: CANARIAS })
    expect(readSiteTimeZone()).toBe(CANARIAS)
  })

  it('sin `site_time_zone` en la respuesta no toca lo cacheado', async () => {
    storeSiteTimeZone(MADRID)
    await beat({})
    expect(readSiteTimeZone()).toBe(MADRID)
  })

  it('un nombre desconocido se descarta sin romper el latido', async () => {
    storeSiteTimeZone(MADRID)
    await beat({ site_time_zone: 'Marte/Olympus' })
    expect(readSiteTimeZone()).toBeNull()
  })
})
