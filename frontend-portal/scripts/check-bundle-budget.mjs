#!/usr/bin/env node
//
// KronoQR — presupuesto de rendimiento del portal del empleado (doc 02, Anexo A;
// 2.3.0, tarea 8.8).
//
//   JS critico (gzip)  <= 85 KiB
//   CSS       (gzip)   <= 10 KiB
//
// El portal se abre desde el movil personal, con datos propios: el arranque tiene
// que ser pequeno. La mecanica es comun con el quiosco y el panel y vive en
// `packages/web-kit/scripts/bundle-budget.mjs`; aqui solo los umbrales del portal.
//
// Medido al introducir la carga diferida de pantallas: 67,8 KiB de JS critico y
// 5,1 KiB de CSS (antes, con todo en un unico chunk, ~70 KiB de JS). El umbral
// deja ~20 % de margen en JS y mas en CSS (Tailwind crece con las pantallas): sirve
// para cazar que alguien vuelva a importar una pantalla de forma sincrona o
// anada una libreria pesada, no para pelear por 2 KiB.

import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { checkBundleBudget } from '../../packages/web-kit/scripts/bundle-budget.mjs'

const KIB = 1024
const JS_BUDGET_BYTES = 85 * KIB
const CSS_BUDGET_BYTES = 10 * KIB

checkBundleBudget({
  appDir: path.resolve(fileURLToPath(new URL('..', import.meta.url))),
  title: 'Presupuesto del portal (doc 02, Anexo A)',
  jsBudgetBytes: JS_BUDGET_BYTES,
  cssBudgetBytes: CSS_BUDGET_BYTES,
  deferredTitle: 'JS diferido (pantallas por import(), no bloquea el primer pintado)',
  exceededRemedy:
    'carga con `() => import(...)` en router/index.ts las pantallas que no sean el acceso ' +
    'y no importes de forma sincrona librerias pesadas; o justifica y actualiza ' +
    'el Anexo A del doc 02 antes de subir el limite.',
})
