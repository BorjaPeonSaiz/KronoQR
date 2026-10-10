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
//
// Las pruebas del marco en si no llevan etiqueta de requisito a proposito (tarea 3.7, decision 10 de la ficha):
// `docs/requisitos.yaml` no tiene un RF/RN/RQ para «el menu de navegacion del
// panel» en si mismo -RF-PA-01 es la vista en vivo de presencia, no el menu-;
// lo unico que rige esta pantalla es la guia visual (doc 06 §6 regla 11, que
// no es un requisito con `id`) y el WCAG 2.2 AA general (doc 01 §6.5, sin
// `id` propio en el catalogo). Inventar una etiqueta aqui falsearia la
// matriz de trazabilidad.
import AxeBuilder from '@axe-core/playwright'
import { expect, test, type Page } from '@playwright/test'
import { EMPLOYEE_UUID, logInAsAdmin, stubManagementApi } from './support/admin'

const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']

type BoundingBox = { x: number; y: number; width: number; height: number }

/**
 * Afirma que la caja se ha pintado, sin aserciones no nulas (`!`, prohibidas
 * por ESLint) ni un `if` con logica en el cuerpo de la prueba (doc 02 §3.5).
 */
function assertPainted(box: BoundingBox | null, label: string): asserts box is BoundingBox {
  expect(box, `${label} no se ha pintado: no hay caja que medir.`).not.toBeNull()
}

const SECTIONS = [
  'Plantilla',
  'Departamentos',
  'Ausencias',
  'Presencia',
  'Incidencias',
  'Cumplimiento',
  'Credenciales',
  'Informes',
  'Impacto y adopción',
  'Nómina',
  'Inspección',
  'Perfil de cumplimiento',
  'Ajustes operativos',
  'Quioscos',
  'Marca',
  'Cuentas',
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

// Pedido del propietario (tarea 4.1): «Impacto y adopcion» y «Nomina» tienen
// entrada propia, asi que al estar en ellas «Informes» NO debe quedar activa.
// Las etiquetas de requisito son de las pantallas (RF-IN-07, RF-IN-08), no
// del menu, y van literales en cada `test()` (el escaner de trazabilidad es
// lexico).
async function expectOnlySubsectionActive(
  page: Page,
  label: string,
  path: RegExp,
  url: string,
): Promise<void> {
  const nav = page.getByRole('banner').getByRole('navigation')
  const reports = nav.getByRole('link', { name: 'Informes', exact: true })
  const entry = nav.getByRole('link', { name: label, exact: true })

  // Por el menu: Informes primero y despues la subseccion.
  await reports.click()
  await expect(page).toHaveURL(/\/reports$/)
  await expect(reports).toHaveAttribute('aria-current', 'page')

  await entry.click()
  await expect(page).toHaveURL(path)
  await expect(entry).toHaveAttribute('aria-current', 'page')
  await expect(reports).not.toHaveAttribute('aria-current')
  await expect(nav.locator('[aria-current="page"]')).toHaveCount(1)

  // Entrando directamente por URL.
  await page.goto('/employees')
  await page.goto(url)
  await expect(entry).toHaveAttribute('aria-current', 'page')
  await expect(reports).not.toHaveAttribute('aria-current')
  await expect(nav.locator('[aria-current="page"]')).toHaveCount(1)
}

test(
  'en «Impacto y adopción» solo esa subseccion es activa, no «Informes»',
  { tag: ['@RF-IN-08'] },
  async ({ page }) => {
    await expectOnlySubsectionActive(
      page,
      'Impacto y adopción',
      /\/reports\/adoption$/,
      '/reports/adoption',
    )
  },
)

test(
  'en «Nómina» solo esa subseccion es activa, no «Informes»',
  { tag: ['@RF-IN-07'] },
  async ({ page }) => {
    await expectOnlySubsectionActive(page, 'Nómina', /\/reports\/payroll$/, '/reports/payroll')
  },
)

test('por debajo de md el menu se apila y las diecinueve secciones siguen visibles', async ({
  page,
}) => {
  await page.setViewportSize({ width: 700, height: 900 })

  const banner = page.getByRole('banner')
  const nav = banner.getByRole('navigation')

  for (const section of SECTIONS) {
    // `exact: true`: «Cumplimiento» es subcadena de «Perfil de cumplimiento»
    // (RF-PA-06/RF-PD-07, tarea 3.4) y una coincidencia por subcadena
    // encontraria las dos.
    await expect(nav.getByRole('link', { name: section, exact: true })).toBeVisible()
  }
  await expect(nav.getByRole('link')).toHaveCount(SECTIONS.length)

  // Apilado: la cabecera termina antes de que empiece el contenido, y ninguno
  // de los dos se sale del ancho de la ventana.
  const header = await banner.boundingBox()
  const main = await page.getByRole('main').boundingBox()

  assertPainted(header, 'La cabecera')
  assertPainted(main, 'El contenido')
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
