// Fichaje de respaldo por PIN (tarea 1.12, RF-AT-11), con y sin red.
//
// Mismo patron que `offline.spec.ts` de la 1.9 para la parte de cola: el
// backend no participa, se intercepta cada llamada, y se abre IndexedDB por
// debajo para comprobar lo que queda escrito de verdad.

import AxeBuilder from '@axe-core/playwright'
import { expect, test } from '@playwright/test'
import { readQueue } from './support/offlineQueue'
import {
  enterEmployeeCode,
  pressPinDigits,
  stubKioskApiWithoutPin,
  stubKioskApiWithPin,
  stubPinScanApi,
} from './support/pin'

const EMPLOYEE_CODE = 'E7QK2MXPR'
const RAW_PIN = '483920'
const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']
const DISABLED_RULES = ['video-caption']

test(
  'la instalacion sin PIN no ofrece el boton de respaldo (ADR-017)',
  { tag: ['@RF-AT-11'] },
  async ({ page }) => {
    await stubKioskApiWithoutPin(page)
    await page.goto('/')

    await expect(page.getByTestId('pin-entry-link')).toHaveCount(0)
  },
)

test(
  'la instalacion con PIN ofrece el boton, y ficha con red',
  { tag: ['@RF-AT-11'] },
  async ({ page }) => {
    await stubKioskApiWithPin(page)
    const pinApi = await stubPinScanApi(page, 'clock_in')

    await page.goto('/')
    await expect(page.getByTestId('pin-entry-link')).toBeVisible()
    await page.getByTestId('pin-entry-link').click()

    await expect(page.getByTestId('pin-step-code')).toBeVisible()
    await enterEmployeeCode(page, EMPLOYEE_CODE)

    await expect(page.getByTestId('pin-step-pin')).toBeVisible()
    // El PIN nunca aparece en pantalla, ni siquiera parcialmente.
    await pressPinDigits(page, RAW_PIN)
    await expect(page.locator('body')).not.toContainText(RAW_PIN)

    await page.getByTestId('pin-confirm').click()

    await expect(page.getByTestId('scan-confirmation')).toBeVisible()
    await expect.poll(() => pinApi.recorded.length).toBeGreaterThan(0)

    const sent = pinApi.recorded[0]
    expect(sent?.employeeCode).toBe(EMPLOYEE_CODE)
    // Lo unico que viaja del PIN es el sobre sellado: nunca los 6 digitos.
    expect(sent?.pinSealed).not.toBe(RAW_PIN)
    expect(sent?.pinSealed).not.toContain(RAW_PIN)
    expect(sent?.pinSealed).toMatch(/^[A-Za-z0-9+/]+={0,2}$/)
    // La `Idempotency-Key` es el `scan_id`, igual que en `/scan` (regla dura 8).
    expect(sent?.idempotencyKey).toBe(sent?.scanId)
  },
)

test(
  'sin red: se confirma en local, se encola sellado y se sincroniza al volver',
  { tag: ['@RF-AT-11', '@RQ-05'] },
  async ({ page }) => {
    await stubKioskApiWithPin(page)
    await page.route('**/api/v1/scan/pin', async (route) => route.abort('failed'))

    await page.goto('/')
    await page.getByTestId('pin-entry-link').click()
    await enterEmployeeCode(page, EMPLOYEE_CODE)
    await pressPinDigits(page, RAW_PIN)
    await page.getByTestId('pin-confirm').click()

    // Confirmacion LOCAL y honesta: pendiente, no rechazada.
    await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'pending')

    // En IndexedDB: el sobre sellado, JAMAS el PIN en claro.
    await expect.poll(async () => (await readQueue(page)).length).toBeGreaterThan(0)
    const queued = await readQueue(page)
    const serialized = JSON.stringify(queued)
    expect(serialized).not.toContain(RAW_PIN)
    expect(serialized).toContain('"kind":"pin"')

    // Vuelve la red: se sincroniza por `/api/v1/scan/pin`, UNA llamada, sin lote
    // (el PIN no tiene variante de lote, doc 02 §11).
    await page.unroute('**/api/v1/scan/pin')
    const pinApi = await stubPinScanApi(page, 'clock_in')
    await page.evaluate(() => window.dispatchEvent(new Event('online')))

    await expect.poll(() => pinApi.recorded.length).toBeGreaterThan(0)
    await expect.poll(async () => (await readQueue(page)).length).toBe(0)
  },
)

test(
  'el rechazo del servidor es el mismo mensaje generico que en la tarjeta (regla dura 17)',
  { tag: ['@RF-AT-11', '@RS-03'] },
  async ({ page }) => {
    await stubKioskApiWithPin(page)
    await stubPinScanApi(page, 'rejected')

    await page.goto('/')
    await page.getByTestId('pin-entry-link').click()
    await enterEmployeeCode(page, EMPLOYEE_CODE)
    await pressPinDigits(page, RAW_PIN)
    await page.getByTestId('pin-confirm').click()

    // Empieza «pendiente» y se asienta en el rechazo generico al contestar el
    // servidor: el mismo texto que usa el escaneo de tarjeta.
    await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'rejected', {
      timeout: 10_000,
    })
    await expect(page.getByTestId('confirmation-headline')).toContainText('Código no válido')
  },
)

test(
  'la pantalla de PIN no tiene violaciones de accesibilidad criticas ni graves',
  { tag: ['@RF-KI-06'] },
  async ({ page }) => {
    await stubKioskApiWithPin(page)
    await page.goto('/')
    await page.getByTestId('pin-entry-link').click()
    await expect(page.getByTestId('pin-step-code')).toBeVisible()

    const codeResults = await new AxeBuilder({ page })
      .withTags(WCAG_TAGS)
      .disableRules(DISABLED_RULES)
      .analyze()
    const codeBlocking = codeResults.violations.filter(
      (violation) => violation.impact === 'critical' || violation.impact === 'serious',
    )
    expect(
      codeBlocking,
      codeBlocking.map((violation) => `${violation.id}: ${violation.help}`).join('\n'),
    ).toEqual([])

    await enterEmployeeCode(page, EMPLOYEE_CODE)
    await expect(page.getByTestId('pin-step-pin')).toBeVisible()

    const pinResults = await new AxeBuilder({ page })
      .withTags(WCAG_TAGS)
      .disableRules(DISABLED_RULES)
      .analyze()
    const pinBlocking = pinResults.violations.filter(
      (violation) => violation.impact === 'critical' || violation.impact === 'serious',
    )
    expect(
      pinBlocking,
      pinBlocking.map((violation) => `${violation.id}: ${violation.help}`).join('\n'),
    ).toEqual([])
  },
)
