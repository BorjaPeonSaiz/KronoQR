// Mi registro de jornada (RL-05, RF-ID-05, art. 34.9 ET).
//
// Lo que se comprueba es lo que le pasa a una persona: entra y ve sus propias
// jornadas -nunca las de otra persona, aunque manipule la URL-, con un
// resumen legible arriba y el detalle de tramos y correcciones debajo. El
// backend no participa: aqui se prueba el recorrido por el portal con la API
// simulada en `support/portal.ts`. La autorizacion real -que el token de
// portal solo alcance `self:read` y ningun `uuid` de tercero- se prueba en el
// backend (regla dura 18); lo que SI es de este nivel es que el cliente
// nunca pide ni construye una URL con un identificador que no sea el propio.
import { expect, test } from '@playwright/test'
import type { Route } from '@playwright/test'
import { EMPLOYEE_UUID, logInToPortal, stubPortalApi } from './support/portal'

test(
  've sus jornadas con el total en horas y minutos, nunca en decimal',
  { tag: ['@RL-05', '@RF-ID-05'] },
  async ({ page }) => {
    await stubPortalApi(page, { locale: 'es' })
    await logInToPortal(page)

    await expect(page.getByTestId('workday')).toHaveCount(6)

    const firstDay = page.getByTestId('workday').first()
    await expect(firstDay.getByTestId('day-total')).toHaveText('8 h 00 min')
    // Nunca un numero decimal ambiguo como "8,08 h" o "8.08".
    await expect(firstDay.getByTestId('day-total')).not.toHaveText(/[.,]\d/)
  },
)

test(
  'la jornada corregida enseña el tramo vigente y el historial con lo que decia antes',
  { tag: ['@RL-05', '@RF-ID-05', '@RN-13', '@RL-04'] },
  async ({ page }) => {
    await stubPortalApi(page, { locale: 'es' })
    await logInToPortal(page)

    const correctedCard = page
      .getByTestId('workday')
      .filter({ has: page.getByTestId('correction') })

    await expect(correctedCard).toHaveCount(1)

    // El tramo vigente ya refleja la correccion (salida a las 14:05 local).
    await expect(correctedCard.getByTestId('entry-duration')).toHaveText('8 h 05 min')
    await expect(correctedCard.getByTestId('summed-total')).toHaveText('8 h 05 min')
    // Los dos totales cuadran: no hay banda de aviso de descuadre.
    await expect(correctedCard.getByTestId('totals-mismatch')).not.toBeVisible()

    // El historial cuenta que RRHH cerro un turno al que le faltaba la salida.
    const history = correctedCard.getByTestId('correction')
    await expect(history).toContainText('Turno cerrado')
    await expect(history).toContainText('Olvido de fichaje de salida')
    await expect(history).toContainText('Cuenta de RRHH')

    // Nada se ha borrado ni se ha sustituido en silencio: el valor anterior
    // ("Sin salida", el turno seguia abierto) sigue legible junto al nuevo.
    await expect(history).toContainText('Sin salida')
  },
)

test(
  'el turno todavia abierto se marca como tal y no como un dato definitivo',
  { tag: ['@RL-05', '@RF-ID-05'] },
  async ({ page }) => {
    await stubPortalApi(page, { locale: 'es' })
    await logInToPortal(page)

    const openCard = page.getByTestId('workday').filter({ hasText: 'Turno abierto' })

    await expect(openCard).toHaveCount(1)
    await expect(openCard.getByTestId('flag-open-shift')).toBeVisible()
    await expect(openCard).toContainText(/todavía no has fichado la salida/i)
  },
)

test(
  'los dos totales de un dia se muestran juntos cuando no cuadran, sin elegir ninguno',
  { tag: ['@RL-05', '@RF-ID-05'] },
  async ({ page }) => {
    await stubPortalApi(page, { locale: 'es' })

    // Un dia con un descuadre real (RN-06): la suma de los tramos vigentes no
    // coincide con lo que declara `daily_totals`. Se registra DESPUES de
    // `stubPortalApi` para que esta ruta, mas especifica, gane a la generica.
    await page.route('**/api/v1/me/workdays', async (route: Route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          employee_uuid: EMPLOYEE_UUID,
          time_zone: 'Europe/Madrid',
          from: '2026-03-09',
          to: '2026-03-09',
          data: [
            {
              work_date: '2026-03-09',
              time_zone: 'Europe/Madrid',
              total_minutes: 500,
              shift_count: 1,
              has_open_shift: false,
              has_incident: true,
              recalculated_at: '2026-03-09T14:00:00.000000Z',
              shift_entries: [
                {
                  uuid: '0199f2c1-8a10-7b40-9c50-6d7e8f9a0b30',
                  version: 1,
                  status: 'closed',
                  time_zone: 'Europe/Madrid',
                  clocked_in_at: '2026-03-09T06:00:00.000000Z',
                  clocked_in_at_local: '2026-03-09T07:00:00.000000+01:00',
                  clocked_in_recorded_at: '2026-03-09T06:00:00.000000Z',
                  clock_in_source: 'qr_kiosk',
                  clocked_out_at: '2026-03-09T14:00:00.000000Z',
                  clocked_out_at_local: '2026-03-09T15:00:00.000000+01:00',
                  clocked_out_recorded_at: '2026-03-09T14:00:00.000000Z',
                  clock_out_source: 'qr_kiosk',
                  duration_minutes: 480,
                  recorded_at: '2026-03-09T14:00:00.000000Z',
                },
              ],
              corrections: [],
              incidents: [],
            },
          ],
          meta: { total: 1 },
        }),
      })
    })

    await logInToPortal(page)

    const day = page.getByTestId('workday')
    await expect(day.getByTestId('totals-mismatch')).toBeVisible()
    await expect(day.getByTestId('summed-total')).toHaveText('8 h 00 min')
    await expect(day.getByTestId('declared-total')).toHaveText('8 h 20 min')
    await expect(day).toContainText('avisa a Recursos Humanos')
    // Y la incidencia abierta se dice, no se esconde detras de un numero.
    await expect(day.getByTestId('flag-incident')).toBeVisible()
  },
)

test(
  'cambiar el periodo consultado pide de nuevo el servidor y actualiza lo que se ve',
  { tag: ['@RL-05', '@RF-ID-05'] },
  async ({ page }) => {
    const api = await stubPortalApi(page, { locale: 'es' })
    await logInToPortal(page)

    await page.locator('input[type="date"]').first().fill('2026-03-09')
    await page.locator('input[type="date"]').last().fill('2026-03-12')
    await page.getByRole('button', { name: 'Consultar' }).click()

    await expect(page.getByTestId('resolved-range')).toContainText(
      'Jornadas del 2026-03-09 al 2026-03-12',
    )
    await expect(page.getByTestId('workday')).toHaveCount(3)

    const request = api.requests.find(
      (it) =>
        it.method === 'GET' &&
        it.path === '/api/v1/me/workdays' &&
        it.query.includes('to=2026-03-12'),
    )
    expect(request?.query).toContain('from=2026-03-09')
  },
)

test(
  'manipular la URL con el identificador de otra persona no cambia lo que se pide ni lo que se muestra',
  { tag: ['@RL-05', '@RF-ID-07'] },
  async ({ page }) => {
    const api = await stubPortalApi(page, { locale: 'es' })
    await logInToPortal(page)

    const OTHER_UUID = '0199f0c2-2222-7c3e-9b21-4d5e6f7a8bff'

    // No hay ningun campo en esta pantalla que acepte un identificador de
    // tercero: intentarlo por la URL es la unica superficie que queda, y no
    // existe ninguna ruta del portal que la lea.
    await page.goto(`/records?employee_uuid=${OTHER_UUID}&uuid=${OTHER_UUID}`)

    // Sigue viendo su propio registro, con su propio nombre.
    await expect(page.getByRole('heading', { level: 1, name: 'Mi registro horario' })).toBeVisible()
    await expect(page.getByText('Youssef Amrani', { exact: true })).toBeVisible()
    await expect(page.getByTestId('workday')).toHaveCount(6)

    // Y la peticion al servidor nunca llevo ese identificador: el empleado se
    // resuelve del token, no de nada que venga en la URL (regla dura 18).
    const workdaysRequests = api.requests.filter((it) => it.path === '/api/v1/me/workdays')
    expect(workdaysRequests.length).toBeGreaterThan(0)
    for (const request of workdaysRequests) {
      expect(request.query).not.toContain(OTHER_UUID)
      expect(request.path).not.toContain(OTHER_UUID)
    }
  },
)

test(
  'una ruta con forma de gestion (con un uuid en la propia URL) no existe en el portal',
  { tag: ['@RL-05', '@RF-ID-07'] },
  async ({ page }) => {
    await stubPortalApi(page, { locale: 'es' })
    await logInToPortal(page)

    await page.goto(`/employees/${EMPLOYEE_UUID}/workdays`)

    // El portal no tiene esa ruta -es del panel, con otro token y otro
    // ambito-: cae en el rescate de «pagina no encontrada», nunca en un
    // registro ajeno.
    await expect(page.getByRole('heading', { name: 'Esta página no existe' })).toBeVisible()
  },
)
