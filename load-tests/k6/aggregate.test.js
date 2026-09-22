// Las pruebas del agregado: el codigo que decide si una version mayor sale o no
// sale (RQ-08). Sin ellas, «VEREDICTO: verde» es una afirmacion que nadie ha
// comprobado nunca — y un agregado que no supiera fallar daria verde tambien
// sobre una pasada rota.
//
// CADA PRUEBA ROMPE UNA SOLA CONDICION sobre la misma pasada verde: p95 de 151
// ms, 49,9 tramos/s, un reenvio con cuerpo distinto, una clase de rechazo
// separada 21 ms, un 403 entre fichajes validos, una clase con pocas muestras y
// un `check()` de contrato fallido. Asi el fallo senala la condicion y no «algo
// del agregado».
//
// EL CUARTO RECHAZO SE PRUEBA AL REVES QUE LOS DEMAS. `RS-03-RN-18` es
// INFORMATIVO —A-13 del doc 07 §6 acepto la diferencia sin suelo de tiempo— asi
// que lo que hay que demostrar no es que sepa fallar, sino que **no puede**: la
// pasada verde lleva el cuarto rechazo 20 ms por encima de las tres clases de
// credencial, y hay una prueba que lo sube a medio segundo y sigue esperando
// verde. Si alguien lo convierte en un presupuesto, esas pruebas se ponen rojas.
//
// Se corre a mano; `make` no la ejecuta porque no hay etapa de Node para
// `load-tests/`:
//
//   node --test load-tests/k6/aggregate.test.js
//
// (o `cd load-tests/k6 && node --test`. La forma `node --test load-tests/k6/`
// falla en Windows: el descubridor por directorio resuelve la ruta como modulo.)
//
// Sin dependencias: `node --test` y `node:assert` vienen con Node 24.

'use strict'

const test = require('node:test')
const assert = require('node:assert/strict')
const fs = require('node:fs')
const os = require('node:os')
const path = require('node:path')

const { readResults, analyse, exitCodeOf, splitCsvLine, parseTags, percentiles } = require('./aggregate.js')

// --- Construccion de CSV como el que escribe k6 ------------------------------

const HEADER = [
  'metric_name',
  'timestamp',
  'metric_value',
  'check',
  'error',
  'error_code',
  'expected_response',
  'group',
  'method',
  'name',
  'proto',
  'scenario',
  'service',
  'status',
  'subproto',
  'tls_version',
  'url',
  'extra_tags',
  'metadata',
]

function row({ metric, value = 1, check = '', scenario = '', status = '', tags = {} }) {
  const fields = Array.from({ length: HEADER.length }, () => '')

  fields[0] = metric
  fields[1] = '1789658089'
  fields[2] = Number(value).toFixed(6)
  fields[3] = check
  fields[11] = scenario
  fields[13] = status
  fields[17] = Object.entries(tags)
    .map(([key, tagValue]) => `${key}=${tagValue}`)
    .join('&')

  // Solo `extra_tags` puede llevar comas (no las lleva) o espacios; el escritor
  // de Go entrecomilla lo que haga falta y aqui se imita esa regla.
  return fields.map((field) => (field.includes(',') ? `"${field}"` : field)).join(',')
}

function scanRows({ count, durationMs, status = '200', phase = 'scan', scenario = 'scan' }) {
  return Array.from({ length: count }, () =>
    row({
      metric: 'http_req_duration',
      value: durationMs,
      scenario,
      status,
      tags: { requirements: 'RNF-P-06 RNF-P-02 RQ-08', phase },
    }),
  )
}

function rejectRows({ rejectClass, durations, status = '422' }) {
  return durations.map((durationMs) =>
    row({
      metric: 'http_req_duration',
      value: durationMs,
      scenario: 'reject',
      status,
      tags: { requirements: 'RS-03', phase: 'reject', reject_class: rejectClass },
    }),
  )
}

/**
 * El CUARTO rechazo, el de RN-18: otra fase y ninguna `reject_class`, que es lo
 * que lo mantiene fuera del veredicto de RS-03.
 */
function outOfOrderRows({ durations, status = '422' }) {
  return durations.map((durationMs) =>
    row({
      metric: 'http_req_duration',
      value: durationMs,
      scenario: 'reject-out-of-order',
      status,
      tags: { requirements: 'RS-03 RN-18', phase: 'reject_out_of_order' },
    }),
  )
}

/**
 * Una pasada VERDE, con las cifras justas por encima de cada suelo. Cada prueba
 * la muta en un solo punto.
 */
function greenRun(overrides = {}) {
  const lines = [
    ...scanRows({ count: 60, durationMs: overrides.scanDurationMs ?? 100 }),
    ...rejectRows({ rejectClass: 'signature', durations: Array(20).fill(30) }),
    ...rejectRows({
      rejectClass: 'unknown',
      durations: Array(overrides.unknownSamples ?? 20).fill(30),
    }),
    ...rejectRows({ rejectClass: 'revoked', durations: overrides.revokedDurations ?? Array(20).fill(30) }),
    ...outOfOrderRows({ durations: overrides.outOfOrderDurations ?? Array(20).fill(50) }),
    row({
      metric: 'scan_outcomes',
      value: overrides.shiftProducing ?? 60,
      scenario: 'scan',
      tags: { phase: 'scan', action: 'clock_in' },
    }),
    row({
      metric: 'resend_matches',
      value: overrides.resendIdentical ?? 12,
      scenario: 'resend',
      tags: { match: 'yes' },
    }),
    row({ metric: 'checks', value: 1, check: 'el rechazo es 422 problem+json', scenario: 'reject' }),
    // El elemento imposible que cada lote lleva a proposito (RN-18): registrado
    // como irreconciliable, que es lo que se espera.
    row({
      metric: 'batch_impossible',
      value: overrides.impossibleUnreconcilable ?? 2,
      scenario: 'batch',
      tags: { outcome: 'unreconcilable' },
    }),
  ]

  if (overrides.impossibleRetried) {
    lines.push(
      row({
        metric: 'batch_impossible',
        value: overrides.impossibleRetried,
        scenario: 'batch',
        tags: { outcome: 'retried' },
      }),
    )
  }

  if (overrides.batchUnreconcilableItems) {
    lines.push(
      row({
        metric: 'batch_outcomes',
        value: overrides.batchUnreconcilableItems,
        scenario: 'batch',
        tags: { action: 'http_422' },
      }),
    )
  }

  if (overrides.resendDivergent) {
    lines.push(
      row({
        metric: 'resend_matches',
        value: overrides.resendDivergent,
        scenario: 'resend',
        tags: { match: 'no' },
      }),
    )
  }

  if (overrides.failedCheck) {
    lines.push(
      row({
        metric: 'checks',
        value: 0,
        check: 'el cuerpo es el generico, con el scan_id como unica variacion',
        scenario: 'reject',
      }),
    )
  }

  if (overrides.extraScanStatus) {
    lines.push(...scanRows({ count: 1, durationMs: 100, status: overrides.extraScanStatus }))
  }

  return lines
}

function runAnalysis(lines, options = {}) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'k6-aggregate-'))

  try {
    fs.writeFileSync(path.join(dir, 'instance-0.csv'), `${HEADER.join(',')}\n${lines.join('\n')}\n`)

    return analyse(readResults(dir), {
      durationSeconds: 1,
      instances: 10,
      scanRate: 6,
      offeredRate: 60,
      rejectionFloorMs: 25,
      now: '2026-09-17T00:00:00.000Z',
      ...options,
    })
  } finally {
    fs.rmSync(dir, { recursive: true, force: true })
  }
}

const statusOf = (summary, requirement) => summary.verdicts[requirement].status

// --- Las funciones sueltas ---------------------------------------------------

test('parte una linea de CSV respetando el entrecomillado', () => {
  assert.deepEqual(splitCsvLine('a,"b,c",d'), ['a', 'b,c', 'd'])
  assert.deepEqual(splitCsvLine('a,"b""c",d'), ['a', 'b"c', 'd'])
})

test('lee las etiquetas extra con valores que llevan espacios', () => {
  assert.deepEqual(parseTags('requirements=RNF-P-06 RQ-08&phase=scan'), {
    requirements: 'RNF-P-06 RQ-08',
    phase: 'scan',
  })
})

test('no inventa percentiles cuando no hay muestras', () => {
  assert.deepEqual(percentiles([]), { samples: 0, min: null, p50: null, p95: null, p99: null, max: null })
})

// --- La pasada verde ---------------------------------------------------------

test('da verde cuando todo esta dentro de presupuesto', () => {
  const summary = runAnalysis(greenRun())

  assert.equal(statusOf(summary, 'RNF-P-02'), 'pass')
  assert.equal(statusOf(summary, 'RNF-P-06'), 'pass')
  assert.equal(statusOf(summary, 'RQ-03'), 'pass')
  assert.equal(statusOf(summary, 'RS-03'), 'pass')
  assert.equal(statusOf(summary, 'CHECKS'), 'pass')
  assert.equal(statusOf(summary, 'RN-18'), 'pass')
  assert.equal(exitCodeOf(summary), 0)
})

// --- RN-18: el irreconciliable se registra, no se reintenta ------------------

test('un 422 en un elemento de lote es registro y no rechazo al empleado', () => {
  // RN-18: el elemento queda como `rejected_out_of_order` marcado para revision
  // y el quiosco lo SACA de la cola. Ni es un fichaje perdido ni es degradacion.
  const summary = runAnalysis(greenRun({ batchUnreconcilableItems: 3 }))

  assert.equal(summary.totals.batch_unreconcilable, 3)
  assert.equal(summary.totals.batch_rejected, 0)
  assert.equal(summary.totals.batch_retryable, 0)
  assert.equal(statusOf(summary, 'RN-18'), 'pass')
  assert.equal(exitCodeOf(summary), 0)
})

test('falla RN-18 si el elemento imposible vuelve a la cola con un 5xx', () => {
  // Es el bucle de reintentos infinito que RN-18 vino a eliminar: un elemento
  // que jamas podra cuadrar y que el quiosco conserva para siempre.
  const summary = runAnalysis(greenRun({ impossibleRetried: 1 }))

  assert.equal(statusOf(summary, 'RN-18'), 'fail')
  assert.match(summary.verdicts['RN-18'].detail, /1 devueltos a la cola con 5xx/)
  assert.equal(exitCodeOf(summary), 1)
})

test('falla RN-18 si el servidor acepta el elemento imposible', () => {
  const lines = greenRun({ impossibleUnreconcilable: 0 })

  lines.push(
    row({ metric: 'batch_impossible', value: 1, scenario: 'batch', tags: { outcome: 'accepted' } }),
  )

  const summary = runAnalysis(lines)

  assert.equal(statusOf(summary, 'RN-18'), 'fail')
  assert.equal(exitCodeOf(summary), 1)
})

test('declara RN-18 no evaluable cuando ningun lote llego a montar el caso', () => {
  const lines = greenRun({ impossibleUnreconcilable: 0 })

  lines.push(
    row({ metric: 'batch_impossible', value: 4, scenario: 'batch', tags: { outcome: 'not_set_up' } }),
  )

  const summary = runAnalysis(lines)

  assert.equal(statusOf(summary, 'RN-18'), 'unmeasurable')
  assert.match(summary.verdicts['RN-18'].detail, /RN-18 no se ha ejercitado/)
  assert.equal(exitCodeOf(summary), 2)
})

test('publica en el resumen de quien y de donde salio la medida', () => {
  const summary = runAnalysis(greenRun(), {
    gitSha: 'abc1234',
    runner: 'ubuntu-24.04',
    k6Version: 'v2.2.0',
    k6Image: 'grafana/k6:2.2.0@sha256:deadbeef',
  })

  assert.equal(summary.git_sha, 'abc1234')
  assert.equal(summary.runner, 'ubuntu-24.04')
  assert.equal(summary.k6_version, 'v2.2.0')
  assert.equal(summary.k6_image, 'grafana/k6:2.2.0@sha256:deadbeef')
  assert.equal(summary.instances, 10)
  // De serie se juzga contra el requisito; el modo `baseline` hay que pedirlo.
  assert.equal(summary.latency_verdict_mode, 'threshold')
})

// --- Un solo fallo cada vez --------------------------------------------------

test('falla RNF-P-02 con un p95 de 151 ms', () => {
  const summary = runAnalysis(greenRun({ scanDurationMs: 151 }))

  assert.equal(statusOf(summary, 'RNF-P-02'), 'fail')
  assert.equal(statusOf(summary, 'RNF-P-06'), 'pass')
  assert.equal(exitCodeOf(summary), 1)
})

test('falla RNF-P-06 con 49,9 tramos por segundo', () => {
  const summary = runAnalysis(greenRun({ shiftProducing: 499 }), { durationSeconds: 10 })

  assert.equal(statusOf(summary, 'RNF-P-06'), 'fail')
  assert.match(summary.verdicts['RNF-P-06'].detail, /49\.9\/s/)
  assert.equal(exitCodeOf(summary), 1)
})

test('falla RQ-03 cuando un reenvio devuelve un cuerpo distinto', () => {
  const summary = runAnalysis(greenRun({ resendDivergent: 1 }))

  assert.equal(statusOf(summary, 'RQ-03'), 'fail')
  assert.equal(exitCodeOf(summary), 1)
})

test('falla RS-03 cuando una clase de rechazo se separa 21 ms de las otras', () => {
  // Mismo minimo que las demas —30 ms— y mediana 21 ms por encima: se rompe la
  // separacion de medianas y NADA MAS, ni el suelo ni la de minimos.
  const summary = runAnalysis(greenRun({ revokedDurations: [30, ...Array(19).fill(51)] }))

  assert.equal(statusOf(summary, 'RS-03'), 'fail')
  assert.match(summary.verdicts['RS-03'].detail, /separacion de medianas 21\.0 ms/)
  assert.match(summary.verdicts['RS-03'].detail, /suelo de 25 ms respetado/)
  assert.equal(exitCodeOf(summary), 1)
})

test('falla RS-03 cuando un rechazo baja del suelo de tiempo constante', () => {
  const summary = runAnalysis(greenRun({ revokedDurations: Array(20).fill(24) }))

  assert.equal(statusOf(summary, 'RS-03'), 'fail')
  assert.match(summary.verdicts['RS-03'].detail, /NO respetado/)
})

test('falla RNF-P-06 con un solo 403 entre fichajes validos', () => {
  // Un `429` seria degradacion encolable y se tolera; un `403` es el servidor
  // diciendole que no a alguien que venia a fichar.
  const summary = runAnalysis(greenRun({ extraScanStatus: '403' }))

  assert.equal(statusOf(summary, 'RNF-P-06'), 'fail')
  assert.match(summary.verdicts['RNF-P-06'].detail, /rechazos al empleado 1/)
})

test('falla SERVICIO cuando el servidor contesta 5xx a los fichajes', () => {
  // El caso que se colo el 18-09-2026: un metodo de dominio inexistente hacia
  // que TODO cierre de turno devolviera 500, y la pasada terminaba en verde
  // porque los cuadres comparaban cero con cero y RNF-P-06 no era evaluable.
  const lines = greenRun()

  lines.push(...scanRows({ count: 60, durationMs: 40, status: '500' }))

  const summary = runAnalysis(lines, { offeredRate: 6 })

  assert.equal(statusOf(summary, 'RNF-P-06'), 'unmeasurable')
  assert.equal(statusOf(summary, 'SERVICIO'), 'fail')
  assert.match(summary.verdicts.SERVICIO.detail, /60 de 120 fichajes/)
  assert.equal(exitCodeOf(summary), 1)
})

test('SERVICIO tolera un 5xx suelto en una maquina saturada', () => {
  // Bajo presion de CPU, el borde devuelve algun `504` por agotarse el tiempo
  // del upstream. En la pasada llena del runner fueron 14 de 6.600 (0,2 %): eso
  // no es un servidor roto y no puede teñir la pasada de rojo.
  const lines = greenRun()

  lines.push(...scanRows({ count: 240, durationMs: 100 }))
  lines.push(...scanRows({ count: 1, durationMs: 40, status: '503' }))

  const summary = runAnalysis(lines)

  assert.equal(statusOf(summary, 'SERVICIO'), 'pass')
  assert.match(summary.verdicts.SERVICIO.detail, /1 de 301 fichajes/)
})

test('SERVICIO no culpa al servidor de lo que freno el borde', () => {
  // Un `429` no llego a ejecutar codigo: es capacidad, y de eso responde
  // RNF-P-06. Aqui no cuenta ni arriba ni abajo.
  const lines = greenRun()

  lines.push(...scanRows({ count: 300, durationMs: 1, status: '429' }))

  const summary = runAnalysis(lines)

  assert.equal(statusOf(summary, 'SERVICIO'), 'pass')
})

test('falla CHECKS cuando una comprobacion de contrato no pasa bajo carga', () => {
  const summary = runAnalysis(greenRun({ failedCheck: true }))

  assert.equal(statusOf(summary, 'CHECKS'), 'fail')
  assert.equal(summary.checks.failed.length, 1)
  assert.equal(exitCodeOf(summary), 1)
})

// --- No evaluable, que no es ni verde ni rojo --------------------------------

test('declara RS-03 no evaluable cuando una clase apenas tiene muestras', () => {
  const summary = runAnalysis(greenRun({ unknownSamples: 5 }))

  assert.equal(statusOf(summary, 'RS-03'), 'unmeasurable')
  assert.equal(exitCodeOf(summary), 2)
})

test('declara RNF-P-06 no evaluable cuando la carga ofrecida no llega a 50/s', () => {
  const summary = runAnalysis(greenRun(), { instances: 2, offeredRate: 12 })

  assert.equal(statusOf(summary, 'RNF-P-06'), 'unmeasurable')
  assert.equal(exitCodeOf(summary), 2)
})

test('declara RQ-03 no evaluable cuando casi ningun par se pudo comparar', () => {
  const lines = greenRun({ resendIdentical: 5 })

  lines.push(
    row({ metric: 'resend_matches', value: 200, scenario: 'resend', tags: { match: 'skipped' } }),
  )

  const summary = runAnalysis(lines)

  assert.equal(statusOf(summary, 'RQ-03'), 'unmeasurable')
})

test('un rojo manda sobre un no evaluable', () => {
  const summary = runAnalysis(greenRun({ unknownSamples: 5, scanDurationMs: 151 }))

  assert.equal(statusOf(summary, 'RS-03'), 'unmeasurable')
  assert.equal(statusOf(summary, 'RNF-P-02'), 'fail')
  assert.equal(exitCodeOf(summary), 1)
})

// --- RS-03-RN-18: la cifra de A-13, informativa y nunca bloqueante -----------

test('publica la separacion del cuarto rechazo frente a las tres clases de credencial', () => {
  // La pasada verde trae RN-18 a 50 ms y las tres clases a 30: veinte milisegundos
  // de separacion, en mediana y en minimo, frente a cada una de las tres.
  const summary = runAnalysis(greenRun())

  assert.equal(summary.reject_out_of_order.samples, 20)
  assert.equal(summary.reject_out_of_order.p50, 50)
  assert.equal(summary.reject_out_of_order.min, 50)
  assert.deepEqual(summary.reject_out_of_order.separation_ms.median, {
    signature: 20,
    unknown: 20,
    revoked: 20,
  })
  assert.deepEqual(summary.reject_out_of_order.separation_ms.minimum, {
    signature: 20,
    unknown: 20,
    revoked: 20,
  })
})

test('el cuarto rechazo no entra en el veredicto de RS-03', () => {
  // Es la razon de que sea un escenario aparte: veinte milisegundos por encima
  // del tope de separacion de RS-03 y RS-03 sigue verde, porque lo que compara
  // son las tres clases de CREDENCIAL entre si.
  const summary = runAnalysis(greenRun({ outOfOrderDurations: Array(20).fill(300) }))

  assert.equal(statusOf(summary, 'RS-03'), 'pass')
  assert.equal(summary.reject_out_of_order.separation_ms.median.revoked, 270)
})

test('una separacion enorme del cuarto rechazo sigue dando verde', () => {
  // A-13 (doc 07 §6) esta aceptada SIN suelo de tiempo: lo que faltaba era la
  // cifra, no una puerta. Medio segundo de diferencia se publica y no falla.
  const summary = runAnalysis(greenRun({ outOfOrderDurations: Array(20).fill(530) }))

  assert.equal(statusOf(summary, 'RS-03-RN-18'), 'info')
  assert.equal(summary.reject_out_of_order.separation_ms.median.signature, 500)
  assert.match(summary.verdicts['RS-03-RN-18'].detail, /INFORMATIVO/)
  assert.equal(exitCodeOf(summary), 0)
})

test('sin muestras del cuarto rechazo la pasada no se declara no fiable', () => {
  // Un `info` sin datos no es un `unmeasurable`: no puede convertir en «vuelve a
  // medir» una pasada en la que todo lo que se juzga salio bien.
  const lines = greenRun({ outOfOrderDurations: [] })

  const summary = runAnalysis(lines)

  assert.equal(statusOf(summary, 'RS-03-RN-18'), 'info')
  assert.equal(summary.reject_out_of_order.samples, 0)
  assert.match(summary.verdicts['RS-03-RN-18'].detail, /sin cifra que publicar/)
  assert.equal(exitCodeOf(summary), 0)
})

test('cuenta aparte los escaneos de RN-18 que si se pudieron reconciliar', () => {
  // Un `200` significa que el turno sembrado dejo de estar abierto: la medida no
  // se monto. No es un rechazo al empleado y no puede pasar por uno.
  const lines = greenRun()

  lines.push(...outOfOrderRows({ durations: [40, 41], status: '200' }))

  const summary = runAnalysis(lines)

  assert.equal(summary.reject_out_of_order.reconciled, 2)
  assert.equal(summary.reject_out_of_order.samples, 20)
  assert.equal(summary.reject_out_of_order.offered, 22)
  assert.equal(statusOf(summary, 'RNF-P-06'), 'pass')
  assert.equal(exitCodeOf(summary), 0)
})

test('avisa de que la cifra es orientativa cuando hay pocas muestras', () => {
  const summary = runAnalysis(greenRun({ outOfOrderDurations: Array(19).fill(50) }))

  assert.equal(summary.reject_out_of_order.firm, false)
  assert.match(summary.verdicts['RS-03-RN-18'].detail, /la cifra es orientativa/)
  assert.equal(exitCodeOf(summary), 0)
})

// --- Linea base: avisa, no falla ---------------------------------------------

test('avisa de una regresion del 26 por ciento sin cambiar el veredicto', () => {
  const summary = runAnalysis(greenRun({ scanDurationMs: 126 }), {
    baseline: { scenarios: { scan: { p95: 100 } } },
  })

  assert.equal(summary.baseline.regression, true)
  assert.match(summary.baseline.note, /AVISO/)
  assert.equal(exitCodeOf(summary), 0)
})

test('no avisa de una regresion del 24 por ciento', () => {
  const summary = runAnalysis(greenRun({ scanDurationMs: 124 }), {
    baseline: { scenarios: { scan: { p95: 100 } } },
  })

  assert.equal(summary.baseline.regression, false)
  assert.equal(exitCodeOf(summary), 0)
})

// --- Modo «linea base»: el runner no juzga el umbral (decision 19) -----------

/**
 * La linea base de una pasada igual a la verde: p95 de 100 ms y 60 tramos/s con
 * los mismos parametros (10 instancias, 6 fichajes/s, 1 s).
 */
const RUNNER_BASELINE = {
  git_sha: 'deadbee',
  runner: 'github-Linux-X64',
  instances: 10,
  scan_rate: 6,
  duration_seconds: 1,
  write_path: { p95: 100 },
  totals: { shift_entries_per_second: 60 },
  scenarios: { scan: { p95: 100 } },
}

const asBaseline = (overrides = {}) => ({ ...RUNNER_BASELINE, ...overrides })

test('en modo linea base da verde cuando la pasada iguala a la anterior', () => {
  const summary = runAnalysis(greenRun(), {
    latencyVerdictMode: 'baseline',
    baseline: asBaseline(),
  })

  assert.equal(summary.latency_verdict_mode, 'baseline')
  assert.equal(statusOf(summary, 'RNF-P-02'), 'pass')
  assert.equal(statusOf(summary, 'RNF-P-06'), 'pass')
  // El detalle dice contra que se ha juzgado y que el umbral no se juzga aqui.
  assert.match(summary.verdicts['RNF-P-02'].detail, /linea base del runner: p95 100 ms, 60 tramos\/s \(git_sha deadbee\)/)
  assert.match(summary.verdicts['RNF-P-06'].detail, /El umbral de RNF-P-02 y RNF-P-06 NO se juzga en este runner/)
  assert.equal(exitCodeOf(summary), 0)
})

test('en modo linea base una carga ofrecida por debajo de 50/s sigue siendo evaluable', () => {
  // El perfil del runner son 18/s (3 instancias) a proposito: a 60/s se satura
  // y RQ-03 y RS-03 se quedan sin muestras. La regla «sin 50/s no hay nada que
  // juzgar» es del modo umbral; aqui lo que se juzga es la tasa frente a la
  // que esta misma maquina sostuvo con los mismos parametros.
  const summary = runAnalysis(greenRun(), {
    instances: 3,
    offeredRate: 18,
    latencyVerdictMode: 'baseline',
    baseline: asBaseline({ instances: 3, totals: { shift_entries_per_second: 18 } }),
  })

  assert.equal(statusOf(summary, 'RNF-P-06'), 'pass')
  assert.match(summary.verdicts['RNF-P-06'].detail, /minimo 14.4\/s/)
  assert.equal(exitCodeOf(summary), 0)
})

test('en modo linea base el p95 que empeora un 26 por ciento es rojo', () => {
  const summary = runAnalysis(greenRun({ scanDurationMs: 126 }), {
    latencyVerdictMode: 'baseline',
    baseline: asBaseline(),
  })

  assert.equal(statusOf(summary, 'RNF-P-02'), 'fail')
  assert.equal(statusOf(summary, 'RNF-P-06'), 'pass')
  assert.equal(exitCodeOf(summary), 1)
})

test('en modo linea base una caida del 21 por ciento en la tasa es roja', () => {
  // 47 tramos/s frente a los 60 de la linea base: por debajo del 80 %.
  const summary = runAnalysis(greenRun({ shiftProducing: 47 }), {
    latencyVerdictMode: 'baseline',
    baseline: asBaseline(),
  })

  assert.equal(statusOf(summary, 'RNF-P-06'), 'fail')
  assert.match(summary.verdicts['RNF-P-06'].detail, /minimo 48\/s/)
  assert.equal(statusOf(summary, 'RNF-P-02'), 'pass')
  assert.equal(exitCodeOf(summary), 1)
})

test('en modo linea base una pasada con otros parametros no es evaluable', () => {
  // Comparar un p95 de 120 s de carga con el de 1 s es comparar dos cosas
  // distintas y llamarlo regresion.
  const summary = runAnalysis(greenRun(), {
    latencyVerdictMode: 'baseline',
    baseline: asBaseline({ duration_seconds: 120 }),
  })

  assert.equal(statusOf(summary, 'RNF-P-02'), 'unmeasurable')
  assert.equal(statusOf(summary, 'RNF-P-06'), 'unmeasurable')
  assert.match(summary.verdicts['RNF-P-02'].detail, /duracion: 120 frente a 1/)
  assert.equal(exitCodeOf(summary), 2)
})

test('en modo linea base sin linea base no hay veredicto, y lo dice', () => {
  const summary = runAnalysis(greenRun(), { latencyVerdictMode: 'baseline' })

  assert.equal(statusOf(summary, 'RNF-P-02'), 'unmeasurable')
  assert.equal(statusOf(summary, 'RNF-P-06'), 'unmeasurable')
  assert.match(summary.verdicts['RNF-P-02'].detail, /La PRIMERA pasada de este runner es la que la crea/)
  assert.equal(exitCodeOf(summary), 2)
})

test('en modo linea base un rechazo al empleado sigue siendo rojo', () => {
  // Lo que se relaja contra la linea base es la CAPACIDAD, no el trato al
  // empleado: un 403 sobre un fichaje valido es una jornada perdida en
  // cualquier maquina.
  const summary = runAnalysis(greenRun({ extraScanStatus: '403' }), {
    latencyVerdictMode: 'baseline',
    baseline: asBaseline(),
  })

  assert.equal(statusOf(summary, 'RNF-P-06'), 'fail')
  assert.match(summary.verdicts['RNF-P-06'].detail, /rechazos al empleado 1/)
})
