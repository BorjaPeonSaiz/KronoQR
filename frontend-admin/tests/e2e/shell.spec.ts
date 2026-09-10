// El marco del panel: la navegacion lateral (guia visual, doc 06 §6 regla 11)
// y lo que la revision de UI/UX del 10-09-2026 pidio que verificara una
// herramienta y no un comentario:
//
// - la seccion activa lleva `aria-current="page"`, tambien en las pantallas
//   que cuelgan de ella sin entrada propia (la ficha de un empleado);
// - por debajo de `md` el menu se apila sobre el contenido y TODAS las
//   secciones siguen en el DOM y visibles: no hay menu plegable, y las demas
//   pruebas cuentan enlaces (`toHaveCount(0)`) dando eso por hecho.
//
// El backend no participa: dobles de `support/admin.ts`.
import AxeBuilder from '@axe-core/playwright'
import { expect, test } from '@playwright/test'
import { EMPLOYEE_UUID, logInAsAdmin, stubManagementApi } from './support/admin'

const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']

const SECTIONS = [
  'Plantilla',
  'Presencia',
  'Incidencias',
  'Credenciales',
  'Informes',
  'Inspección',
  'Cumplimiento',
  'Ajustes operativos',
  'Quioscos',
  'Marca',
  'Licencia',
  'Soporte',
  'Errores',
]

test.beforeEach(async ({ page }) => {
  await stubManagementApi(page, { role: 'admin' })
  await logInAsAdmin(page)
})

test('la seccion activa lleva aria-current, tambien desde la ficha de un empleado', async ({
  page,
}) => {
  const nav = page.getByRole('banner').getByRole('navigation')
  const employees = nav.getByRole('link', { name: 'Plantilla' })

  await expect(page).toHaveURL(/\/employees$/)
  await expect(employees).toHaveAttribute('aria-current', 'page')
  await expect(nav.getByRole('link', { name: 'Presencia' })).not.toHaveAttribute('aria-current')

  // La ficha no tiene entrada de menu: cuelga de «Plantilla» (meta.section).
  await page.goto(`/employees/${EMPLOYEE_UUID}`)
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
  await expect(employees).toHaveAttribute('aria-current', 'page')
  await expect(nav.getByRole('link', { name: 'Presencia' })).not.toHaveAttribute('aria-current')

  await nav.getByRole('link', { name: 'Presencia' }).click()
  await expect(page).toHaveURL(/\/live$/)
  await expect(nav.getByRole('link', { name: 'Presencia' })).toHaveAttribute('aria-current', 'page')
  await expect(employees).not.toHaveAttribute('aria-current')
})

test('por debajo de md el menu se apila y las trece secciones siguen visibles', async ({
  page,
}) => {
  await page.setViewportSize({ width: 700, height: 900 })

  const banner = page.getByRole('banner')
  const nav = banner.getByRole('navigation')

  for (const section of SECTIONS) {
    await expect(nav.getByRole('link', { name: section })).toBeVisible()
  }
  await expect(nav.getByRole('link')).toHaveCount(SECTIONS.length)

  // Apilado: la cabecera termina antes de que empiece el contenido, y ninguno
  // de los dos se sale del ancho de la ventana.
  const header = await banner.boundingBox()
  const main = await page.getByRole('main').boundingBox()

  if (header === null || main === null) {
    throw new Error('La cabecera o el contenido no se han pintado: no hay caja que medir.')
  }
  expect(main.y).toBeGreaterThanOrEqual(header.y + header.height)
  expect(header.x + header.width).toBeLessThanOrEqual(700)
  expect(main.x + main.width).toBeLessThanOrEqual(700)

  const results = await new AxeBuilder({ page }).withTags(WCAG_TAGS).analyze()
  const blocking = results.violations.filter(
    (violation) => violation.impact === 'critical' || violation.impact === 'serious',
  )

  expect(
    blocking,
    blocking.map((violation) => `${violation.id}: ${violation.help}`).join('\n'),
  ).toEqual([])
})
