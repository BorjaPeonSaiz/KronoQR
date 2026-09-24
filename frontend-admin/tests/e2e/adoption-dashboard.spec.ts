// Cuadro de impacto y adopcion (RF-IN-08, RNF-D-01, tarea 3.13): «el cuadro
// que responde a "¿esto está sirviendo?" con datos» (doc 05 §5.4).
//
// El backend no participa: aqui se prueba el recorrido por el panel con la
// API simulada en `support/admin.ts`. Que el servidor de verdad calcule cada
// porcentaje se prueba en el backend (regla dura 18); lo que se prueba aqui
// es que la pantalla enseña los indicadores con su objetivo y su variacion,
// que un periodo anterior sin datos se enseña vacio y no como «0», que la
// exportacion dispara la peticion con el formato pedido, que el aviso de
// licencia aparece con el `402`, y que un rol sin `reports:*` no llega a la
// pantalla ni por URL.
import { expect, test } from '@playwright/test'
import {
  ADOPTION_REPORT_WITHOUT_PREVIOUS_PERIOD,
  logIn,
  logInAsManager,
  stubManagementApi,
} from './support/admin'

test(
  'los indicadores se enseñan con su objetivo, su estado y su variacion contra el periodo anterior',
  { tag: ['@RF-IN-08'] },
  async ({ page }) => {
    await stubManagementApi(page)
    await logIn(page)

    await page.getByRole('link', { name: 'Impacto y adopción', exact: true }).click()
    await expect(page.getByRole('heading', { level: 1, name: 'Impacto y adopción' })).toBeVisible()

    // Seis tarjetas, una por indicador con objetivo del §1.3 (decision 7 de
    // la ficha; los otros seis del contrato viajan como dato secundario).
    await expect(page.getByTestId('indicator-card')).toHaveCount(6)

    const completeWorkdaysCard = page
      .getByTestId('indicator-card')
      .filter({ has: page.getByText('Jornadas con registro completo') })

    // Dos decimales, con coma (idioma es): un solo decimal colapsaba el
    // margen de RNF-D-01 (segunda vuelta de la ficha, bloqueante).
    await expect(completeWorkdaysCard.getByTestId('workdays-complete-ratio-value')).toHaveText(
      '99,40 %',
    )
    await expect(completeWorkdaysCard.getByTestId('workdays-complete-ratio-status')).toContainText(
      'Dentro del objetivo',
    )
    // La variacion de un porcentaje va en puntos porcentuales, no en «%»
    // (segunda vuelta de la ficha).
    await expect(completeWorkdaysCard.getByTestId('workdays-complete-ratio-delta')).toHaveText(
      '+1,30 pp',
    )
    await expect(completeWorkdaysCard).toContainText('Objetivo: ≥ 99,00 %')

    // La disponibilidad lleva el subindicador «resueltos sin servidor» junto
    // a ella (decision 7 de la ficha).
    const availabilityCard = page
      .getByTestId('indicator-card')
      .filter({ has: page.getByText('Disponibilidad del acto de fichar') })

    await expect(availabilityCard.getByTestId('availability-offline')).toBeVisible()

    // El tiempo de resolucion lleva la mediana como dato secundario.
    const resolutionCard = page
      .getByTestId('indicator-card')
      .filter({ has: page.getByText('Tiempo medio de resolución de un turno sin cerrar') })

    await expect(resolutionCard.getByTestId('median-resolution')).toBeVisible()

    // Fotos sin comparacion: incidencias abiertas y empleados sin credencial.
    await expect(page.getByTestId('incidents-open-card')).toContainText('3')
    await expect(page.getByTestId('credentials-card')).toContainText('2')

    // Horas trabajadas frente a contratadas, en horas y minutos enteros
    // (regla dura, nunca decimal): 612 000 min = 10200 h 00 min, 604 800 min
    // = 10080 h 00 min.
    await expect(page.getByTestId('worked-hours-card')).toContainText('10200 h 00 min')
    await expect(page.getByTestId('worked-hours-card')).toContainText('10080 h 00 min')

    // La linea base declarada de horas/mes consolidando hojas (1080 min = 18 h 00 min).
    const baselineCard = page
      .getByTestId('indicator-card')
      .filter({ has: page.getByText('Horas al mes consolidando hojas de horas') })

    await expect(baselineCard.getByTestId('baseline-declared')).toBeVisible()
    await expect(baselineCard.getByTestId('baseline-manual-minutes-per-month-value')).toContainText(
      '18 h 00 min',
    )

    // Los criterios del cuadro, tal cual los da el servidor.
    await expect(page.getByTestId('adoption-criteria').locator('li')).toHaveCount(2)
  },
)

test(
  'un periodo sin anterior comparable se enseña vacio, nunca como 0',
  { tag: ['@RF-IN-08'] },
  async ({ page }) => {
    await stubManagementApi(page, { adoptionReport: ADOPTION_REPORT_WITHOUT_PREVIOUS_PERIOD })
    await logIn(page)

    await page.goto('/reports/adoption')

    const completeWorkdaysCard = page
      .getByTestId('indicator-card')
      .filter({ has: page.getByText('Jornadas con registro completo') })

    await expect(completeWorkdaysCard.getByTestId('workdays-complete-ratio-delta')).toHaveText(
      'Sin periodo anterior comparable.',
    )
    await expect(
      completeWorkdaysCard.getByTestId('workdays-complete-ratio-delta'),
    ).not.toContainText('0')
  },
)

test(
  'la exportacion dispara la peticion con el formato pedido',
  { tag: ['@RF-IN-08'] },
  async ({ page }) => {
    await stubManagementApi(page)
    await logIn(page)
    await page.goto('/reports/adoption')
    await expect(page.getByTestId('indicator-card')).toHaveCount(6)

    const [request, download] = await Promise.all([
      page.waitForRequest((candidate) => candidate.url().includes('/reports/adoption/export')),
      page.waitForEvent('download'),
      page.getByTestId('export-xlsx').click(),
    ])

    expect(request.url()).toContain('format=xlsx')
    expect(download.suggestedFilename()).toContain('.xlsx')
  },
)

test(
  'el aviso de licencia aparece cuando el cuadro no esta en el plan',
  { tag: ['@RF-IN-08'] },
  async ({ page }) => {
    await stubManagementApi(page, { adoptionReportOutcome: 'licenseRequired' })
    await logIn(page)

    await page.goto('/reports/adoption')

    await expect(page.getByTestId('license-required-notice')).toBeVisible()
    await expect(page.getByTestId('license-required-notice')).toContainText(
      'no está incluido en tu licencia actual',
    )
  },
)

test(
  'un rango que supera el techo de 366 dias enseña el aviso de acortar el rango, distinguido por el `type`',
  { tag: ['@RF-IN-08'] },
  async ({ page }) => {
    await stubManagementApi(page, { periodReportOutcome: 'tooLarge' })
    await logIn(page)

    await page.goto('/reports/adoption')

    await expect(page.getByTestId('range-too-large-notice')).toBeVisible()
    await expect(page.getByTestId('range-too-large-notice')).toContainText('366 días')
    // Este cuadro no ofrece generacion en segundo plano (son doce filas): no
    // hay boton de «Generar en segundo plano» como en el informe por periodo.
    await expect(page.getByTestId('request-background')).not.toBeVisible()
  },
)

test(
  'un responsable de departamento no ve «Impacto y adopción» en la navegación, ni puede llegar a la pantalla',
  { tag: ['@RF-IN-08', '@RF-ID-03'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'manager' })
    await logInAsManager(page)

    await expect(
      page.getByRole('link', { name: 'Impacto y adopción', exact: true }),
    ).not.toBeVisible()

    // El enlace esta oculto, pero la URL sigue existiendo: quien la escribe a
    // mano no llega a la pantalla, y la guarda manda a la primera seccion a
    // su alcance (regla dura 18: la autorizacion real es del servidor, rol
    // `admin|rrhh`, Anexo B).
    await page.goto('/reports/adoption')
    await expect(page).toHaveURL(/\/live$/)
    await expect(
      page.getByRole('heading', { level: 1, name: 'Impacto y adopción' }),
    ).not.toBeVisible()
  },
)
