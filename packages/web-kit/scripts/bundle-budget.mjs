// KronoQR — comprobacion comun del presupuesto de JS y CSS de una SPA
// (doc 02, Anexo A; RNF-P-07 quiosco, RNF-P-04 panel).
//
// "JS critico" no es "todo el JS de dist/": es lo que el navegador necesita para
// pintar la primera pantalla. Se mide leyendo el `dist/index.html` construido y
// sumando lo que referencia: el script de entrada, sus modulepreload y sus
// hojas de estilo. Los trozos por import() dinamico se informan aparte.
//
// Cada SPA llama a `checkBundleBudget` desde un script fino
// (`frontend-*/scripts/check-bundle-budget.mjs`) que lleva SUS umbrales a la
// vista; aqui vive la mecanica, para que las dos no diverjan.
//
// Falla (codigo 1) si: no hay dist/index.html; no referencia nada; no se
// resuelve NINGUN .js critico (un prefijo `KRONOQR_BASE` mal puesto dejaria el
// presupuesto en 0 KiB y pasaria en falso); se excede un presupuesto; un trozo
// critico contiene un marcador prohibido; o falla una comprobacion extra.

import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import path from 'node:path'
import { gzipSync } from 'node:zlib'

const KIB = 1024
const kib = (bytes) => (bytes / KIB).toFixed(1) + ' KiB'

/**
 * @param {object} options
 * @param {string} options.appDir Raiz de la SPA (la que contiene dist/).
 * @param {string} options.title Titulo del informe.
 * @param {number} options.jsBudgetBytes
 * @param {number} options.cssBudgetBytes
 * @param {string} options.exceededRemedy Que hacer si se excede.
 * @param {string} [options.deferredTitle]
 * @param {ReadonlyArray<readonly [string, string]>} [options.forbiddenMarkers]
 *   Pares [nombre, cadena]: si un JS critico contiene la cadena, falla.
 * @param {RegExp} [options.serviceWorkerPattern] JS que se informa aparte.
 * @param {(context: {distDir: string, fail: (message: string, remedy: string) => never}) => void} [options.extraChecks]
 */
export function checkBundleBudget(options) {
  const distDir = path.join(options.appDir, 'dist')
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
  for (const match of html.matchAll(
    /<link[^>]+rel="(?:stylesheet|modulepreload|preload)"[^>]*>/g,
  )) {
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
  // Con `base: '/kiosk/'` o `'/admin/'` las referencias llevan el prefijo; dist/ no.
  const base = process.env.KRONOQR_BASE ?? '/'
  const toDist = (ref) => {
    const clean = ref.split('?')[0]
    const relative = clean.startsWith(base) ? clean.slice(base.length) : clean
    return path.join(distDir, relative.replace(/^\//, ''))
  }
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
      'si la SPA se sirve bajo otro prefijo, exporta KRONOQR_BASE con el mismo valor del build.',
    )
  }

  for (const entry of critical.js) {
    const content = readFileSync(path.join(distDir, entry.name), 'utf8')
    const found = (options.forbiddenMarkers ?? [])
      .filter(([, marker]) => content.includes(marker))
      .map(([name]) => name)
    if (found.length > 0) {
      fail(
        entry.name + ' (JS critico) contiene ' + found.join(' y ') + '.',
        'cargalo con import() dinamico tras el primer pintado: no puede estar en el camino critico.',
      )
    }
  }

  options.extraChecks?.({ distDir, fail })

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
  const isServiceWorker = (file) => options.serviceWorkerPattern?.test(file) ?? false
  const jsOutsideCritical = allFiles.filter(
    (file) => file.endsWith('.js') && !criticalNames.has(rel(file)),
  )
  const sized = (files) => files.map((file) => ({ name: rel(file), size: gzipSize(file) }))
  const deferred = sized(jsOutsideCritical.filter((file) => !isServiceWorker(file)))
  const serviceWorker = sized(jsOutsideCritical.filter(isServiceWorker))

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

  process.stdout.write('\n' + options.title + ' — tamanos gzip\n\n')
  report('JS critico', critical.js)
  report('CSS', critical.css)
  report(
    options.deferredTitle ?? 'JS diferido (import dinamico, no bloquea el primer pintado)',
    deferred,
  )
  report('Service worker (contexto aparte, no bloquea el primer pintado)', serviceWorker)

  const rows = [
    ['JS critico', criticalJs, options.jsBudgetBytes],
    ['CSS', criticalCss, options.cssBudgetBytes],
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
    fail('Presupuesto de rendimiento excedido (' + options.title + ').', options.exceededRemedy)
  }

  process.stdout.write('[presupuesto] Dentro de presupuesto.\n')
}
