import type { Page, Route } from '@playwright/test'

/** El payload que lleva el QR de `e2e/fixtures/qr-video.y4m`. */
export const FIXTURE_PAYLOAD = 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa'

export interface ScanStubOptions {
  /** Desenlace que devuelve el servidor simulado. */
  readonly outcome?: 'clock_in' | 'clock_out' | 'debounced' | 'rejected' | 'offline'
  readonly displayName?: string
  readonly workedMinutes?: number
}

interface RecordedScan {
  readonly scanId: string
  readonly occurredAt: string
  readonly idempotencyKey: string | undefined
  readonly intent: string | undefined
}

export interface ScanStub {
  readonly recorded: RecordedScan[]
}

/**
 * Intercepta `POST /api/v1/scan`. El backend no participa en el E2E de esta
 * tarea: lo que se prueba aqui es la pantalla del quiosco. El ciclo completo
 * contra el servidor —cola offline, reconexion y consolidacion con el
 * `occurred_at` original— es de la tarea 1.9.
 */
export async function stubScanApi(page: Page, options: ScanStubOptions = {}): Promise<ScanStub> {
  const recorded: RecordedScan[] = []
  const outcome = options.outcome ?? 'clock_in'
  const displayName = options.displayName ?? 'Lucia G.'
  const workedMinutes = options.workedMinutes ?? 0

  await page.route('**/api/v1/scan', async (route: Route) => {
    const body = route.request().postDataJSON() as {
      scan_id: string
      occurred_at: string
      intent?: string
    }
    recorded.push({
      scanId: body.scan_id,
      occurredAt: body.occurred_at,
      idempotencyKey: route.request().headers()['idempotency-key'],
      intent: body.intent,
    })

    if (outcome === 'offline') {
      await route.abort('failed')
      return
    }

    if (outcome === 'rejected') {
      await route.fulfill({
        status: 422,
        contentType: 'application/problem+json',
        body: JSON.stringify({
          type: 'urn:kronoqr:problem:scan-rejected',
          title: 'Escaneo no valido',
          status: 422,
          detail: 'El escaneo no se ha podido registrar.',
          scan_id: body.scan_id,
        }),
      })
      return
    }

    if (outcome === 'debounced') {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          scan_id: body.scan_id,
          action: 'debounced',
          employee_display_name: displayName,
          occurred_at: body.occurred_at,
          recorded_at: new Date().toISOString(),
          worked_minutes: workedMinutes,
          last_accepted_at: new Date(Date.now() - 20_000).toISOString(),
        }),
      })
      return
    }

    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        scan_id: body.scan_id,
        action: outcome,
        employee_display_name: displayName,
        work_date: body.occurred_at.slice(0, 10),
        occurred_at: body.occurred_at,
        recorded_at: new Date().toISOString(),
        worked_minutes: workedMinutes,
      }),
    })
  })

  return { recorded }
}

/** El latido no debe ensuciar las trazas ni fallar por no haber servidor. */
export async function stubKioskApi(page: Page): Promise<void> {
  await page.route('**/api/v1/kiosk/heartbeat', async (route: Route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ server_time: new Date().toISOString() }),
    })
  })
  await page.route('**/api/v1/kiosk/roster', async (route: Route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ generated_at: new Date().toISOString(), entries: [] }),
    })
  })
}
