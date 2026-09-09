// Accesibilidad automatizada del portal con axe-core (doc 02 §9.2 y §9.4;
// WCAG 2.2 AA, doc 01 §6.5). Mismo criterio que en el panel y el quiosco:
// CERO violaciones criticas o graves en cada pantalla. Las de impacto menor
// se listan en la salida para que se vean, pero no bloquean.
//
// `@axe-core/playwright` no esta declarado en `package.json` de este
// paquete: llega HOISTED a la raiz del workspace porque `frontend-admin` ya
// lo declara (mismo arbol de dependencias, ADR-036). Añadirlo aqui tambien
// exigiria un `npm install` que, en Windows con `node_modules/` presente,
// rompe los binarios nativos de `@tailwindcss/oxide` (ver HANDOFF.md ->
// "Trampas del entorno"): se importa tal cual, sin declararlo.
import AxeBuilder from '@axe-core/playwright'
import type { Page } from '@playwright/test'
import { expect, test } from '@playwright/test'
import { HOTEL_BRANDING, logInToPortal, stubPortalApi, submitLoginForm } from './support/portal'

/** Etiquetas WCAG que se comprueban: A y AA hasta la 2.2 (doc 01 §6.5). */
const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']

async function expectNoBlockingViolations(page: Page): Promise<void> {
  const results = await new AxeBuilder({ page }).withTags(WCAG_TAGS).analyze()

  const blocking = results.violations.filter(
    (violation) => violation.impact === 'critical' || violation.impact === 'serious',
  )

  expect(
    blocking,
    blocking.map((violation) => `${violation.id}: ${violation.help}`).join('\n'),
  ).toEqual([])
}

test(
  'el acceso no tiene violaciones criticas ni graves',
  { tag: ['@RL-05', '@RF-ID-06'] },
  async ({ page }) => {
    await stubPortalApi(page, { locale: 'es' })
    await page.goto('/login')
    await expect(page.getByRole('heading', { name: 'Acceso al portal' })).toBeVisible()

    await expectNoBlockingViolations(page)
  },
)

test('el acceso con el logotipo del cliente tampoco', { tag: ['@RF-PD-08'] }, async ({ page }) => {
  await stubPortalApi(page, { locale: 'es', branding: HOTEL_BRANDING })
  await page.goto('/login')
  await expect(page).toHaveTitle('Hotel Marina')

  await expectNoBlockingViolations(page)
})

test(
  'el aviso de credenciales rechazadas tampoco',
  { tag: ['@RL-05', '@RF-ID-06'] },
  async ({ page }) => {
    await stubPortalApi(page, { locale: 'es', loginOutcome: 'invalid' })
    await submitLoginForm(page)
    await expect(page.getByRole('alert')).toBeVisible()

    await expectNoBlockingViolations(page)
  },
)

test(
  'mi registro tampoco, con una jornada corregida y otra abierta',
  { tag: ['@RL-05', '@RF-ID-05'] },
  async ({ page }) => {
    await stubPortalApi(page, { locale: 'es' })
    await logInToPortal(page)
    await expect(page.getByTestId('workday')).toHaveCount(6)

    await expectNoBlockingViolations(page)
  },
)

test('descargar mi historial tampoco', { tag: ['@RL-05', '@RF-ID-05'] }, async ({ page }) => {
  await stubPortalApi(page, { locale: 'es' })
  await logInToPortal(page)
  await page.goto('/export')
  await expect(
    page.getByRole('heading', { level: 1, name: 'Descargar mi historial' }),
  ).toBeVisible()

  await expectNoBlockingViolations(page)
})

test('la pagina de "no encontrado" tampoco', { tag: ['@RL-05'] }, async ({ page }) => {
  await stubPortalApi(page, { locale: 'es' })
  await logInToPortal(page)
  await page.goto('/algo-que-no-existe')
  await expect(page.getByRole('heading', { name: 'Esta página no existe' })).toBeVisible()

  await expectNoBlockingViolations(page)
})
