// Accesibilidad automatizada del panel con axe-core (doc 02 §9.2 y §9.4;
// WCAG 2.2 AA, doc 01 §6.5). Mismo criterio que en el quiosco: CERO
// violaciones criticas o graves en cada pantalla del recorrido de la Fase 1.
// Las de impacto menor se listan en la salida para que se vean, pero no
// bloquean.
//
// Hasta ahora el panel solo tenia una comprobacion estructural en Vitest; esto
// es el analisis de verdad, sobre el DOM real del build.

import AxeBuilder from '@axe-core/playwright'
import type { Page } from '@playwright/test'
import { expect, test } from '@playwright/test'
import { EMPLOYEE_UUID, logIn, stubManagementApi, USER } from './support/admin'
import { stubOnboardingApi } from './support/setupWizard'

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

test.beforeEach(async ({ page }) => {
  await stubManagementApi(page)
})

test(
  'el acceso no tiene violaciones criticas ni graves',
  { tag: ['@RF-ID-01'] },
  async ({ page }) => {
    await page.goto('/login')
    await expect(page.getByRole('heading', { name: 'Acceso al panel de gestión' })).toBeVisible()

    await expectNoBlockingViolations(page)
  },
)

test('la plantilla tampoco', { tag: ['@RF-GP-01'] }, async ({ page }) => {
  await logIn(page)
  await expect(page.getByRole('table')).toBeVisible()

  await expectNoBlockingViolations(page)
})

test('la ficha de una persona tampoco', { tag: ['@RF-GP-01'] }, async ({ page }) => {
  await logIn(page)
  await page.goto(`/employees/${EMPLOYEE_UUID}`)
  await expect(page.getByRole('heading', { level: 1, name: 'Youssef Amrani' })).toBeVisible()

  await expectNoBlockingViolations(page)
})

test('el registro horario tampoco', { tag: ['@RF-PA-03'] }, async ({ page }) => {
  await logIn(page)
  await page.goto(`/employees/${EMPLOYEE_UUID}/workdays`)
  await expect(page.getByTestId('workday')).toHaveCount(1)

  await expectNoBlockingViolations(page)
})

test(
  'la pantalla del codigo de segundo factor tampoco',
  { tag: ['@RF-ID-01', '@RS-06'] },
  async ({ page }) => {
    await stubManagementApi(page, { twoFactor: 'verify' })

    await page.goto('/login')
    await page.getByLabel(/Correo electrónico/).fill(USER.email)
    await page.getByLabel(/Contraseña/).fill('una-contraseña-larga-y-valida')
    await page.getByRole('button', { name: 'Entrar' }).click()

    await expect(page.getByLabel(/Código de verificación/)).toBeVisible()

    await expectNoBlockingViolations(page)
  },
)

test(
  'la pantalla de alta del segundo factor, con el QR, tampoco',
  { tag: ['@RF-ID-01', '@RS-06'] },
  async ({ page }) => {
    await stubManagementApi(page, { twoFactor: 'enrol' })

    await page.goto('/login')
    await page.getByLabel(/Correo electrónico/).fill(USER.email)
    await page.getByLabel(/Contraseña/).fill('una-contraseña-larga-y-valida')
    await page.getByRole('button', { name: 'Entrar' }).click()

    await expect(page.getByTestId('two-factor-secret')).toBeVisible()

    await expectNoBlockingViolations(page)
  },
)

test('la presencia en vivo tampoco', { tag: ['@RF-PA-01'] }, async ({ page }) => {
  await logIn(page)
  await page.goto('/live')
  await expect(page.getByTestId('presence-entry').first()).toBeVisible()

  await expectNoBlockingViolations(page)
})

test('la bandeja de incidencias tampoco', { tag: ['@RF-PA-05'] }, async ({ page }) => {
  await logIn(page)
  await page.goto('/incidents')
  await expect(page.getByTestId('incident-row').first()).toBeVisible()

  await expectNoBlockingViolations(page)
})

test(
  'el dialogo de resolver una incidencia tampoco, con el foco dentro',
  { tag: ['@RF-PA-05'] },
  async ({ page }) => {
    await logIn(page)
    await page.goto('/incidents')
    await page.getByTestId('resolve-button').click()
    await expect(page.getByRole('dialog')).toBeVisible()

    await expectNoBlockingViolations(page)
  },
)

// --- Asistente de puesta en marcha (RF-PD-03, RQ-04, tarea 5.5) -------------
//
// Es la PRIMERA pantalla del producto: cero violaciones criticas o graves en
// CADA paso, no solo en el asistente en general. Cada prueba aterriza
// directamente en el paso que comprueba —con el estado de los pasos previos
// ya resuelto en el doble— para no repetir el recorrido completo ocho veces.

test(
  'el paso del primer administrador no tiene violaciones',
  { tag: ['@RQ-04'] },
  async ({ page }) => {
    await stubOnboardingApi(page)
    await page.goto('/setup')
    await expect(page.getByRole('heading', { name: 'Primer administrador' })).toBeVisible()

    await expectNoBlockingViolations(page)
  },
)

test(
  'el alta del segundo factor del primer administrador tampoco, con el QR',
  { tag: ['@RQ-04'] },
  async ({ page }) => {
    await stubOnboardingApi(page)
    await page.goto('/setup')
    await page.getByLabel('Nombre').fill('Dirección del hotel')
    await page.getByLabel('Correo electrónico').fill('direccion@hotel.example')
    await page.getByLabel('Contraseña').fill('una-contrasena-larga-y-propia-1!')
    await page.getByRole('button', { name: 'Crear la cuenta' }).click()
    await expect(page.getByTestId('two-factor-secret')).toBeVisible()

    await expectNoBlockingViolations(page)
  },
)

test('el paso de organizacion no tiene violaciones', { tag: ['@RQ-04'] }, async ({ page }) => {
  await stubOnboardingApi(page, { administratorAlreadyDone: true })
  await page.goto('/setup')
  await expect(page.getByRole('heading', { name: 'Organización' })).toBeVisible()

  await expectNoBlockingViolations(page)
})

test('el paso del centro de trabajo tampoco', { tag: ['@RQ-04'] }, async ({ page }) => {
  await stubOnboardingApi(page, {
    administratorAlreadyDone: true,
    stepsDone: { organisation: 'completed' },
  })
  await page.goto('/setup')
  await expect(page.getByRole('heading', { name: 'Centro de trabajo' })).toBeVisible()

  await expectNoBlockingViolations(page)
})

test('el paso de departamentos tampoco', { tag: ['@RQ-04'] }, async ({ page }) => {
  await stubOnboardingApi(page, {
    administratorAlreadyDone: true,
    siteDone: true,
    stepsDone: { organisation: 'completed' },
  })
  await page.goto('/setup')
  await expect(page.getByRole('heading', { name: 'Departamentos' })).toBeVisible()

  await expectNoBlockingViolations(page)
})

test(
  'el paso del perfil de convenio tampoco, con el aviso de RL-21',
  { tag: ['@RQ-04'] },
  async ({ page }) => {
    await stubOnboardingApi(page, {
      administratorAlreadyDone: true,
      siteDone: true,
      stepsDone: { organisation: 'completed', departments: 'skipped' },
    })
    await page.goto('/setup')
    await expect(page.getByRole('heading', { name: 'Perfil de convenio' })).toBeVisible()

    await expectNoBlockingViolations(page)
  },
)

test(
  'el paso de plantilla tampoco, con el informe de importacion',
  { tag: ['@RQ-04'] },
  async ({ page }) => {
    await stubOnboardingApi(page, {
      administratorAlreadyDone: true,
      siteDone: true,
      stepsDone: {
        organisation: 'completed',
        departments: 'skipped',
        compliance_profile: 'completed',
      },
    })
    await page.goto('/setup')
    await expect(page.getByRole('heading', { name: 'Plantilla' })).toBeVisible()
    await page.getByTestId('import-file').setInputFiles({
      name: 'plantilla.csv',
      mimeType: 'text/csv',
      buffer: Buffer.from('first_name,last_name\nYoussef,Amrani\n'),
    })
    await page.getByTestId('validate').click()
    await expect(page.getByTestId('import-row-2')).toBeVisible()

    await expectNoBlockingViolations(page)
  },
)

test(
  'el paso de licencia tampoco, con la pantalla de activacion incrustada',
  { tag: ['@RQ-04'] },
  async ({ page }) => {
    await stubOnboardingApi(page, {
      administratorAlreadyDone: true,
      siteDone: true,
      stepsDone: {
        organisation: 'completed',
        departments: 'skipped',
        compliance_profile: 'completed',
        employees: 'skipped',
      },
    })
    await page.goto('/setup')
    await expect(page.getByRole('heading', { name: 'Licencia', level: 2 })).toBeVisible()

    await expectNoBlockingViolations(page)
  },
)

test('el paso del primer quiosco tampoco', { tag: ['@RQ-04'] }, async ({ page }) => {
  await stubOnboardingApi(page, {
    administratorAlreadyDone: true,
    siteDone: true,
    stepsDone: {
      organisation: 'completed',
      departments: 'skipped',
      compliance_profile: 'completed',
      employees: 'skipped',
      license: 'skipped',
    },
  })
  await page.goto('/setup')
  await expect(page.getByRole('heading', { name: 'Primer quiosco' })).toBeVisible()

  await expectNoBlockingViolations(page)
})

test('la revision final tampoco', { tag: ['@RQ-04'] }, async ({ page }) => {
  await stubOnboardingApi(page, {
    administratorAlreadyDone: true,
    siteDone: true,
    stepsDone: {
      organisation: 'completed',
      departments: 'skipped',
      compliance_profile: 'completed',
      employees: 'skipped',
      license: 'skipped',
      kiosk: 'skipped',
    },
  })
  await page.goto('/setup')
  await expect(page.getByRole('heading', { name: 'Revisa antes de terminar' })).toBeVisible()

  await expectNoBlockingViolations(page)
})

test('el resumen final de cierre tampoco', { tag: ['@RQ-04'] }, async ({ page }) => {
  await stubOnboardingApi(page, {
    administratorAlreadyDone: true,
    siteDone: true,
    stepsDone: {
      organisation: 'completed',
      departments: 'skipped',
      compliance_profile: 'completed',
      employees: 'skipped',
      license: 'skipped',
      kiosk: 'skipped',
    },
  })
  await page.goto('/setup')
  await page.getByTestId('complete-setup').click()
  await expect(page.getByRole('heading', { name: 'Puesta en marcha completada' })).toBeVisible()

  await expectNoBlockingViolations(page)
})
