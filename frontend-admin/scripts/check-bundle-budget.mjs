#!/usr/bin/env node
//
// KronoQR — presupuesto de rendimiento del panel de gestion (doc 02, Anexo A;
// RNF-P-04, carga del panel).
//
//   JS critico (gzip)  <= 190 KiB
//   CSS       (gzip)   <=  40 KiB
//
// La mecanica es comun con el quiosco y vive en
// `packages/web-kit/scripts/bundle-budget.mjs`; aqui solo los umbrales del panel.
//
// Medido al introducir la carga diferida de vistas (bloque 17 de la 2.2.1):
// antes ~247 KiB de JS gzip solo en el chunk de entrada (las ~27 vistas
// importadas de forma sincrona); despues 151,5 KiB en total critico (entrada +
// modulepreload). El umbral deja ~20 % de margen sobre lo medido: sirve para
// cazar que alguien vuelva a importar una vista (o una libreria pesada como
// ECharts) de forma sincrona, no para pelear por 2 KiB.

import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { checkBundleBudget } from '../../packages/web-kit/scripts/bundle-budget.mjs'

const KIB = 1024
const JS_BUDGET_BYTES = 190 * KIB
const CSS_BUDGET_BYTES = 40 * KIB

checkBundleBudget({
  appDir: path.resolve(fileURLToPath(new URL('..', import.meta.url))),
  title: 'Presupuesto del panel (doc 02, Anexo A; RNF-P-04)',
  jsBudgetBytes: JS_BUDGET_BYTES,
  cssBudgetBytes: CSS_BUDGET_BYTES,
  deferredTitle: 'JS diferido (vistas y librerias por import(), no bloquea el primer pintado)',
  exceededRemedy:
    'carga con `() => import(...)` en router/index.ts las vistas que no sean la de aterrizaje ' +
    'y no importes de forma sincrona librerias pesadas (ECharts); o justifica y actualiza ' +
    'el Anexo A del doc 02 antes de subir el limite.',
})
