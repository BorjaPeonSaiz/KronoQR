// Tarjeta con desgaste REPARTIDO (tarea 3.7).
//
// Este fichero corre en el proyecto `kiosk-qr-worn`, que arranca Chromium con
// `qr-video-worn.y4m`: el mismo QR con una fraccion de sus PALABRAS DE CODIGO
// (no de modulos sueltos) invertidas al azar, con semilla fija
// (`KIOSK_E2E_QR_WEAR`, por defecto `0.1`, `scripts/generate-qr-fixture.mjs`).
// Es el escenario de una tarjeta real: roces, grasa, un doblez -dano
// disperso por todo el simbolo-, distinto del caso peor de `degraded.spec.ts`
// (un parche opaco y contiguo).
//
// LAS CIFRAS -fraccion, palabras corrompidas, cuantas de 20 semillas
// decodifican- estan medidas y documentadas en `e2e/fixtures/README.md`, con
// el metodo. Son evidencia de que el decodificador sigue funcionando, no una
// promesa al cliente: el producto no documenta ninguna cifra de tolerancia
// al desgaste.
//
// La prueba de «confirma con el mismo feedback que una tarjeta nueva» no
// esta aqui: ya la cubren `degraded.spec.ts` (con este mismo servidor
// simulado) y, en general, `scan.spec.ts:89`. Repetirla por tercera vez no
// prueba nada nuevo.

import { expect, test } from '@playwright/test'
import { FIXTURE_PAYLOAD, stubKioskApi, stubScanApi } from './support/kiosk'

test(
  'una tarjeta con desgaste repartido por toda la superficie se sigue leyendo',
  { tag: ['@RF-QR-05', '@RQ-04'] },
  async ({ page }) => {
    await stubKioskApi(page)
    const stub = await stubScanApi(page, { outcome: 'clock_in' })

    await page.goto('/')

    await expect(page.getByTestId('scan-confirmation')).toBeVisible()
    // Y lo leido es el payload INTACTO: la correccion de errores lo
    // reconstruyo a partir de las palabras que seguian bien. Se espera al
    // envio: desde la tarea 1.9 la confirmacion es local y va por delante de
    // la peticion, asi que darla por hecha aqui seria una carrera.
    await expect.poll(() => stub.recorded[0]?.qrPayload).toBe(FIXTURE_PAYLOAD)
  },
)
