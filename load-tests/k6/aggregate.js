// Agrega las muestras crudas de todas las instancias de k6 y da el veredicto
// POR REQUISITO. Node sin dependencias, a proposito: la prueba de carga no
// puede traerse un arbol de paquetes al que auditar.
//
// POR QUE AGREGAR Y NO LEER EL RESUMEN DE CADA INSTANCIA. Los percentiles no se
// promedian: el p95 de diez p95 no es el p95 del total. Cada instancia exporta
// sus muestras en CSV y aqui se ordenan todas juntas.
//
// COMO SABE ESTE SCRIPT QUE MIDE. Las etiquetas viajan en las propias muestras:
// `scenario` es etiqueta de sistema de k6, y `requirements`, `phase` y
// `reject_class` salen de `scan-peak.js` como etiquetas de escenario o de
// peticion. De ahi que el veredicto se pueda dar por requisito sin que el
// agregado tenga que saberse el guion de memoria.
//
// TRES DESENLACES Y NO DOS. Un requisito sale `pass`, `fail` o **`unmeasurable`**.
// El tercero existe porque un verde que se apoya en cuatro muestras no es un
// verde: cuando el borde frena el 80 % del trafico, «0 reenvios distintos» se
// cumple tambien sin haber comparado ninguno. Un requisito no evaluable termina
// con salida 2, que es «vuelve a medir», y nunca con 0.
//
// Uso:
//   node aggregate.js <directorio con instance-*.csv> --duration=120 --instances=10
//        [--scan-rate 6] [--rejection-floor-ms 25] [--debounce-seconds 60]
//        [--git-sha abc1234] [--runner ubuntu-24.04] [--k6-version v2.2.0]
//        [--k6-image grafana/k6:2.2.0@sha256:…]
//        [--edge-limits fichero.json] [--server-metrics fichero.json]
//        [--baseline load-tests/k6/baseline.json]
//        [--latency-verdict threshold|baseline]
//
// Las funciones puras se exportan para `aggregate.test.js`:
//   node --test load-tests/k6/aggregate.test.js

'use strict'

const fs = require('fs')
const path = require('path')

// --- Presupuestos (doc 02 §9.2, doc 01 §6.1) ---------------------------------

const P95_BUDGET_MS = 150 // RNF-P-02
const P99_BUDGET_MS = 400 // RNF-P-02
const SHIFT_RATE_BUDGET = 50 // RNF-P-06: fichajes que producen tramo, por segundo
const QUEUEABLE_DEGRADATION_RATIO = 0.005 // regla dura 19: 429, 5xx y sin respuesta
const DEBOUNCE_CEILING_RATIO = 0.05 // el anti-rebote no puede ser la mayoria
const REJECT_SPREAD_BUDGET_MS = 20 // RS-03: separacion maxima entre clases
const DEFAULT_REJECTION_FLOOR_MS = 25 // si la instalacion no lo dice
const COMPLIANCE_BUDGET_MS = 2000 // orientativo: ningun requisito lo fija

/**
 * Cuanto puede empeorar el p95 frente a la linea base.
 *
 * El MISMO numero en los dos usos que tiene, y a proposito: en modo `threshold`
 * es un aviso sobre la tendencia y en modo `baseline` es el veredicto. Lo que
 * cambia es quien manda, no cuanto se tolera.
 */
const BASELINE_REGRESSION_RATIO = 1.25

/** Cuanto puede caer la tasa de tramos frente a la linea base (modo `baseline`). */
const BASELINE_RATE_TOLERANCE = 0.8

// Suelos de evaluabilidad.
const ATTENDED_SAMPLES_FLOOR = 30 // RNF-P-02
const RESEND_PAIRS_FLOOR = 10 // RQ-03, en valor absoluto
const RESEND_PAIRS_RATIO_FLOOR = 0.5 // RQ-03, sobre los pares intentados
const REJECT_SAMPLES_FLOOR = 20 // RS-03, en valor absoluto por clase
const REJECT_SAMPLES_RATIO_FLOOR = 0.5 // RS-03, sobre las ofrecidas por clase

/** Desenlaces que crean o cierran un tramo (RF-AT-02, RF-AT-03, RF-AT-12). */
const SHIFT_ACTIONS = new Set(['clock_in', 'clock_out', 'break_start', 'break_end'])

/**
 * Fases cuya respuesta cuenta para el pico de RNF-P-06.
 *
 * `batch` NO entra: RNF-P-06 es el pico del cambio de turno **en tiempo real**,
 * gente pasando la tarjeta por el quiosco, y un lote de cincuenta fichajes de
 * hace diez minutos inflaria la cifra con trabajo que no llego a la vez. Su tasa
 * se publica aparte.
 */
const REALTIME_PHASES = new Set(['scan', 'resend_original'])

/** Fases que son un fichaje valido: aqui un 4xx que no sea 429 es un rechazo al empleado. */
const VALID_SCAN_PHASES = new Set(['scan', 'resend_original', 'resend_replay', 'batch'])

const REJECT_CLASSES = ['signature', 'unknown', 'revoked']

/**
 * Los dos modos de juzgar la latencia y el pico.
 *
 * POR QUE EXISTE EL SEGUNDO (decision 19 de la ficha 3.6). En el runner de
 * GitHub —4 vCPU donde el servidor, la base de datos y los once generadores
 * comparten la misma maquina— el presupuesto de RNF-P-02 es inalcanzable por
 * construccion: a 12 fichajes/s el p95 ya esta en 157 ms, y a 60 ofrecidos el
 * servidor sostiene 29 tramos/s con un p95 de 26 s. Se comprobo ademas que
 * subir `PHP_FPM_MAX_CHILDREN` de 20 a 40 no mueve la cifra (27,8 tramos/s,
 * p95 27,6 s): el cuello es la CPU compartida, no el pool.
 *
 * Juzgar el umbral ahi solo puede dar dos resultados, y los dos son malos: un
 * rojo permanente que se aprende a ignorar, o un umbral rebajado que deja de
 * significar lo que dice el requisito. Asi que en el runner **no se juzga el
 * umbral**: se juzga la REGRESION contra la linea base tomada en ese mismo
 * runner. El umbral de RNF-P-02 y RNF-P-06 se sigue juzgando donde tiene
 * sentido —hardware de referencia, `make load-test`— y ahi el modo es
 * `threshold`, que es el de serie.
 */
const LATENCY_VERDICT_MODES = ['threshold', 'baseline']

// --- Lectura del CSV ---------------------------------------------------------

/**
 * Una linea de CSV, respetando las comillas.
 *
 * El escritor de Go entrecomilla cualquier campo con coma, comilla o salto de
 * linea, y `extra_tags` lleva cadenas con espacios: partir por comas sin mirar
 * las comillas desplazaria las columnas justo en las filas con mas informacion.
 */
function splitCsvLine(line) {
  const fields = []
  let field = ''
  let quoted = false

  for (let i = 0; i < line.length; i++) {
    const character = line[i]

    if (quoted && character === '"' && line[i + 1] === '"') {
      field += '"'
      i++
    } else if (character === '"') {
      quoted = !quoted
    } else if (character === ',' && !quoted) {
      fields.push(field)
      field = ''
    } else {
      field += character
    }
  }

  fields.push(field)

  return fields
}

/** `requirements=RS-03&phase=reject` → objeto. */
function parseTags(raw) {
  const tags = {}

  if (raw === undefined || raw === '') {
    return tags
  }

  for (const pair of raw.split('&')) {
    const separator = pair.indexOf('=')

    if (separator > 0) {
      tags[pair.slice(0, separator)] = pair.slice(separator + 1)
    }
  }

  return tags
}

function percentiles(values) {
  if (values.length === 0) {
    return { samples: 0, min: null, p50: null, p95: null, p99: null, max: null }
  }

  const sorted = [...values].sort((a, b) => a - b)
  const at = (p) => sorted[Math.min(sorted.length - 1, Math.ceil((p / 100) * sorted.length) - 1)]

  return {
    samples: sorted.length,
    min: sorted[0],
    p50: at(50),
    p95: at(95),
    p99: at(99),
    max: sorted[sorted.length - 1],
  }
}

/**
 * Lee los `instance-*.csv` de un directorio.
 *
 * @returns {{durations: object[], counters: object[], checks: Map<string, {passed: number, failed: number}>, statusesByScenario: Map<string, Map<string, number>>, requirementsByScenario: Map<string, string[]>, instances: string[]}}
 */
function readResults(dir) {
  const durations = []
  const counters = []
  const checks = new Map()
  const statusesByScenario = new Map()
  const requirementsByScenario = new Map()
  const instances = new Set()

  const files = fs.readdirSync(dir).filter((file) => /^instance-.+\.csv$/.test(file))

  for (const file of files) {
    const instance = file.replace(/^instance-/, '').replace(/\.csv$/, '')
    const lines = fs.readFileSync(path.join(dir, file), 'utf8').split('\n')
    const header = splitCsvLine(lines[0] ?? '')
    const column = {
      metric: header.indexOf('metric_name'),
      value: header.indexOf('metric_value'),
      check: header.indexOf('check'),
      scenario: header.indexOf('scenario'),
      status: header.indexOf('status'),
      extraTags: header.indexOf('extra_tags'),
    }

    if (column.metric < 0 || column.value < 0) {
      continue
    }

    instances.add(instance)

    for (let i = 1; i < lines.length; i++) {
      if (lines[i] === '') {
        continue
      }

      const row = splitCsvLine(lines[i])
      const metric = row[column.metric]
      const scenario = row[column.scenario] || '(sin escenario)'
      const tags = parseTags(row[column.extraTags])

      if (tags.requirements !== undefined && !requirementsByScenario.has(scenario)) {
        requirementsByScenario.set(scenario, tags.requirements.split(/\s+/).filter(Boolean))
      }

      if (metric === 'http_req_duration') {
        const status = row[column.status] || '(sin status)'

        durations.push({
          value: Number(row[column.value]),
          scenario,
          phase: tags.phase ?? scenario,
          rejectClass: tags.reject_class,
          instance,
          status,
        })

        const byStatus = statusesByScenario.get(scenario) ?? new Map()

        byStatus.set(status, (byStatus.get(status) ?? 0) + 1)
        statusesByScenario.set(scenario, byStatus)
        continue
      }

      // LOS `check()` DE K6 SON PARTE DEL VEREDICTO, no adorno del log. Ahi
      // viven las afirmaciones de contrato —que el cuerpo del rechazo es el
      // generico exacto, que el lote responde 207 con cincuenta resultados— y
      // `run.sh` tiene que ignorar el codigo de salida de cada instancia porque
      // sus umbrales son informativos. Sin leerlas aqui, un contrato roto bajo
      // carga pasaria en silencio.
      if (metric === 'checks') {
        const name = row[column.check] || '(sin nombre)'
        const tally = checks.get(name) ?? { passed: 0, failed: 0 }

        if (Number(row[column.value]) === 0) {
          tally.failed++
        } else {
          tally.passed++
        }

        checks.set(name, tally)
        continue
      }

      if (
        metric === 'scan_outcomes' ||
        metric === 'resend_matches' ||
        metric === 'batch_outcomes' ||
        metric === 'dropped_iterations'
      ) {
        counters.push({ metric, value: Number(row[column.value]), tags, scenario })
      }
    }
  }

  return {
    durations,
    counters,
    checks,
    statusesByScenario,
    requirementsByScenario,
    instances: [...instances].sort(),
  }
}

// --- Analisis ----------------------------------------------------------------

const isQueueable = (status) =>
  status === '429' || status === '0' || status === '(sin status)' || status.startsWith('5')

const isAttended = (status) => status === '200' || status === '207'

const round = (value) => (value === null || value === undefined ? null : Math.round(value * 10) / 10)

/**
 * El analisis completo, sin tocar disco ni proceso: entra lo que leyo
 * `readResults()` y sale el objeto que se escribe en `summary.json`.
 */
function analyse(results, options = {}) {
  const durationSeconds = Number(options.durationSeconds ?? 0)
  const instanceCount = Number(options.instances ?? results.instances.filter((id) => id !== 'panel').length)
  const scanRate = Number(options.scanRate ?? 0)
  const offeredRate = Number(options.offeredRate ?? instanceCount * scanRate)
  const rejectionFloorMs = Number(options.rejectionFloorMs ?? DEFAULT_REJECTION_FLOOR_MS)
  const { durations, counters, checks } = results

  const valuesWhere = (predicate) => durations.filter(predicate).map((sample) => sample.value)
  const countersWhere = (metric, predicate) =>
    counters
      .filter((sample) => sample.metric === metric && predicate(sample.tags, sample.scenario))
      .reduce((total, sample) => total + sample.value, 0)

  const verdicts = {}
  const verdict = (requirement, status, detail) => {
    verdicts[requirement] = { status, detail }
  }

  // --- Contra que se juzgan la latencia y el pico ----------------------------

  const mode = LATENCY_VERDICT_MODES.includes(options.latencyVerdictMode)
    ? options.latencyVerdictMode
    : 'threshold'

  const baseline = options.baseline ?? null
  const baselineP95 = baseline?.write_path?.p95 ?? null
  const baselineRate = baseline?.totals?.shift_entries_per_second ?? null

  // LA LINEA BASE SOLO VALE PARA LA MISMA PASADA. Comparar un p95 de diez
  // instancias con el de dos, o de 120 s con el de 30, es comparar dos cosas
  // distintas y llamarlo regresion. Si los parametros no coinciden, el veredicto
  // no es rojo: es que no hay con que juzgarlo.
  const baselineParameters = [
    ['instancias', baseline?.instances, instanceCount],
    ['fichajes/s por instancia', baseline?.scan_rate, scanRate],
    ['duracion', baseline?.duration_seconds, durationSeconds],
  ]
  const baselineMismatches = baselineParameters.filter(([, before, now]) => before !== now)

  const baselineUsable =
    baseline !== null && baselineP95 !== null && baselineRate !== null && baselineMismatches.length === 0

  const baselineReference =
    baseline === null
      ? ''
      : `linea base del runner: p95 ${baselineP95} ms, ${baselineRate} tramos/s ` +
        `(git_sha ${baseline.git_sha ?? 'n/d'})`

  const noBaselineDetail =
    baseline === null
      ? 'modo linea base sin --baseline: no hay con que comparar. La PRIMERA pasada de este ' +
        'runner es la que la crea; versiona su summary.json como baseline.json y vuelve a medir.'
      : `la linea base no es comparable con esta pasada (${baselineMismatches
          .map(([name, before, now]) => `${name}: ${before} frente a ${now}`)
          .join('; ')}). Mide con los mismos parametros o regenera la linea base.`

  /** La coletilla que explica por que el umbral no se esta juzgando. */
  const thresholdNotJudged =
    'El umbral de RNF-P-02 y RNF-P-06 NO se juzga en este runner (decision 19): ' +
    'solo en hardware de referencia con make load-test.'

  // --- Por escenario ---------------------------------------------------------

  const scenarioNames = [...new Set(durations.map((sample) => sample.scenario))].sort()
  const scenarios = {}

  for (const scenario of scenarioNames) {
    const stats = percentiles(valuesWhere((sample) => sample.scenario === scenario))

    // «Contestada por el servidor» y no «aceptada»: en el escenario de rechazo
    // la respuesta CORRECTA es un `422`, y medir su latencia sobre los `200`
    // dejaria la fila a cero. Lo que se descuenta es lo que el servidor no
    // llego a procesar —`429`, `5xx` y las que no tuvieron respuesta—, que no
    // dice nada de lo que tarda el producto.
    const answered = percentiles(
      valuesWhere((sample) => sample.scenario === scenario && !isQueueable(sample.status)),
    )

    scenarios[scenario] = {
      requirements: results.requirementsByScenario.get(scenario) ?? [],
      samples: stats.samples,
      answered_samples: answered.samples,
      p50: round(answered.p50),
      p95: round(answered.p95),
      p99: round(answered.p99),
      max: round(answered.max),
      statuses: Object.fromEntries([...(results.statusesByScenario.get(scenario) ?? new Map())].sort()),
    }
  }

  // --- Los `check()` de k6 ---------------------------------------------------

  const failedChecks = [...checks.entries()]
    .filter(([, tally]) => tally.failed > 0)
    .map(([name, tally]) => ({ name, failed: tally.failed, passed: tally.passed }))

  const totalChecks = [...checks.values()].reduce((total, tally) => total + tally.passed + tally.failed, 0)

  verdict(
    'CHECKS',
    totalChecks === 0 ? 'unmeasurable' : failedChecks.length === 0 ? 'pass' : 'fail',
    totalChecks === 0
      ? 'ninguna instancia emitio comprobaciones: la medida no llego a ejecutarse'
      : `${totalChecks} comprobaciones de k6, ${failedChecks.length} con fallos` +
          (failedChecks.length === 0
            ? ''
            : `: ${failedChecks.map((entry) => `«${entry.name}» ${entry.failed}`).join('; ')}`),
  )

  // --- RNF-P-02: latencia del endpoint de fichaje -----------------------------

  // SOLO RESPUESTAS ATENDIDAS. Un `429` del borde se resuelve en medio
  // milisegundo sin ejecutar una linea de PHP: mezclarlo con los fichajes
  // servidos hunde la mediana y disfraza de bueno un servidor saturado. El
  // recuento de lo frenado va aparte, en RNF-P-06.
  const isWritePath = (sample) => REALTIME_PHASES.has(sample.phase)
  const write = percentiles(valuesWhere((sample) => isWritePath(sample) && isAttended(sample.status)))

  const perInstance = results.instances
    .map((instance) => ({
      instance,
      stats: percentiles(
        valuesWhere(
          (sample) => sample.instance === instance && isWritePath(sample) && isAttended(sample.status),
        ),
      ),
    }))
    .filter((entry) => entry.stats.samples >= ATTENDED_SAMPLES_FLOOR)

  const instancesOutOfBudget = perInstance.filter(
    (entry) => entry.stats.p95 > P95_BUDGET_MS || entry.stats.p99 > P99_BUDGET_MS,
  )

  const latencyMeasurable = write.samples >= ATTENDED_SAMPLES_FLOOR

  const noSamplesDetail =
    `solo ${write.samples} fichajes atendidos, hacen falta ${ATTENDED_SAMPLES_FLOOR}: ` +
    'el servidor no llego a servir bastantes para medir un percentil'

  if (mode === 'threshold') {
    verdict(
      'RNF-P-02',
      !latencyMeasurable
        ? 'unmeasurable'
        : write.p95 <= P95_BUDGET_MS && write.p99 <= P99_BUDGET_MS && instancesOutOfBudget.length === 0
          ? 'pass'
          : 'fail',
      !latencyMeasurable
        ? noSamplesDetail
        : `p95 ${round(write.p95)} ms (presupuesto ${P95_BUDGET_MS}), p99 ${round(write.p99)} ms ` +
          `(presupuesto ${P99_BUDGET_MS}) sobre ${write.samples} fichajes atendidos; ` +
          `${instancesOutOfBudget.length} de ${perInstance.length} instancias fuera de presupuesto`,
    )
  } else {
    const allowedP95 = baselineP95 === null ? null : round(baselineP95 * BASELINE_REGRESSION_RATIO)

    verdict(
      'RNF-P-02',
      !latencyMeasurable || !baselineUsable
        ? 'unmeasurable'
        : write.p95 <= baselineP95 * BASELINE_REGRESSION_RATIO
          ? 'pass'
          : 'fail',
      !latencyMeasurable
        ? noSamplesDetail
        : !baselineUsable
          ? `${noBaselineDetail} ${thresholdNotJudged}`
          : `p95 ${round(write.p95)} ms sobre ${write.samples} fichajes atendidos, frente a un maximo ` +
            `de ${allowedP95} ms (${BASELINE_REGRESSION_RATIO}x la ${baselineReference}). ` +
            thresholdNotJudged,
    )
  }

  // --- RNF-P-06: 50 fichajes/s que producen tramo ----------------------------

  const realtimeShifts = countersWhere(
    'scan_outcomes',
    (tags) => REALTIME_PHASES.has(tags.phase) && SHIFT_ACTIONS.has(tags.action),
  )
  const batchShifts = countersWhere(
    'scan_outcomes',
    (tags) => tags.phase === 'batch' && SHIFT_ACTIONS.has(tags.action),
  )
  const debounced = countersWhere(
    'scan_outcomes',
    (tags) => REALTIME_PHASES.has(tags.phase) && tags.action === 'debounced',
  )

  const shiftRate = durationSeconds > 0 ? realtimeShifts / durationSeconds : 0
  const batchRate = durationSeconds > 0 ? batchShifts / durationSeconds : 0

  const validScanSamples = durations.filter((sample) => VALID_SCAN_PHASES.has(sample.phase))
  const queueable = validScanSamples.filter((sample) => isQueueable(sample.status))
  const refusals = validScanSamples.filter(
    (sample) => !isQueueable(sample.status) && !isAttended(sample.status),
  )
  const queueableRatio = validScanSamples.length === 0 ? 1 : queueable.length / validScanSamples.length
  const debounceRatio = realtimeShifts + debounced === 0 ? 1 : debounced / (realtimeShifts + debounced)

  // Si el generador no ofrecio 50/s, no hay nada que juzgar: una pasada de dos
  // instancias no puede decir si el servidor sostiene el pico.
  const peakOffered = offeredRate >= SHIFT_RATE_BUDGET

  const peakMeasured =
    `${realtimeShifts} tramos en tiempo real en ${durationSeconds} s = ${shiftRate.toFixed(1)}/s ` +
    `(ofrecidos ${offeredRate}/s); anti-rebote ${(debounceRatio * 100).toFixed(1)} %; ` +
    `degradacion encolable ${queueable.length} (${(queueableRatio * 100).toFixed(2)} %); ` +
    `rechazos al empleado ${refusals.length}`

  // EN LOS DOS MODOS: un `4xx` que no sea 429 sobre un fichaje valido es una
  // jornada perdida, y el anti-rebote por encima del techo significa que el
  // guion se esta midiendo a si mismo. Ninguna de las dos cosas depende de la
  // maquina, asi que ninguna se relaja contra la linea base.
  const alwaysRequired = refusals.length === 0 && debounceRatio <= DEBOUNCE_CEILING_RATIO

  if (mode === 'threshold') {
    verdict(
      'RNF-P-06',
      !peakOffered
        ? 'unmeasurable'
        : shiftRate >= SHIFT_RATE_BUDGET &&
            queueableRatio <= QUEUEABLE_DEGRADATION_RATIO &&
            alwaysRequired
          ? 'pass'
          : 'fail',
      !peakOffered
        ? `la carga ofrecida son ${offeredRate}/s y el umbral es ${SHIFT_RATE_BUDGET}/s: ` +
          'sube INSTANCES o SCAN_RATE para poder juzgarlo'
        : `${peakMeasured}; presupuesto ${SHIFT_RATE_BUDGET}/s y tope de degradacion ` +
          `${QUEUEABLE_DEGRADATION_RATIO * 100} %`,
    )
  } else {
    const allowedRate = baselineRate === null ? null : Math.round(baselineRate * BASELINE_RATE_TOLERANCE * 10) / 10

    verdict(
      'RNF-P-06',
      !peakOffered || !baselineUsable
        ? 'unmeasurable'
        : shiftRate >= baselineRate * BASELINE_RATE_TOLERANCE && alwaysRequired
          ? 'pass'
          : 'fail',
      !peakOffered
        ? `la carga ofrecida son ${offeredRate}/s y el umbral es ${SHIFT_RATE_BUDGET}/s: ` +
          'sube INSTANCES o SCAN_RATE para poder juzgarlo'
        : !baselineUsable
          ? `${noBaselineDetail} ${thresholdNotJudged}`
          : // La degradacion encolable NO se juzga aqui: en este runner es el
            // sintoma de la CPU compartida y ya esta contada en la tasa. Lo que
            // se exige es que la tasa no caiga respecto a lo que esta maquina ya
            // demostro sostener.
            `${peakMeasured}; minimo ${allowedRate}/s (${BASELINE_RATE_TOLERANCE}x la ${baselineReference}). ` +
            thresholdNotJudged,
    )
  }

  // --- RQ-03: idempotencia bajo carga ----------------------------------------

  const resendYes = countersWhere('resend_matches', (tags) => tags.match === 'yes')
  const resendNo = countersWhere('resend_matches', (tags) => tags.match === 'no')
  const resendSkipped = countersWhere('resend_matches', (tags) => tags.match === 'skipped')
  const resendAttempted = resendYes + resendNo + resendSkipped
  const resendComparable = resendYes + resendNo
  const resendMeasurable =
    resendComparable >= RESEND_PAIRS_FLOOR &&
    resendAttempted > 0 &&
    resendComparable / resendAttempted >= RESEND_PAIRS_RATIO_FLOOR

  verdict(
    'RQ-03',
    !resendMeasurable ? 'unmeasurable' : resendNo === 0 ? 'pass' : 'fail',
    `${resendYes} reenvios identicos al original, ${resendNo} distintos, ` +
      `${resendSkipped} sin respuesta del servidor en alguno de los dos envios` +
      (resendMeasurable
        ? ''
        : ` — NO EVALUABLE: ${resendComparable} pares comparables de ${resendAttempted} intentados ` +
          `(hacen falta ${RESEND_PAIRS_FLOOR} y el ${RESEND_PAIRS_RATIO_FLOOR * 100} %)`),
  )

  // --- RS-03: los tres rechazos, indistinguibles entre si --------------------

  const rejectStats = {}

  for (const rejectClass of REJECT_CLASSES) {
    // SOLO LAS QUE EL SERVIDOR CONTESTO (422). Un `429` del borde no ha
    // ejecutado ni una linea del resolutor de credenciales; meterlo en la
    // mediana convierte esta comprobacion en una medida del limitador de tasa.
    const answered = percentiles(
      valuesWhere((sample) => sample.rejectClass === rejectClass && sample.status === '422'),
    )
    const offered = durations.filter((sample) => sample.rejectClass === rejectClass).length

    rejectStats[rejectClass] = {
      samples: answered.samples,
      offered,
      min: round(answered.min),
      p50: round(answered.p50),
      p95: round(answered.p95),
      max: round(answered.max),
      measurable:
        answered.samples >= REJECT_SAMPLES_FLOOR &&
        offered > 0 &&
        answered.samples / offered >= REJECT_SAMPLES_RATIO_FLOOR,
      raw: answered,
    }
  }

  const measured = REJECT_CLASSES.filter((rejectClass) => rejectStats[rejectClass].measurable)
  const medians = measured.map((rejectClass) => rejectStats[rejectClass].raw.p50)
  const minima = measured.map((rejectClass) => rejectStats[rejectClass].raw.min)
  const spreadOf = (values) => (values.length > 1 ? Math.max(...values) - Math.min(...values) : null)
  const medianSpread = spreadOf(medians)
  const minimumSpread = spreadOf(minima)

  // EL SUELO SE JUZGA SOBRE EL MINIMO, no sobre la mediana. `ConstantTimeFloor`
  // garantiza que ningun rechazo baja de ese tiempo; la mediana lleva encima la
  // cola del servidor y con carga se va a los segundos, asi que comprobar el
  // suelo contra ella es comprobar que el servidor va lento. El minimo es la
  // muestra que menos cola llevo: es donde el suelo se ve o no se ve.
  const floorRespected = minima.every((minimum) => minimum >= rejectionFloorMs)
  const rejectMeasurable = measured.length === REJECT_CLASSES.length

  verdict(
    'RS-03',
    !rejectMeasurable
      ? 'unmeasurable'
      : medianSpread <= REJECT_SPREAD_BUDGET_MS &&
          minimumSpread <= REJECT_SPREAD_BUDGET_MS &&
          floorRespected
        ? 'pass'
        : 'fail',
    !rejectMeasurable
      ? `solo ${measured.length} de ${REJECT_CLASSES.length} clases con muestras suficientes ` +
        `(${REJECT_SAMPLES_FLOOR} respuestas 422 y el ${REJECT_SAMPLES_RATIO_FLOOR * 100} % de las ofrecidas)`
      : `separacion de medianas ${medianSpread.toFixed(1)} ms y de minimos ` +
        `${minimumSpread.toFixed(1)} ms (tope ${REJECT_SPREAD_BUDGET_MS}); suelo de ` +
        `${rejectionFloorMs} ms ${floorRespected ? 'respetado' : 'NO respetado'} sobre el minimo de cada clase`,
  )

  // --- Informativos ----------------------------------------------------------

  const batchAccepted = countersWhere('batch_outcomes', (tags) => SHIFT_ACTIONS.has(tags.action))
  const batchDebounced = countersWhere('batch_outcomes', (tags) => tags.action === 'debounced')
  // `503` por elemento no es un rechazo: el contrato dice que el quiosco
  // CONSERVA ese elemento en la cola y lo reintenta. Solo el `4xx` saca un
  // fichaje de la cola sin haberlo registrado.
  const batchRetryable = countersWhere('batch_outcomes', (tags) => /^http_5\d\d$/.test(String(tags.action)))
  const batchRejected = countersWhere('batch_outcomes', (tags) => /^http_4\d\d$/.test(String(tags.action)))
  const compliance = percentiles(
    valuesWhere((sample) => sample.phase === 'compliance' && isAttended(sample.status)),
  )

  const droppedByScenario = {}
  let droppedTotal = 0

  for (const sample of counters.filter((entry) => entry.metric === 'dropped_iterations')) {
    droppedByScenario[sample.scenario] = (droppedByScenario[sample.scenario] ?? 0) + sample.value
    droppedTotal += sample.value
  }

  // --- Linea base ------------------------------------------------------------

  let baselineReport = null

  if (baseline !== null) {
    const before = baseline?.scenarios?.scan?.p95 ?? null
    const now = scenarios.scan?.p95 ?? null

    if (before === null || now === null) {
      baselineReport = { note: 'la linea base o esta pasada no traen el p95 de scan', regression: null }
    } else {
      const ratio = now / before

      baselineReport = {
        note:
          `p95 de scan ${now} ms frente a ${before} ms de la linea base ` +
          `(${((ratio - 1) * 100).toFixed(1)} %)` +
          (ratio > BASELINE_REGRESSION_RATIO
            ? ` — AVISO: empeora mas del ${((BASELINE_REGRESSION_RATIO - 1) * 100).toFixed(0)} %`
            : ''),
        regression: ratio > BASELINE_REGRESSION_RATIO,
        ratio: Math.round(ratio * 1000) / 1000,
      }
    }
  }

  // --- El resumen ------------------------------------------------------------

  return {
    generated_at: options.now ?? new Date().toISOString(),
    git_sha: options.gitSha ?? null,
    runner: options.runner ?? null,
    k6_version: options.k6Version ?? null,
    k6_image: options.k6Image ?? null,
    instances: instanceCount,
    instance_ids: results.instances,
    duration_seconds: durationSeconds,
    scan_rate: scanRate,
    offered_scan_rate: offeredRate,
    // `threshold` (el de serie) juzga RNF-P-02 y RNF-P-06 contra el requisito;
    // `baseline`, contra la pasada anterior de la MISMA maquina (decision 19).
    latency_verdict_mode: mode,
    rejection_floor_ms: rejectionFloorMs,
    debounce_seconds: options.debounceSeconds ?? null,
    scenarios,
    reject_classes: Object.fromEntries(
      REJECT_CLASSES.map((rejectClass) => {
        const { raw, ...published } = rejectStats[rejectClass]

        return [rejectClass, published]
      }),
    ),
    checks: {
      total: totalChecks,
      failed: failedChecks,
    },
    totals: {
      shift_producing_realtime: realtimeShifts,
      shift_entries_per_second: Math.round(shiftRate * 10) / 10,
      shift_producing_batch: batchShifts,
      batch_entries_per_second: Math.round(batchRate * 10) / 10,
      debounced,
      debounced_ratio: Math.round(debounceRatio * 1000) / 1000,
      queueable_degradation: queueable.length,
      employee_refusals: refusals.length,
      resend_identical: resendYes,
      resend_divergent: resendNo,
      resend_skipped: resendSkipped,
      batch_accepted: batchAccepted,
      batch_debounced: batchDebounced,
      batch_retryable: batchRetryable,
      batch_rejected: batchRejected,
      compliance_p95: round(compliance.p95),
      dropped_iterations: droppedTotal,
      dropped_iterations_by_scenario: droppedByScenario,
    },
    write_path: {
      samples: write.samples,
      min: round(write.min),
      p50: round(write.p50),
      p95: round(write.p95),
      p99: round(write.p99),
      max: round(write.max),
      per_instance: perInstance.map((entry) => ({
        instance: entry.instance,
        samples: entry.stats.samples,
        p95: round(entry.stats.p95),
        p99: round(entry.stats.p99),
      })),
    },
    edge_limits: options.edgeLimits ?? null,
    server_metrics: options.serverMetrics ?? null,
    verdicts,
    baseline: baselineReport,
  }
}

/**
 * 0 verde, 1 rojo, 2 no fiable.
 *
 * Un rojo manda sobre un «no evaluable»: si algo se midio y se salio del
 * presupuesto, eso es lo que hay que contar aunque otra cosa no se pudiera
 * medir.
 */
function exitCodeOf(summary) {
  const statuses = Object.values(summary.verdicts).map((entry) => entry.status)

  if (statuses.includes('fail')) {
    return 1
  }

  return statuses.includes('unmeasurable') ? 2 : 0
}

// --- Informe legible ---------------------------------------------------------

function formatReport(summary) {
  const lines = []
  const say = (line) => lines.push(line)
  const fmt = (value) => (value === null || value === undefined ? '     n/d' : value.toFixed(1).padStart(8))
  const label = { pass: 'OK   ', fail: 'FALLA', unmeasurable: 'S/MED' }

  say(
    `Instancias: ${summary.instance_ids.join(', ')}   duracion: ${summary.duration_seconds} s   ` +
      `ofrecidos: ${summary.offered_scan_rate} fichajes/s`,
  )
  say(`generador ${summary.k6_version ?? 'n/d'}   commit ${summary.git_sha ?? 'n/d'}   runner ${summary.runner ?? 'n/d'}`)
  say(
    summary.latency_verdict_mode === 'baseline'
      ? 'RNF-P-02 y RNF-P-06 se juzgan contra la LINEA BASE de esta maquina, no contra el umbral (decision 19).'
      : 'RNF-P-02 y RNF-P-06 se juzgan contra el UMBRAL del requisito.',
  )
  say('')
  say('Latencia de las respuestas CONTESTADAS por el servidor, por escenario (ms):')

  for (const [scenario, stats] of Object.entries(summary.scenarios)) {
    say(
      `  ${scenario.padEnd(11)} n=${String(stats.answered_samples).padStart(6)}/${String(stats.samples).padStart(6)}  ` +
        `p50 ${fmt(stats.p50)}  p95 ${fmt(stats.p95)}  p99 ${fmt(stats.p99)}  max ${fmt(stats.max)}` +
        (stats.requirements.length > 0 ? `   [${stats.requirements.join(' ')}]` : ''),
    )
  }

  say('')
  say('Respuestas por escenario y codigo:')

  for (const [scenario, stats] of Object.entries(summary.scenarios)) {
    say(
      `  ${scenario.padEnd(11)} ${Object.entries(stats.statuses)
        .map(([status, count]) => `${status}:${count}`)
        .join('  ')}`,
    )
  }

  if (summary.edge_limits !== null) {
    say('')
    say(
      `Borde en la ventana de la pasada: ${summary.edge_limits.limiting_requests ?? 'n/d'} «limiting requests», ` +
        `${summary.edge_limits.limiting_connections ?? 'n/d'} «limiting connections»`,
    )
  }

  if (summary.totals.dropped_iterations > 0) {
    say('')
    say(
      `INFO iteraciones descartadas por falta de VU libres: ${summary.totals.dropped_iterations} ` +
        `(${Object.entries(summary.totals.dropped_iterations_by_scenario)
          .map(([scenario, count]) => `${scenario}:${count}`)
          .join(', ')}). El generador no pudo mantener el ritmo porque el servidor tardaba.`,
    )
  }

  say('')

  for (const requirement of ['CHECKS', 'RNF-P-02', 'RNF-P-06', 'RQ-03']) {
    const entry = summary.verdicts[requirement]

    if (entry !== undefined) {
      say(`${label[entry.status]} ${requirement}: ${entry.detail}`)
    }
  }

  say('')
  say('Rechazos por clase (ms):')

  for (const [rejectClass, stats] of Object.entries(summary.reject_classes)) {
    say(
      `  ${rejectClass.padEnd(11)} n=${String(stats.samples).padStart(5)}/${String(stats.offered).padStart(5)}  ` +
        `min ${fmt(stats.min)}  p50 ${fmt(stats.p50)}  p95 ${fmt(stats.p95)}  max ${fmt(stats.max)}`,
    )
  }

  const rejectVerdict = summary.verdicts['RS-03']

  say(`${label[rejectVerdict.status]} RS-03: ${rejectVerdict.detail}`)
  say('')
  say(
    `INFO RF-KI-04: ${summary.totals.batch_accepted} elementos con tramo, ` +
      `${summary.totals.batch_debounced} anti-rebote, ${summary.totals.batch_retryable} conservados ` +
      `en la cola (5xx) y ${summary.totals.batch_rejected} rechazados (4xx); ` +
      `${summary.totals.batch_entries_per_second}/s, aparte del pico en tiempo real`,
  )
  say(
    `INFO RF-PA-06: vista de cumplimiento p95 ${summary.totals.compliance_p95 ?? 'n/d'} ms ` +
      `(orientativo ${COMPLIANCE_BUDGET_MS} ms, ningun requisito lo fija)`,
  )

  if (summary.server_metrics !== null) {
    say(`INFO series del servidor (delta de la pasada): ${JSON.stringify(summary.server_metrics)}`)
  }

  if (summary.baseline !== null) {
    say(`INFO linea base: ${summary.baseline.note}`)
  }

  const failed = Object.entries(summary.verdicts).filter(([, entry]) => entry.status === 'fail')
  const unmeasurable = Object.entries(summary.verdicts).filter(([, entry]) => entry.status === 'unmeasurable')

  say('')

  if (failed.length > 0) {
    say(`VEREDICTO: rojo en ${failed.map(([requirement]) => requirement).join(', ')}.`)
  } else if (unmeasurable.length > 0) {
    say(
      `VEREDICTO: no fiable. Sin datos suficientes para juzgar ` +
        `${unmeasurable.map(([requirement]) => requirement).join(', ')}.`,
    )
  } else {
    say('VEREDICTO: verde. Todos los requisitos medidos dentro de presupuesto.')
  }

  return lines.join('\n')
}

// --- CLI ---------------------------------------------------------------------

function parseArguments(argv) {
  const positional = []
  const named = {}

  for (let i = 0; i < argv.length; i++) {
    const argument = argv[i]

    if (!argument.startsWith('--')) {
      positional.push(argument)
      continue
    }

    const separator = argument.indexOf('=')

    if (separator > 0) {
      named[argument.slice(2, separator)] = argument.slice(separator + 1)
      continue
    }

    named[argument.slice(2)] = argv[i + 1] ?? ''
    i++
  }

  return { positional, named }
}

function readJsonOrNull(file) {
  if (file === undefined || file === '' || !fs.existsSync(file)) {
    return null
  }

  return JSON.parse(fs.readFileSync(file, 'utf8'))
}

function main() {
  const { positional, named } = parseArguments(process.argv.slice(2))
  const dir = positional[0]

  if (dir === undefined) {
    console.error('Uso: node aggregate.js <directorio de resultados> --duration=120 [--baseline fichero]')
    process.exit(2)
  }

  const durationSeconds = Number(named.duration ?? 0)

  if (!Number.isFinite(durationSeconds) || durationSeconds <= 0) {
    console.error('Falta --duration=<segundos>: sin el no se puede calcular la tasa de RNF-P-06.')
    process.exit(2)
  }

  const results = readResults(dir)

  if (results.durations.length === 0) {
    console.error(
      `Sin muestras de http_req_duration en ${dir}: la prueba no llego a ejecutar peticiones. ` +
        'La medida no es fiable.',
    )
    process.exit(2)
  }

  const summary = analyse(results, {
    durationSeconds,
    instances: named.instances === undefined ? undefined : Number(named.instances),
    scanRate: named['scan-rate'] === undefined ? undefined : Number(named['scan-rate']),
    offeredRate: named['offered-rate'] === undefined ? undefined : Number(named['offered-rate']),
    rejectionFloorMs:
      named['rejection-floor-ms'] === undefined ? undefined : Number(named['rejection-floor-ms']),
    debounceSeconds: named['debounce-seconds'] === undefined ? null : Number(named['debounce-seconds']),
    gitSha: named['git-sha'] ?? null,
    runner: named.runner ?? null,
    k6Version: named['k6-version'] ?? null,
    k6Image: named['k6-image'] ?? null,
    latencyVerdictMode: named['latency-verdict'] ?? 'threshold',
    edgeLimits: readJsonOrNull(named['edge-limits']),
    serverMetrics: readJsonOrNull(named['server-metrics']),
    baseline: readJsonOrNull(named.baseline),
  })

  fs.writeFileSync(path.join(dir, 'summary.json'), `${JSON.stringify(summary, null, 2)}\n`)
  console.log(formatReport(summary))
  process.exit(exitCodeOf(summary))
}

module.exports = {
  splitCsvLine,
  parseTags,
  percentiles,
  readResults,
  analyse,
  exitCodeOf,
  formatReport,
  P95_BUDGET_MS,
  SHIFT_RATE_BUDGET,
  REJECT_SPREAD_BUDGET_MS,
}

if (require.main === module) {
  main()
}
