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

const { readResults, analyse, exitCodeOf, formatReport, splitCsvLine, parseTags, percentiles } = require('./aggregate.js')

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
 * El fichaje por PIN (RF-AT-11, K6-3): escenario `scan-pin`, fase `pin` para el
 * envio original y `pin_replay` para su reenvio identico.
 */
function pinRows({ durations, status = '200', phase = 'pin' }) {
  return durations.map((durationMs) =>
    row({
      metric: 'http_req_duration',
      value: durationMs,
      scenario: 'scan-pin',
      status,
      tags: { requirements: 'RF-AT-11 RQ-03', phase },
    }),
  )
}

/** La subida y la bajada del cambio de turno (K6-2): mismo escenario, otra fase. */
function rampRows({ count, durationMs, status = '200' }) {
  return scanRows({ count, durationMs, status, phase: 'scan_ramp' })
}

/**
 * Una pasada VERDE, con las cifras justas por encima de cada suelo. Cada prueba
 * la muta en un solo punto.
 */
function greenRun(overrides = {}) {
  const rejectMs = overrides.rejectDurationMs ?? 30
  const lines = [
    ...scanRows({ count: 60, durationMs: overrides.scanDurationMs ?? 100 }),
    ...pinRows({ durations: overrides.pinDurations ?? Array(20).fill(250) }),
    row({
      metric: 'scan_outcomes',
      value: overrides.pinShiftProducing ?? 20,
      scenario: 'scan-pin',
      tags: { phase: 'pin', action: 'clock_in' },
    }),
    row({
      metric: 'resend_matches',
      value: 5,
      scenario: 'scan-pin',
      tags: { match: 'yes', channel: 'pin' },
    }),
    ...rejectRows({ rejectClass: 'signature', durations: Array(20).fill(rejectMs) }),
    ...rejectRows({
      rejectClass: 'unknown',
      durations: Array(overrides.unknownSamples ?? 20).fill(rejectMs),
    }),
    ...rejectRows({ rejectClass: 'revoked', durations: overrides.revokedDurations ?? Array(20).fill(rejectMs) }),
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

  if (overrides.extraPinStatus) {
    lines.push(...pinRows({ durations: [250], status: overrides.extraPinStatus }))
  }

  if (overrides.pinResendDivergent) {
    lines.push(
      row({
        metric: 'resend_matches',
        value: overrides.pinResendDivergent,
        scenario: 'scan-pin',
        tags: { match: 'no', channel: 'pin' },
      }),
    )
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
      rampSeconds: 30,
      pinPeriodSeconds: 4,
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
  assert.equal(statusOf(summary, 'RNF-P-08'), 'pass')
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
  assert.match(summary.verdicts.SERVICIO.detail, /60 de 140 fichajes/)
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
  assert.match(summary.verdicts.SERVICIO.detail, /1 de 321 fichajes/)
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

// --- Modo «linea base»: el runner no juzga el umbral (decision 19) -----------

/**
 * La linea base de una pasada igual a la verde: p95 de 100 ms y 60 tramos/s con
 * la MISMA carga —10 instancias, 6 fichajes/s, 1 s de meseta, 30 s de rampa, un
 * PIN cada 4 s y los mismos cuatro escenarios con muestras—.
 */
const RUNNER_BASELINE = {
  git_sha: 'deadbee',
  runner: 'github-Linux-X64',
  instances: 10,
  scan_rate: 6,
  duration_seconds: 1,
  ramp_seconds: 30,
  pin_period_seconds: 4,
  write_path: { p95: 100 },
  // La velocidad de la maquina de la pasada verde: rechazos de 30 ms, asi que
  // su p95 de 100 ms vale 3,33 rechazos.
  reject_classes: { signature: { p50: 30 }, unknown: { p50: 30 }, revoked: { p50: 30 } },
  totals: { shift_entries_per_second: 60 },
  // El PIN de la pasada verde (RNF-P-08): 250 ms con rechazos de 30 ms son 8,33
  // rechazos; maximo 10,41 y guarda gruesa 750 ms.
  pin_path: { p95: 250, p95_over_reject_p50: 8.33 },
  scenarios: { scan: { p95: 100 }, 'scan-pin': {}, reject: {}, 'reject-out-of-order': {} },
}

const asBaseline = (overrides = {}) => ({ ...RUNNER_BASELINE, ...overrides })

/**
 * La linea base tal y como estaba versionada antes del bloque 15: tomada con 3
 * instancias, sin rampa, sin PIN y con los seis escenarios de entonces.
 */
const LEGACY_BASELINE = asBaseline({
  instances: 3,
  ramp_seconds: undefined,
  pin_period_seconds: undefined,
  scenarios: {
    batch: {},
    compliance: {},
    reject: {},
    'reject-out-of-order': {},
    resend: {},
    scan: { p95: 100 },
  },
})

// --- Linea base: avisa, no falla ---------------------------------------------

test('[RNF-P-02] avisa de una regresion del 26 por ciento sin cambiar el veredicto', () => {
  const summary = runAnalysis(greenRun({ scanDurationMs: 126 }), { baseline: asBaseline() })

  assert.equal(summary.baseline.comparable, true)
  assert.equal(summary.baseline.regression, true)
  assert.match(summary.baseline.note, /AVISO/)
  assert.equal(exitCodeOf(summary), 0)
})

test('[RNF-P-02] no avisa de una regresion del 24 por ciento', () => {
  const summary = runAnalysis(greenRun({ scanDurationMs: 124 }), { baseline: asBaseline() })

  assert.equal(summary.baseline.regression, false)
  assert.equal(exitCodeOf(summary), 0)
})

test('[R5-QA-02] el aviso de tendencia no calcula ninguna razon contra una linea base de otra carga', () => {
  // p95 cinco veces el de la linea base: si se comparara, saltaria el AVISO.
  const summary = runAnalysis(greenRun({ scanDurationMs: 500 }), {
    baseline: asBaseline({ instances: 3 }),
  })

  assert.equal(summary.baseline.comparable, false)
  assert.equal(summary.baseline.regression, null)
  assert.equal(summary.baseline.ratio, undefined)
  assert.match(summary.baseline.note, /^NO COMPARABLE \(instancias: 3 frente a 10\)/)
  assert.doesNotMatch(summary.baseline.note, /AVISO/)
})

// --- Modo «linea base»: juicio ------------------------------------------------

test('[RNF-P-02][RNF-P-06] en modo linea base da verde cuando la pasada iguala a la anterior', () => {
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

test('[RNF-P-06] en modo linea base una carga ofrecida por debajo de 50/s sigue siendo evaluable', () => {
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

test('[RNF-P-02] en modo linea base el p95 que empeora un 26 por ciento es rojo', () => {
  const summary = runAnalysis(greenRun({ scanDurationMs: 126 }), {
    latencyVerdictMode: 'baseline',
    baseline: asBaseline(),
  })

  assert.equal(statusOf(summary, 'RNF-P-02'), 'fail')
  assert.equal(statusOf(summary, 'RNF-P-06'), 'pass')
  assert.equal(exitCodeOf(summary), 1)
})

test('[RNF-P-06] en modo linea base una caida del 21 por ciento en la tasa es roja', () => {
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

test('en modo linea base sin linea base no hay veredicto, y lo dice', () => {
  const summary = runAnalysis(greenRun(), { latencyVerdictMode: 'baseline' })

  assert.equal(statusOf(summary, 'RNF-P-02'), 'unmeasurable')
  assert.equal(statusOf(summary, 'RNF-P-06'), 'unmeasurable')
  assert.match(summary.verdicts['RNF-P-02'].detail, /La PRIMERA pasada de este runner es la que la crea/)
  assert.equal(exitCodeOf(summary), 2)
})

test('[RNF-P-06] en modo linea base un rechazo al empleado sigue siendo rojo', () => {
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

// --- R5-QA-02: una linea base de otra carga no se compara --------------------

/**
 * Lo que dispara «NO COMPARABLE», uno por caso. El 8 frente a 3 es el caso real
 * de la etiqueta de la 2.2.0; los demas son las otras formas de cambiar la
 * carga sin cambiar el numero de instancias.
 */
const NOT_COMPARABLE_CASES = [
  ['instancias: la etiqueta lanzo 8 y la linea base era de 3', { instances: 3 }, { instances: 8 }, /instancias: 3 frente a 8/],
  ['duracion: 120 s frente a 1 s', { duration_seconds: 120 }, {}, /duracion: 120 frente a 1/],
  ['rampa: la linea base no tenia', { ramp_seconds: undefined }, {}, /rampa: n\/d frente a 30/],
  ['PIN: otro periodo', { pin_period_seconds: 8 }, {}, /periodo del PIN: 8 frente a 4/],
  ['escenarios: la linea base no tenia scan-pin', { scenarios: { scan: { p95: 100 }, reject: {}, 'reject-out-of-order': {} } }, {}, /escenarios: reject reject-out-of-order scan frente a reject reject-out-of-order scan scan-pin/],
]

for (const [name, baselineOverrides, runOverrides, mismatch] of NOT_COMPARABLE_CASES) {
  test(`[R5-QA-02][RNF-P-02][RNF-P-06][RNF-P-08] declara NO COMPARABLE y no compara: ${name}`, () => {
    const summary = runAnalysis(greenRun(), {
      latencyVerdictMode: 'baseline',
      baseline: asBaseline(baselineOverrides),
      ...runOverrides,
    })

    assert.equal(statusOf(summary, 'RNF-P-02'), 'not_comparable')
    assert.equal(statusOf(summary, 'RNF-P-06'), 'not_comparable')
    assert.equal(statusOf(summary, 'RNF-P-08'), 'not_comparable')
    assert.match(summary.verdicts['RNF-P-08'].detail, mismatch)
    assert.match(summary.verdicts['RNF-P-02'].detail, mismatch)
    assert.match(summary.verdicts['RNF-P-02'].detail, /^NO COMPARABLE con la linea base/)
    assert.doesNotMatch(summary.verdicts['RNF-P-02'].detail, /frente a un maximo de/)
    assert.equal(exitCodeOf(summary), 2)
  })
}

test('[R5-QA-02] la linea base versionada antes del bloque 15 no es comparable con la carga de hoy', () => {
  const summary = runAnalysis(greenRun(), {
    instances: 3,
    offeredRate: 18,
    latencyVerdictMode: 'baseline',
    baseline: LEGACY_BASELINE,
  })

  assert.equal(statusOf(summary, 'RNF-P-02'), 'not_comparable')
  assert.match(summary.verdicts['RNF-P-02'].detail, /rampa: n\/d frente a 30; periodo del PIN: n\/d frente a 4; escenarios:/)
  assert.equal(exitCodeOf(summary), 2)
})

test('[R5-QA-02] el veredicto legible dice «no comparable» y no «no fiable»', () => {
  const summary = runAnalysis(greenRun(), {
    latencyVerdictMode: 'baseline',
    baseline: asBaseline({ instances: 3 }),
    instances: 8,
  })

  const report = formatReport(summary)

  assert.match(report, /NOCMP RNF-P-02: NO COMPARABLE/)
  assert.match(
    report,
    /VEREDICTO: no comparable\. RNF-P-02, RNF-P-06, RNF-P-08 se juzgan contra la linea base y esta se tomo con otra carga \(instancias: 3 frente a 8\)\./,
  )
  assert.doesNotMatch(report, /VEREDICTO: no fiable/)
})

test('[R5-QA-02][RNF-P-06] una linea base no comparable no esconde un rechazo al empleado', () => {
  const summary = runAnalysis(greenRun({ extraScanStatus: '403' }), {
    latencyVerdictMode: 'baseline',
    baseline: asBaseline({ instances: 3 }),
  })

  assert.equal(statusOf(summary, 'RNF-P-06'), 'fail')
  assert.match(summary.verdicts['RNF-P-06'].detail, /rechazos al empleado 1/)
  assert.equal(exitCodeOf(summary), 1)
})

test('[R5-QA-02] publica la forma de la carga para que la siguiente pasada pueda compararse', () => {
  const summary = runAnalysis(greenRun(), { gitSha: 'abc1234', harnessSha: 'def5678' })

  assert.equal(summary.ramp_seconds, 30)
  assert.equal(summary.pin_period_seconds, 4)
  assert.equal(summary.git_sha, 'abc1234')
  assert.equal(summary.harness_sha, 'def5678')
})

// --- RF-AT-11: el fichaje por PIN bajo carga (K6-3) --------------------------

test('[RF-AT-11] da verde cuando todos los PIN correctos se atienden', () => {
  const summary = runAnalysis(greenRun())

  assert.equal(statusOf(summary, 'RF-AT-11'), 'pass')
  assert.equal(summary.pin_path.samples, 20)
  assert.equal(summary.totals.shift_producing_pin, 20)
  assert.equal(summary.totals.pin_resend_identical, 5)
})

test('[RF-AT-11] un 422 a un PIN correcto es una persona sin tarjeta que no ha podido fichar', () => {
  const summary = runAnalysis(greenRun({ extraPinStatus: '422' }))

  assert.equal(statusOf(summary, 'RF-AT-11'), 'fail')
  assert.match(summary.verdicts['RF-AT-11'].detail, /1 rechazados al empleado/)
  assert.equal(exitCodeOf(summary), 1)
})

test('[RF-AT-11][RQ-03] un reenvio por PIN con otra respuesta es rojo en los dos', () => {
  const summary = runAnalysis(greenRun({ pinResendDivergent: 1 }))

  assert.equal(statusOf(summary, 'RF-AT-11'), 'fail')
  assert.equal(statusOf(summary, 'RQ-03'), 'fail')
})

test('[RF-AT-11] un 429 al PIN se encola y no es un rechazo', () => {
  const summary = runAnalysis(greenRun({ extraPinStatus: '429' }))

  assert.equal(statusOf(summary, 'RF-AT-11'), 'pass')
  assert.equal(summary.pin_path.queueable, 1)
})

test('[RF-AT-11] una instalacion sin fichaje por PIN no deja la pasada sin veredicto', () => {
  // ADR-017: sin clave de sellado el quiosco no ofrece PIN y es legitimo. La
  // pasada no trae ni una muestra de `scan-pin`, y eso no es una medida rota.
  const summary = runAnalysis(greenRun({ pinDurations: [], pinShiftProducing: 0 }), { pinPeriodSeconds: 0 })

  assert.equal(statusOf(summary, 'RF-AT-11'), 'info')
  assert.match(summary.verdicts['RF-AT-11'].detail, /fichaje por PIN NO medido/)
  assert.equal(exitCodeOf(summary), 0)
})

test('[RF-AT-11] con 19 PIN atendidos no hay veredicto', () => {
  const summary = runAnalysis(greenRun({ pinDurations: Array(19).fill(250) }))

  assert.equal(statusOf(summary, 'RF-AT-11'), 'unmeasurable')
  assert.equal(exitCodeOf(summary), 2)
})

test('[RF-AT-11][RNF-P-02] el bcrypt del PIN no entra en el p95 del fichaje por tarjeta', () => {
  // Dos segundos por PIN: si se mezclara con `/scan`, el p95 de 60 fichajes de
  // 100 ms mas 20 de 2000 ms saldria muy por encima de 150 ms.
  const summary = runAnalysis(greenRun({ pinDurations: Array(20).fill(2000) }))

  assert.equal(statusOf(summary, 'RNF-P-02'), 'pass')
  assert.equal(summary.write_path.p95, 100)
  assert.equal(summary.pin_path.p95, 2000)
})

test('[RF-AT-11] un 5xx en el PIN cuenta para SERVICIO', () => {
  // 3 de 83 fichajes que llegaron al servidor (60 por tarjeta y 23 por PIN): un 3,6 %, por encima del 1 %.
  const lines = [...greenRun(), ...pinRows({ durations: [250, 250, 250], status: '503' })]
  const summary = runAnalysis(lines)

  assert.equal(statusOf(summary, 'SERVICIO'), 'fail')
  assert.match(summary.verdicts.SERVICIO.detail, /3 de 83 fichajes/)
})

// --- RNF-P-08: la latencia del fichaje por PIN (decision de 15-10-2026) ------
//
// p95 < 400 ms y p99 < 800 ms en hardware de referencia (modo `threshold`); en
// el runner, el mismo juicio que RNF-P-02: cociente frente a la velocidad de la
// maquina y guarda gruesa de 3x en milisegundos (modo `baseline`).

/** Los PIN de la pasada y el desenlace de RNF-P-08 en modo umbral. */
const PIN_THRESHOLD_CASES = [
  ['un p95 de 400 ms cabe en el presupuesto', Array(20).fill(400), 'pass'],
  ['un p95 de 401 ms se sale del presupuesto', Array(20).fill(401), 'fail'],
  ['un p99 de 800 ms cabe en el presupuesto', [...Array(19).fill(300), 800], 'pass'],
  ['un p99 de 801 ms se sale del presupuesto aunque el p95 quepa', [...Array(19).fill(300), 801], 'fail'],
]

for (const [name, pinDurations, expected] of PIN_THRESHOLD_CASES) {
  test(`[RNF-P-08] en modo umbral ${name}`, () => {
    const summary = runAnalysis(greenRun({ pinDurations }))

    assert.equal(statusOf(summary, 'RNF-P-08'), expected)
    assert.equal(statusOf(summary, 'RNF-P-02'), 'pass')
  })
}

test('[RNF-P-08] en modo umbral el detalle dice que las cifras estan por confirmar', () => {
  const summary = runAnalysis(greenRun({ pinDurations: Array(20).fill(401) }))

  assert.match(
    summary.verdicts['RNF-P-08'].detail,
    /p95 401 ms \(presupuesto 400\), p99 401 ms \(presupuesto 800\) sobre 20 fichajes por PIN atendidos/,
  )
  assert.match(summary.verdicts['RNF-P-08'].detail, /a confirmar en hardware de referencia/)
  assert.equal(exitCodeOf(summary), 1)
})

test('[RNF-P-08] con 19 PIN atendidos no hay veredicto de latencia', () => {
  const summary = runAnalysis(greenRun({ pinDurations: Array(19).fill(250) }))

  assert.equal(statusOf(summary, 'RNF-P-08'), 'unmeasurable')
})

test('[RNF-P-08] una instalacion sin fichaje por PIN no deja la pasada sin veredicto', () => {
  const summary = runAnalysis(greenRun({ pinDurations: [], pinShiftProducing: 0 }), { pinPeriodSeconds: 0 })

  assert.equal(statusOf(summary, 'RNF-P-08'), 'info')
  assert.equal(exitCodeOf(summary), 0)
})

test('[RNF-P-08] un 429 al PIN no entra en su latencia', () => {
  // Un 429 del borde se resuelve sin pagar el bcrypt: con estos 400 de 1 ms
  // dentro, el p95 bajaria a 1 ms y el PIN saldria verde.
  const lines = [...greenRun({ pinDurations: Array(20).fill(401) }), ...pinRows({ durations: Array(400).fill(1), status: '429' })]
  const summary = runAnalysis(lines)

  assert.equal(statusOf(summary, 'RNF-P-08'), 'fail')
})

test('[RNF-P-08] en modo linea base da verde cuando el PIN iguala a la linea base', () => {
  const summary = runAnalysis(greenRun(), { latencyVerdictMode: 'baseline', baseline: asBaseline() })

  assert.equal(statusOf(summary, 'RNF-P-08'), 'pass')
  assert.match(summary.verdicts['RNF-P-08'].detail, /vale 8\.33 rechazos, frente a un maximo de 10\.41/)
  assert.match(summary.verdicts['RNF-P-08'].detail, /El umbral de RNF-P-08 \(p95 < 400 ms, p99 < 800 ms\) NO se juzga en este runner/)
  assert.equal(exitCodeOf(summary), 0)
})

test('[RNF-P-08] en modo linea base un PIN que empeora frente a la velocidad de la maquina es rojo', () => {
  // 315 ms con rechazos de 30 ms: 10,5 rechazos, por encima de 10,41.
  const summary = runAnalysis(greenRun({ pinDurations: Array(20).fill(315) }), {
    latencyVerdictMode: 'baseline',
    baseline: asBaseline(),
  })

  assert.equal(statusOf(summary, 'RNF-P-08'), 'fail')
  assert.match(summary.verdicts['RNF-P-08'].detail, /vale 10\.5 rechazos, frente a un maximo de 10\.41/)
  assert.equal(statusOf(summary, 'RNF-P-02'), 'pass')
  assert.equal(exitCodeOf(summary), 1)
})

test('[RNF-P-08] en modo linea base 310 ms siguen dentro de la tolerancia', () => {
  // 310 ms con rechazos de 30 ms: 10,33 rechazos, por debajo de 10,41.
  const summary = runAnalysis(greenRun({ pinDurations: Array(20).fill(310) }), {
    latencyVerdictMode: 'baseline',
    baseline: asBaseline(),
  })

  assert.equal(statusOf(summary, 'RNF-P-08'), 'pass')
})

test('[RNF-P-08] en modo linea base un runner tres veces mas lento con el mismo codigo sigue en verde', () => {
  // 740 ms con rechazos de 87 ms: 8,51 rechazos, y 740 <= 750.
  const summary = runAnalysis(greenRun({ pinDurations: Array(20).fill(740), rejectDurationMs: 87 }), {
    latencyVerdictMode: 'baseline',
    baseline: asBaseline(),
  })

  assert.equal(statusOf(summary, 'RNF-P-08'), 'pass')
})

test('[RNF-P-08] en modo linea base un PIN por encima de 3x la linea base en ms es rojo aunque el cociente cuadre', () => {
  // 780 ms con rechazos de 85 ms: 9,18 rechazos, pero 780 > 750. El runner va
  // 2,83 veces mas lento, por debajo de los 3x que lo hacen no fiable.
  const summary = runAnalysis(greenRun({ pinDurations: Array(20).fill(780), rejectDurationMs: 85 }), {
    latencyVerdictMode: 'baseline',
    baseline: asBaseline(),
  })

  assert.equal(statusOf(summary, 'RNF-P-08'), 'fail')
  assert.match(summary.verdicts['RNF-P-08'].detail, /ROJO por la guarda gruesa/)
})

test('[RNF-P-08] en modo linea base sin rechazos bastantes para medir la velocidad de la maquina no hay verde', () => {
  const summary = runAnalysis(greenRun({ unknownSamples: 19 }), {
    latencyVerdictMode: 'baseline',
    baseline: asBaseline(),
  })

  assert.equal(statusOf(summary, 'RNF-P-08'), 'unmeasurable')
  assert.match(summary.verdicts['RNF-P-08'].detail, /NO EVALUABLE: sin rechazos de credencial suficientes/)
})

test('[RNF-P-08] una linea base sin la latencia del PIN en unidades de la maquina no es comparable', () => {
  // La forma de baseline.json antes del 15-10-2026: p50 y p95 del PIN, sin cociente.
  const summary = runAnalysis(greenRun(), {
    latencyVerdictMode: 'baseline',
    baseline: asBaseline({ pin_path: { p50: 708.6, p95: 895.2 } }),
  })

  assert.equal(statusOf(summary, 'RNF-P-08'), 'not_comparable')
  assert.match(summary.verdicts['RNF-P-08'].detail, /^NO COMPARABLE: la linea base no trae .*baseline\.js/)
  assert.equal(statusOf(summary, 'RNF-P-02'), 'pass')
  assert.equal(exitCodeOf(summary), 2)
})

test('[RNF-P-08] el veredicto legible de una linea base sin PIN dice que rehacer y no inventa diferencias de carga', () => {
  const summary = runAnalysis(greenRun(), {
    latencyVerdictMode: 'baseline',
    baseline: asBaseline({ pin_path: { p50: 708.6, p95: 895.2 } }),
  })

  const report = formatReport(summary)

  assert.match(report, /NOCMP RNF-P-08: NO COMPARABLE/)
  assert.match(report, /VEREDICTO: no comparable\. RNF-P-08 se juzgan contra la linea base y esta no trae lo necesario/)
  assert.doesNotMatch(report, /otra carga \(n\/d\)/)
})

test('[RNF-P-08] en modo linea base sin linea base no hay veredicto, y lo dice', () => {
  const summary = runAnalysis(greenRun(), { latencyVerdictMode: 'baseline' })

  assert.equal(statusOf(summary, 'RNF-P-08'), 'unmeasurable')
})

test('[RNF-P-02][RNF-P-08] con el PIN en marcha y en verde, RNF-P-02 se sigue juzgando aparte y sale rojo si las tarjetas van lentas', () => {
  // Solo comprueba que los dos veredictos son independientes: un PIN que cumple
  // no tapa unas tarjetas lentas. No modela la contencion de CPU (ver el
  // comentario de la guarda de capacidad en aggregate.js).
  const summary = runAnalysis(greenRun({ scanDurationMs: 130, pinDurations: Array(20).fill(250) }), {
    latencyVerdictMode: 'baseline',
    baseline: asBaseline(),
  })

  assert.equal(statusOf(summary, 'RNF-P-08'), 'pass')
  assert.equal(statusOf(summary, 'RNF-P-02'), 'fail')
})

// --- K6-2: la rampa del cambio de turno --------------------------------------

test('[RNF-P-06] la rampa no infla la tasa de la meseta', () => {
  // 49 tramos en la meseta de 1 s y 100 mas en la rampa: sigue por debajo de 50/s.
  const lines = [
    ...greenRun({ shiftProducing: 49 }),
    ...rampRows({ count: 100, durationMs: 80 }),
    row({ metric: 'scan_outcomes', value: 100, scenario: 'scan', tags: { phase: 'scan_ramp', action: 'clock_in' } }),
  ]
  const summary = runAnalysis(lines)

  assert.equal(statusOf(summary, 'RNF-P-06'), 'fail')
  assert.match(summary.verdicts['RNF-P-06'].detail, /49 tramos en tiempo real en 1 s/)
  assert.equal(summary.totals.shift_producing_ramp, 100)
})

test('[RNF-P-02] la latencia de la rampa no entra en el p95 de la meseta', () => {
  const lines = [...greenRun(), ...rampRows({ count: 60, durationMs: 900 })]
  const summary = runAnalysis(lines)

  assert.equal(statusOf(summary, 'RNF-P-02'), 'pass')
  assert.equal(summary.ramp_path.p95, 900)
})

test('[RNF-P-06] un rechazo en la rampa es un rechazo al empleado', () => {
  const lines = [...greenRun(), ...rampRows({ count: 1, durationMs: 80, status: '403' })]
  const summary = runAnalysis(lines)

  assert.equal(statusOf(summary, 'RNF-P-06'), 'fail')
  assert.match(summary.verdicts['RNF-P-06'].detail, /rechazos al empleado 1/)
})

// --- La linea base versionada (bloque 15, R5-QA-02) --------------------------

/**
 * `baseline.json` tal y como esta en el arbol: la mediana (`baseline.js`) de tres
 * pasadas de load-test.yml sobre la rama del bloque 15 con la misma carga.
 */
const VERSIONED_BASELINE = JSON.parse(fs.readFileSync(path.join(__dirname, 'baseline.json'), 'utf8'))

/** Una muestra atendida de cada escenario que la verde no trae. */
const OTHER_SCENARIO_ROWS = [
  row({ metric: 'http_req_duration', value: 300, scenario: 'batch', status: '207', tags: { phase: 'batch' } }),
  row({ metric: 'http_req_duration', value: 300, scenario: 'compliance', status: '200', tags: { phase: 'compliance' } }),
  row({ metric: 'http_req_duration', value: 100, scenario: 'resend', status: '200', tags: { phase: 'resend_replay' } }),
]

/** Los parametros que lanza load-test.yml sin inputs, y con los que se tomo. */
const WORKFLOW_PARAMETERS = {
  instances: 3,
  scanRate: 6,
  offeredRate: 18,
  durationSeconds: 120,
  rampSeconds: 30,
  pinPeriodSeconds: 4,
  latencyVerdictMode: 'baseline',
  baseline: VERSIONED_BASELINE,
}

test('[R5-QA-02] la linea base versionada es la mediana de tres pasadas y dice cuales', () => {
  assert.deepEqual(
    VERSIONED_BASELINE.baseline_of.runs.map((run) => run.run_id),
    ['38654065980', '38656006160', '38659326956'],
  )
  assert.equal(VERSIONED_BASELINE.write_path.p95, 401.6)
})

test('[R5-QA-02] la linea base versionada declara la forma de la carga de hoy', () => {
  assert.equal(VERSIONED_BASELINE.instances, 3)
  assert.equal(VERSIONED_BASELINE.duration_seconds, 120)
  assert.equal(VERSIONED_BASELINE.ramp_seconds, 30)
  assert.equal(VERSIONED_BASELINE.pin_period_seconds, 4)
  assert.ok(Object.keys(VERSIONED_BASELINE.scenarios).includes('scan-pin'))
})

test('[R5-QA-02][RNF-P-02][RNF-P-06] la linea base versionada es comparable con una pasada de los parametros del workflow', () => {
  const summary = runAnalysis([...greenRun(), ...OTHER_SCENARIO_ROWS], WORKFLOW_PARAMETERS)

  assert.equal(summary.baseline.comparable, true)
  assert.notEqual(statusOf(summary, 'RNF-P-02'), 'not_comparable')
  assert.notEqual(statusOf(summary, 'RNF-P-06'), 'not_comparable')
})

test('[R5-QA-02] la linea base versionada no se compara con las 8 instancias que lanzaba la etiqueta', () => {
  const summary = runAnalysis([...greenRun(), ...OTHER_SCENARIO_ROWS], { ...WORKFLOW_PARAMETERS, instances: 8 })

  assert.equal(statusOf(summary, 'RNF-P-02'), 'not_comparable')
  assert.match(summary.verdicts['RNF-P-02'].detail, /instancias: 3 frente a 8/)
})

// --- baseline.js: la linea base es la mediana de varias pasadas --------------

const { buildBaseline } = require('./baseline.js')

/** Un summary.json minimo con la carga del workflow y las cifras indicadas. */
function runSummary({ writeP95, scanP95, rate, rejectP50 = 80, pinP95 = 600, instances = 3 }) {
  return {
    git_sha: 'abc1234',
    runner: 'github-Linux-X64',
    instances,
    scan_rate: 6,
    duration_seconds: 120,
    ramp_seconds: 30,
    pin_period_seconds: 4,
    scenarios: { scan: { p95: scanP95 }, 'scan-pin': { p95: 600 }, reject: {}, 'reject-out-of-order': {} },
    write_path: { p95: writeP95 },
    pin_path: { p95: pinP95 },
    reject_classes: { signature: { p50: rejectP50 }, unknown: { p50: rejectP50 }, revoked: { p50: rejectP50 } },
    totals: { shift_entries_per_second: rate },
  }
}

const THREE_RUNS = [
  { id: '101', summary: runSummary({ writeP95: 287, scanP95: 267.3, rate: 19.5, rejectP50: 80, pinP95: 625.7 }) },
  { id: '102', summary: runSummary({ writeP95: 401.6, scanP95: 371, rate: 19.4, rejectP50: 104.7, pinP95: 895.2 }) },
  { id: '103', summary: runSummary({ writeP95: 330, scanP95: 300, rate: 19.6, rejectP50: 90, pinP95: 900 }) },
]

test('[R5-QA-02] la linea base es la mediana de cada metrica, no una pasada', () => {
  const baseline = buildBaseline(THREE_RUNS)

  assert.equal(baseline.write_path.p95, 330)
  assert.equal(baseline.scenarios.scan.p95, 300)
  assert.equal(baseline.totals.shift_entries_per_second, 19.5)
  assert.deepEqual(baseline.baseline_of.runs.map((run) => run.run_id), ['101', '102', '103'])
  assert.equal(baseline.baseline_of.spread.write_path_p95_max_over_median, 1.217)
})

test('[R5-QA-02] no construye una linea base con pasadas de cargas distintas', () => {
  const mixed = [...THREE_RUNS.slice(0, 2), { id: '104', summary: runSummary({ writeP95: 300, scanP95: 300, rate: 19.5, instances: 8 }) }]

  assert.throws(() => buildBaseline(mixed), /no tienen la misma carga/)
})

test('[R5-QA-02] no construye una linea base con menos de tres pasadas', () => {
  assert.throws(() => buildBaseline(THREE_RUNS.slice(0, 2)), /al menos 3 pasadas/)
})

test('[R5-QA-02][RNF-P-02] el agregado acepta la linea base de la mediana y dice de que pasadas sale', () => {
  const summary = runAnalysis(greenRun(), {
    latencyVerdictMode: 'baseline',
    baseline: buildBaseline(THREE_RUNS),
    instances: 3,
    offeredRate: 18,
    durationSeconds: 120,
  })

  assert.equal(summary.baseline.comparable, true)
  assert.equal(statusOf(summary, 'RNF-P-02'), 'pass')
  assert.match(summary.verdicts['RNF-P-02'].detail, /mediana de 3 pasadas: 101, 102, 103/)
})

// --- RNF-P-02 en modo linea base: el cociente y la guarda gruesa -------------
//
// Decision del propietario (15-10-2026). La linea base de `asBaseline()` es
// p95 100 ms con rechazos de 30 ms: 3,33 rechazos, maximo 4,17; guarda gruesa
// 300 ms.

test('[RNF-P-02] un p95 que empeora frente a la velocidad de la maquina es rojo aunque los ms quepan en la guarda', () => {
  // 130 ms con rechazos de 30 ms: 4,33 rechazos, por encima de 4,17.
  const summary = runAnalysis(greenRun({ scanDurationMs: 130 }), {
    latencyVerdictMode: 'baseline',
    baseline: asBaseline(),
  })

  assert.equal(statusOf(summary, 'RNF-P-02'), 'fail')
  assert.match(summary.verdicts['RNF-P-02'].detail, /vale 4\.33 rechazos, frente a un maximo de 4\.17/)
})

test('[RNF-P-02] un runner tres veces mas lento con el mismo codigo sigue en verde', () => {
  // 290 ms con rechazos de 87 ms: los mismos 3,33 rechazos, y 290 <= 300.
  const summary = runAnalysis(greenRun({ scanDurationMs: 290, rejectDurationMs: 87 }), {
    latencyVerdictMode: 'baseline',
    baseline: asBaseline(),
  })

  assert.equal(statusOf(summary, 'RNF-P-02'), 'pass')
})

test('[RNF-P-02] un p95 por encima de 3x la linea base en ms es rojo aunque el cociente cuadre', () => {
  // 340 ms con rechazos de 85 ms: 4 rechazos, por debajo de 4,17, pero 340 > 300.
  // Una ralentizacion global frena tambien los rechazos y el cociente no la ve.
  // El runner va 2,83 veces mas lento: por debajo de 3x, la guarda gruesa manda.
  const summary = runAnalysis(greenRun({ scanDurationMs: 340, rejectDurationMs: 85 }), {
    latencyVerdictMode: 'baseline',
    baseline: asBaseline(),
  })

  assert.equal(statusOf(summary, 'RNF-P-02'), 'fail')
  assert.match(summary.verdicts['RNF-P-02'].detail, /ROJO por la guarda gruesa/)
  assert.equal(exitCodeOf(summary), 1)
})

test('[RNF-P-02] sin rechazos bastantes para medir la velocidad de la maquina no hay verde', () => {
  // 19 rechazos `unknown`: por debajo del suelo de RS-03.
  const summary = runAnalysis(greenRun({ unknownSamples: 19 }), {
    latencyVerdictMode: 'baseline',
    baseline: asBaseline(),
  })

  assert.equal(statusOf(summary, 'RNF-P-02'), 'unmeasurable')
  assert.match(summary.verdicts['RNF-P-02'].detail, /NO EVALUABLE: sin rechazos de credencial suficientes/)
  assert.equal(exitCodeOf(summary), 2)
})

test('[RNF-P-02] la guarda gruesa da rojo aunque no se pueda medir la velocidad de la maquina', () => {
  const summary = runAnalysis(greenRun({ scanDurationMs: 400, unknownSamples: 19 }), {
    latencyVerdictMode: 'baseline',
    baseline: asBaseline(),
  })

  assert.equal(statusOf(summary, 'RNF-P-02'), 'fail')
})

for (const run of VERSIONED_BASELINE.baseline_of.runs) {
  test(`[R5-QA-02][RNF-P-02] la pasada real ${run.run_id} da verde contra la linea base que ayudo a construir`, () => {
    // Las cifras reales de la pasada: su p95 de escritura y la velocidad de la
    // maquina que midio. Con la linea base de una sola pasada y 1,25x en ms, la
    // 38659326956 (999,7 ms) salia roja con el mismo codigo.
    const lines = [
      ...greenRun({ scanDurationMs: run.write_path_p95, rejectDurationMs: run.reject_p50 }),
      ...OTHER_SCENARIO_ROWS,
    ]
    const summary = runAnalysis(lines, WORKFLOW_PARAMETERS)

    assert.equal(statusOf(summary, 'RNF-P-02'), 'pass')
  })
}

test('[RNF-P-02] el aviso de tendencia tambien lee el p95 en unidades de velocidad de la maquina', () => {
  // El mismo codigo en un runner tres veces mas lento: +190 % en ms, 0 % en
  // unidades de velocidad. No hay regresion que avisar.
  const summary = runAnalysis(greenRun({ scanDurationMs: 290, rejectDurationMs: 87 }), { baseline: asBaseline() })

  assert.equal(summary.baseline.normalised, true)
  assert.equal(summary.baseline.regression, false)
  assert.equal(summary.write_path.machine_speed_ms, 87)
  assert.equal(summary.write_path.p95_over_reject_p50, 3.33)
})

// --- RNF-P-08 contra la linea base: baseline.js y la pasada real -------------

test('[RNF-P-08] la linea base del PIN es la mediana de los cocientes de cada pasada, no el cociente de las medianas', () => {
  // Cocientes por pasada: 625,7/80 = 7,82; 895,2/104,7 = 8,55; 900/90 = 10.
  // El cociente de las medianas seria 895,2/90 = 9,95.
  const baseline = buildBaseline(THREE_RUNS)

  assert.equal(baseline.pin_path.p95_over_reject_p50, 8.55)
  assert.equal(baseline.pin_path.p95, 895.2)
  assert.deepEqual(
    baseline.baseline_of.runs.map((run) => run.pin_p95_over_reject_p50),
    [7.82, 8.55, 10],
  )
})

test('[RNF-P-08] la linea base versionada trae la latencia del PIN en unidades de velocidad de la maquina', () => {
  // Las tres pasadas reales: 625,7/80 = 7,82; 895,2/104,7 = 8,55; 2076,4/260 = 7,99.
  assert.equal(VERSIONED_BASELINE.pin_path.p95, 895.2)
  assert.equal(VERSIONED_BASELINE.pin_path.p95_over_reject_p50, 7.99)
})

for (const run of VERSIONED_BASELINE.baseline_of.runs) {
  test(`[RNF-P-08] la pasada real ${run.run_id} da verde en el PIN contra la linea base que ayudo a construir`, () => {
    const lines = [
      ...greenRun({
        scanDurationMs: run.write_path_p95,
        rejectDurationMs: run.reject_p50,
        pinDurations: Array(20).fill(run.pin_p95),
      }),
      ...OTHER_SCENARIO_ROWS,
    ]
    const summary = runAnalysis(lines, WORKFLOW_PARAMETERS)

    assert.equal(statusOf(summary, 'RNF-P-08'), 'pass')
  })
}

// --- V21-5-QA-01: con el runner degradado, «no fiable» y no rojo --------------
//
// El run 38746064846 salio rojo con la maquina unas 15 veces mas lenta que en la
// linea base, y 35 min despues salio verde con un comentario de diferencia: lo
// pusieron en rojo la guarda gruesa de 3x y RS-03, que se juzga en ms. La
// velocidad de la maquina de `asBaseline()` son rechazos de 30 ms: por encima
// de 90 ms (3x) la pasada no es fiable para lo que depende de la maquina.

test('[V21-5-QA-01][RNF-P-02][RNF-P-08] un runner 15 veces mas lento da no fiable y no rojo', () => {
  // El mismo codigo en una maquina 15 veces mas lenta: rechazos de 450 ms, p95
  // de 1500 ms y PIN de 3750 ms. Los dos cocientes cuadran; la guarda gruesa no.
  const summary = runAnalysis(
    greenRun({ scanDurationMs: 1500, rejectDurationMs: 450, pinDurations: Array(20).fill(3750) }),
    { latencyVerdictMode: 'baseline', baseline: asBaseline() },
  )

  assert.equal(statusOf(summary, 'RNF-P-02'), 'unreliable')
  assert.equal(statusOf(summary, 'RNF-P-08'), 'unreliable')
  assert.match(summary.verdicts['RNF-P-02'].detail, /^NO FIABLE: el runner iba 15 veces mas lento que en la linea base/)
  assert.equal(exitCodeOf(summary), 2)
})

test('[V21-5-QA-01][RNF-P-08] el veredicto no fiable no calla el no comparable de una linea base sin PIN', () => {
  // La maquina 15 veces mas lenta y una linea base comparable en carga pero sin
  // `pin_path.p95_over_reject_p50`: RNF-P-02 no es fiable y RNF-P-08 no es
  // comparable, a la vez.
  const summary = runAnalysis(greenRun({ scanDurationMs: 1500, rejectDurationMs: 450 }), {
    latencyVerdictMode: 'baseline',
    baseline: asBaseline({ pin_path: { p50: 708.6, p95: 895.2 } }),
  })

  const report = formatReport(summary)

  assert.equal(statusOf(summary, 'RNF-P-02'), 'unreliable')
  assert.equal(statusOf(summary, 'RNF-P-08'), 'not_comparable')
  assert.match(report, /VEREDICTO: no fiable\. .*Repite la pasada\. Ademas, no comparable: RNF-P-08; .*baseline\.js/)
})

test('[V21-5-QA-01] publica cuanto mas lenta iba la maquina que en la linea base', () => {
  const summary = runAnalysis(greenRun({ rejectDurationMs: 450 }), {
    latencyVerdictMode: 'baseline',
    baseline: asBaseline(),
  })

  assert.deepEqual(summary.machine, {
    speed_ms: 450,
    baseline_speed_ms: 30,
    slowdown: 15,
    max_slowdown: 3,
    degraded: true,
  })
})

test('[V21-5-QA-01][RNF-P-02] un runner exactamente 3 veces mas lento sigue juzgandose, y la guarda gruesa da rojo', () => {
  // Rechazos de 90 ms: 3x justos, no por encima. 360 ms son 4 rechazos (maximo
  // 4,17), pero 360 > 300.
  const summary = runAnalysis(greenRun({ scanDurationMs: 360, rejectDurationMs: 90 }), {
    latencyVerdictMode: 'baseline',
    baseline: asBaseline(),
  })

  assert.equal(summary.machine.degraded, false)
  assert.equal(statusOf(summary, 'RNF-P-02'), 'fail')
  assert.match(summary.verdicts['RNF-P-02'].detail, /ROJO por la guarda gruesa/)
  assert.equal(exitCodeOf(summary), 1)
})

test('[V21-5-QA-01][RNF-P-02] un runner 3,1 veces mas lento con la guarda gruesa superada da no fiable', () => {
  // Rechazos de 93 ms: 3,1x. 372 ms son 4 rechazos, y 372 > 300.
  const summary = runAnalysis(greenRun({ scanDurationMs: 372, rejectDurationMs: 93 }), {
    latencyVerdictMode: 'baseline',
    baseline: asBaseline(),
  })

  assert.equal(summary.machine.degraded, true)
  assert.equal(statusOf(summary, 'RNF-P-02'), 'unreliable')
  assert.equal(exitCodeOf(summary), 2)
})

test('[V21-5-QA-01][RNF-P-02] un runner degradado no convierte en no fiable lo que sigue en verde', () => {
  // 4 veces mas lento, y el p95 en 2,42 rechazos y 290 ms: dentro de las dos
  // condiciones. Lo que se midio se cumplio aun con la maquina peor.
  const summary = runAnalysis(greenRun({ scanDurationMs: 290, rejectDurationMs: 120 }), {
    latencyVerdictMode: 'baseline',
    baseline: asBaseline(),
  })

  assert.equal(summary.machine.degraded, true)
  assert.equal(statusOf(summary, 'RNF-P-02'), 'pass')
})

test('[V21-5-QA-01][RNF-P-06] con el runner degradado una tasa por debajo de la linea base da no fiable', () => {
  // 47 tramos/s frente a un minimo de 48, con rechazos de 450 ms.
  const summary = runAnalysis(greenRun({ shiftProducing: 47, rejectDurationMs: 450, scanDurationMs: 1500 }), {
    latencyVerdictMode: 'baseline',
    baseline: asBaseline(),
  })

  assert.equal(statusOf(summary, 'RNF-P-06'), 'unreliable')
  assert.match(summary.verdicts['RNF-P-06'].detail, /minimo 48\/s/)
})

test('[V21-5-QA-01][RNF-P-06] con el runner degradado un rechazo al empleado sigue siendo rojo', () => {
  // Un 403 sobre un fichaje valido es una jornada perdida en cualquier maquina.
  const summary = runAnalysis(greenRun({ extraScanStatus: '403', rejectDurationMs: 450, scanDurationMs: 1500 }), {
    latencyVerdictMode: 'baseline',
    baseline: asBaseline(),
  })

  assert.equal(statusOf(summary, 'RNF-P-06'), 'fail')
  assert.equal(exitCodeOf(summary), 1)
})

test('[V21-5-QA-01][RS-03] con el runner degradado una separacion de 30 ms entre clases da no fiable', () => {
  // Medianas de 450, 450 y 480 ms: 30 ms de separacion, con la maquina 15x.
  const summary = runAnalysis(
    greenRun({ rejectDurationMs: 450, scanDurationMs: 1500, revokedDurations: [450, ...Array(19).fill(480)] }),
    { latencyVerdictMode: 'baseline', baseline: asBaseline() },
  )

  assert.equal(statusOf(summary, 'RS-03'), 'unreliable')
  assert.match(summary.verdicts['RS-03'].detail, /separacion de medianas 30\.0 ms/)
})

test('[V21-5-QA-01][RS-03] con el runner degradado un rechazo por debajo del suelo sigue siendo rojo', () => {
  // Una maquina lenta no hace mas rapido un rechazo: 24 ms con suelo de 25 es el
  // suelo de tiempo constante roto, vaya como vaya el runner.
  const summary = runAnalysis(
    greenRun({ rejectDurationMs: 450, scanDurationMs: 1500, revokedDurations: Array(20).fill(24) }),
    { latencyVerdictMode: 'baseline', baseline: asBaseline() },
  )

  assert.equal(summary.machine.degraded, true)
  assert.equal(statusOf(summary, 'RS-03'), 'fail')
  assert.equal(exitCodeOf(summary), 1)
})

test('[V21-5-QA-01][RS-03] en modo umbral una maquina lenta no es excusa: la separacion es roja', () => {
  // En hardware de referencia se juzga el requisito, no la regresion: alli no
  // hay «runner degradado» que valga.
  const summary = runAnalysis(
    greenRun({ rejectDurationMs: 450, scanDurationMs: 1500, revokedDurations: [450, ...Array(19).fill(480)] }),
    { baseline: asBaseline() },
  )

  assert.equal(summary.machine.degraded, false)
  assert.equal(statusOf(summary, 'RS-03'), 'fail')
})

test('[V21-5-QA-01] contra una linea base de otra carga no se declara degradado: no hay con que comparar', () => {
  const summary = runAnalysis(greenRun({ rejectDurationMs: 450, scanDurationMs: 1500 }), {
    latencyVerdictMode: 'baseline',
    baseline: asBaseline({ instances: 3 }),
  })

  assert.equal(summary.machine.degraded, false)
  assert.equal(statusOf(summary, 'RNF-P-02'), 'not_comparable')
})

test('[V21-5-QA-01] el veredicto legible dice «no fiable» y por que, no «rojo»', () => {
  const summary = runAnalysis(
    greenRun({ scanDurationMs: 1500, rejectDurationMs: 450, pinDurations: Array(20).fill(3750) }),
    { latencyVerdictMode: 'baseline', baseline: asBaseline() },
  )

  const report = formatReport(summary)

  assert.match(report, /NOFIA RNF-P-02: NO FIABLE/)
  assert.match(
    report,
    /VEREDICTO: no fiable\. El runner iba 15 veces mas lento que en la linea base \(maximo 3x\): RNF-P-02, RNF-P-08 no se dan ni por rojos ni por verdes\. Repite la pasada\./,
  )
  assert.doesNotMatch(report, /VEREDICTO: rojo/)
})

test('[V21-5-QA-01] la pasada mas lenta de la linea base versionada no cuenta como runner degradado', () => {
  // 260 ms frente a la mediana de 104,7: 2,48x, la mayor dispersion medida.
  const slowest = VERSIONED_BASELINE.baseline_of.runs.find((run) => run.run_id === '38659326956')
  const lines = [
    ...greenRun({ scanDurationMs: slowest.write_path_p95, rejectDurationMs: slowest.reject_p50 }),
    ...OTHER_SCENARIO_ROWS,
  ]
  const summary = runAnalysis(lines, WORKFLOW_PARAMETERS)

  assert.equal(summary.machine.degraded, false)
})
