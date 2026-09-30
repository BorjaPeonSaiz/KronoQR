// El fichaje por PIN BAJO LA CSP DE PRODUCCION (PIN-01 y PIN-04 de la
// verificacion de la 2.1.0, RF-AT-11).
//
// En la 2.1.0 instalada el PIN no funcionaba: la CSP de Nginx no permitia
// compilar el WebAssembly de libsodium, el sellado fallaba y el quiosco
// decia «Código no válido» sin mandar nada al servidor. `pin.spec.ts` no lo
// vio porque corria sin CSP. Aqui el servidor sirve las cabeceras del snippet
// real de Nginx (`support/securityHeaders.ts`) y el sellado es el de verdad:
// no se simula libsodium, solo la respuesta de la API.

import { Buffer } from 'node:buffer'
import { expect, test } from '@playwright/test'
import {
  enterEmployeeCode,
  pressPinDigits,
  stubKioskApiWithPin,
  stubPinScanApi,
} from './support/pin'
import { cspDirective, readProductionSecurityHeaders } from './support/securityHeaders'

const PIN_CSP_EMPLOYEE_CODE = 'E7QK2MXPR'
const PIN_CSP_RAW_PIN = '483920'
// `crypto_box_seal`: 32 bytes de clave efimera + 16 de MAC + los 6 del PIN.
const PIN_CSP_SEALED_BYTES = 54
// Lo que deja en consola o en `pageerror` un WebAssembly que la CSP no deja
// compilar, o cualquier otra violacion de CSP.
const PIN_CSP_BLOCKED = /CompileError|WebAssembly|wasm|Content Security Policy|Refused to/i

test(
  'el quiosco se sirve con la Content-Security-Policy del snippet de Nginx, con script-src',
  { tag: ['@RF-AT-11', '@RS-09'] },
  async ({ page }) => {
    const expected = readProductionSecurityHeaders()

    const response = await page.goto('/')

    const csp = response?.headers()['content-security-policy']
    expect(csp).toBe(expected['Content-Security-Policy'])
    expect(cspDirective(csp ?? '', 'script-src')).not.toBeNull()
  },
)

test(
  'bajo la CSP de produccion el PIN se sella con el WebAssembly real y viaja en una peticion',
  { tag: ['@RF-AT-11', '@RS-09'] },
  async ({ page }) => {
    const browserDiagnostics: string[] = []
    page.on('pageerror', (error) =>
      browserDiagnostics.push(`pageerror: ${error.name}: ${error.message}`),
    )
    page.on('console', (message) =>
      browserDiagnostics.push(`console.${message.type()}: ${message.text()}`),
    )
    await page.addInitScript(() => {
      document.addEventListener('securitypolicyviolation', (event) => {
        console.error(`Content Security Policy violation: ${event.violatedDirective}`)
      })
    })
    await stubKioskApiWithPin(page)
    const pinApi = await stubPinScanApi(page, 'clock_in')
    const response = await page.goto('/')
    expect(response?.headers()['content-security-policy']).toBe(
      readProductionSecurityHeaders()['Content-Security-Policy'],
    )

    await page.getByTestId('pin-entry-link').click()
    await enterEmployeeCode(page, PIN_CSP_EMPLOYEE_CODE)
    await pressPinDigits(page, PIN_CSP_RAW_PIN)
    await page.getByTestId('pin-confirm').click()

    await expect(page.getByTestId('scan-confirmation')).toHaveAttribute(
      'data-kind',
      /^(accepted|rejected)$/,
      { timeout: 10_000 },
    )
    const blocked = browserDiagnostics.filter((text) => PIN_CSP_BLOCKED.test(text))
    expect(blocked, blocked.join('\n')).toEqual([])
    await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'accepted')
    expect(pinApi.recorded).toHaveLength(1)
    expect(Buffer.from(pinApi.recorded[0]?.pinSealed ?? '', 'base64')).toHaveLength(
      PIN_CSP_SEALED_BYTES,
    )
  },
)
