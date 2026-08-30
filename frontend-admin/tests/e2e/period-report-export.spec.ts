// Descarga del informe de horas en CSV, XLSX y PDF (RF-IN-04).
//
// El backend no participa: lo que se prueba aqui es el recorrido por el panel
// con la API simulada en `support/admin.ts`. El contenido de los ficheros —el
// BOM, el separador, las celdas de texto, el sello del PDF— se prueba en el
// backend, que es donde se escribe; y la autorizacion negativa por rol tambien,
// que es donde vive la policy (regla dura 18).
//
// Lo que si es de este nivel y de ningun otro: que **una persona que ha generado
// un informe puede llevarselo**, y que si no puede se entera.
import { expect, test } from '@playwright/test'
import { logIn, stubManagementApi } from './support/admin'

/** Genera el informe de marzo, que es el paso previo a poder descargarlo. */
async function generateMarchReport(page: import('@playwright/test').Page): Promise<void> {
  await page.goto('/reports')
  await page.getByLabel('Desde').fill('2026-03-01')
  await page.getByLabel('Hasta').fill('2026-03-31')
  await page.getByLabel('Granularidad').selectOption('month')
  await page.getByRole('button', { name: 'Generar informe' }).click()
  await expect(page.getByTestId('report-criteria')).toBeVisible()
}

test(
  'RRHH descarga en XLSX el informe que acaba de consultar',
  { tag: ['@RF-IN-04'] },
  async ({ page }) => {
    const api = await stubManagementApi(page)
    await logIn(page)
    await generateMarchReport(page)

    // LOS BOTONES NO ESTAN ANTES DE GENERAR: no habria nada que descargar, y un
    // boton que lanza la consulta cara por su cuenta es lo que esta pantalla
    // evita a proposito.
    const download = page.waitForEvent('download')

    await page.getByTestId('export-xlsx').click()

    const file = await download

    // El nombre lo pone el `Content-Disposition` del servidor, y NO LLEVA EL
    // NOMBRE DE NADIE: solo el periodo (regla dura 21).
    expect(file.suggestedFilename()).toBe('kronoqr-horas-2026-03-01_2026-03-31.xlsx')
    expect(file.suggestedFilename()).not.toContain('Amrani')

    // La peticion lleva el formato y EL MISMO PERIODO del informe consultado.
    const request = api.requests.find((it) => it.path === '/api/v1/reports/period/export')

    expect(request).toBeDefined()
    expect(request?.query).toContain('format=xlsx')
    expect(request?.query).toContain('from=2026-03-01')
    expect(request?.query).toContain('to=2026-03-31')
    expect(request?.query).toContain('granularity=month')

    // Y con el token de la sesion: un `<a href>` iria sin `Authorization`, y la
    // unica forma de que funcionara seria poner el token en la URL.
    expect(request?.authorization).toMatch(/^Bearer /)
  },
)

test(
  'los tres formatos piden el mismo informe y solo cambian el parametro',
  { tag: ['@RF-IN-04'] },
  async ({ page }) => {
    // Es lo que garantiza que el CSV que alguien adjunta a un correo y el PDF que
    // otra persona imprime son el MISMO informe. Si cada boton construyera su
    // consulta, bastaria un descuido para que dos ficheros del mismo dia
    // discreparan.
    const api = await stubManagementApi(page)
    await logIn(page)
    await generateMarchReport(page)

    for (const format of ['csv', 'xlsx', 'pdf']) {
      const download = page.waitForEvent('download')

      await page.getByTestId(`export-${format}`).click()
      await download
    }

    const exports = api.requests.filter((it) => it.path === '/api/v1/reports/period/export')

    expect(exports).toHaveLength(3)
    expect(exports.map((it) => new URLSearchParams(it.query).get('format'))).toEqual([
      'csv',
      'xlsx',
      'pdf',
    ])

    // Todo lo demas es identico en las tres.
    const withoutFormat = exports.map((it) => {
      const params = new URLSearchParams(it.query)

      params.delete('format')

      return params.toString()
    })

    expect(new Set(withoutFormat).size).toBe(1)
  },
)

test(
  'una descarga denegada se ve en pantalla y deja volver a intentarlo',
  { tag: ['@RF-IN-04', '@RF-ID-03'] },
  async ({ page }) => {
    // Pasa si a alguien le retiran el ambito con la pantalla abierta. El panel
    // tiene que decirlo: un boton que se queda pensando obliga a recargar y a
    // volver a generar el informe, que es la consulta cara.
    await stubManagementApi(page, { exportOutcome: 'forbidden' })
    await logIn(page)
    await generateMarchReport(page)

    await page.getByTestId('export-csv').click()

    await expect(page.getByText('No tienes permiso para esta acción')).toBeVisible()

    // Y el informe sigue en pantalla: lo que fallo fue la descarga, no la
    // consulta.
    await expect(page.getByTestId('report-criteria')).toBeVisible()
    await expect(page.getByTestId('export-csv')).toBeEnabled()
  },
)
