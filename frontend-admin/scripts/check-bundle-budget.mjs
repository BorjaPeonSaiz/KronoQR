#!/usr/bin/env node
//
// KronoQR — presupuesto de rendimiento del panel de gestion (doc 02, Anexo A;
// RNF-P-04, carga del panel).
//
//   JS critico (gzip)  <= 190 KiB
//   CSS       (gzip)   <=  40 KiB
//
// "JS critico" es lo que el navegador necesita para pintar la primera
// pantalla: el script de entrada, sus modulepreload y sus hojas de estilo,
// tal y como los referencia dist/index.html. Las vistas llegan por import()
// dinamico desde router/index.ts y se informan aparte, porque no bloquean el
// primer pintado.
//
// Medido al introducir la carga diferida de vistas (bloque 17 de la 2.2.1):
// antes ~247 KiB de JS gzip solo en el chunk de entrada (las ~27 vistas importadas de
// forma sincrona); despues 151,5 KiB en total critico (entrada + modulepreload). El umbral deja ~20 %
// de margen sobre lo medido: sirve para cazar que alguien vuelva a importar
// una vista (o una libreria pesada como ECharts) de forma sincrona, no para
// pelear por 2 KiB.
//
// Codigos de salida:
//   0  dentro de presupuesto
//   1  presupuesto excedido, o dist/ no existe o no tiene forma reconocible

import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { gzipSync } from 'node:zlib'

const KIB = 1024
const JS_BUDGET_BYTES = 190 * KIB
const CSS_BUDGET_BYTES = 40 * KIB

const appDir = path.resolve(fileURLToPath(new URL('..', import.meta.url)))
const distDir = path.join(appDir, 'dist')
const indexPath = path.join(distDir, 'index.html')

const fail = (message, remedy) => {
  process.stderr.write('[presupuesto] ' + message + '\n')
  process.stderr.write('[presupuesto] Que hacer: ' + remedy + '\n')
  process.exit(1)
}

if (!existsSync(indexPath)) {
  fail(
    'No existe dist/index.html.',
    'ejecuta `npm run build`, que construye antes de comprobar el presupuesto.',
  )
}

const html = readFileSync(indexPath, 'utf8')

/** Recursos que el HTML de entrada pide de forma sincrona o con modulepreload. */
const criticalRefs = new Set()
for (const match of html.matchAll(/<script[^>]+src="([^"]+)"/g)) {
  criticalRefs.add(match[1])
}
for (const match of html.matchAll(/<link[^>]+rel="(?:stylesheet|modulepreload|preload)"[^>]*>/g)) {
  const href = /href="([^"]+)"/.exec(match[0])
  if (href) criticalRefs.add(href[1])
}

if (criticalRefs.size === 0) {
  fail(
    'dist/index.html no referencia ningun recurso: el build no ha producido nada que medir.',
    'revisa vite.config.ts y vuelve a construir.',
  )
}

const gzipSize = (file) => gzipSync(readFileSync(file), { level: 9 }).byteLength
// Con `base: '/admin/'` las referencias llevan el prefijo; dist/ no.
const base = process.env.KRONOQR_BASE ?? '/'
const toDist = (ref) => {
  const clean = ref.split('?')[0]
  const relative = clean.startsWith(base) ? clean.slice(base.length) : clean
  return path.join(distDir, relative.replace(/^\//, ''))
}
const kib = (bytes) => (bytes / KIB).toFixed(1) + ' KiB'
const rel = (file) => path.relative(distDir, file).replaceAll(path.sep, '/')

const critical = { js: [], css: [] }
for (const ref of criticalRefs) {
  const file = toDist(ref)
  if (!existsSync(file)) continue
  const entry = { name: rel(file), size: gzipSize(file) }
  if (file.endsWith('.js')) critical.js.push(entry)
  else if (file.endsWith('.css')) critical.css.push(entry)
}

if (critical.js.length === 0) {
  fail(
    'No se ha podido resolver ningun .js critico de dist/index.html.',
    'si el panel se sirve bajo otro prefijo, exporta KRONOQR_BASE con el mismo valor del build.',
  )
}

/** Todo lo demas que hay en dist/, para informar sin contarlo como critico. */
const allFiles = []
const walk = (dir) => {
  for (const name of readdirSync(dir)) {
    const full = path.join(dir, name)
    if (statSync(full).isDirectory()) walk(full)
    else allFiles.push(full)
  }
}
walk(distDir)

const criticalNames = new Set([...critical.js, ...critical.css].map((entry) => entry.name))
const deferred = allFiles
  .filter((file) => file.endsWith('.js') && !criticalNames.has(rel(file)))
  .map((file) => ({ name: rel(file), size: gzipSize(file) }))

const total = (entries) => entries.reduce((sum, entry) => sum + entry.size, 0)
const criticalJs = total(critical.js)
const criticalCss = total(critical.css)

const report = (title, entries) => {
  if (entries.length === 0) return
  process.stdout.write('  ' + title + '\n')
  for (const entry of [...entries].sort((a, b) => b.size - a.size)) {
    process.stdout.write('    ' + entry.name.padEnd(46) + kib(entry.size).padStart(10) + '\n')
  }
}

process.stdout.write('\nPresupuesto del panel (doc 02, Anexo A; RNF-P-04) — tamanos gzip\n\n')
report('JS critico', critical.js)
report('CSS', critical.css)
report('JS diferido (vistas y librerias por import(), no bloquea el primer pintado)', deferred)

const rows = [
  ['JS critico', criticalJs, JS_BUDGET_BYTES],
  ['CSS', criticalCss, CSS_BUDGET_BYTES],
]

process.stdout.write('\n  Recurso        Medido        Presupuesto      Margen\n')
let exceeded = false
for (const [name, measured, budget] of rows) {
  const margin = budget - measured
  if (margin < 0) exceeded = true
  const pct = ((measured / budget) * 100).toFixed(1)
  process.stdout.write(
    '  ' +
      name.padEnd(14) +
      kib(measured).padStart(10) +
      kib(budget).padStart(16) +
      (margin >= 0 ? kib(margin) : '-' + kib(-margin)).padStart(12) +
      '  (' +
      pct +
      ' % del presupuesto)\n',
  )
}
process.stdout.write('\n')

if (exceeded) {
  fail(
    'Presupuesto de rendimiento del panel excedido.',
    'carga con `() => import(...)` en router/index.ts las vistas que no sean la de aterrizaje ' +
      'y no importes de forma sincrona librerias pesadas (ECharts); o justifica y actualiza ' +
      'el Anexo A del doc 02 antes de subir el limite.',
  )
}

process.stdout.write('[presupuesto] Dentro de presupuesto.\n')
