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

/**
 * Sin token de dispositivo el guard del router (RF-PD-06, tarea 5.6) manda
 * cualquier navegacion a `/pair`: es la pantalla correcta para una tablet sin
 * vincular, pero ninguno de los E2E de fichaje quiere probar ESO. Se «empareja»
 * la tablet de pruebas con `addInitScript`, que la deja en `localStorage`
 * ANTES de que arranque cualquier script de la pagina — incluida la primera
 * navegacion, que es cuando el guard mira.
 */
export async function pairDevice(page: Page): Promise<void> {
  await page.addInitScript(() => {
    window.localStorage.setItem('kronoqr.kiosk.device_token', 'device-token-e2e')
  })
}

/** El latido no debe ensuciar las trazas ni fallar por no haber servidor. */
export async function stubKioskApi(page: Page): Promise<void> {
  await pairDevice(page)
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
  // Por defecto, la marca del PRODUCTO (RF-PD-08): sin esto, cada prueba que
  // no la necesite dejaria una peticion real sin contestar contra `vite
  // preview`. Quien quiera la de un cliente llama a `stubBrandingApi` DESPUES
  // de esta (Playwright ejecuta el manejador registrado mas recientemente).
  await stubBrandingApi(page)
}

/** El 1x1 transparente de siempre: no hace falta un logotipo de verdad para probar la cache. */
const PNG_1X1_BASE64 =
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='

export interface BrandingStubOptions {
  /**
   * `product`: la marca por defecto (`accent_color`/`logo_url` nulos). `hotel-marina`: el
   * ejemplo del contrato (`docs/api/openapi.yaml`), con acento y logotipo.
   */
  readonly variant?: 'product' | 'hotel-marina'
}

/**
 * Intercepta `GET /api/v1/branding` y `GET /api/v1/branding/logo` (RF-PD-08,
 * tarea 5.8). El backend no participa en el E2E de esta tarea: el contrato
 * completo -incluida la validacion del logotipo al guardar- es de
 * `BrandingEndpointTest` en el backend.
 */
export async function stubBrandingApi(
  page: Page,
  options: BrandingStubOptions = {},
): Promise<void> {
  const variant = options.variant ?? 'product'
  const body =
    variant === 'hotel-marina'
      ? {
          application_name: 'Hotel Marina',
          accent_color: '#0f5c8c',
          logo_url: '/api/v1/branding/logo?v=3f9a1c2b7e4d',
          locales: { default: 'es', available: ['es'] },
        }
      : {
          application_name: 'KronoQR',
          accent_color: null,
          logo_url: null,
          locales: { default: 'es', available: ['es', 'en'] },
        }

  await page.route('**/api/v1/branding', async (route: Route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(body),
    })
  })

  // Regex y no glob: el patron tiene que sobrevivir a la huella `?v=...` que
  // el propio `body.logo_url` publica.
  await page.route(/\/api\/v1\/branding\/logo(\?.*)?$/, async (route: Route) => {
    if (variant !== 'hotel-marina') {
      await route.fulfill({
        status: 404,
        contentType: 'application/problem+json',
        body: JSON.stringify({
          type: 'urn:kronoqr:problem:not-found',
          title: 'No encontrado',
          status: 404,
        }),
      })
      return
    }
    await route.fulfill({
      status: 200,
      contentType: 'image/png',
      headers: {
        'Cache-Control': 'public, max-age=31536000, immutable',
        ETag: '"3f9a1c2b7e4d"',
      },
      body: Buffer.from(PNG_1X1_BASE64, 'base64'),
    })
  })
}
