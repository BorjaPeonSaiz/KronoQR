// Accesibilidad automatizada con axe-core (doc 02 §9.2 y §9.4).
//
// Criterio: CERO violaciones criticas o graves. Las de impacto menor se listan
// en la salida para que se vean, pero no bloquean: axe marca como «minor» cosas
// que en una pantalla de quiosco sin teclado no significan nada.
//
// REGLA DESACTIVADA, CON MOTIVO. `video-caption` exige una pista de subtitulos
// en todo `<video>`. Aqui el `<video>` no es contenido audiovisual: es el visor
// EN VIVO de la camara, se abre con `audio: false` y va marcado `aria-hidden`.
// No hay nada que subtitular, y anadir un `<track>` vacio para contentar a la
// herramienta seria falsear el resultado en lugar de arreglarlo.

import AxeBuilder from '@axe-core/playwright'
import { expect, test } from '@playwright/test'
import { stubBrandingApi, stubKioskApi, stubScanApi } from './support/kiosk'

const DISABLED_RULES = ['video-caption']

/** Etiquetas WCAG que se comprueban: A y AA hasta la 2.2 (doc 01 §6.5). */
const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']

test.beforeEach(async ({ page }) => {
  await stubKioskApi(page)
})

test(
  'la pantalla de escaneo no tiene violaciones criticas ni graves',
  { tag: ['@RF-KI-06'] },
  async ({ page }) => {
    await stubScanApi(page, { outcome: 'offline' })
    await page.goto('/')
    await expect(page.getByTestId('privacy-notice')).toBeVisible()

    const results = await new AxeBuilder({ page })
      .withTags(WCAG_TAGS)
      .disableRules(DISABLED_RULES)
      .analyze()

    const blocking = results.violations.filter(
      (violation) => violation.impact === 'critical' || violation.impact === 'serious',
    )

    expect(
      blocking,
      blocking.map((violation) => `${violation.id}: ${violation.help}`).join('\n'),
    ).toEqual([])
  },
)

test(
  'la pantalla de confirmacion tampoco',
  { tag: ['@RF-KI-06', '@RF-AT-05'] },
  async ({ page }) => {
    await stubScanApi(page, { outcome: 'clock_in', workedMinutes: 0 })
    await page.goto('/')
    await expect(page.getByTestId('scan-confirmation')).toBeVisible()

    const results = await new AxeBuilder({ page })
      .withTags(WCAG_TAGS)
      .disableRules(DISABLED_RULES)
      .analyze()

    const blocking = results.violations.filter(
      (violation) => violation.impact === 'critical' || violation.impact === 'serious',
    )

    expect(
      blocking,
      blocking.map((violation) => `${violation.id}: ${violation.help}`).join('\n'),
    ).toEqual([])
  },
)

test('en ingles tampoco', { tag: ['@RF-KI-05', '@RF-KI-06'] }, async ({ page }) => {
  await stubScanApi(page, { outcome: 'offline' })
  await page.goto('/')
  await page.getByRole('button', { name: 'English' }).click()
  await expect(page.getByTestId('privacy-notice')).toContainText('Data protection notice')

  const results = await new AxeBuilder({ page })
    .withTags(WCAG_TAGS)
    .disableRules(DISABLED_RULES)
    .analyze()

  const blocking = results.violations.filter(
    (violation) => violation.impact === 'critical' || violation.impact === 'serious',
  )

  expect(
    blocking,
    blocking.map((violation) => `${violation.id}: ${violation.help}`).join('\n'),
  ).toEqual([])
})

test(
  'el resultado del escaneo se anuncia a un lector de pantalla',
  { tag: ['@RF-KI-06'] },
  async ({ page }) => {
    await stubScanApi(page, { outcome: 'clock_in' })
    await page.goto('/')

    const panel = page.getByTestId('scan-confirmation')
    await expect(panel).toHaveAttribute('role', 'alert')
    await expect(panel).toHaveAttribute('aria-live', 'assertive')
  },
)

test(
  'con la marca de un cliente aplicada (logotipo y color propios) tampoco hay violaciones (RF-PD-08)',
  { tag: ['@RF-KI-06', '@RF-PD-08'] },
  async ({ page }) => {
    // `stubKioskApi` YA deja la marca del producto por defecto; se sobreescribe
    // DESPUES con la de «Hotel Marina» (acento, logotipo, un unico idioma) que
    // es el caso que mas toca: color de acento derivado en tiempo de ejecucion
    // y el selector de idioma SIN pintarse (doc 06 regla 8: nada que elegir).
    await stubBrandingApi(page, { variant: 'hotel-marina' })
    await stubScanApi(page, { outcome: 'clock_in' })
    await page.goto('/')

    await expect(page.getByTestId('brand-logo')).toBeVisible()
    await expect(page.getByTestId('scan-confirmation')).toBeVisible()

    const results = await new AxeBuilder({ page })
      .withTags(WCAG_TAGS)
      .disableRules(DISABLED_RULES)
      .analyze()

    const blocking = results.violations.filter(
      (violation) => violation.impact === 'critical' || violation.impact === 'serious',
    )

    expect(
      blocking,
      blocking.map((violation) => `${violation.id}: ${violation.help}`).join('\n'),
    ).toEqual([])
  },
)
