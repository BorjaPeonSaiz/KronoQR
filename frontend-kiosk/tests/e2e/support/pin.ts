// Soporte del E2E del fichaje por PIN (tarea 1.12, RF-AT-11).

import type { Page, Route } from '@playwright/test'
import { expect } from '@playwright/test'
import { pairDevice, stubScanApi } from './kiosk'
import { stubBatchApi } from './offlineQueue'

/**
 * La camara del quiosco decodifica el fixture de video EN CONTINUO (es el
 * mismo que usan `scan.spec.ts`/`offline.spec.ts`): sin esto, cada prueba de
 * PIN acumularia fichajes por QR de fondo contra un `/api/v1/scan` sin
 * mockear, y el indicador de conexion se pondria en «sin conexion» por un
 * fallo de transporte que no tiene nada que ver con lo que se esta probando.
 */
async function stubBackgroundQrTraffic(page: Page): Promise<void> {
  await stubScanApi(page)
  await stubBatchApi(page)
}

/**
 * Clave publica de ejemplo del contrato (`docs/api/openapi.yaml`). Un X25519
 * valido acepta cualquier cadena de 32 bytes como entrada — no hace falta la
 * privada emparejada para que `crypto_box_seal` selle en el navegador: este
 * E2E no abre el sobre, solo comprueba que se sella y viaja.
 */
export const PIN_SEALING_PUBLIC_KEY = '7cXt0m5rXf8mB2mHnV1kQe0k0f5T2xY3rZq8w9AbCdE='

export interface PinKioskStubOptions {
  /** `KioskHeartbeat.break_clocking_enabled` (RF-AT-12, tarea 3.5). Por defecto `false`. */
  readonly breakClockingEnabled?: boolean
  /** `KioskHeartbeat.clock_skew_tolerance_seconds` (RF-AT-10, tarea 3.5). Por defecto 900. */
  readonly clockSkewToleranceSeconds?: number
}

/** El `GET /api/v1/kiosk/roster` de esta instalacion SI ofrece fichaje por PIN. */
export async function stubKioskApiWithPin(
  page: Page,
  options: PinKioskStubOptions = {},
): Promise<void> {
  await pairDevice(page)
  await stubBackgroundQrTraffic(page)
  await page.route('**/api/v1/kiosk/heartbeat', async (route: Route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        server_time: new Date().toISOString(),
        client_errors_accepted: 0,
        break_clocking_enabled: options.breakClockingEnabled ?? false,
        clock_skew_tolerance_seconds: options.clockSkewToleranceSeconds ?? 900,
      }),
    })
  })
  await page.route('**/api/v1/kiosk/roster', async (route: Route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        generated_at: new Date().toISOString(),
        entries: [],
        pin_sealing_public_key: PIN_SEALING_PUBLIC_KEY,
      }),
    })
  })
}

/** Como la anterior, pero la instalacion NO ofrece fichaje por PIN (ADR-017). */
export async function stubKioskApiWithoutPin(
  page: Page,
  options: PinKioskStubOptions = {},
): Promise<void> {
  await pairDevice(page)
  await stubBackgroundQrTraffic(page)
  await page.route('**/api/v1/kiosk/heartbeat', async (route: Route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        server_time: new Date().toISOString(),
        client_errors_accepted: 0,
        break_clocking_enabled: options.breakClockingEnabled ?? false,
        clock_skew_tolerance_seconds: options.clockSkewToleranceSeconds ?? 900,
      }),
    })
  })
  await page.route('**/api/v1/kiosk/roster', async (route: Route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        generated_at: new Date().toISOString(),
        entries: [],
        pin_sealing_public_key: null,
      }),
    })
  })
}

export interface RecordedPinScan {
  readonly scanId: string
  readonly employeeCode: string
  readonly pinSealed: string
  readonly occurredAt: string
  readonly idempotencyKey: string | undefined
  readonly intent: string | undefined
}

export interface PinScanStub {
  readonly recorded: RecordedPinScan[]
}

/** Intercepta `POST /api/v1/scan/pin`. */
export async function stubPinScanApi(
  page: Page,
  // `break_start`/`break_end` (tarea 3.5, ADR-024): mismo criterio que
  // `stubScanApi` en `support/kiosk.ts`.
  outcome: 'clock_in' | 'break_start' | 'break_end' | 'rejected' | 'offline' = 'clock_in',
  /**
   * Retraso artificial antes de contestar (RF-AT-11): la interceptacion de
   * Playwright resuelve en microsegundos, demasiado rapido para observar con
   * fiabilidad la pantalla intermedia «Comprobando…» antes de que se asiente
   * en el desenlace real. Un retraso corto —muy por debajo de
   * `PIN_VERIFY_TIMEOUT_MS`— la deja visible sin caer en la rama del plazo
   * vencido.
   */
  delayMs = 0,
): Promise<PinScanStub> {
  const recorded: RecordedPinScan[] = []

  await page.route('**/api/v1/scan/pin', async (route: Route) => {
    const body = route.request().postDataJSON() as {
      scan_id: string
      employee_code: string
      pin_sealed: string
      occurred_at: string
      intent?: string
    }
    recorded.push({
      scanId: body.scan_id,
      employeeCode: body.employee_code,
      pinSealed: body.pin_sealed,
      occurredAt: body.occurred_at,
      idempotencyKey: route.request().headers()['idempotency-key'],
      intent: body.intent,
    })

    if (delayMs > 0) {
      // eslint-disable-next-line no-restricted-syntax -- el retraso es del servidor simulado, no una espera de la prueba
      await new Promise((resolve) => setTimeout(resolve, delayMs))
    }

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

    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        scan_id: body.scan_id,
        action: outcome,
        employee_display_name: 'Lucia G.',
        work_date: body.occurred_at.slice(0, 10),
        occurred_at: body.occurred_at,
        recorded_at: new Date().toISOString(),
        worked_minutes: 0,
      }),
    })
  })

  return { recorded }
}

/** Teclea el codigo de empleado y pasa al paso del PIN. */
export async function enterEmployeeCode(page: Page, code: string): Promise<void> {
  await page.getByTestId('pin-code-input').fill(code)
  await page.getByTestId('pin-code-continue').click()
}

/** Pulsa cada digito en el teclado numerico dedicado. */
export async function pressPinDigits(page: Page, pin: string): Promise<void> {
  for (const digit of pin) {
    await page.getByRole('button', { name: digit, exact: true }).click()
  }
}

/**
 * Un ciclo completo del teclado del PIN, desde la pantalla de fichaje, hasta
 * el rechazo. No espera a la vuelta automatica tras el rechazo
 * (`CONFIRMATION_DISPLAY_MS.rejected`, 5 s) -eso seria un `sleep`
 * disfrazado-: fuerza la vuelta navegando de nuevo a `/`.
 */
async function attemptRejectedPin(page: Page, employeeCode: string, pin: string): Promise<string> {
  await page.goto('/')
  await page.getByTestId('pin-entry-link').click()
  await enterEmployeeCode(page, employeeCode)
  await pressPinDigits(page, pin)
  await page.getByTestId('pin-confirm').click()
  await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'rejected', {
    timeout: 10_000,
  })
  return (await page.getByTestId('confirmation-headline').textContent()) ?? ''
}

/**
 * Repite `attemptRejectedPin` `times` veces seguidas y devuelve el titular
 * mostrado en cada una, en orden. `pin-lockout.spec.ts` compara esa lista
 * para afirmar que ninguna intentona -tampoco la que en el servidor real
 * coincidiria con un bloqueo ya activo- distingue nada por texto.
 */
export async function consecutiveRejections(
  page: Page,
  times: number,
  employeeCode: string,
  pin: string,
): Promise<string[]> {
  const headlines: string[] = []
  for (let attempt = 0; attempt < times; attempt += 1) {
    headlines.push(await attemptRejectedPin(page, employeeCode, pin))
  }
  return headlines
}
