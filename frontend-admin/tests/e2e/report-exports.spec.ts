// Exportaciones de informes en segundo plano (RF-IN-06, tarea 3.9): informes
// que no caben en una respuesta al momento se generan en cola, con un enlace
// de descarga caducable y de un solo uso (ADR-041).
//
// El backend no participa: aquí se prueba el recorrido por el panel con la
// API simulada en `support/admin.ts`. Que el enlace caduque de verdad en el
// servidor y la autorización negativa por rol se prueban en el backend
// (regla dura 18).
import { expect, test } from '@playwright/test'
import { logIn, stubManagementApi } from './support/admin'

test(
  'un informe demasiado grande se genera en segundo plano, se ve en curso, termina y se descarga; ' +
    'el enlace usado dos veces da 410 y la pantalla lo explica',
  { tag: ['@RF-IN-06'] },
  async ({ page }) => {
    const api = await stubManagementApi(page, { periodReportOutcome: 'tooLarge' })

    await logIn(page)
    await page.goto('/reports')

    await page.getByLabel('Desde').fill('2026-01-01')
    await page.getByLabel('Hasta').fill('2026-06-30')
    await page.getByRole('button', { name: 'Generar informe' }).click()

    // El 422 de RNF-P-05/RF-IN-06 ofrece generar en segundo plano, con el
    // formato elegido (CSV por omisión: la consulta no tiene formato propio).
    const offer = page.getByTestId('oversized-offer')
    await expect(offer).toBeVisible()
    await expect(offer).toContainText('segundo plano')

    await page.getByTestId('request-background').click()

    await expect(page.getByTestId('background-requested')).toBeVisible()

    // La fila creada aparece en el bloque de exportaciones EN EL ACTO -sin
    // esperar al primer sondeo- y termina sola, con la progresión real del
    // doble.
    const statusBadge = page.locator('[data-test^="status-"]').first()
    const testId = await statusBadge.getAttribute('data-test')
    const uuid = testId?.replace('status-', '') ?? ''

    expect(uuid).not.toBe('')

    await expect(statusBadge).toHaveText('Lista para descargar', { timeout: 15_000 })

    const download = page.waitForEvent('download')
    await page.getByTestId(`download-${uuid}`).click()
    const file = await download

    expect(file.suggestedFilename()).toMatch(/\.csv$/)

    // El enlace que se acaba de usar: reutilizarlo directamente da 410
    // (decisión 3 de la ficha, ADR-041). Se recupera de las peticiones que
    // registró el doble, tal y como salió del panel.
    const usedDownload = [...api.requests]
      .reverse()
      .find(
        (request) =>
          request.method === 'GET' && request.path.endsWith(`/reports/exports/${uuid}/download`),
      )

    expect(usedDownload).toBeDefined()

    // `page.request` es un cliente aparte que NO pasa por `page.route`: la
    // repeticion se hace con `fetch` DENTRO de la pagina, que es donde vive
    // el doble de `stubManagementApi`.
    const usedUrl = new URL(`${usedDownload?.path}?${usedDownload?.query}`, page.url())
    const replayStatus = await page.evaluate(
      async (url) => (await fetch(url)).status,
      usedUrl.toString(),
    )

    expect(replayStatus).toBe(410)

    // Y LA PANTALLA LO EXPLICA: se fuerza que el SIGUIENTE intento del propio
    // panel reciba ese mismo 410 -como si el enlace se hubiera caducado justo
    // entre pedir el estado y abrirlo-, y se comprueba que lo cuenta sin
    // dejar el botón bloqueado.
    await page.route(
      '**/reports/exports/*/download*',
      async (route) => {
        await route.fulfill({
          status: 410,
          contentType: 'application/problem+json',
          body: JSON.stringify({
            type: 'urn:kronoqr:problem:report-export-link-used',
            title: 'El enlace de descarga ya no vale',
            status: 410,
          }),
        })
      },
      { times: 1 },
    )

    await page.getByTestId(`download-${uuid}`).click()

    await expect(page.getByTestId('download-error')).toBeVisible()
    await expect(page.getByTestId(`download-${uuid}`)).toBeEnabled()

    // Y, sin la interceptación de arriba, volver a pulsar pide otro enlace y
    // funciona: "si el usuario vuelve a pulsar, se pide otro" (decisión 8).
    const secondDownload = page.waitForEvent('download')
    await page.getByTestId(`download-${uuid}`).click()
    await secondDownload
  },
)
