// Bloqueo del PIN por intentos (RS-12, RF-AT-11, tarea 3.7).
//
// LO QUE EL CONTRATO DICE DE VERDAD, Y POR QUE ESTE FICHERO NO USA `429`.
// La ficha de esta tarea preveia un `429` con `Retry-After` para el bloqueo,
// calcado del que ya usan las cuentas de gestion del panel
// (`Identity\Infrastructure\Adapter\CacheLoginAttempts`, que SI responde con
// `429`). El PIN del quiosco (y el del portal) es DELIBERADAMENTE distinto:
// `docs/api/openapi.yaml` en `/api/v1/scan/pin` dice, literalmente, que
// «el bloqueo por intentos esta activo» es una de las CINCO causas que
// comparten el MISMO `422` generico -codigo inexistente, PIN incorrecto, PIN
// nunca emitido, empleado de baja y bloqueo activo-, y que el cuerpo
// «no admite miembros adicionales: el contrato hace IMPOSIBLE describir la
// causa, incluida la de "tu PIN esta bloqueado durante 15 minutos", que es
// la que mas tienta». El backend lo cumple al pie de la letra
// (`PinVerification::locked()`: «el retryAfterSeconds NO SALE POR LA API»;
// `AuthFailureReason::LOCKED`: «solo se emite donde la respuesta YA distingue
// el bloqueo: el panel»; `HashedEmployeePinVerifier`: estando bloqueado se
// compara contra un senuelo, nunca contra el hash real, para que ni el
// TIEMPO delate nada). Un `429` con `Retry-After` en el PIN del quiosco
// seria precisamente el oraculo que la regla dura 17 y RS-03 prohiben:
// alguien que prueba codigos al azar podria distinguir «bloqueado» de
// «no existe» por el simple codigo de estado HTTP. Este fichero prueba el
// comportamiento REAL y CORRECTO -el mismo `422` de siempre, indistinguible,
// sin cuenta atras en pantalla porque el cliente no recibe ningun plazo que
// mostrar- y no el que la ficha asumia.
//
// COMO SE SIMULA EL BLOQUEO SIN BACKEND. El quiosco no sabe nada de RS-12: no
// cuenta intentos, no arma ningun temporizador, no pinta ningun aviso propio
// de bloqueo. Todo lo que hace es lo de siempre -pintar el rechazo generico
// que llega-. Por eso «bloquear» aqui es, sencillamente, hacer que el
// servidor simulado conteste el rechazo generico un rato: no hace falta
// reloj falso ni espera, porque el cliente no mide ningun plazo.
//
// DOS PRUEBAS DE COMPORTAMIENTO, NO TRES (mas accesibilidad y objetivos
// tactiles, que no hablan de RS-12). La revision de QA (commit `3d004f4`)
// encontro que la version anterior tenia tres pruebas de comportamiento, y
// las otras dos no aportaban ningun modo de fallo propio: una era
// subconjunto exacto de la primera (mismo doble constante, mismo resultado
// que ya demuestra la primera), y la tercera duplicaba `pin.spec.ts:35` (PIN
// correcto, respuesta `clock_in`, sin bloqueo de por medio). Quedan la que SI
// puede fallar de una forma que importa -el quiosco no exhibe ningun
// oraculo, aunque el propio servidor simulado lo ofreciera- y su control
// negativo.
//
// LA CAMARA NO ARRANCA EN ESTE FICHERO (intermitencia de la 2.2.1, bloque 16
// §4). El proyecto `kiosk-qr` pone delante de la camara un video con una
// tarjeta que se decodifica EN BUCLE, y su confirmacion sustituye a la pantalla
// de espera, enlace «Ficha con tu codigo y PIN» incluido. Cada intento de aqui
// empieza en `/` y pulsa ese enlace: si la primera lectura de la camara
// llegaba entre la comprobacion de Playwright y el clic, el clic caia sobre la
// confirmacion, no se navegaba al PIN y el `fill` del codigo agotaba el plazo
// esperando un campo que no existia. No era el teclado deshabilitado: el campo
// del codigo no tiene `disabled` y el quiosco no pinta ningun estado de
// bloqueo. Nada de esto prueba la camara, asi que se quita la carrera de raiz
// (`blockCamera`) y cada intento espera, ademas, al paso del codigo
// (`openPinScreen`) antes de teclear.

import { expect, test } from '@playwright/test'
import { expectNoBlockingViolations } from './support/axe'
import { blockCamera } from './support/offlineQueue'
import {
  consecutiveRejections,
  enterEmployeeCode,
  openPinScreen,
  pressPinDigits,
  stubKioskApiWithPin,
  stubPinScanApi,
} from './support/pin'
import { expectTouchTargets, TABLET_VIEWPORTS } from './support/touchTargets'

const EMPLOYEE_CODE = 'E7QK2MXPR'
const PIN = '483920'
const CONSECUTIVE_ATTEMPTS = 3

test.beforeEach(async ({ page }) => {
  await blockCamera(page)
})

test(
  'el quiosco no exhibe ningun oraculo de bloqueo: varios rechazos seguidos, mismo cuerpo generico',
  { tag: ['@RS-03', '@RS-12', '@RF-AT-11'] },
  async ({ page }) => {
    await stubKioskApiWithPin(page)
    // El servidor simulado rechaza TODO intento con el cuerpo generico de
    // siempre: es exactamente lo que hace tambien un bloqueo activo en el
    // servidor real (`RegisterPinScanHandler::resolve()`, el mismo
    // `CredentialRejectionReason::UNKNOWN` para las tres causas del PIN).
    await stubPinScanApi(page, 'rejected')

    const headlines = await consecutiveRejections(page, CONSECUTIVE_ATTEMPTS, EMPLOYEE_CODE, PIN)

    // Ni un solo intento -tampoco el que en el servidor real coincidiria con
    // el bloqueo ya activo- distingue nada: mismo titular, todas las veces.
    expect(new Set(headlines).size).toBe(1)
    expect(headlines[0]).toBe('Código no válido')

    // Y no hay cuenta atras ni aviso de bloqueo en pantalla: el cliente no
    // recibe ningun plazo que mostrar (ver el comentario de cabecera).
    await expect(page.getByTestId('scan-confirmation')).not.toContainText(/minuto|bloque/i)
  },
)

test(
  'aunque el servidor simulado ofreciera el oraculo prohibido, la pantalla lo ignora (control negativo, RS-03)',
  { tag: ['@RS-03', '@RS-12', '@RF-AT-11'] },
  async ({ page }) => {
    // Control negativo real: si `RegisterPinScanHandler` tuviera algun dia un
    // defecto y empezara a filtrar el oraculo que el contrato prohibe -un
    // `Retry-After`, un `retry_after_seconds`, un `detail` que nombre el
    // bloqueo-, esta prueba lo pillaria, porque el doble se lo ofrece a
    // proposito y comprueba que la pantalla NO lo usa.
    await stubKioskApiWithPin(page)
    await page.route('**/api/v1/scan/pin', async (route) => {
      const body = route.request().postDataJSON() as { scan_id: string }
      await route.fulfill({
        status: 422,
        contentType: 'application/problem+json',
        headers: { 'Retry-After': '900' },
        body: JSON.stringify({
          type: 'urn:kronoqr:problem:scan-rejected',
          title: 'Escaneo no valido',
          status: 422,
          detail: 'PIN bloqueado 15 minutos por intentos fallidos.',
          scan_id: body.scan_id,
          retry_after_seconds: 900,
        }),
      })
    })

    await openPinScreen(page)
    await enterEmployeeCode(page, EMPLOYEE_CODE)
    await pressPinDigits(page, PIN)
    await page.getByTestId('pin-confirm').click()

    await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'rejected', {
      timeout: 10_000,
    })
    // El literal generico de siempre -ni el `detail` del servidor (que
    // menciona el bloqueo) ni ningun texto de cuenta atras se cuelan en la
    // pantalla-.
    await expect(page.getByTestId('confirmation-headline')).toHaveText('Código no válido')
    await expect(page.getByTestId('scan-confirmation')).not.toContainText(
      /minuto|bloque|retry|900/i,
    )
  },
)

// KI7-03: en las tres orientaciones, no solo en la apaisada de la configuracion.
for (const { name, viewport } of TABLET_VIEWPORTS) {
  test.describe(`rechazo del PIN en ${name}`, () => {
    test.use({ viewport })

    test(
      'los objetivos tactiles de la pantalla de rechazo del PIN miden al menos 48 px',
      { tag: ['@RF-KI-06'] },
      async ({ page }) => {
        await stubKioskApiWithPin(page)
        await stubPinScanApi(page, 'rejected')

        await consecutiveRejections(page, 1, EMPLOYEE_CODE, PIN)

        await expectTouchTargets(page.locator('button:visible, a[href]:visible'))
      },
    )
  })
}

test(
  'la pantalla de rechazo del PIN, con varios fallos acumulados, no tiene violaciones criticas ni graves',
  { tag: ['@RF-KI-06'] },
  async ({ page }) => {
    await stubKioskApiWithPin(page)
    await stubPinScanApi(page, 'rejected')

    await consecutiveRejections(page, CONSECUTIVE_ATTEMPTS, EMPLOYEE_CODE, PIN)

    await expectNoBlockingViolations(page)
  },
)
