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
// Y UN CUARTO, `not_comparable`, que es un `unmeasurable` con causa: RNF-P-02,
// RNF-P-06 y RNF-P-08 se juzgan contra la linea base (modo `baseline`) y esta
// se tomo con otra carga —instancias, duracion, rampa, PIN o escenarios
// distintos— o, en RNF-P-08, no trae la latencia del PIN en unidades de
// velocidad de la maquina. No se compara y sale con 2 (R5-QA-02).
//
// Y UN QUINTO, `unreliable` («no fiable»), que tampoco es rojo ni verde: el
// runner iba mas de `DEGRADED_MACHINE_RATIO` veces mas lento que en la linea
// base, y un rojo de lo que depende de la maquina no distingue una regresion de
// un runner degradado. Sale con 2: repite la pasada (V21-5-QA-01).
//
// Y UNO MAS QUE NO ES UN DESENLACE: `info`. Lo lleva `RS-03-RN-18`, que publica
// cuanto se separa el `422` de RN-18 de los tres rechazos de credencial. A-13
// (doc 07 §6) acepto esa diferencia SIN suelo de tiempo y pidio la cifra, no una
// puerta: por eso `info` no entra en el codigo de salida ni en el «VEREDICTO».
//
// QUE PUBLICA `summary.json`, ademas de lo evidente:
//
//   verdicts['<requisito>']       `{ status, detail }`. `status` es `pass`,
//                                 `fail`, `unmeasurable`, `not_comparable`,
//                                 `unreliable` o —solo en `RS-03-RN-18`—
//                                 `info`.
//   machine                       velocidad de la maquina de la pasada y de la
//                                 linea base, cuantas veces mas lenta iba
//                                 (`slowdown`), el maximo (`max_slowdown`) y si
//                                 la pasada cuenta como de un runner degradado.
//   reject_classes.<clase>        `signature`, `unknown` y `revoked`: muestras,
//                                 minimo y percentiles del rechazo de
//                                 credencial. Es lo que compara RS-03.
//   reject_out_of_order           el CUARTO rechazo (RN-18): `samples`,
//                                 `offered`, `reconciled` (las que si cuadraron
//                                 y por tanto no miden nada), `min`, `p50`,
//                                 `p95`, `max`, `firm` (si hay muestras
//                                 bastantes) y `separation_ms`, con la
//                                 diferencia FIRMADA —positiva si RN-18 tarda
//                                 mas— de la mediana y del minimo frente a cada
//                                 una de las tres clases de credencial.
//
// Uso:
//   node aggregate.js <directorio con instance-*.csv> --duration=120 --instances=10
//        [--scan-rate 6] [--rejection-floor-ms 25] [--debounce-seconds 60]
//        [--ramp-seconds 30] [--pin-period-seconds 4]
//        [--git-sha abc1234] [--harness-sha def5678] [--runner ubuntu-24.04] [--k6-version v2.2.0]
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
// RNF-P-08 (decision del propietario, 15-10-2026): el fichaje por PIN paga un
// bcrypt de coste 12 (~160 ms solo el hash) que no se rebaja, porque con un PIN
// de pocas cifras es lo unico que frena una fuerza bruta offline si se roba la
// base. Cifras ESTIMADAS —150 ms de RNF-P-02 + el hash + margen—, a confirmar
// cuando se midan en hardware de referencia (doc 01 §6.1).
const PIN_P95_BUDGET_MS = 400 // RNF-P-08
const PIN_P99_BUDGET_MS = 800 // RNF-P-08
const SHIFT_RATE_BUDGET = 50 // RNF-P-06: fichajes que producen tramo, por segundo
const QUEUEABLE_DEGRADATION_RATIO = 0.005 // regla dura 19: 429, 5xx y sin respuesta
const DEBOUNCE_CEILING_RATIO = 0.05 // el anti-rebote no puede ser la mayoria
const SERVER_ERROR_TOLERANCE = 0.01 // 5xx sobre lo que SI llego al servidor
const REJECT_SPREAD_BUDGET_MS = 20 // RS-03: separacion maxima entre clases
const DEFAULT_REJECTION_FLOOR_MS = 25 // si la instalacion no lo dice
const COMPLIANCE_BUDGET_MS = 2000 // orientativo: ningun requisito lo fija

/**
 * Cuanto puede empeorar el p95 frente a la linea base.
 *
 * El MISMO numero en los dos usos que tiene, y a proposito: en modo `threshold`
 * es un aviso sobre la tendencia y en modo `baseline` es el veredicto. Lo que
 * cambia es quien manda, no cuanto se tolera.
 *
 * DISPERSION MEDIDA DEL RUNNER, Y POR QUE 1,25 SE QUEDA CORTO (bloque 15).
 * Tres pasadas del MISMO codigo con la misma carga (38654065980, 38656006160 y
 * 38659326956) dieron un p95 de escritura de 287, 401,6 y 999,7 ms: la mas
 * lenta es 2,49 veces la mediana y 3,48 veces la mas rapida. Con 1,25 sobre la
 * mediana, una de cada tres pasadas sale roja sin que nada haya cambiado, que
 * es una prueba intermitente. En las tres, la mediana de los rechazos de
 * credencial —trabajo poco y fijo, la «velocidad de la maquina» de esa
 * pasada— crecio en la misma proporcion (80, 104,7 y 260 ms), y el p95 medido
 * en esa unidad fue 3,59, 3,84 y 3,85: un 7 % de dispersion en vez de un 248 %.
 *
 * DECISION DEL PROPIETARIO (15-10-2026), APLICADA. En modo `baseline`, RNF-P-02
 * juzga ese COCIENTE —p95 de escritura entre la mediana de los p50 de las tres
 * clases de rechazo de credencial de la MISMA pasada— con esta tolerancia sobre
 * el cociente de la linea base. El p95 en milisegundos queda como guarda gruesa
 * a `BASELINE_GROSS_RATIO`: una ralentizacion GLOBAL, que tambien frena los
 * rechazos y que el cociente no ve, sigue saliendo roja. Sin rechazos bastantes
 * para medir la velocidad de la maquina, el veredicto es «no fiable», nunca
 * verde. En modo `threshold` la cifra solo alimenta el aviso de tendencia. Las
 * cifras por pasada estan en `baseline.json`, `baseline_of.runs`.
 */
const BASELINE_REGRESSION_RATIO = 1.25

/**
 * La guarda gruesa en milisegundos del modo `baseline` (decision de 15-10-2026).
 *
 * 3x la linea base: por encima de lo que se ha visto variar el runner con el
 * mismo codigo (la pasada mas lenta fue 2,49 veces la mediana), y por debajo de
 * lo que costaria una regresion global seria que el cociente no detecta.
 */
const BASELINE_GROSS_RATIO = 3

/**
 * Cuantas veces mas lento que en la linea base puede ir el runner antes de que
 * la pasada deje de ser fiable (V21-5-QA-01, tarea 23.1 de la 2.3.0).
 *
 * QUE SE COMPARA. La velocidad de la maquina de esta pasada —la mediana de los
 * p50 de las tres clases de rechazo de credencial, `machineSpeedOf`— frente a la
 * de la linea base (la misma mediana sobre `baseline.reject_classes`). Solo en
 * modo `baseline` y solo con una linea base COMPARABLE: en hardware de
 * referencia se juzga el umbral del requisito, y una maquina lenta alli es un
 * rojo de verdad; con otra carga, la velocidad no es comparable.
 *
 * POR QUE 3, Y POR QUE EL MISMO NUMERO QUE LA GUARDA GRUESA. Con el mismo
 * codigo, una maquina N veces mas lenta lleva el p95 en milisegundos a unas N
 * veces el de la linea base. Por encima de `BASELINE_GROSS_RATIO`, la guarda
 * gruesa sale roja POR CONSTRUCCION, y desde dentro de la pasada no se puede
 * distinguir un runner degradado de una regresion que frene todo por igual.
 * Las tres pasadas de la linea base variaron hasta 2,48 veces la mediana (260
 * frente a 104,7 ms) y siguen siendo fiables; el run 38746064846, que salio
 * rojo y 35 min despues verde con un comentario de diferencia, iba unas 15.
 *
 * QUE CAMBIA, Y QUE NO. Con la maquina por encima de este factor, un ROJO de lo
 * que depende de la maquina —RNF-P-02, RNF-P-06 en su tasa, RNF-P-08 y la
 * separacion de RS-03 en milisegundos— pasa a `unreliable` («no fiable»): ni
 * rojo ni verde, salida 2, repite la pasada. Un verde sigue siendo verde: se
 * cumplio con la maquina peor. Y lo que no depende de la maquina sigue siendo
 * rojo: un rechazo al empleado, un reenvio distinto, un 5xx, un `check()`
 * fallido o un rechazo por debajo del suelo de tiempo constante. Si la
 * regresion global es del producto, la pasada saldra «no fiable» una y otra
 * vez, y eso tambien se ve.
 */
const DEGRADED_MACHINE_RATIO = 3

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

/**
 * Fases que son un fichaje valido: aqui un 4xx que no sea 429 es un rechazo al
 * empleado.
 *
 * `batch_seed` es el escaneo con el que el guion abre el tramo que despues
 * contradice el elemento imposible de RN-18. Es un fichaje como cualquier otro
 * —si el servidor se lo niega, es un rechazo— pero no cuenta para el pico: lo
 * emite el guion una vez por lote, no una persona en la cola del quiosco.
 */
const VALID_SCAN_PHASES = new Set(['scan', 'scan_ramp', 'resend_original', 'resend_replay', 'batch', 'batch_seed'])

/**
 * La subida y la bajada del cambio de turno (K6-2): fichajes validos que llegan
 * FUERA de la meseta del pico.
 *
 * El escenario `scan` sube de 0 al pico, lo sostiene `--duration` segundos y
 * vuelve a 0. Solo la MESETA cuenta para RNF-P-06 y RNF-P-02 (fase `scan`): la
 * tasa se divide entre la duracion de la meseta, y mezclar la rampa la
 * diluiria con trafico que llego a media carga. La rampa se publica aparte; sus
 * fichajes si son fichajes, y un rechazo en ella es un rechazo al empleado.
 */
const RAMP_PHASE = 'scan_ramp'

/**
 * El fichaje por PIN (RF-AT-11, K6-3): el envio original y su reenvio identico.
 *
 * VA APARTE DEL PICO DE TARJETAS, A PROPOSITO. El PIN paga un bcrypt en el
 * servidor (coste 12, unos 160 ms solo de hash) y meter sus muestras en el p95
 * de `/scan` cambiaria lo que RNF-P-02 mide desde la tarea 3.6 —y dejaria de
 * ser comparable con cualquier linea base anterior—. RNF-P-02 es SOLO el de
 * `/scan` (decision del propietario, 15-10-2026). El PIN tiene dos veredictos
 * propios: `RF-AT-11`, que bajo carga ningun PIN correcto se rechace y que el
 * reenvio devuelva lo mismo; y `RNF-P-08`, su latencia, con su presupuesto y el
 * mismo mecanismo de linea base que RNF-P-02.
 */
const PIN_PHASE = 'pin'
const PIN_REPLAY_PHASE = 'pin_replay'
const PIN_PHASES = new Set([PIN_PHASE, PIN_REPLAY_PHASE])

/**
 * Fichajes por PIN atendidos que hacen falta para dar un veredicto de RF-AT-11
 * y de RNF-P-08. Con 20, el p99 es el maximo: el suelo es conservador, no laxo.
 */
const PIN_SAMPLES_FLOOR = 20

const REJECT_CLASSES = ['signature', 'unknown', 'revoked']

/**
 * La fase del escenario `reject-out-of-order`: el CUARTO rechazo, el de RN-18.
 *
 * NO ES UNA CLASE MAS DE `REJECT_CLASSES`, Y ESO ES LA MITAD DEL ASUNTO. Las
 * tres de arriba son rechazos de CREDENCIAL y `ConstantTimeFloor` las iguala
 * entre si; el `422` de RN-18 comparte el cuerpo generico pero llega por otro
 * camino —tarjeta ya resuelta, ajustes leidos, jornada cargada— y tarda mas.
 * A-13 (doc 07 §6) acepto esa diferencia con argumento y **sin suelo de tiempo**,
 * a condicion de revisarla «con la medicion real que produzca la prueba de
 * carga». Esa medicion es lo que falta y lo que esta fase aporta.
 *
 * POR ESO SU VEREDICTO ES `info` Y NUNCA `fail`: publicar la cifra es el
 * encargo; poner una puerta donde la decision dice que no la hay seria inventar
 * un requisito. Si algun dia se decide lo contrario, lo que cambia es la
 * decision de doc 07 §6, y entonces esto pasa a ser un presupuesto.
 */
const OUT_OF_ORDER_PHASE = 'reject_out_of_order'

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

/** Mediana de los valores numericos; `null` si no hay ninguno o falta alguno. */
function medianOf(values) {
  if (values.length === 0 || values.some((value) => typeof value !== 'number')) {
    return null
  }

  const sorted = [...values].sort((a, b) => a - b)
  const middle = Math.floor(sorted.length / 2)

  return sorted.length % 2 === 1 ? sorted[middle] : (sorted[middle - 1] + sorted[middle]) / 2
}

/**
 * La «velocidad de la maquina» de una pasada: la mediana de los p50 de las tres
 * clases de rechazo de credencial, contestadas con `422`.
 *
 * POR QUE ESTA. Un rechazo de credencial hace poco trabajo, y el mismo en
 * cualquier version —firma, busqueda, suelo de tiempo constante—, asi que lo
 * que hace variar su mediana de una pasada a otra es la maquina: la CPU que el
 * runner tenia libre. Medido en el bloque 15: 80, 104,7 y 260 ms en tres
 * pasadas del mismo codigo.
 *
 * `null` si alguna clase no llega a los suelos de RS-03: entonces no hay
 * velocidad que medir, y quien la necesite tiene que decir «no fiable».
 */
function machineSpeedOf(durations) {
  const medians = REJECT_CLASSES.map((rejectClass) => {
    const answered = percentiles(
      durations
        .filter((sample) => sample.rejectClass === rejectClass && sample.status === '422')
        .map((sample) => sample.value),
    )
    const offered = durations.filter((sample) => sample.rejectClass === rejectClass).length
    const enough =
      answered.samples >= REJECT_SAMPLES_FLOOR && offered > 0 && answered.samples / offered >= REJECT_SAMPLES_RATIO_FLOOR

    return enough ? answered.p50 : null
  })

  return medianOf(medians)
}

/**
 * El juicio de una latencia contra la linea base en modo `baseline`: el de
 * RNF-P-02 desde la decision del 15-10-2026, y el mismo para RNF-P-08 (el PIN).
 * UNA funcion y no dos copias, para que los dos veredictos no puedan divergir.
 *
 * DOS CONDICIONES, Y LAS DOS TIENEN QUE CUMPLIRSE:
 *   1. el p95 en unidades de velocidad de la maquina no empeora mas de
 *      BASELINE_REGRESSION_RATIO frente al de la linea base: es lo que separa
 *      una regresion del producto de un runner lento;
 *   2. el p95 en milisegundos no pasa de BASELINE_GROSS_RATIO veces el de la
 *      linea base: lo que una ralentizacion global —que frena tambien los
 *      rechazos— esconderia en el cociente.
 * Sin velocidad de la maquina que medir, o sin cociente en la linea base,
 * `speedMissing`: quien llama dice «no fiable», nunca verde.
 */
function judgeAgainstMachineSpeed({ p95, machineSpeedMs, baselineP95, baselineNormalisedP95 }) {
  const normalisedP95 = machineSpeedMs === null || machineSpeedMs <= 0 || p95 === null ? null : p95 / machineSpeedMs
  const allowedNormalised = baselineNormalisedP95 === null ? null : baselineNormalisedP95 * BASELINE_REGRESSION_RATIO
  const allowedGrossP95 = baselineP95 === null ? null : baselineP95 * BASELINE_GROSS_RATIO
  const speedMissing = normalisedP95 === null || allowedNormalised === null
  const grossOk = allowedGrossP95 !== null && p95 !== null && p95 <= allowedGrossP95
  const normalisedOk = !speedMissing && normalisedP95 <= allowedNormalised

  return {
    status: !grossOk ? 'fail' : speedMissing ? 'unmeasurable' : normalisedOk ? 'pass' : 'fail',
    normalisedP95,
    allowedNormalised,
    allowedGrossP95,
    grossOk,
    speedMissing,
  }
}

/** Dos decimales para los cocientes del detalle; `n/d` si no hay. */
const twoDecimals = (value) => (value === null ? 'n/d' : (Math.round(value * 100) / 100).toString())

/** La explicacion legible de `judgeAgainstMachineSpeed`, la misma para los dos requisitos. */
function machineSpeedDetail({ judgement, p95, samples, what, machineSpeedMs, baselineNormalisedP95, reference }) {
  return (
    `p95 ${round(p95)} ms sobre ${samples} ${what}; velocidad de la maquina ` +
    `(mediana de los rechazos de credencial) ${round(machineSpeedMs) ?? 'n/d'} ms, asi que el p95 ` +
    `vale ${twoDecimals(judgement.normalisedP95)} rechazos, frente a un maximo de ${twoDecimals(judgement.allowedNormalised)} ` +
    `(${BASELINE_REGRESSION_RATIO}x el ${twoDecimals(baselineNormalisedP95)} de la ${reference}); ` +
    `guarda gruesa: ${round(p95)} ms frente a un maximo de ${round(judgement.allowedGrossP95)} ms ` +
    `(${BASELINE_GROSS_RATIO}x)` +
    (!judgement.grossOk
      ? ' — ROJO por la guarda gruesa: el p95 en milisegundos se ha ido mas alla de lo que el runner varia'
      : judgement.speedMissing
        ? ' — NO EVALUABLE: sin rechazos de credencial suficientes (los suelos de RS-03) no hay ' +
          'velocidad de la maquina con que medir, o la linea base no la trae'
        : '')
  )
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
        metric === 'batch_impossible' ||
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
  // `null` y no 0 cuando no se dicen: una pasada anterior a la rampa o al PIN no
  // declaraba ninguno de los dos, y eso tiene que poder distinguirse de «rampa
  // de 0 s» al comparar con la linea base.
  const rampSeconds = options.rampSeconds === undefined || options.rampSeconds === null ? null : Number(options.rampSeconds)
  const pinPeriodSeconds =
    options.pinPeriodSeconds === undefined || options.pinPeriodSeconds === null ? null : Number(options.pinPeriodSeconds)
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

  // LA VELOCIDAD DE LA MAQUINA de esta pasada y el p95 medido en esa unidad
  // (decision de 15-10-2026, ver `BASELINE_REGRESSION_RATIO`). Solo cuenta si las
  // tres clases de rechazo tienen las muestras que exige RS-03: con menos, la
  // mediana de un puñado de rechazos no es la velocidad de nada.
  const machineSpeedMs = machineSpeedOf(durations)
  const baselineSpeedMs =
    baseline === null
      ? null
      : medianOf(REJECT_CLASSES.map((rejectClass) => baseline?.reject_classes?.[rejectClass]?.p50 ?? null))
  // La linea base de varias pasadas trae el cociente ya hecho (mediana de los
  // cocientes de cada pasada, `baseline.js`); la de una sola, se calcula.
  const baselineNormalisedP95 =
    baseline?.write_path?.p95_over_reject_p50 ??
    (baselineP95 !== null && baselineSpeedMs !== null && baselineSpeedMs > 0 ? baselineP95 / baselineSpeedMs : null)

  // La del PIN (RNF-P-08), con el mismo criterio pero SIN calcularla si falta:
  // `baseline.js` la trae desde el 15-10-2026, y una linea base anterior no
  // tiene con que juzgar el PIN. Se dice «no comparable» y se rehace con
  // `baseline.js` a partir de las pasadas que la construyeron, nunca a ojo.
  const baselinePinP95 = baseline?.pin_path?.p95 ?? null
  const baselinePinNormalisedP95 = baseline?.pin_path?.p95_over_reject_p50 ?? null

  const scenarioNames = [...new Set(durations.map((sample) => sample.scenario))].sort()

  // LA LINEA BASE SOLO VALE PARA LA MISMA PASADA. Comparar un p95 de ocho
  // instancias con el de tres, de 120 s con el de 30, con rampa contra sin
  // rampa o con PIN contra sin PIN es comparar dos cargas distintas y llamarlo
  // regresion (R5-QA-02: la etiqueta lanzaba 8 instancias contra una linea
  // base de 3). Si algo no coincide, el veredicto no es rojo ni verde: es
  // «NO COMPARABLE», y no se calcula ninguna razon entre las dos.
  //
  // Los ESCENARIOS se comparan por el conjunto que dejo muestras, y no por un
  // numero de version del guion: un escenario nuevo —o uno que no llego a
  // arrancar— cambia lo que compite por la CPU, y eso se ve en los datos sin
  // depender de que alguien se acuerde de subir una version.
  const scenarioSet = (names) => [...names].sort().join(' ')
  const baselineParameters = [
    ['instancias', baseline?.instances, instanceCount],
    ['fichajes/s por instancia', baseline?.scan_rate, scanRate],
    ['duracion', baseline?.duration_seconds, durationSeconds],
    ['rampa', baseline?.ramp_seconds, rampSeconds],
    ['periodo del PIN', baseline?.pin_period_seconds, pinPeriodSeconds],
    [
      'escenarios',
      baseline?.scenarios === undefined || baseline?.scenarios === null
        ? undefined
        : scenarioSet(Object.keys(baseline.scenarios)),
      scenarioSet(scenarioNames),
    ],
  ]
  const baselineMismatches =
    baseline === null ? [] : baselineParameters.filter(([, before, now]) => (before ?? null) !== (now ?? null))

  const baselineComparable = baseline !== null && baselineMismatches.length === 0
  const baselineNotComparable = baseline !== null && !baselineComparable
  const baselineUsable = baselineComparable && baselineP95 !== null && baselineRate !== null

  const mismatchText = baselineMismatches
    .map(([name, before, now]) => `${name}: ${before ?? 'n/d'} frente a ${now ?? 'n/d'}`)
    .join('; ')

  // `baseline.js` la construye como mediana de varias pasadas y dice de
  // cuales; una linea base de una sola pasada lleva su `git_sha`.
  const baselineProvenance =
    baseline === null
      ? ''
      : Array.isArray(baseline.baseline_of?.runs)
        ? `(mediana de ${baseline.baseline_of.runs.length} pasadas: ` +
          `${baseline.baseline_of.runs.map((run) => run.run_id).join(', ')})`
        : `(git_sha ${baseline.git_sha ?? 'n/d'})`

  const baselineReference =
    baseline === null ? '' : `linea base del runner: p95 ${baselineP95} ms, ${baselineRate} tramos/s ${baselineProvenance}`

  const noBaselineDetail = baselineNotComparable
    ? `NO COMPARABLE con la linea base (${mismatchText}): no se compara. Mide con los mismos ` +
      'parametros que la linea base o regenerala con los de esta pasada.'
    : baseline === null
      ? 'modo linea base sin --baseline: no hay con que comparar. La PRIMERA pasada de este ' +
        'runner es la que la crea; versiona su summary.json como baseline.json y vuelve a medir.'
      : 'la linea base no trae el p95 del camino de escritura o la tasa de tramos: no hay con que comparar.'

  /** El desenlace de un veredicto que depende de la linea base y no la tiene. */
  const withoutBaseline = baselineNotComparable ? 'not_comparable' : 'unmeasurable'

  // EL RUNNER DEGRADADO (V21-5-QA-01, ver `DEGRADED_MACHINE_RATIO`): cuantas
  // veces mas lenta iba la maquina que en la linea base. Solo en modo
  // `baseline` y con una linea base comparable.
  const machineSlowdown =
    machineSpeedMs === null || baselineSpeedMs === null || baselineSpeedMs <= 0 ? null : machineSpeedMs / baselineSpeedMs
  const machineDegraded =
    mode === 'baseline' && baselineComparable && machineSlowdown !== null && machineSlowdown > DEGRADED_MACHINE_RATIO
  const degradedDetail =
    `NO FIABLE: el runner iba ${twoDecimals(machineSlowdown)} veces mas lento que en la linea base ` +
    `(velocidad de la maquina ${round(machineSpeedMs)} ms frente a ${round(baselineSpeedMs)} ms; por encima de ` +
    `${DEGRADED_MACHINE_RATIO}x no se distingue una regresion de un runner degradado). Repite la pasada.`

  /**
   * El rojo de algo que depende de la maquina, con el runner degradado, no es
   * rojo: es «no fiable». Cualquier otro desenlace se queda como esta.
   */
  const unlessDegraded = (status) => (machineDegraded && status === 'fail' ? 'unreliable' : status)
  const degradedPrefix = (status) => (status === 'unreliable' ? `${degradedDetail} ` : '')

  /** La coletilla que explica por que el umbral no se esta juzgando. */
  const thresholdNotJudged =
    'El umbral de RNF-P-02 y RNF-P-06 NO se juzga en este runner (decision 19): ' +
    'solo en hardware de referencia con make load-test.'

  // --- Por escenario ---------------------------------------------------------

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
    // El juicio de la decision de 15-10-2026: cociente frente a la velocidad de
    // la maquina y guarda gruesa en milisegundos (`judgeAgainstMachineSpeed`).
    const judgement = judgeAgainstMachineSpeed({
      p95: write.p95,
      machineSpeedMs,
      baselineP95,
      baselineNormalisedP95,
    })

    const status = !latencyMeasurable ? 'unmeasurable' : !baselineUsable ? withoutBaseline : unlessDegraded(judgement.status)

    verdict(
      'RNF-P-02',
      status,
      !latencyMeasurable
        ? noSamplesDetail
        : !baselineUsable
          ? `${noBaselineDetail} ${thresholdNotJudged}`
          : degradedPrefix(status) +
            machineSpeedDetail({
              judgement,
              p95: write.p95,
              samples: write.samples,
              what: 'fichajes atendidos',
              machineSpeedMs,
              baselineNormalisedP95,
              reference: baselineReference,
            }) + `. ${thresholdNotJudged}`,
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

    // Aqui NO se exige que la carga ofrecida llegue a 50/s: el perfil del runner
    // son 18/s a proposito (decision 19), porque a 60/s se satura y deja RQ-03 y
    // RS-03 sin muestras. Lo unico que hace evaluable este veredicto es que la
    // linea base sea comparable (mismos parametros); la tasa se juzga contra lo
    // que esta misma maquina demostro sostener con esos parametros.
    //
    // LO QUE NO DEPENDE DE LA MAQUINA SE JUZGA AUNQUE NO HAYA CON QUE COMPARAR.
    // Un rechazo a un fichaje valido es una jornada perdida con linea base y
    // sin ella: una pasada «no comparable» no puede esconderlo detras de un
    // «no evaluable».
    // Con el runner degradado, solo la TASA pasa a «no fiable»: un rechazo al
    // empleado (`alwaysRequired`) no depende de la maquina y sigue siendo rojo.
    const status = !alwaysRequired
      ? 'fail'
      : !baselineUsable
        ? withoutBaseline
        : unlessDegraded(shiftRate >= baselineRate * BASELINE_RATE_TOLERANCE ? 'pass' : 'fail')

    verdict(
      'RNF-P-06',
      status,
      !baselineUsable
        ? `${peakMeasured}. ${noBaselineDetail} ${thresholdNotJudged}`
        : // La degradacion encolable NO se juzga aqui: en este runner es el
          // sintoma de la CPU compartida y ya esta contada en la tasa. Lo que
          // se exige es que la tasa no caiga respecto a lo que esta maquina ya
          // demostro sostener.
          degradedPrefix(status) +
          `${peakMeasured}; minimo ${allowedRate}/s (${BASELINE_RATE_TOLERANCE}x la ${baselineReference}). ` +
          thresholdNotJudged,
    )
  }

  // --- SERVICIO: el servidor esta sirviendo, no reventando --------------------

  // POR QUE ESTO EXISTE APARTE DE RNF-P-06. La tolerancia de degradacion vive
  // dentro de RNF-P-06, y RNF-P-06 deja de evaluarse en cuanto la carga ofrecida
  // no llega al umbral — que es lo normal en una pasada corta de depuracion. Con
  // eso, una pasada de 30 s en la que el servidor contesta `500` a TODOS los
  // cierres de turno terminaba en verde: los cuadres comparaban cero con cero y
  // nadie miraba los codigos. Paso de verdad, el 18-09-2026, con un metodo de
  // dominio que no existia.
  //
  // SE MIDE SOBRE LO QUE LLEGO AL SERVIDOR, no sobre todo lo ofrecido: un `429`
  // del borde y una peticion sin respuesta son capacidad —y de eso ya responde
  // RNF-P-06—, mientras que un `5xx` significa que la peticion entro, se ejecuto
  // codigo y reventó. Un uno por ciento es holgado para una maquina saturada y
  // deja fuera cualquier fallo sistematico.
  //
  // El PIN entra aqui aunque no entre en el pico: un `5xx` en `/scan/pin` es el
  // mismo servidor reventando, y es el camino que mas CPU gasta (K6-3).
  const reachedServer = [
    ...validScanSamples,
    ...durations.filter((sample) => PIN_PHASES.has(sample.phase)),
  ].filter(
    (sample) => sample.status !== '429' && sample.status !== '0' && sample.status !== '(sin status)',
  )
  const serverErrors = reachedServer.filter((sample) => sample.status.startsWith('5'))
  const serverErrorRatio = reachedServer.length === 0 ? 0 : serverErrors.length / reachedServer.length

  verdict(
    'SERVICIO',
    reachedServer.length === 0
      ? 'unmeasurable'
      : serverErrorRatio <= SERVER_ERROR_TOLERANCE
        ? 'pass'
        : 'fail',
    reachedServer.length === 0
      ? 'ninguna peticion de fichaje llego al servidor: no hay nada que juzgar'
      : `${serverErrors.length} de ${reachedServer.length} fichajes que llegaron al servidor ` +
        `terminaron en 5xx (${(serverErrorRatio * 100).toFixed(2)} %, tope ` +
        `${SERVER_ERROR_TOLERANCE * 100} %)`,
  )

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

  // --- RF-AT-11: el fichaje por PIN bajo carga (K6-3) ------------------------

  // QUE SE JUZGA. Cada empleado del escenario `scan-pin` tiene un PIN emitido y
  // un sobre cerrado con la clave publica de la instalacion: TODOS sus
  // fichajes son correctos. Un `422` aqui no es «PIN incorrecto», es una
  // persona sin tarjeta a la que el servidor no dejo fichar en el cambio de
  // turno —la jornada que RF-AT-11 existe para no perder—. Igual que en las
  // tarjetas, un `429`, un `5xx` o una peticion sin respuesta no cuentan como
  // rechazo: el quiosco los encola (regla dura 19).
  //
  // LO QUE NO JUZGA. La latencia: es RNF-P-08, mas abajo (RNF-P-02 es solo el
  // de `/scan`). Ver el comentario de `PIN_PHASE`.
  const pinOriginals = durations.filter((sample) => sample.phase === PIN_PHASE)
  const pinAttended = percentiles(valuesWhere((sample) => sample.phase === PIN_PHASE && isAttended(sample.status)))
  const pinRefusals = pinOriginals.filter((sample) => !isQueueable(sample.status) && !isAttended(sample.status))
  const pinQueueable = pinOriginals.filter((sample) => isQueueable(sample.status))
  const pinShifts = countersWhere('scan_outcomes', (tags) => tags.phase === PIN_PHASE && SHIFT_ACTIONS.has(tags.action))
  const pinDebounced = countersWhere('scan_outcomes', (tags) => tags.phase === PIN_PHASE && tags.action === 'debounced')
  const pinResendYes = countersWhere('resend_matches', (tags) => tags.channel === 'pin' && tags.match === 'yes')
  const pinResendNo = countersWhere('resend_matches', (tags) => tags.channel === 'pin' && tags.match === 'no')
  const pinDebounceRatio = pinShifts + pinDebounced === 0 ? 0 : pinDebounced / (pinShifts + pinDebounced)
  const pinMeasurable = pinAttended.samples >= PIN_SAMPLES_FLOOR
  const pinBroken =
    pinRefusals.length > 0 || pinResendNo > 0 || pinDebounceRatio > DEBOUNCE_CEILING_RATIO

  // `--pin-period-seconds=0`: la instalacion no ofrece fichaje por PIN —sin
  // clave de sellado el quiosco oculta el teclado, y es legitimo (ADR-017)— o
  // se apago a proposito. No hay nada que juzgar y no es una medida rota:
  // `info`, que no entra en el codigo de salida.
  const pinOff = pinPeriodSeconds === 0

  verdict(
    'RF-AT-11',
    pinOff ? 'info' : pinBroken ? 'fail' : !pinMeasurable ? 'unmeasurable' : 'pass',
    pinOff
      ? 'fichaje por PIN NO medido: la instalacion no lo ofrece (sin IDENTITY_PIN_SEALING_SECRET_KEY) o ' +
        'se apago con PIN_PERIOD_SECONDS=0'
      : `${pinOriginals.length} fichajes por PIN enviados, ${pinAttended.samples} atendidos ` +
        `(${pinShifts} con tramo, ${pinDebounced} anti-rebote), ${pinRefusals.length} rechazados al ` +
        `empleado, ${pinQueueable.length} encolables; reenvios por PIN: ${pinResendYes} identicos y ` +
        `${pinResendNo} distintos; p50 ${round(pinAttended.p50) ?? 'n/d'} ms y p95 ` +
        `${round(pinAttended.p95) ?? 'n/d'} ms (la latencia la juzga RNF-P-08)` +
        (pinMeasurable || pinBroken
          ? ''
          : ` — NO EVALUABLE: hacen falta ${PIN_SAMPLES_FLOOR} fichajes por PIN atendidos`),
  )

  // --- RNF-P-08: latencia del fichaje por PIN (decision de 15-10-2026) -------

  // LA MISMA REGLA QUE RNF-P-02, SOBRE OTRA POBLACION: solo los envios
  // originales atendidos (`pin_path`; los reenvios no pagan bcrypt, PIN-02, y
  // un 429 del borde no ejecuta ni una linea de PHP). En modo `threshold`, el
  // presupuesto del requisito; en modo `baseline`, `judgeAgainstMachineSpeed`
  // con la velocidad de la maquina de esta misma pasada. No hay umbral por
  // instancia como en RNF-P-02: a un PIN cada pocos segundos por origen, ninguna
  // instancia reune muestras para un percentil propio.
  //
  // LA GUARDA DE CAPACIDAD NO ES UN VEREDICTO MAS, Y ES A PROPOSITO. Que los
  // fichajes por PIN simultaneos no frenen los de tarjeta ya lo juzgan RNF-P-02
  // y RNF-P-06: `scan-pin` corre en la MISMA meseta que `scan` (scan-peak.js),
  // en un volumen de via de reserva —uno cada `--pin-period-seconds` por origen,
  // frente a `--scan-rate` tarjetas por segundo—, y el periodo del PIN forma
  // parte de la carga que se compara con la linea base. En modo `threshold`
  // (hardware de referencia) la guarda es literal: el umbral de RNF-P-02. En
  // modo `baseline` NO lo es del todo: si el bcrypt solo quita CPU, frena por
  // igual tarjetas y rechazos y el cociente de RNF-P-02 no se mueve; alli la
  // guarda la dan la gruesa de 3x y el propio RNF-P-08 (su cociente se dispara
  // si el PIN se encarece). Una interferencia que no es CPU (un bloqueo que
  // retiene `/scan`) si sale en el cociente de RNF-P-02.
  const pinNotJudged =
    `El umbral de RNF-P-08 (p95 < ${PIN_P95_BUDGET_MS} ms, p99 < ${PIN_P99_BUDGET_MS} ms) NO se juzga en este ` +
    'runner: solo en hardware de referencia con make load-test.'

  if (pinOff) {
    verdict(
      'RNF-P-08',
      'info',
      'latencia del fichaje por PIN NO medida: la instalacion no lo ofrece o se apago con PIN_PERIOD_SECONDS=0',
    )
  } else if (!pinMeasurable) {
    verdict(
      'RNF-P-08',
      'unmeasurable',
      `solo ${pinAttended.samples} fichajes por PIN atendidos, hacen falta ${PIN_SAMPLES_FLOOR}`,
    )
  } else if (mode === 'threshold') {
    verdict(
      'RNF-P-08',
      pinAttended.p95 <= PIN_P95_BUDGET_MS && pinAttended.p99 <= PIN_P99_BUDGET_MS ? 'pass' : 'fail',
      `p95 ${round(pinAttended.p95)} ms (presupuesto ${PIN_P95_BUDGET_MS}), p99 ${round(pinAttended.p99)} ms ` +
        `(presupuesto ${PIN_P99_BUDGET_MS}) sobre ${pinAttended.samples} fichajes por PIN atendidos; ` +
        'cifras estimadas, a confirmar en hardware de referencia (doc 01 §6.1)',
    )
  } else if (!baselineComparable || baselinePinP95 === null || baselinePinNormalisedP95 === null) {
    verdict(
      'RNF-P-08',
      baseline === null ? 'unmeasurable' : 'not_comparable',
      baselineComparable
        ? 'NO COMPARABLE: la linea base no trae la latencia del PIN en unidades de velocidad de la maquina ' +
            '(pin_path.p95_over_reject_p50). Rehazla con baseline.js a partir de las pasadas que la construyeron. ' +
            pinNotJudged
        : `${noBaselineDetail} ${pinNotJudged}`,
    )
  } else {
    const judgement = judgeAgainstMachineSpeed({
      p95: pinAttended.p95,
      machineSpeedMs,
      baselineP95: baselinePinP95,
      baselineNormalisedP95: baselinePinNormalisedP95,
    })

    const status = unlessDegraded(judgement.status)

    verdict(
      'RNF-P-08',
      status,
      degradedPrefix(status) +
      machineSpeedDetail({
        judgement,
        p95: pinAttended.p95,
        samples: pinAttended.samples,
        what: 'fichajes por PIN atendidos',
        machineSpeedMs,
        baselineNormalisedP95: baselinePinNormalisedP95,
        reference: `linea base del PIN: p95 ${baselinePinP95} ms ${baselineProvenance}`,
      }) + `. ${pinNotJudged}`,
    )
  }

  // --- La rampa del cambio de turno (K6-2), informativa -----------------------

  // La subida y la bajada no entran en RNF-P-06 ni en RNF-P-02 (ver `RAMP_PHASE`),
  // pero sus rechazos SI estan en `refusals`, porque son fichajes validos.
  const rampPath = percentiles(valuesWhere((sample) => sample.phase === RAMP_PHASE && isAttended(sample.status)))
  const rampShifts = countersWhere('scan_outcomes', (tags) => tags.phase === RAMP_PHASE && SHIFT_ACTIONS.has(tags.action))

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

  // Con el runner degradado, solo la SEPARACION pasa a «no fiable»: se mide en
  // milisegundos y crece con la cola de una maquina lenta. Un rechazo por debajo
  // del suelo no lo produce ninguna lentitud y sigue siendo rojo.
  const spreadOk = medianSpread <= REJECT_SPREAD_BUDGET_MS && minimumSpread <= REJECT_SPREAD_BUDGET_MS
  const rejectStatus = !rejectMeasurable
    ? 'unmeasurable'
    : !floorRespected
      ? 'fail'
      : unlessDegraded(spreadOk ? 'pass' : 'fail')

  verdict(
    'RS-03',
    rejectStatus,
    degradedPrefix(rejectStatus) +
      (!rejectMeasurable
        ? `solo ${measured.length} de ${REJECT_CLASSES.length} clases con muestras suficientes ` +
          `(${REJECT_SAMPLES_FLOOR} respuestas 422 y el ${REJECT_SAMPLES_RATIO_FLOOR * 100} % de las ofrecidas)`
        : `separacion de medianas ${medianSpread.toFixed(1)} ms y de minimos ` +
          `${minimumSpread.toFixed(1)} ms (tope ${REJECT_SPREAD_BUDGET_MS}); suelo de ` +
          `${rejectionFloorMs} ms ${floorRespected ? 'respetado' : 'NO respetado'} sobre el minimo de cada clase`),
  )

  // --- RS-03-RN-18: la separacion del cuarto rechazo, INFORMATIVA -------------

  // QUE SE PUBLICA Y POR QUE NO SE JUZGA. A-13 del doc 07 §6 acepto que el `422`
  // de RN-18 tarde mas que los tres rechazos de credencial, con un argumento que
  // se sostiene —para provocarlo hace falta un token de quiosco valido Y un
  // `occurred_at` anterior a la entrada abierta de una persona concreta— y **sin
  // suelo de tiempo anadido**, porque anadirlo gastaria latencia en el cambio de
  // turno sin cerrar el oraculo mas limpio que ya existe (el `200` del
  // anti-rebote, ADR-031). Lo que aquella aceptacion prometia era revisarse «con
  // la medicion real de la prueba de carga», y esa cifra no existia (H-08).
  //
  // Aqui esta. Con `verdict: 'info'`: no entra en `exitCodeOf()`, no tiñe la
  // pasada de rojo y no la declara no fiable. Si alguna vez se decide poner un
  // suelo, lo que cambia primero es la fila de doc 07 §6.
  const outOfOrderAnswered = percentiles(
    valuesWhere((sample) => sample.phase === OUT_OF_ORDER_PHASE && sample.status === '422'),
  )
  const outOfOrderOffered = durations.filter((sample) => sample.phase === OUT_OF_ORDER_PHASE).length

  // Un `200` aqui significa que el escaneo SI se pudo reconciliar: o el turno
  // sembrado dejo de estar abierto, o la respuesta salio por el anti-rebote. No
  // es un fallo del producto, es la medida que no se monto, y se cuenta aparte
  // en vez de mezclarse con los rechazos.
  const outOfOrderReconciled = durations.filter(
    (sample) => sample.phase === OUT_OF_ORDER_PHASE && isAttended(sample.status),
  ).length

  // LA SEPARACION VA FIRMADA. Un positivo es «RN-18 tarda mas», que es lo que
  // A-13 predice; un negativo seria la sorpresa, y redondear el valor absoluto
  // la escondera.
  const separationOf = (statistic) =>
    Object.fromEntries(
      REJECT_CLASSES.map((rejectClass) => {
        const mine = outOfOrderAnswered[statistic]
        const theirs = rejectStats[rejectClass].raw[statistic]

        return [rejectClass, mine === null || theirs === null ? null : round(mine - theirs)]
      }),
    )

  const outOfOrderSeparation = { median: separationOf('p50'), minimum: separationOf('min') }
  const outOfOrderFirm = outOfOrderAnswered.samples >= REJECT_SAMPLES_FLOOR
  const separationText = (bucket) =>
    REJECT_CLASSES.map(
      (rejectClass) =>
        `${rejectClass} ${bucket[rejectClass] === null ? 'n/d' : bucket[rejectClass].toFixed(1)}`,
    ).join(', ')

  verdict(
    'RS-03-RN-18',
    'info',
    outOfOrderAnswered.samples === 0
      ? `el escenario reject-out-of-order no dejo ninguna respuesta 422 de ${outOfOrderOffered} ` +
        `peticiones (${outOfOrderReconciled} se pudieron reconciliar): sin cifra que publicar`
      : `mediana ${round(outOfOrderAnswered.p50)} ms y minimo ${round(outOfOrderAnswered.min)} ms sobre ` +
        `${outOfOrderAnswered.samples} rechazos de RN-18. Separacion de medianas frente a la credencial: ` +
        `${separationText(outOfOrderSeparation.median)}; de minimos: ` +
        `${separationText(outOfOrderSeparation.minimum)}. INFORMATIVO: A-13 acepta esta diferencia sin ` +
        'suelo de tiempo (doc 07 §6), asi que la cifra no cambia el veredicto' +
        (outOfOrderFirm
          ? ''
          : ` — con solo ${outOfOrderAnswered.samples} muestras la cifra es orientativa ` +
            `(${REJECT_SAMPLES_FLOOR} para que sea firme)`),
  )

  const outOfOrder = {
    samples: outOfOrderAnswered.samples,
    offered: outOfOrderOffered,
    reconciled: outOfOrderReconciled,
    min: round(outOfOrderAnswered.min),
    p50: round(outOfOrderAnswered.p50),
    p95: round(outOfOrderAnswered.p95),
    max: round(outOfOrderAnswered.max),
    firm: outOfOrderFirm,
    separation_ms: outOfOrderSeparation,
  }

  // --- Informativos ----------------------------------------------------------

  const batchAccepted = countersWhere('batch_outcomes', (tags) => SHIFT_ACTIONS.has(tags.action))
  const batchDebounced = countersWhere('batch_outcomes', (tags) => tags.action === 'debounced')
  // TRES DESENLACES DE ELEMENTO, Y NINGUNO ES EL OTRO:
  //
  //   5xx  el servidor NO lo proceso; el quiosco lo conserva en la cola y lo
  //        reintenta (regla dura 19).
  //   422  RN-18: SI lo proceso, no se puede reconciliar, queda como
  //        `rejected_out_of_order` marcado para revision humana, y el quiosco
  //        tiene que SACARLO de la cola. Es registro, no perdida.
  //   resto de 4xx  el unico que saca un fichaje de la cola sin dejar rastro.
  //        Ese es el que cuenta como rechazo.
  const batchRetryable = countersWhere('batch_outcomes', (tags) => /^http_5\d\d$/.test(String(tags.action)))
  const batchUnreconcilable = countersWhere('batch_outcomes', (tags) => String(tags.action) === 'http_422')
  const batchRejected = countersWhere(
    'batch_outcomes',
    (tags) => /^http_4\d\d$/.test(String(tags.action)) && String(tags.action) !== 'http_422',
  )

  // --- RN-18: el elemento imposible se registra, no se reintenta -------------

  const impossibleOf = (outcome) => countersWhere('batch_impossible', (tags) => tags.outcome === outcome)
  const impossible = {
    unreconcilable: impossibleOf('unreconcilable'),
    retried: impossibleOf('retried'),
    accepted: impossibleOf('accepted'),
    inconclusive: impossibleOf('inconclusive'),
    not_set_up: impossibleOf('not_set_up'),
  }
  const impossibleJudged = impossible.unreconcilable + impossible.retried + impossible.accepted

  verdict(
    'RN-18',
    impossibleJudged === 0
      ? 'unmeasurable'
      : impossible.retried === 0 && impossible.accepted === 0
        ? 'pass'
        : 'fail',
    impossibleJudged === 0
      ? `ningun lote llego a montar el caso imposible (${impossible.not_set_up} sin tramo previo, ` +
        `${impossible.inconclusive} sin desenlace concluyente): RN-18 no se ha ejercitado`
      : `${impossible.unreconcilable} elementos imposibles registrados como irreconciliables (422), ` +
        `${impossible.retried} devueltos a la cola con 5xx y ${impossible.accepted} aceptados. ` +
        'Un 5xx aqui es el bucle de reintentos infinito que RN-18 vino a eliminar.',
  )
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

  // TAMBIEN EN MODO UMBRAL: el aviso de tendencia no puede calcular una razon
  // entre dos cargas distintas. Antes lo hacia, y una pasada local de diez
  // instancias «empeoraba un 400 %» frente a la linea base de tres del runner.
  if (baselineNotComparable) {
    baselineReport = {
      comparable: false,
      differences: mismatchText,
      note: `NO COMPARABLE (${mismatchText}): no se compara con la linea base`,
      regression: null,
    }
  } else if (baseline !== null) {
    const before = baseline?.scenarios?.scan?.p95 ?? null
    const now = scenarios.scan?.p95 ?? null

    if (before === null || now === null) {
      baselineReport = {
        comparable: true,
        note: 'la linea base o esta pasada no traen el p95 de scan',
        regression: null,
      }
    } else {
      // EL MISMO CRITERIO QUE RNF-P-02 EN MODO `baseline` (decision de
      // 15-10-2026): si las dos pasadas miden la velocidad de su maquina, la
      // tendencia se lee en esa unidad, y la guarda gruesa en milisegundos
      // sigue avisando. Sin velocidad en alguna de las dos, en milisegundos,
      // y lo dice.
      const normalised = machineSpeedMs !== null && baselineSpeedMs !== null && baselineSpeedMs > 0
      const ratio = normalised ? now / machineSpeedMs / (before / baselineSpeedMs) : now / before
      const gross = now / before
      const regression = ratio > BASELINE_REGRESSION_RATIO || gross > BASELINE_GROSS_RATIO

      baselineReport = {
        comparable: true,
        note:
          `p95 de scan ${now} ms frente a ${before} ms de la linea base; ` +
          (normalised
            ? `en unidades de velocidad de la maquina (${round(machineSpeedMs)} ms frente a ` +
              `${round(baselineSpeedMs)} ms) ${((ratio - 1) * 100).toFixed(1)} %`
            : `${((ratio - 1) * 100).toFixed(1)} % en milisegundos (sin velocidad de la maquina que comparar)`) +
          (regression
            ? ` — AVISO: empeora mas del ${((BASELINE_REGRESSION_RATIO - 1) * 100).toFixed(0)} %` +
              (gross > BASELINE_GROSS_RATIO ? ` o pasa de ${BASELINE_GROSS_RATIO}x en milisegundos` : '')
            : ''),
        regression,
        normalised,
        ratio: Math.round(ratio * 1000) / 1000,
      }
    }
  }

  // --- El resumen ------------------------------------------------------------

  return {
    generated_at: options.now ?? new Date().toISOString(),
    // `git_sha` es la APLICACION medida; `harness_sha`, el commit de
    // `load-tests/` con el que se midio. Coinciden salvo cuando el workflow mide
    // otra version con el guion de hoy (`app_ref`), que es justo lo que hace
    // falta para comparar dos versiones con la misma carga.
    git_sha: options.gitSha ?? null,
    harness_sha: options.harnessSha ?? options.gitSha ?? null,
    runner: options.runner ?? null,
    k6_version: options.k6Version ?? null,
    k6_image: options.k6Image ?? null,
    instances: instanceCount,
    instance_ids: results.instances,
    duration_seconds: durationSeconds,
    scan_rate: scanRate,
    offered_scan_rate: offeredRate,
    // La forma de la carga: subida y bajada del cambio de turno (K6-2) y un
    // fichaje por PIN cada tantos segundos y por instancia (K6-3). Las dos
    // entran en la comparacion con la linea base.
    ramp_seconds: rampSeconds,
    pin_period_seconds: pinPeriodSeconds,
    // `threshold` (el de serie) juzga RNF-P-02 y RNF-P-06 contra el requisito;
    // `baseline`, contra la pasada anterior de la MISMA maquina (decision 19).
    latency_verdict_mode: mode,
    // La velocidad de la maquina frente a la de la linea base y si la pasada
    // cuenta como hecha en un runner degradado (V21-5-QA-01, `DEGRADED_MACHINE_RATIO`).
    machine: {
      speed_ms: round(machineSpeedMs),
      baseline_speed_ms: round(baselineSpeedMs),
      slowdown: machineSlowdown === null ? null : Math.round(machineSlowdown * 100) / 100,
      max_slowdown: DEGRADED_MACHINE_RATIO,
      degraded: machineDegraded,
    },
    rejection_floor_ms: rejectionFloorMs,
    debounce_seconds: options.debounceSeconds ?? null,
    scenarios,
    reject_classes: Object.fromEntries(
      REJECT_CLASSES.map((rejectClass) => {
        const { raw, ...published } = rejectStats[rejectClass]

        return [rejectClass, published]
      }),
    ),
    // La cifra que A-13 (doc 07 §6) prometia y no tenia, publicada aparte de
    // `reject_classes` porque no es una clase mas: `verdicts['RS-03-RN-18']`
    // lleva su lectura y vale siempre `info`.
    reject_out_of_order: outOfOrder,
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
      batch_unreconcilable: batchUnreconcilable,
      batch_rejected: batchRejected,
      batch_impossible: impossible,
      // Fuera del pico de RNF-P-06 (ver `RAMP_PHASE` y `PIN_PHASE`), pero dentro
      // del cuadre de `verify-after-load.php`: son tramos que existen.
      shift_producing_ramp: rampShifts,
      shift_producing_pin: pinShifts,
      pin_debounced: pinDebounced,
      pin_resend_identical: pinResendYes,
      pin_resend_divergent: pinResendNo,
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
      // La velocidad de la maquina de esta pasada y el p95 en esa unidad, lo
      // que juzga RNF-P-02 en modo `baseline` (decision de 15-10-2026).
      machine_speed_ms: round(machineSpeedMs),
      p95_over_reject_p50:
        machineSpeedMs === null || machineSpeedMs <= 0 || write.p95 === null
          ? null
          : Math.round((write.p95 / machineSpeedMs) * 100) / 100,
      per_instance: perInstance.map((entry) => ({
        instance: entry.instance,
        samples: entry.stats.samples,
        p95: round(entry.stats.p95),
        p99: round(entry.stats.p99),
      })),
    },
    // El fichaje por PIN (RF-AT-11): solo las respuestas atendidas del envio
    // original; los reenvios no pagan bcrypt (PIN-02) y no dicen nada del coste.
    pin_path: {
      sent: pinOriginals.length,
      samples: pinAttended.samples,
      refusals: pinRefusals.length,
      queueable: pinQueueable.length,
      min: round(pinAttended.min),
      p50: round(pinAttended.p50),
      p95: round(pinAttended.p95),
      p99: round(pinAttended.p99),
      max: round(pinAttended.max),
      // El p95 del PIN en unidades de velocidad de la maquina: lo que juzga
      // RNF-P-08 en modo `baseline` y lo que `baseline.js` hace mediana.
      p95_over_reject_p50:
        machineSpeedMs === null || machineSpeedMs <= 0 || pinAttended.p95 === null
          ? null
          : Math.round((pinAttended.p95 / machineSpeedMs) * 100) / 100,
    },
    // La subida y la bajada del cambio de turno (K6-2), fuera de la meseta.
    ramp_path: {
      seconds: rampSeconds,
      samples: rampPath.samples,
      shift_producing: rampShifts,
      p50: round(rampPath.p50),
      p95: round(rampPath.p95),
      p99: round(rampPath.p99),
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
 *
 * **`info` no es un cuarto desenlace de esta funcion, y es a proposito.** Un
 * veredicto informativo —hoy solo `RS-03-RN-18`— publica una cifra que una
 * decision aceptada con argumento pidio medir; convertirla en codigo de salida
 * seria crear el presupuesto que esa decision dice que no hay. Se ve en el
 * informe y en `summary.json`, y no en el `exit`.
 *
 * **`not_comparable` sale con 2, como `unmeasurable`**: la pasada no ha podido
 * decir si hay regresion, y un 0 ahi seria un verde que no se ha ganado. Lo que
 * cambia es el texto, que dice POR QUE (R5-QA-02): no es que falten muestras,
 * es que la linea base se tomo con otra carga.
 *
 * **`unreliable` sale con 2 tambien** (V21-5-QA-01): el runner iba tan lento
 * que un rojo de lo que depende de la maquina no distingue una regresion de un
 * runner degradado. Ni verde ni rojo: repite la pasada.
 */
function exitCodeOf(summary) {
  const statuses = Object.values(summary.verdicts).map((entry) => entry.status)

  if (statuses.includes('fail')) {
    return 1
  }

  return ['unmeasurable', 'not_comparable', 'unreliable'].some((status) => statuses.includes(status)) ? 2 : 0
}

// --- Informe legible ---------------------------------------------------------

function formatReport(summary) {
  const lines = []
  const say = (line) => lines.push(line)
  const fmt = (value) => (value === null || value === undefined ? '     n/d' : value.toFixed(1).padStart(8))
  const label = {
    pass: 'OK   ',
    fail: 'FALLA',
    unmeasurable: 'S/MED',
    not_comparable: 'NOCMP',
    unreliable: 'NOFIA',
    info: 'INFO ',
  }

  say(
    `Instancias: ${summary.instance_ids.join(', ')}   duracion: ${summary.duration_seconds} s   ` +
      `ofrecidos: ${summary.offered_scan_rate} fichajes/s   rampa: ${summary.ramp_seconds ?? 'n/d'} s   ` +
      `PIN: uno cada ${summary.pin_period_seconds ?? 'n/d'} s por instancia`,
  )
  say(
    `generador ${summary.k6_version ?? 'n/d'}   commit ${summary.git_sha ?? 'n/d'}` +
      (summary.harness_sha !== null && summary.harness_sha !== summary.git_sha
        ? `   guion ${summary.harness_sha}`
        : '') +
      `   runner ${summary.runner ?? 'n/d'}`,
  )
  say(
    summary.latency_verdict_mode === 'baseline'
      ? 'RNF-P-02, RNF-P-06 y RNF-P-08 se juzgan contra la LINEA BASE de esta maquina, no contra el umbral (decision 19).'
      : 'RNF-P-02, RNF-P-06 y RNF-P-08 se juzgan contra el UMBRAL del requisito.',
  )
  say('')
  say('Latencia de las respuestas CONTESTADAS por el servidor, por escenario (ms):')

  for (const [scenario, stats] of Object.entries(summary.scenarios)) {
    say(
      `  ${scenario.padEnd(19)} n=${String(stats.answered_samples).padStart(6)}/${String(stats.samples).padStart(6)}  ` +
        `p50 ${fmt(stats.p50)}  p95 ${fmt(stats.p95)}  p99 ${fmt(stats.p99)}  max ${fmt(stats.max)}` +
        (stats.requirements.length > 0 ? `   [${stats.requirements.join(' ')}]` : ''),
    )
  }

  say('')
  say('Respuestas por escenario y codigo:')

  for (const [scenario, stats] of Object.entries(summary.scenarios)) {
    say(
      `  ${scenario.padEnd(19)} ${Object.entries(stats.statuses)
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

  for (const requirement of ['CHECKS', 'SERVICIO', 'RNF-P-02', 'RNF-P-06', 'RQ-03', 'RF-AT-11', 'RNF-P-08', 'RN-18']) {
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

  // El cuarto rechazo va en la MISMA tabla y en una fila aparte: es la unica
  // forma de ver de un vistazo lo que A-13 dice —que RN-18 tarda mas— sin
  // sugerir que sea una clase de credencial comparable con las otras tres.
  const outOfOrder = summary.reject_out_of_order

  say(
    `  ${'RN-18'.padEnd(11)} n=${String(outOfOrder.samples).padStart(5)}/` +
      `${String(outOfOrder.offered).padStart(5)}  min ${fmt(outOfOrder.min)}  p50 ${fmt(outOfOrder.p50)}  ` +
      `p95 ${fmt(outOfOrder.p95)}  max ${fmt(outOfOrder.max)}` +
      (outOfOrder.reconciled > 0 ? `   (+${outOfOrder.reconciled} que si cuadraron)` : ''),
  )

  const rejectVerdict = summary.verdicts['RS-03']
  const outOfOrderVerdict = summary.verdicts['RS-03-RN-18']

  say(`${label[rejectVerdict.status]} RS-03: ${rejectVerdict.detail}`)
  say(`${label[outOfOrderVerdict.status]} RS-03-RN-18: ${outOfOrderVerdict.detail}`)
  say('')
  say(
    `INFO RF-KI-04: ${summary.totals.batch_accepted} elementos con tramo, ` +
      `${summary.totals.batch_debounced} anti-rebote, ${summary.totals.batch_unreconcilable} ` +
      `irreconciliables registrados (422, RN-18), ${summary.totals.batch_retryable} conservados ` +
      `en la cola (5xx) y ${summary.totals.batch_rejected} rechazados (4xx); ` +
      `${summary.totals.batch_entries_per_second}/s, aparte del pico en tiempo real`,
  )
  say(
    `INFO RF-PA-06: vista de cumplimiento p95 ${summary.totals.compliance_p95 ?? 'n/d'} ms ` +
      `(orientativo ${COMPLIANCE_BUDGET_MS} ms, ningun requisito lo fija)`,
  )

  say(
    `INFO rampa del cambio de turno: ${summary.ramp_path.samples} fichajes atendidos en la subida y la ` +
      `bajada (${summary.ramp_path.shift_producing} con tramo), p95 ${summary.ramp_path.p95 ?? 'n/d'} ms; ` +
      'fuera de la meseta que juzgan RNF-P-02 y RNF-P-06',
  )

  if (summary.server_metrics !== null) {
    say(`INFO series del servidor (delta de la pasada): ${JSON.stringify(summary.server_metrics)}`)
  }

  if (summary.baseline !== null) {
    say(`INFO linea base: ${summary.baseline.note}`)
  }

  const failed = Object.entries(summary.verdicts).filter(([, entry]) => entry.status === 'fail')
  const unmeasurable = Object.entries(summary.verdicts).filter(([, entry]) => entry.status === 'unmeasurable')
  const notComparable = Object.entries(summary.verdicts).filter(([, entry]) => entry.status === 'not_comparable')
  const unreliable = Object.entries(summary.verdicts).filter(([, entry]) => entry.status === 'unreliable')

  say('')

  if (failed.length > 0) {
    say(`VEREDICTO: rojo en ${failed.map(([requirement]) => requirement).join(', ')}.`)
  } else if (unreliable.length > 0) {
    // Antes que «no comparable»: «no fiable» exige una linea base comparable, y
    // con ella solo queda «no comparable» el requisito que la linea base no
    // trae (RNF-P-08 sin `pin_path.p95_over_reject_p50`). Pueden darse los dos
    // a la vez: el veredicto dice primero que repetir la pasada y despues que
    // rehacer la linea base, sin callar ninguno.
    say(
      `VEREDICTO: no fiable. El runner iba ${summary.machine.slowdown} veces mas lento que en la linea base ` +
        `(maximo ${summary.machine.max_slowdown}x): ${unreliable.map(([requirement]) => requirement).join(', ')} ` +
        'no se dan ni por rojos ni por verdes. Repite la pasada.' +
        (notComparable.length > 0
          ? ` Ademas, no comparable: ${notComparable.map(([requirement]) => requirement).join(', ')}; la linea ` +
            'base no trae lo necesario para juzgarlos. Rehazla con baseline.js a partir de las pasadas que la ' +
            'construyeron.'
          : '') +
        (unmeasurable.length > 0
          ? ` Ademas, sin datos suficientes para ${unmeasurable.map(([requirement]) => requirement).join(', ')}.`
          : ''),
    )
  } else if (notComparable.length > 0) {
    // Antes que «no fiable»: es la causa mas concreta, y la que dice que hacer.
    say(
      `VEREDICTO: no comparable. ${notComparable.map(([requirement]) => requirement).join(', ')} se ` +
        // Dos causas: otra carga, o una linea base que no trae lo que el
        // requisito juzga (la del PIN anterior a RNF-P-08). No se inventa una
        // diferencia de carga donde no la hay.
        (summary.baseline?.differences
          ? `juzgan contra la linea base y esta se tomo con otra carga (${summary.baseline.differences}). ` +
            'Mide con sus mismos parametros o regenera baseline.json con esta pasada.'
          : 'juzgan contra la linea base y esta no trae lo necesario para juzgarlos. ' +
            'Rehazla con baseline.js a partir de las pasadas que la construyeron.') +
        (unmeasurable.length > 0
          ? ` Ademas, sin datos suficientes para ${unmeasurable.map(([requirement]) => requirement).join(', ')}.`
          : ''),
    )
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
    rampSeconds: named['ramp-seconds'] === undefined ? null : Number(named['ramp-seconds']),
    pinPeriodSeconds: named['pin-period-seconds'] === undefined ? null : Number(named['pin-period-seconds']),
    gitSha: named['git-sha'] ?? null,
    harnessSha: named['harness-sha'] ?? null,
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
