// Salida a nómina (RF-IN-07, tarea 3.9): el formato del fichero es
// configuración de la instalación (`PAYROLL_EXPORT_*`, ADR-017), no un
// parámetro que se elija al descargar.
//
// El backend no participa: aquí se prueba el recorrido por el panel con la
// API simulada en `support/admin.ts`. Que el servidor de verdad aplique el
// separador y las etiquetas al escribir el fichero se prueba en el backend
// (regla dura 18); lo que se prueba aquí es que cambiar el ajuste en el panel
// cambia lo que se descarga después, y que un rol sin `reports:*` no llega a
// la pestaña.
import type { Download } from '@playwright/test'
import { expect, test } from '@playwright/test'
import { logInAsAdmin, logInAsManager, stubManagementApi } from './support/admin'

/** El texto del fichero descargado, sin volcarlo a disco. */
async function readDownloadText(download: Download): Promise<string> {
  const stream = await download.createReadStream()

  if (stream === null) {
    throw new Error('La descarga no tiene contenido que leer.')
  }

  const chunks: Buffer[] = []

  return new Promise((resolve, reject) => {
    stream.on('data', (chunk: Buffer) => chunks.push(chunk))
    stream.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')))
    stream.on('error', reject)
  })
}

test(
  'un responsable de departamento no ve «Nómina» en la navegación, ni puede llegar a la pestaña',
  { tag: ['@RF-IN-07'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'manager' })
    await logInAsManager(page)

    await expect(page.getByRole('link', { name: 'Nómina', exact: true })).not.toBeVisible()

    // El enlace está oculto, pero la URL sigue existiendo: quien la escribe a
    // mano no llega a la pestaña, y la guarda manda a la primera sección a su
    // alcance (regla dura 18, la autorización real es del servidor: rol
    // `rrhh+`, Anexo B).
    await page.goto('/reports/payroll')
    await expect(page).toHaveURL(/\/live$/)
    await expect(page.getByRole('heading', { level: 1, name: 'Salida a nómina' })).not.toBeVisible()
  },
)

test(
  'cambiar el separador y una etiqueta de columna en ajustes cambia la descarga de nómina',
  { tag: ['@RF-IN-07'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'admin' })
    await logInAsAdmin(page)

    // 1) El estado de serie: separador punto y coma, etiqueta de fábrica.
    await page.getByRole('link', { name: 'Nómina', exact: true }).click()
    await page.getByLabel('Desde').fill('2026-01-01')
    await page.getByLabel('Hasta').fill('2026-06-30')

    const firstDownload = page.waitForEvent('download')
    await page.getByTestId('payroll-download').click()
    const beforeFile = await firstDownload
    const beforeText = await readDownloadText(beforeFile)

    expect(beforeText).toContain(';')
    expect(beforeText).not.toContain('Horas trabajadas (personalizado)')

    // 2) Se cambia el separador a coma y se renombra una columna, en
    // «Ajustes operativos» (RF-PD-01): el formato de nómina es configuración
    // de la instalación, no un parámetro de la descarga.
    await page.getByRole('link', { name: 'Ajustes operativos' }).click()
    await page.getByTestId('payroll-delimiter').selectOption('comma')
    await page
      .getByTestId('payroll-columns')
      .fill(
        [
          'employee_code',
          'last_name',
          'first_name',
          'department',
          'period_from',
          'period_to',
          'worked_hours=Horas trabajadas (personalizado)',
          'contracted_hours',
          'overtime_hours',
          'absence_days',
        ].join('\n'),
      )
    await page.getByTestId('save').click()
    await expect(page.getByTestId('saved')).toBeVisible()

    // 3) La previsualización de «Nómina» refleja el ajuste nuevo, y la
    // descarga siguiente lleva el separador y la etiqueta nuevos.
    await page.getByRole('link', { name: 'Nómina', exact: true }).click()
    await expect(page.getByTestId('payroll-columns-preview')).toContainText(
      'Horas trabajadas (personalizado)',
    )

    await page.getByLabel('Desde').fill('2026-01-01')
    await page.getByLabel('Hasta').fill('2026-06-30')

    const secondDownload = page.waitForEvent('download')
    await page.getByTestId('payroll-download').click()
    const afterFile = await secondDownload
    const afterText = await readDownloadText(afterFile)

    expect(afterText).toContain(',')
    expect(afterText).toContain('Horas trabajadas (personalizado)')
    expect(afterText).not.toContain(';')
  },
)
