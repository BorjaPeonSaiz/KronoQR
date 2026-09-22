import { createHash } from 'node:crypto'
import type { Page, Route } from '@playwright/test'

/**
 * Huella del codigo de servicio (RF-KI-08, tarea 3.3, decision 6): SHA-256 hex
 * de `"{device_id}:{codigo}"`, calculada en el proceso de Node de la prueba
 * -no en el navegador-, con la misma formula que `KioskHeartbeat.service_code_hash`.
 */
function serviceCodeHashOf(deviceId: string, code: string): string {
  return createHash('sha256').update(`${deviceId}:${code}`).digest('hex')
}

/** El payload que lleva el QR de `e2e/fixtures/qr-video.y4m`. */
export const FIXTURE_PAYLOAD = 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa'

/**
 * Retrasa `getUserMedia` `delayMs`, para las pruebas que necesitan interactuar
 * con la pantalla (armar el boton «Pausa», tarea 3.5) ANTES de que la camara
 * simulada -que decodifica la tarjeta del video en bucle en cuanto arranca-
 * produzca el primer fichaje. Sin esto, armar «a tiempo» seria una carrera
 * contra la camara; con esto, el boton siempre esta listo antes de que exista
 * nada que decodificar. No toca codigo de produccion: envuelve la funcion
 * nativa del dispositivo falso (`--use-fake-device-for-media-stream`).
 */
export async function delayCameraStart(page: Page, delayMs: number): Promise<void> {
  await page.addInitScript((delay: number) => {
    const original = navigator.mediaDevices.getUserMedia.bind(navigator.mediaDevices)
    navigator.mediaDevices.getUserMedia = (constraints) =>
      new Promise((resolve, reject) => {
        setTimeout(() => {
          original(constraints).then(resolve, reject)
        }, delay)
      })
  }, delayMs)
}

export interface ScanStubOptions {
  /**
   * Desenlace que devuelve el servidor simulado. `break_start` y `break_end`
   * (tarea 3.5, ADR-024): el servidor decide la ACCION, que puede no
   * coincidir con el `intent` que llevo la peticion (`ScanIntentPolicy`) —
   * este doble siempre devuelve la que se le pida, para que la prueba fije lo
   * que le interesa sin montar el backend real.
   */
  readonly outcome?:
    'clock_in' | 'clock_out' | 'break_start' | 'break_end' | 'debounced' | 'rejected' | 'offline'
  readonly displayName?: string
  readonly workedMinutes?: number
}

interface RecordedScan {
  readonly scanId: string
  readonly occurredAt: string
  readonly idempotencyKey: string | undefined
  readonly intent: string | undefined
  /** El payload QR tal y como lo decodifico la camara (`degraded.spec.ts`, `worn.spec.ts`). */
  readonly qrPayload: string | undefined
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
      qr_payload?: string
    }
    recorded.push({
      scanId: body.scan_id,
      occurredAt: body.occurred_at,
      idempotencyKey: route.request().headers()['idempotency-key'],
      intent: body.intent,
      qrPayload: body.qr_payload,
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

/**
 * Codigo de servicio de la pantalla de diagnostico (RF-KI-08, tarea 3.3,
 * decision 6), para las pruebas que necesitan una huella conocida. `deviceId`
 * tiene que fijarse en `localStorage` ANTES de que la aplicacion arranque
 * -por eso `stubKioskApi` lo escribe con `addInitScript`-, o la tablet
 * generaria uno propio y la huella precalculada aqui no coincidiria con la
 * que comprueba `serviceCodeGate.ts`.
 */
export interface ServiceCodeStubOptions {
  readonly deviceId: string
  readonly code: string
}

export interface KioskApiStubOptions {
  /** Ausente = instalacion SIN codigo de servicio (el caso de todas las pruebas que no son de diagnostico). */
  readonly serviceCode?: ServiceCodeStubOptions
  /** `KioskHeartbeat.break_clocking_enabled` (RF-AT-12, tarea 3.5). Por defecto `false`. */
  readonly breakClockingEnabled?: boolean
  /**
   * `KioskHeartbeat.clock_skew_tolerance_seconds` (RF-AT-10, tarea 3.5). Por
   * defecto 900 (`ATTENDANCE_MAX_CLOCK_SKEW_MINUTES=15` del Anexo B del doc
   * 02).
   */
  readonly clockSkewToleranceSeconds?: number
  /**
   * `KioskHeartbeat.server_time` de cada latido. Por defecto la hora REAL del
   * proceso de pruebas. Para simular un reloj de servidor desviado del de la
   * tablet (RF-AT-10) sin tocar el reloj de la pagina, que sigue midiendo el
   * paso real del tiempo entre latidos.
   */
  readonly serverTime?: () => string
}

/** El latido no debe ensuciar las trazas ni fallar por no haber servidor. */
export async function stubKioskApi(page: Page, options: KioskApiStubOptions = {}): Promise<void> {
  await pairDevice(page)

  let serviceCodeHash: string | null = null
  if (options.serviceCode !== undefined) {
    const { deviceId, code } = options.serviceCode
    serviceCodeHash = serviceCodeHashOf(deviceId, code)
    await page.addInitScript((id: string) => {
      window.localStorage.setItem('kronoqr.kiosk.device_id', id)
    }, deviceId)
  }

  const breakClockingEnabled = options.breakClockingEnabled ?? false
  const clockSkewToleranceSeconds = options.clockSkewToleranceSeconds ?? 900

  await page.route('**/api/v1/kiosk/heartbeat', async (route: Route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      // `client_errors_accepted` es obligatorio en el contrato (RF-PD-15,
      // tarea 5.12): `0` porque este doble no inspecciona lo que llego.
      // `service_code_hash` (RF-KI-08, tarea 3.3): `null` salvo que la prueba
      // pida explicitamente un codigo de servicio. `break_clocking_enabled`
      // y `clock_skew_tolerance_seconds` (RF-AT-12, RF-AT-10, tarea 3.5).
      body: JSON.stringify({
        server_time: options.serverTime?.() ?? new Date().toISOString(),
        client_errors_accepted: 0,
        service_code_hash: serviceCodeHash,
        break_clocking_enabled: breakClockingEnabled,
        clock_skew_tolerance_seconds: clockSkewToleranceSeconds,
      }),
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

/** Un `client_errors` tal y como lo manda el latido, ya sin `device_id` (RF-PD-15). */
export interface RecordedHeartbeatCall {
  readonly clientErrors: ReadonlyArray<{
    code: string
    occurred_at: string
    app_version: string
    context: Record<string, unknown>
  }>
}

export interface HeartbeatRecorder {
  readonly calls: RecordedHeartbeatCall[]
}

export interface HeartbeatCaptureOptions {
  /** Cuantos `client_errors` dice aceptar el servidor simulado. Por defecto, todos. */
  readonly accepted?: number
  /**
   * `400`: simula un rechazo de forma (`ValidationProblem`). El quiosco solo
   * vacia el buffer de errores si `invalidFields` nombra `client_errors`
   * (decision 7 de la tarea 5.12, revision del `heartbeat.ts:157-165`): con
   * `400` no basta, hay que decir QUE campo ha fallado, igual que haria el
   * servidor real.
   */
  readonly status?: 200 | 400 | 500
  /**
   * Campos que el `400` simulado declara invalidos, en la forma
   * `ValidationProblem.errors` (RFC 9457: `{ <campo>: string[] }`). Solo
   * aplica con `status: 400`. Por defecto `['client_errors']`: es el caso que
   * la mayoria de las pruebas de este fichero quiere simular. Pasar `[]` (o
   * cualquier otro campo) para el caso contrario: un `400` que NO habla de
   * `client_errors` y por tanto no debe vaciar el buffer.
   */
  readonly invalidFields?: readonly string[]
  /**
   * `server_time` de la respuesta. Por defecto la hora REAL del proceso de
   * pruebas, que no tiene por que coincidir con el reloj -posiblemente
   * simulado con `page.clock`- de la pagina: sin sincronizarlos, cada latido
   * reporta un `kiosk.clock.skew_detected` que ensucia `client_errors` con
   * algo que no tiene nada que ver con lo que la prueba quiere observar.
   */
  readonly serverTime?: () => string
  /** Igual que en `KioskApiStubOptions` (RF-KI-08, tarea 3.3). Ausente = sin codigo. */
  readonly serviceCode?: ServiceCodeStubOptions
  /** Igual que en `KioskApiStubOptions` (RF-AT-12, tarea 3.5). Por defecto `false`. */
  readonly breakClockingEnabled?: boolean
  /** Igual que en `KioskApiStubOptions` (RF-AT-10, tarea 3.5). Por defecto 900. */
  readonly clockSkewToleranceSeconds?: number
}

/**
 * Sustituye la ruta de latido que `stubKioskApi` ya registro (Playwright
 * ejecuta el manejador mas reciente) para poder inspeccionar `client_errors`
 * y decidir la respuesta del servidor (RF-PD-15, tarea 5.12). Llamar SIEMPRE
 * despues de `stubKioskApi`/`stubKioskApiWithPin`.
 */
export async function stubHeartbeatWithErrorCapture(
  page: Page,
  options: HeartbeatCaptureOptions = {},
): Promise<HeartbeatRecorder> {
  const recorder: HeartbeatRecorder = { calls: [] }
  const status = options.status ?? 200
  const serviceCodeHash =
    options.serviceCode === undefined
      ? null
      : serviceCodeHashOf(options.serviceCode.deviceId, options.serviceCode.code)

  await page.route('**/api/v1/kiosk/heartbeat', async (route: Route) => {
    const body = route.request().postDataJSON() as {
      client_errors?: RecordedHeartbeatCall['clientErrors']
    }
    const clientErrors = body.client_errors ?? []
    recorder.calls.push({ clientErrors })

    if (status === 400) {
      const invalidFields = options.invalidFields ?? ['client_errors']
      await route.fulfill({
        status,
        contentType: 'application/problem+json',
        body: JSON.stringify({
          type: 'urn:kronoqr:problem:invalid-request',
          title: 'Peticion invalida',
          status,
          detail: 'La peticion no cumple el contrato.',
          errors: Object.fromEntries(invalidFields.map((field) => [field, ['Formato invalido.']])),
        }),
      })
      return
    }

    if (status !== 200) {
      await route.fulfill({
        status,
        contentType: 'application/problem+json',
        body: JSON.stringify({
          type: 'urn:kronoqr:problem:server-error',
          title: 'Error del servidor',
          status,
        }),
      })
      return
    }

    // Nunca mas de lo que ESTA peticion trajo: un servidor de verdad no puede
    // decir que ha persistido un error que no le han enviado. Sin este tope,
    // un `accepted` fijo pisaria -por `acknowledge(n)`- un error que llegara
    // al buffer del cliente MIENTRAS este latido estaba en el aire, sin que
    // ese error hubiera viajado nunca.
    const accepted = Math.min(options.accepted ?? clientErrors.length, clientErrors.length)
    const serverTime = options.serverTime?.() ?? new Date().toISOString()
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        server_time: serverTime,
        client_errors_accepted: accepted,
        service_code_hash: serviceCodeHash,
        break_clocking_enabled: options.breakClockingEnabled ?? false,
        clock_skew_tolerance_seconds: options.clockSkewToleranceSeconds ?? 900,
      }),
    })
  })

  return recorder
}
