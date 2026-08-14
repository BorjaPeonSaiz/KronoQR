#!/usr/bin/env node
//
// KronoQR — presupuesto de rendimiento del quiosco (doc 02, Anexo A; RNF-P-07).
//
//   JS critico (gzip)  <= 250 KiB
//   CSS       (gzip)   <=  40 KiB
//
// "JS critico" no es "todo el JS de dist/": es lo que el navegador necesita
// para pintar la primera pantalla. Se mide justamente eso, leyendo el
// index.html construido y sumando los recursos que referencia —el script de
// entrada, sus modulepreload y sus hojas de estilo—. Los trozos que llegan por
// import() dinamico y el service worker se cuentan aparte y se informan, porque
// no bloquean el primer pintado.
//
// El presupuesto se comprueba EN EL BUILD, no en una revision manual: una
// tablet de gama media que tarda 4 s en arrancar el quiosco es una cola de
// gente esperando a las 06:00.
//
// Codigos de salida:
//   0  dentro de presupuesto
//   1  presupuesto excedido, o dist/ no existe o no tiene forma reconocible

import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { gzipSync } from 'node:zlib'

const KIB = 1024
const JS_BUDGET_BYTES = 250 * KIB
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
const toDist = (ref) => path.join(distDir, ref.replace(/^\//, '').split('?')[0])
const kib = (bytes) => (bytes / KIB).toFixed(1) + ' KiB'

const critical = { js: [], css: [] }
for (const ref of criticalRefs) {
  const file = toDist(ref)
  if (!existsSync(file)) continue
  const entry = { name: path.relative(distDir, file).replace(/\\/g, '/'), size: gzipSize(file) }
  if (file.endsWith('.js')) critical.js.push(entry)
  else if (file.endsWith('.css')) critical.css.push(entry)
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

const isCritical = (file) =>
  [...critical.js, ...critical.css].some(
    (entry) => entry.name === path.relative(distDir, file).replace(/\\/g, '/'),
  )
const isServiceWorker = (file) => /(^|[\\/])(sw|workbox-[^\\/]+)\.js$/.test(file)

const deferred = allFiles
  .filter((file) => file.endsWith('.js') && !isCritical(file) && !isServiceWorker(file))
  .map((file) => ({ name: path.relative(distDir, file).replace(/\\/g, '/'), size: gzipSize(file) }))
const serviceWorker = allFiles
  .filter((file) => file.endsWith('.js') && isServiceWorker(file))
  .map((file) => ({ name: path.relative(distDir, file).replace(/\\/g, '/'), size: gzipSize(file) }))

const total = (entries) => entries.reduce((sum, entry) => sum + entry.size, 0)
const criticalJs = total(critical.js)
const criticalCss = total(critical.css)

const report = (title, entries) => {
  if (entries.length === 0) return
  process.stdout.write('  ' + title + '\n')
  for (const entry of entries.sort((a, b) => b.size - a.size)) {
    process.stdout.write('    ' + entry.name.padEnd(46) + kib(entry.size).padStart(10) + '\n')
  }
}

process.stdout.write('\nPresupuesto del quiosco (doc 02, Anexo A) — tamanos gzip\n\n')
report('JS critico', critical.js)
report('CSS', critical.css)
report('JS diferido (import dinamico, no bloquea el primer pintado)', deferred)
report('Service worker (contexto aparte, no bloquea el primer pintado)', serviceWorker)

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
    'Presupuesto de rendimiento del quiosco excedido.',
    'divide en trozos con import() lo que no haga falta para el primer escaneo, ' +
      'o justifica y actualiza el Anexo A del doc 02 antes de subir el limite.',
  )
}

process.stdout.write('[presupuesto] Dentro de presupuesto.\n')
