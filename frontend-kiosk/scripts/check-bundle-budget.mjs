#!/usr/bin/env node
//
// KronoQR — presupuesto de rendimiento del quiosco (doc 02, Anexo A; RNF-P-07).
//
//   JS critico (gzip)  <= 250 KiB
//   CSS       (gzip)   <=  40 KiB
//
// La mecanica (que es "critico", como se resuelven las rutas con KRONOQR_BASE,
// cuando falla) es comun a las dos SPA y vive en
// `packages/web-kit/scripts/bundle-budget.mjs`. Aqui solo estan los umbrales del
// quiosco y sus comprobaciones propias:
//
//   * ZXing y libsodium no pueden estar en el camino critico (KP1): el LCP con
//     CPU x4 se estropea aunque el peso siga cabiendo en 250 KiB.
//   * El precacheo del service worker lleva Poppins e Inter: el quiosco tiene que
//     arrancar SIN red con su tipografia, no con la pila de respaldo.
//
// El presupuesto se comprueba EN EL BUILD, no en una revision manual: una
// tablet de gama media que tarda 4 s en arrancar el quiosco es una cola de
// gente esperando a las 06:00.

import { existsSync, readFileSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { checkBundleBudget } from '../../packages/web-kit/scripts/bundle-budget.mjs'

const KIB = 1024
const JS_BUDGET_BYTES = 250 * KIB
const CSS_BUDGET_BYTES = 40 * KIB

checkBundleBudget({
  appDir: path.resolve(fileURLToPath(new URL('..', import.meta.url))),
  title: 'Presupuesto del quiosco (doc 02, Anexo A; RNF-P-07)',
  jsBudgetBytes: JS_BUDGET_BYTES,
  cssBudgetBytes: CSS_BUDGET_BYTES,
  serviceWorkerPattern: /(^|[\\/])(sw|workbox-[^\\/]+)\.js$/,
  // Marcadores que el minificador no renombra.
  forbiddenMarkers: [
    ['ZXing (@zxing/browser)', 'ChecksumException'],
    ['libsodium', 'crypto_box_seal'],
  ],
  exceededRemedy:
    'divide en trozos con import() lo que no haga falta para el primer escaneo, ' +
    'o justifica y actualiza el Anexo A del doc 02 antes de subir el limite.',
  extraChecks: ({ distDir, fail }) => {
    // Presupuesto del precacheo de fuentes (doc 02 §6, RF-KI-01). `vite.config.ts`
    // restringe Workbox a `**/*-latin-*.woff2`; si @fontsource cambia el nombre y
    // el glob deja de casar, el build seguiria en verde y el quiosco arrancaria
    // sin red con la tipografia del sistema. Se lee el manifiesto real.
    const swPath = path.join(distDir, 'sw.js')
    if (!existsSync(swPath)) {
      fail(
        'No existe dist/sw.js: el service worker no se ha generado.',
        'revisa que `VitePWA` siga usando la estrategia `generateSW` en vite.config.ts.',
      )
    }
    const swContent = readFileSync(swPath, 'utf8')
    const precachedWoff2 = [...swContent.matchAll(/url:"([^"]+)"/g)]
      .map((match) => match[1])
      .filter((url) => url.endsWith('.woff2'))
    const missingFamilies = [
      ['Poppins', /poppins/i],
      ['Inter', /inter/i],
    ].filter(([, pattern]) => !precachedWoff2.some((url) => pattern.test(url)))

    if (missingFamilies.length > 0) {
      fail(
        'El precacheo del quiosco (dist/sw.js) no incluye ningun .woff2 de ' +
          missingFamilies.map(([name]) => name).join(' ni ') +
          '.',
        'revisa el glob `**/*-latin-*.woff2` de `workbox.globPatterns` en ' +
          'vite.config.ts contra los ficheros que genera @kronoqr/web-kit/fonts.css.',
      )
    }
  },
})
