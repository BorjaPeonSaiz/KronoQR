// El token del quiosco dura 90 dias y rota solo (RF-ID-04, ADR-044).
//
// Un servidor simulado con la MISMA regla que el real (umbral del 80 %, solape
// de 24 h, primer uso del nuevo retira al anterior) y el reloj de la pagina
// adelantado mas de 170 dias: el quiosco tiene que seguir autenticado y
// sincronizando cuando el token con el que se empareja ya habria caducado dos
// veces. Sin la rotacion, el dia 90 todas las peticiones daban 401 y los
// fichajes se quedaban en la cola (F1-1 de la verificacion de la 2.1.0).

import { expect, test } from '@playwright/test'
import type { Route } from '@playwright/test'
import { stubKioskApi } from './support/kiosk'
import { announceOnline, queueStoreReady, readQueue, seedQueue } from './support/offlineQueue'

const DAY_MS = 86_400_000
const MINUTE_MS = 60_000
const TOKEN_DAYS = 90
const ROTATION_THRESHOLD = 0.8
const OVERLAP_MS = DAY_MS
/** El que escribe `pairDevice` de `support/kiosk.ts`. */
const PAIRED_TOKEN = 'device-token-e2e'

interface IssuedToken {
  readonly value: string
  readonly expiresAt: number
  readonly issuedAt: number
  /** Si hay un token mas reciente, este vale hasta aqui como mucho. */
  overlapUntil: number | null
  retired: boolean
  used: boolean
}

/** El servidor de tokens: lo justo de `DeviceTokenRotationPolicy` y `SanctumDeviceTokenIssuer`. */
function createTokenServer(start: number): {
  now: () => number
  setNow: (value: number) => void
  authorize: (authorization: string | undefined) => IssuedToken | null
  rotateIfDue: (signer: IssuedToken) => { value: string; expires_at: string } | undefined
  readonly issued: IssuedToken[]
} {
  let now = start
  const issued: IssuedToken[] = [
    {
      value: PAIRED_TOKEN,
      issuedAt: start,
      expiresAt: start + TOKEN_DAYS * DAY_MS,
      overlapUntil: null,
      retired: false,
      used: false,
    },
  ]

  function latest(): IssuedToken | undefined {
    return issued.filter((token) => !token.retired).at(-1)
  }

  return {
    issued,
    now: () => now,
    setNow: (value) => {
      now = value
    },

    authorize(authorization) {
      const token = issued.find((candidate) => `Bearer ${candidate.value}` === authorization)
      if (token === undefined || token.retired || now >= token.expiresAt) return null
      if (token.overlapUntil !== null && now >= token.overlapUntil) return null

      // Primer uso del nuevo: el solape termina y los anteriores se retiran.
      if (!token.used) {
        token.used = true
        for (const older of issued) {
          if (older.issuedAt < token.issuedAt) older.retired = true
        }
      }
      return token
    },

    rotateIfDue(signer) {
      if (signer !== latest()) return undefined
      const lifetime = signer.expiresAt - signer.issuedAt
      if (now - signer.issuedAt < lifetime * ROTATION_THRESHOLD) return undefined

      const relief: IssuedToken = {
        value: `${90 + issued.length}|token-rotado-${issued.length}`,
        issuedAt: now,
        expiresAt: now + TOKEN_DAYS * DAY_MS,
        overlapUntil: null,
        retired: false,
        used: false,
      }
      signer.overlapUntil = Math.min(signer.expiresAt, now + OVERLAP_MS)
      issued.push(relief)
      return { value: relief.value, expires_at: new Date(relief.expiresAt).toISOString() }
    },
  }
}

function unauthorized(): { status: 401; contentType: string; body: string } {
  return {
    status: 401,
    contentType: 'application/problem+json',
    body: JSON.stringify({ type: 'urn:kronoqr:problem:unauthenticated', title: 'No autenticado' }),
  }
}

test(
  'a los 170 dias simulados, con dos rotaciones, el quiosco sigue autenticado y sincronizando',
  { tag: ['@RF-ID-04', '@RF-KI-03', '@RQ-05'] },
  async ({ page }) => {
    test.setTimeout(150_000)
    const start = Date.parse('2026-10-01T05:00:00.000Z')
    const server = createTokenServer(start)
    let unauthorizedResponses = 0
    let heartbeats = 0
    const accepted: string[] = []

    await stubKioskApi(page)
    await page.clock.install({ time: new Date(start) })

    await page.route('**/api/v1/kiosk/heartbeat', async (route: Route) => {
      const signer = server.authorize(route.request().headers()['authorization'])
      if (signer === null) {
        unauthorizedResponses += 1
        await route.fulfill(unauthorized())
        return
      }
      heartbeats += 1
      const rotated = server.rotateIfDue(signer)
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          server_time: new Date(server.now()).toISOString(),
          client_errors_accepted: 0,
          service_code_hash: null,
          break_clocking_enabled: false,
          clock_skew_tolerance_seconds: 900,
          ...(rotated === undefined ? {} : { rotated_token: rotated }),
        }),
      })
    })
    // El envio individual no llega: todo se encola y viaja por lote, con token.
    await page.route('**/api/v1/scan', async (route) => route.abort('failed'))
    await page.route('**/api/v1/scan/batch', async (route: Route) => {
      if (server.authorize(route.request().headers()['authorization']) === null) {
        unauthorizedResponses += 1
        await route.fulfill(unauthorized())
        return
      }
      const body = route.request().postDataJSON() as {
        scans: Array<{ scan_id: string; occurred_at: string }>
      }
      accepted.push(...body.scans.map((item) => item.scan_id))
      await route.fulfill({
        status: 207,
        contentType: 'application/json',
        body: JSON.stringify({
          results: body.scans.map((item) => ({
            scan_id: item.scan_id,
            status: 200,
            outcome: {
              scan_id: item.scan_id,
              action: 'clock_in',
              employee_display_name: 'Lucia G.',
              work_date: item.occurred_at.slice(0, 10),
              occurred_at: item.occurred_at,
              recorded_at: new Date(server.now()).toISOString(),
              worked_minutes: 0,
            },
          })),
        }),
      })
    })

    await page.goto('/')
    await expect(page.getByTestId('scan-confirmation')).toBeVisible()
    await expect.poll(() => heartbeats).toBeGreaterThan(0)

    // Los latidos van en serie: si el salto cae mientras el anterior aun esta
    // procesando su respuesta, ese tic se absorbe (como en la tablet, donde el
    // siguiente llega un minuto despues). Se reproduce dando ese minuto.
    async function nextHeartbeat(before: number): Promise<void> {
      for (let attempt = 0; attempt < 8; attempt += 1) {
        try {
          await expect.poll(() => heartbeats, { timeout: 2_500 }).toBeGreaterThan(before)
          return
        } catch {
          server.setNow(server.now() + MINUTE_MS)
          await page.clock.fastForward(MINUTE_MS)
        }
      }
      throw new Error('el quiosco dejo de latir')
    }

    // El `200` del latido llega al servidor simulado ANTES de que el cliente
    // termine de guardar el relevo (re-cifra el padron y escribe el token). Si el
    // reloj saltara 3 dias en ese hueco, un envio en vuelo firmado aun con el
    // token anterior caeria ya fuera del solape de 24 h y recibiria el 401 que
    // ADR-044 preve (y que el cliente recupera repitiendo con el vigente): no es
    // lo que esta prueba mide, que es 170 dias sin ninguna revocacion. Se espera
    // a que el quiosco haya adoptado el ultimo relevo entregado.
    async function adopted(): Promise<void> {
      const latest = server.issued.at(-1)?.value
      await expect
        .poll(() => page.evaluate(() => window.localStorage.getItem('kronoqr.kiosk.device_token')))
        .toBe(latest)
    }

    // 170 dias en saltos de 3: cada salto dispara UN latido (el temporizador
    // vencido se ejecuta una vez), que es lo que hace un quiosco que se despierta.
    const STEP_DAYS = 3
    for (let day = STEP_DAYS; day <= 170; day += STEP_DAYS) {
      const before = heartbeats
      server.setNow(start + day * DAY_MS)
      await page.clock.fastForward(STEP_DAYS * DAY_MS)
      await nextHeartbeat(before)
      await adopted()
    }

    // Dos relevos entregados y ningun 401 en 170 dias.
    expect(server.issued.length).toBe(3)
    expect(unauthorizedResponses).toBe(0)

    // El quiosco usa el ULTIMO token y ya no el del emparejamiento (caducado el dia 90).
    const latest = server.issued.at(-1)
    expect(
      await page.evaluate(() => window.localStorage.getItem('kronoqr.kiosk.device_token')),
    ).toBe(latest?.value)
    expect(server.now()).toBeGreaterThan(server.issued[0]?.expiresAt ?? 0)
    expect(server.now()).toBeGreaterThan(server.issued[1]?.expiresAt ?? 0)

    // Sigue sincronizando: un fichaje encolado sale con el token vigente y
    // desaparece de la cola solo cuando el servidor lo confirma.
    await expect.poll(() => queueStoreReady(page)).toBe(true)
    const scanId = '0199f13a-7c22-7b41-9e88-0c4d5e6f7a82'
    await seedQueue(page, [
      {
        scan_id: scanId,
        occurred_at: new Date(server.now()).toISOString(),
        qr_payload: 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa',
      },
    ])
    await announceOnline(page)
    await expect.poll(() => accepted.includes(scanId)).toBe(true)
    await expect
      .poll(async () => (await readQueue(page)).some((row) => row.scan_id === scanId))
      .toBe(false)

    expect(unauthorizedResponses).toBe(0)
    expect(page.url()).not.toContain('/pair')
  },
)
