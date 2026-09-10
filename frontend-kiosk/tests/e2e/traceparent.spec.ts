// La raiz de la traza es el `fetch` de la tablet (doc 02 §8.1, tarea 3.1,
// decision 7 de la ficha).
//
// POR QUE ESTO SOLO SE PUEDE COMPROBAR AQUI. `apiClient.spec.ts` ya prueba que
// el cliente pone la cabecera, y `traceparent.spec.ts` de `web-kit` prueba que
// el identificador tiene la forma del W3C. Ninguna de las dos ve lo que de
// verdad importa: que la peticion que sale del navegador de la tablet -tras el
// service worker, tras el bundle de produccion, tras la cola offline- la lleva.
// Entre el cliente y la red hay codigo, y un `fetch` que se construya en otro
// sitio, o una capa que rehaga la peticion, dejaria la cabecera por el camino
// sin romper ninguna prueba unitaria.
//
// SIN CABECERA NO HAY TRAZA: el backend abre su span de servidor a partir de
// ella, asi que la promesa «seguir el mismo trace_id en Grafana desde el fetch
// hasta el SQL» se cae entera en este punto exacto.
//
// UNA `traceparent` POR PETICION, NO POR DISPOSITIVO. Dos escaneos son dos
// trazas: si compartieran identificador, buscar «el empleado dice que ficho a
// las 07:02» devolveria la jornada entera de la tablet mezclada en un solo
// arbol. La correlacion entre intentos del mismo fichaje la da el `scan_id`,
// nunca la traza.

import { expect, test } from '@playwright/test'
import type { Route } from '@playwright/test'
import { stubKioskApi } from './support/kiosk'

/** `00-<trace-id 16 bytes>-<span-id 8 bytes>-01`, W3C Trace Context. */
const TRACEPARENT = /^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/

interface RecordedRequest {
  readonly traceparent: string | undefined
  readonly scanId: string
}

/** El `trace-id` de una cabecera `traceparent`: el segundo campo. */
function traceIdOf(traceparent: string | undefined): string {
  return (traceparent ?? '').split('-')[1] ?? ''
}

test.beforeEach(async ({ page }) => {
  await stubKioskApi(page)
})

test(
  'el fichaje que sale de la tablet lleva su cabecera traceparent',
  { tag: ['@RF-PD-15', '@RF-KI-02'] },
  async ({ page }) => {
    const recorded: RecordedRequest[] = []

    await page.route('**/api/v1/scan', async (route: Route) => {
      const body = route.request().postDataJSON() as { scan_id: string; occurred_at: string }
      recorded.push({
        traceparent: route.request().headers()['traceparent'],
        scanId: body.scan_id,
      })

      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          scan_id: body.scan_id,
          action: 'clock_in',
          employee_display_name: 'Lucia G.',
          work_date: body.occurred_at.slice(0, 10),
          occurred_at: body.occurred_at,
          recorded_at: new Date().toISOString(),
          worked_minutes: 0,
        }),
      })
    })

    await page.goto('/')

    // El envio va DETRAS de la confirmacion, no delante: la pantalla no espera a
    // la red. Se espera por condicion, nunca por reloj.
    await expect.poll(() => recorded.length).toBeGreaterThan(0)

    expect(recorded[0]?.traceparent).toMatch(TRACEPARENT)
  },
)

test(
  'dos escaneos distintos viajan con trazas distintas',
  { tag: ['@RF-PD-15'] },
  async ({ page }) => {
    const recorded: RecordedRequest[] = []

    await page.route('**/api/v1/scan', async (route: Route) => {
      const body = route.request().postDataJSON() as { scan_id: string; occurred_at: string }
      recorded.push({
        traceparent: route.request().headers()['traceparent'],
        scanId: body.scan_id,
      })

      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          scan_id: body.scan_id,
          action: 'clock_in',
          employee_display_name: 'Lucia G.',
          work_date: body.occurred_at.slice(0, 10),
          occurred_at: body.occurred_at,
          recorded_at: new Date().toISOString(),
          worked_minutes: 0,
        }),
      })
    })

    await page.goto('/')
    await expect.poll(() => recorded.length).toBeGreaterThan(0)

    // La tablet vuelve a arrancar: la camara decodifica otra vez la misma
    // tarjeta y sale un fichaje nuevo, con su `scan_id` nuevo.
    await page.reload()
    await expect.poll(() => recorded.length).toBeGreaterThan(1)

    const [primero, segundo] = recorded

    expect(segundo?.traceparent).toMatch(TRACEPARENT)
    // Dos gestos, dos escaneos, dos trazas.
    expect(primero?.scanId).not.toBe(segundo?.scanId)
    expect(traceIdOf(primero?.traceparent)).not.toBe(traceIdOf(segundo?.traceparent))
  },
)

test(
  'el latido tambien sale con su traza, y no la comparte con el fichaje',
  { tag: ['@RF-PD-15'] },
  async ({ page }) => {
    // El latido y el fichaje salen por el mismo cliente. Si la cabecera se
    // generara una vez y se reutilizara -por sesion, por dispositivo-, el arbol
    // de la traza de un fichaje acabaria colgando de un latido de hace horas.
    const latidos: string[] = []
    const fichajes: string[] = []

    await page.route('**/api/v1/kiosk/heartbeat', async (route: Route) => {
      latidos.push(route.request().headers()['traceparent'] ?? '')

      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ server_time: new Date().toISOString(), client_errors_accepted: 0 }),
      })
    })

    await page.route('**/api/v1/scan', async (route: Route) => {
      const body = route.request().postDataJSON() as { scan_id: string; occurred_at: string }
      fichajes.push(route.request().headers()['traceparent'] ?? '')

      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          scan_id: body.scan_id,
          action: 'clock_in',
          employee_display_name: 'Lucia G.',
          work_date: body.occurred_at.slice(0, 10),
          occurred_at: body.occurred_at,
          recorded_at: new Date().toISOString(),
          worked_minutes: 0,
        }),
      })
    })

    await page.goto('/')

    await expect.poll(() => latidos.length).toBeGreaterThan(0)
    await expect.poll(() => fichajes.length).toBeGreaterThan(0)

    expect(latidos[0]).toMatch(TRACEPARENT)
    expect(traceIdOf(latidos[0])).not.toBe(traceIdOf(fichajes[0]))
  },
)
