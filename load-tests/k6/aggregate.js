// Agrega las muestras crudas de N instancias de k6 y da el veredicto de
// RNF-P-02 sobre el CONJUNTO. Los percentiles no se pueden promediar entre
// instancias (el p95 de cinco p95 no es el p95 del total), asi que cada
// instancia exporta sus muestras en CSV y aqui se ordenan todas juntas.
//
// Uso: node aggregate.js <directorio con instance-*.csv>

const fs = require('fs')
const path = require('path')

const dir = process.argv[2]
if (!dir) {
  console.error('Uso: node aggregate.js <directorio de resultados>')
  process.exit(2)
}

const P95_BUDGET_MS = 150 // RNF-P-02
const P99_BUDGET_MS = 400 // RNF-P-02

const durations = []
const byStatus = new Map()

for (const file of fs.readdirSync(dir).filter((f) => /^instance-\d+\.csv$/.test(f))) {
  const lines = fs.readFileSync(path.join(dir, file), 'utf8').split('\n')
  const header = lines[0].split(',')
  const metricCol = header.indexOf('metric_name')
  const valueCol = header.indexOf('metric_value')
  const statusCol = header.indexOf('status')

  for (let i = 1; i < lines.length; i++) {
    const row = lines[i].split(',')
    if (row[metricCol] !== 'http_req_duration') continue
    durations.push(parseFloat(row[valueCol]))
    const status = row[statusCol] || '(sin status)'
    byStatus.set(status, (byStatus.get(status) || 0) + 1)
  }
}

if (durations.length === 0) {
  console.error('Sin muestras de http_req_duration: la prueba no llego a ejecutar peticiones.')
  process.exit(2)
}

durations.sort((a, b) => a - b)
const pct = (p) => durations[Math.min(durations.length - 1, Math.ceil((p / 100) * durations.length) - 1)]
const fmt = (n) => n.toFixed(1).padStart(8)

console.log(`Muestras agregadas: ${durations.length}`)
console.log(`  p50 ${fmt(pct(50))} ms`)
console.log(`  p95 ${fmt(pct(95))} ms   (presupuesto ${P95_BUDGET_MS} ms, RNF-P-02)`)
console.log(`  p99 ${fmt(pct(99))} ms   (presupuesto ${P99_BUDGET_MS} ms)`)
console.log(`  max ${fmt(durations[durations.length - 1])} ms`)
console.log('Respuestas por status:')
for (const [status, count] of [...byStatus.entries()].sort()) {
  console.log(`  ${status}: ${count}`)
}

const rejected = [...byStatus.entries()]
  .filter(([s]) => !['200', '201', '429'].includes(s))
  .reduce((acc, [, c]) => acc + c, 0)

let exit = 0
if (pct(95) > P95_BUDGET_MS || pct(99) > P99_BUDGET_MS) {
  console.error('VEREDICTO: fuera del presupuesto de RNF-P-02.')
  exit = 1
}
if (rejected > 0) {
  console.error(`VEREDICTO: ${rejected} respuestas inesperadas (ni aceptadas ni 429): la medida no es fiable.`)
  exit = 1
}
if (exit === 0) {
  console.log('VEREDICTO: dentro del presupuesto de RNF-P-02.')
}
process.exit(exit)
