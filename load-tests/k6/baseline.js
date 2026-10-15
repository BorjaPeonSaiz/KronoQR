// Construye `baseline.json` a partir de VARIAS pasadas de `load-test.yml` con
// la misma carga (bloque 15 de la 2.2.1).
//
// POR QUE NO VALE UNA SOLA PASADA. El runner de GitHub comparte 4 vCPU entre
// el servidor y los once generadores, y su vecino de hipervisor no es siempre
// el mismo: con el MISMO codigo y los mismos parametros, el p95 del camino de
// escritura salio 287 ms en una pasada y 401,6 ms en la siguiente (+40 %). Una
// linea base de una pasada convierte esa lotería en el veredicto de RNF-P-02.
//
// EL CRITERIO: LA MEDIANA POR METRICA. Con tres pasadas, la mediana descarta la
// mas rapida y la mas lenta sin suponer ninguna distribucion, y no la mueve una
// pasada atipica como si la moveria la media. Se calcula metrica a metrica (el
// p95 de una pasada y la tasa de otra pueden ser la mediana cada una), porque
// lo que se compara despues es metrica a metrica.
//
// QUE SE EXIGE A LAS PASADAS: la misma carga —instancias, fichajes/s, meseta,
// rampa, periodo del PIN y escenarios—. Si alguna difiere, no se construye
// nada: una mediana de cargas distintas no es la linea base de ninguna.
//
// Uso:
//   node baseline.js --out=baseline.json <run_id>=<summary.json> <run_id>=<summary.json> ...
//
// Las funciones puras se exportan para `aggregate.test.js`.

'use strict'

const fs = require('fs')

const median = (values) => {
  const sorted = values.filter((value) => typeof value === 'number').sort((a, b) => a - b)

  if (sorted.length === 0) {
    return null
  }

  const middle = Math.floor(sorted.length / 2)

  return sorted.length % 2 === 1 ? sorted[middle] : Math.round(((sorted[middle - 1] + sorted[middle]) / 2) * 10) / 10
}

/** Mediana de los p50 de las tres clases de rechazo de credencial (RS-03). */
const medianRejectP50 = (summary) =>
  median(Object.values(summary.reject_classes ?? {}).map((rejectClass) => rejectClass.p50 ?? null))

const ratioOrNull = (numerator, denominator) =>
  typeof numerator === 'number' && typeof denominator === 'number' && denominator > 0
    ? Math.round((numerator / denominator) * 100) / 100
    : null

/** Los parametros que hacen comparables dos pasadas (los mismos que exige aggregate.js). */
const loadShape = (summary) => ({
  instances: summary.instances,
  scan_rate: summary.scan_rate,
  duration_seconds: summary.duration_seconds,
  ramp_seconds: summary.ramp_seconds ?? null,
  pin_period_seconds: summary.pin_period_seconds ?? null,
  scenarios: Object.keys(summary.scenarios ?? {}).sort().join(' '),
})

/**
 * @param {{id: string, summary: object}[]} runs
 * @returns {object} el contenido de `baseline.json`
 */
function buildBaseline(runs) {
  if (runs.length < 3) {
    throw new Error(`Hacen falta al menos 3 pasadas para la mediana; hay ${runs.length}.`)
  }

  const shapes = runs.map((run) => JSON.stringify(loadShape(run.summary)))

  if (new Set(shapes).size !== 1) {
    throw new Error(
      'Las pasadas no tienen la misma carga y su mediana no seria la linea base de ninguna:\n' +
        runs.map((run, index) => `  ${run.id}: ${shapes[index]}`).join('\n'),
    )
  }

  const pick = (read) => median(runs.map((run) => read(run.summary)))
  const shape = loadShape(runs[0].summary)
  const scenarioNames = shape.scenarios.split(' ').filter(Boolean)
  const writeP95 = runs.map((run) => run.summary.write_path?.p95 ?? null)
  const rates = runs.map((run) => run.summary.totals?.shift_entries_per_second ?? null)
  const writeP95Median = median(writeP95)

  return {
    baseline_of: {
      criterion:
        'mediana por metrica de las pasadas listadas, todas con la misma carga (baseline.js). ' +
        'Una sola pasada no vale: el runner varia mas de un 25 % entre pasadas del mismo codigo.',
      runs: runs.map((run) => ({
        run_id: run.id,
        git_sha: run.summary.git_sha ?? null,
        harness_sha: run.summary.harness_sha ?? null,
        generated_at: run.summary.generated_at ?? null,
        write_path_p95: run.summary.write_path?.p95 ?? null,
        scan_p95: run.summary.scenarios?.scan?.p95 ?? null,
        shift_entries_per_second: run.summary.totals?.shift_entries_per_second ?? null,
        pin_p95: run.summary.pin_path?.p95 ?? null,
        // La velocidad de la maquina en esa pasada: la mediana de los rechazos
        // de credencial, que hacen el mismo trabajo poco y fijo en cualquier
        // version. Y el p95 de escritura y el del PIN medidos en esa unidad:
        // la evidencia por pasada de lo que juzgan RNF-P-02 y RNF-P-08 en modo
        // `baseline` (decisiones de 15-10-2026).
        reject_p50: medianRejectP50(run.summary),
        write_path_p95_over_reject_p50: ratioOrNull(run.summary.write_path?.p95, medianRejectP50(run.summary)),
        pin_p95_over_reject_p50: ratioOrNull(run.summary.pin_path?.p95, medianRejectP50(run.summary)),
      })),
      // La dispersion observada, para que quien mire la tolerancia de
      // aggregate.js vea contra que se fijo: la pasada mas lenta frente a la
      // mediana, y la mas rapida.
      spread: {
        write_path_p95_max_over_median:
          writeP95Median === null ? null : Math.round((Math.max(...writeP95) / writeP95Median) * 1000) / 1000,
        write_path_p95_min_over_median:
          writeP95Median === null ? null : Math.round((Math.min(...writeP95) / writeP95Median) * 1000) / 1000,
        shift_rate_min_over_median:
          median(rates) === null ? null : Math.round((Math.min(...rates) / median(rates)) * 1000) / 1000,
      },
    },
    git_sha: null,
    runner: runs[0].summary.runner ?? null,
    k6_version: runs[0].summary.k6_version ?? null,
    k6_image: runs[0].summary.k6_image ?? null,
    instances: shape.instances,
    duration_seconds: shape.duration_seconds,
    scan_rate: shape.scan_rate,
    offered_scan_rate: runs[0].summary.offered_scan_rate ?? null,
    ramp_seconds: shape.ramp_seconds,
    pin_period_seconds: shape.pin_period_seconds,
    latency_verdict_mode: 'baseline',
    scenarios: Object.fromEntries(
      scenarioNames.map((name) => [
        name,
        {
          p50: pick((summary) => summary.scenarios[name]?.p50 ?? null),
          p95: pick((summary) => summary.scenarios[name]?.p95 ?? null),
          p99: pick((summary) => summary.scenarios[name]?.p99 ?? null),
        },
      ]),
    ),
    write_path: {
      p50: pick((summary) => summary.write_path?.p50 ?? null),
      p95: writeP95Median,
      p99: pick((summary) => summary.write_path?.p99 ?? null),
      // Lo que juzga RNF-P-02 en modo `baseline` (decision del propietario,
      // 15-10-2026): el p95 de escritura en unidades de «velocidad de la
      // maquina» de cada pasada. Mediana de los cocientes por pasada, no
      // cociente de medianas: cada cociente es de una misma maquina.
      p95_over_reject_p50: median(
        runs.map((run) => ratioOrNull(run.summary.write_path?.p95, medianRejectP50(run.summary))),
      ),
    },
    // La velocidad de la maquina de cada clase de rechazo, en mediana: es lo
    // que lee aggregate.js si una linea base no trae el cociente.
    reject_classes: Object.fromEntries(
      ['signature', 'unknown', 'revoked'].map((rejectClass) => [
        rejectClass,
        { p50: pick((summary) => summary.reject_classes?.[rejectClass]?.p50 ?? null) },
      ]),
    ),
    pin_path: {
      p50: pick((summary) => summary.pin_path?.p50 ?? null),
      p95: pick((summary) => summary.pin_path?.p95 ?? null),
      p99: pick((summary) => summary.pin_path?.p99 ?? null),
      // Lo que juzga RNF-P-08 en modo `baseline` (decision del propietario,
      // 15-10-2026): el p95 del PIN en unidades de velocidad de la maquina,
      // con el mismo criterio que `write_path.p95_over_reject_p50`.
      p95_over_reject_p50: median(
        runs.map((run) => ratioOrNull(run.summary.pin_path?.p95, medianRejectP50(run.summary))),
      ),
    },
    totals: {
      shift_entries_per_second: median(rates),
    },
  }
}

function main() {
  const args = process.argv.slice(2)
  const out = args.find((arg) => arg.startsWith('--out='))?.slice('--out='.length)
  const runs = args
    .filter((arg) => !arg.startsWith('--'))
    .map((arg) => {
      const separator = arg.indexOf('=')

      if (separator < 1) {
        throw new Error(`Argumento no valido: ${arg}. Formato: <run_id>=<summary.json>`)
      }

      return { id: arg.slice(0, separator), summary: JSON.parse(fs.readFileSync(arg.slice(separator + 1), 'utf8')) }
    })

  if (out === undefined) {
    throw new Error('Falta --out=<fichero>.')
  }

  fs.writeFileSync(out, `${JSON.stringify(buildBaseline(runs), null, 2)}\n`)
}

module.exports = { buildBaseline, median }

if (require.main === module) {
  main()
}
