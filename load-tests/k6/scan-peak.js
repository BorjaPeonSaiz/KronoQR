// Prueba de carga del pico del cambio de turno (RNF-P-06: 50 fichajes/s
// sostenidos; RNF-P-02: p95 < 150 ms y p99 < 400 ms en el endpoint de fichaje;
// RQ-08: la prueba que valida RNF-P-06 antes de cada version mayor).
//
// UNA INSTANCIA DE K6 ES UN ORIGEN (una IP) y el pico se compone sumando
// origenes, como en un hotel de verdad. El presupuesto por origen sale del
// CUBO CON FUGA de Nginx, no de una ventana fija, y la diferencia importa:
//
//   `limit_req_zone … rate=600r/m` NO significa «600 en cada minuto». Nginx
//   convierte la tasa a UN PERMISO CADA 100 ms y acumula como mucho `burst=50`
//   permisos sin usar. Dos peticiones separadas por 40 ms gastan ráfaga aunque
//   el minuto entero vaya a quedarse en 300. Con cuatro planificadores
//   independientes —`scan`, `resend`, `reject` y `batch`— los solapamientos son
//   inevitables, asi que el presupuesto no puede rozar el techo.
//
//   Por eso: 6 fichajes/s + 1 r/s de reenvio + 1 r/s de rechazo + un lote por
//   minuto ≈ 8 r/s = 480/min por origen, un 20 % por debajo de los 600 del
//   borde. Con INSTANCES=10 salen 60 fichajes validos/s, por encima de los 50
//   de RNF-P-06.
//
// CINCO ESCENARIOS, Y NINGUNO ES DECORATIVO:
//
//   scan        6/s    fichajes validos. Es la cifra de RNF-P-06.
//   resend      0,5/s  un fichaje y su reenvio identico (regla dura 8, RQ-03).
//   reject      1/s    las tres clases de rechazo, comparadas ENTRE SI (RS-03).
//   batch       1 lote de 50 por minuto: 25 pares entrada/salida del MISMO
//                      empleado, enviados en orden inverso a su `occurred_at`,
//                      con UN elemento imposible de reconciliar (RN-18).
//   compliance  0,5/s  solo en la instancia de panel, con token de gestion.
//
// NADIE REPITE TARJETA DENTRO DE LA VENTANA DEL ANTI-REBOTE. El reparto de
// tarjetas —cuantas para cada escenario y cuantas por instancia— lo calcula
// `run.sh` y viaja en el fichero de fixtures: aqui se LEE, no se recalcula. Un
// reparto calculado en dos sitios se desincroniza una vez y a partir de ahi dos
// VU fichan con la misma tarjeta sin que nadie se entere. El indice es el GLOBAL
// DEL ESCENARIO (`exec.scenario.iterationInTest`) y nunca `__ITER`, que es por
// VU.
//
// EL GENERADOR NO COMPITE CON EL SISTEMA BAJO PRUEBA. Todo lo que se puede
// resolver una vez —rebanadas, tokens, cabeceras— se resuelve en el ambito de
// modulo, fuera del bucle de carga: en el runner de la CI k6 y el servidor
// comparten CPU, y recortar tres arrays por iteracion se paga en la medida.
//
// Los payloads, los tokens de dispositivo y el token de gestion vienen de
// `provision-fixtures.php`, firmados y emitidos por el propio servidor: aqui no
// hay criptografia ni emision de sesion que pueda desviarse de la del backend.

import http from 'k6/http'
import exec from 'k6/execution'
import { check, sleep } from 'k6'
import { Counter } from 'k6/metrics'
import { SharedArray } from 'k6/data'

const fixtures = new SharedArray('fixtures', () => [JSON.parse(open('/fixtures/k6-fixtures.json'))])

const DURATION = __ENV.DURATION || '120s'
const INSTANCE = Number(__ENV.INSTANCE || 0)
const INSTANCES = Number(__ENV.INSTANCES || 1)
const ROLE = __ENV.ROLE || 'kiosk'
const BASE = __ENV.BASE_URL || 'https://nginx:8443'

/** Fichajes validos por segundo y por instancia (ver el cubo con fuga, arriba). */
const SCAN_RATE = Number(__ENV.SCAN_RATE || 6)

/** Tamano del lote de sincronizacion (doc 02 §6). */
const BATCH_SIZE = 50

/** Pares entrada/salida por lote: el lote son 50 elementos, 25 personas. */
const BATCH_PAIRS = BATCH_SIZE / 2

/** Los diez minutos de cola que arrastra un quiosco que recupera la red. */
const BATCH_BACKLOG_SECONDS = 600

/** Separacion entre los `occurred_at` de dos personas distintas del lote. */
const BATCH_PAIR_SPACING_SECONDS = 20

/**
 * Los cinco escenarios, con su etiqueta de requisito.
 *
 * `tags.requirements` es ETIQUETA NATIVA DE K6: viaja en cada muestra del CSV
 * —de ahi que el agregado pueda dar el veredicto por requisito— y es ademas el
 * tercer formato de la matriz de trazabilidad (doc 02 §9.6). La clave del
 * objeto es el nombre con el que sale en la matriz.
 */
const SCENARIOS = {
  scan: {
    executor: 'constant-arrival-rate',
    exec: 'validScan',
    rate: SCAN_RATE,
    timeUnit: '1s',
    duration: DURATION,
    preAllocatedVUs: 20,
    maxVUs: 120,
    tags: { requirements: 'RNF-P-06 RNF-P-02 RQ-08' },
  },
  resend: {
    executor: 'constant-arrival-rate',
    exec: 'idempotentResend',
    rate: 1,
    timeUnit: '2s',
    duration: DURATION,
    preAllocatedVUs: 10,
    maxVUs: 40,
    tags: { requirements: 'RQ-03 RF-AT-07' },
  },
  reject: {
    executor: 'constant-arrival-rate',
    exec: 'genericRejection',
    rate: 1,
    timeUnit: '1s',
    duration: DURATION,
    preAllocatedVUs: 5,
    maxVUs: 30,
    tags: { requirements: 'RS-03' },
  },
  batch: {
    executor: 'constant-arrival-rate',
    exec: 'offlineBatch',
    rate: 1,
    timeUnit: '60s',
    duration: DURATION,
    preAllocatedVUs: 2,
    maxVUs: 8,
    tags: { requirements: 'RF-KI-04 RN-18' },
  },
  compliance: {
    executor: 'constant-arrival-rate',
    exec: 'complianceSummary',
    rate: 1,
    timeUnit: '2s',
    duration: DURATION,
    preAllocatedVUs: 5,
    maxVUs: 20,
    tags: { requirements: 'RF-PA-06' },
  },
}

/**
 * Que escenarios corre cada instancia.
 *
 * k6 no admite escenarios condicionales dentro de `options`, asi que el reparto
 * se hace en JS ANTES de exportar. El panel va en su propia instancia —otra IP,
 * zona `api` del borde— porque lo que interesa medir no es su p95 sino que el
 * fichaje sigue dentro de RNF-P-02 con esa lectura en curso: cada consulta deja
 * asiento bajo el candado de la cadena de auditoria (ADR-010, ADR-027).
 */
const SCENARIOS_BY_ROLE = {
  kiosk: ['scan', 'resend', 'reject', 'batch'],
  panel: ['compliance'],
}

function scenariosFor(role) {
  const names = SCENARIOS_BY_ROLE[role]

  if (names === undefined) {
    throw new Error(`ROLE desconocido: ${role}. Valores: kiosk, panel.`)
  }

  const selected = {}

  for (const name of names) {
    selected[name] = SCENARIOS[name]
  }

  return selected
}

export const options = {
  // Contra la pila de desarrollo y contra el paquete recien instalado el
  // certificado es autofirmado y no hay nada que validar; contra un entorno de
  // pruebas con certificado real, `K6_INSECURE_TLS` se deja sin poner y k6
  // valida la cadena como cualquier cliente.
  insecureSkipTLSVerify: __ENV.K6_INSECURE_TLS === '1',
  scenarios: scenariosFor(ROLE),
  thresholds: {
    // INFORMATIVOS Y POR INSTANCIA: el veredicto de RNF-P-02 lo da el agregado
    // sobre las muestras de todas. Aqui sirven para ver de un vistazo que
    // instancia se salio, que es lo que se mira cuando una se sale y las demas
    // no.
    'http_req_duration{scenario:scan}': ['p(95)<150', 'p(99)<400'],
    checks: ['rate>0.99'],
  },
}

/** Respuestas del servidor que producen o cierran un tramo (RF-AT-02/03/12). */
const shiftOutcomes = new Counter('scan_outcomes')

/** Reenvios comparados con su original: `match=yes`, `no` o `skipped` (RQ-03). */
const resendMatches = new Counter('resend_matches')

/** Elementos de lote, por desenlace (RF-KI-04). */
const batchOutcomes = new Counter('batch_outcomes')

/** Que le paso al elemento imposible que cada lote lleva a proposito (RN-18). */
const batchImpossible = new Counter('batch_impossible')

// --- Todo lo que se resuelve UNA vez ----------------------------------------

const DATA = fixtures[0]

/** El reparto de tarjetas, tal y como lo calculo `run.sh`. */
const GEOMETRY = DATA.geometry

/** La ventana del anti-rebote de ESTA instalacion (RF-AT-06), no un literal. */
const DEBOUNCE_SECONDS = Number(DATA.debounce_seconds || 60)

/**
 * La separacion entre la entrada y la salida de un par de lote.
 *
 * TIENE QUE SUPERAR LA VENTANA DEL ANTI-REBOTE. Con una separacion menor, la
 * salida cae dentro del periodo de gracia de su propia entrada y el servidor
 * responde `debounced` —correctamente—, de modo que el par nunca cerraria el
 * tramo y la comprobacion del orden por `occurred_at` mediria otra cosa.
 */
const BATCH_PAIR_GAP_SECONDS = DEBOUNCE_SECONDS + 30

/**
 * La rebanada de tarjetas de esta instancia, partida en tres tramos disjuntos.
 */
const CARDS = (() => {
  if (GEOMETRY === undefined) {
    throw new Error('Los fixtures no traen `geometry`: regenera con provision-fixtures.php.')
  }

  const perInstance = GEOMETRY.cards_per_instance
  const needed = INSTANCES * perInstance

  if (DATA.payloads.length < needed) {
    throw new Error(
      `Faltan credenciales: hay ${DATA.payloads.length} y ${INSTANCES} instancias ` +
        `de ${perInstance} tarjetas necesitan ${needed}.`,
    )
  }

  const base = INSTANCE * perInstance
  const scanEnd = base + GEOMETRY.scan_cards
  const resendEnd = scanEnd + GEOMETRY.resend_cards

  return {
    scan: DATA.payloads.slice(base, scanEnd),
    resend: DATA.payloads.slice(scanEnd, resendEnd),
    batch: DATA.payloads.slice(resendEnd, base + perInstance),
  }
})()

/** Los tokens de quiosco de esta instancia, ya recortados. */
const DEVICE_TOKENS = (() => {
  const all = DATA.device_tokens
  const size = Math.max(1, Math.floor(all.length / INSTANCES))
  const offset = (INSTANCE * size) % all.length

  return Array.from({ length: size }, (unused, index) => all[(offset + index) % all.length])
})()

/** Origen de la ventana de lotes, distinto en cada minuto (ver `offlineBatch`). */
const BATCH_START = Math.floor(Date.now() / 60_000) * BATCH_PAIRS

const UNKNOWN_PAYLOADS = DATA.unknown_payloads
const REVOKED_PAYLOADS = DATA.revoked_payloads
const MANAGEMENT_HEADERS = {
  Accept: 'application/json',
  Authorization: `Bearer ${DATA.management_token}`,
}

/**
 * UUID v7: 48 bits de milisegundos, version y aleatorio, como exige
 * `ScanRequest.scan_id` (regla dura 8: el `scan_id` lo genera el cliente).
 */
function uuidv7() {
  const ms = Date.now()
  const bytes = new Uint8Array(16)
  crypto.getRandomValues(bytes)
  bytes[0] = (ms / 0x10000000000) & 0xff
  bytes[1] = (ms / 0x100000000) & 0xff
  bytes[2] = (ms / 0x1000000) & 0xff
  bytes[3] = (ms / 0x10000) & 0xff
  bytes[4] = (ms / 0x100) & 0xff
  bytes[5] = ms & 0xff
  bytes[6] = (bytes[6] & 0x0f) | 0x70
  bytes[8] = (bytes[8] & 0x3f) | 0x80
  const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')

  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}

/** `2026-09-17T06:00:00Z`, que es lo que acepta `UtcTimestamp`. */
function utc(milliseconds) {
  return new Date(milliseconds).toISOString().replace(/\.\d{3}Z$/, 'Z')
}

function deviceToken(iteration) {
  return DEVICE_TOKENS[iteration % DEVICE_TOKENS.length]
}

function kioskHeaders(token, idempotencyKey) {
  return {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    Authorization: `Bearer ${token}`,
    'Idempotency-Key': idempotencyKey,
  }
}

function scanBody(scanId, payload, occurredAt) {
  return JSON.stringify({
    scan_id: scanId,
    occurred_at: occurredAt,
    qr_payload: payload,
    intent: 'auto',
  })
}

/** El desenlace que el servidor declara, o el codigo de estado si no hubo cuerpo. */
function outcomeOf(response) {
  if (response.status === 429) {
    return 'throttled'
  }

  if (response.status !== 200) {
    return `http_${response.status}`
  }

  const body = response.json()

  return body === null || body.action === undefined ? 'sin_action' : String(body.action)
}

/** Una respuesta que el servidor no llego a dar: el quiosco encola (regla dura 19). */
function unanswered(response) {
  return response.status === 429 || response.status === 0 || response.status >= 500
}

// --- scan: el pico de fichajes validos (RNF-P-06, RNF-P-02, RQ-08) -----------

export function validScan() {
  const iteration = exec.scenario.iterationInTest
  const scanId = uuidv7()

  const response = http.post(
    `${BASE}/api/v1/scan`,
    scanBody(scanId, CARDS.scan[iteration % CARDS.scan.length], utc(Date.now())),
    { headers: kioskHeaders(deviceToken(iteration), scanId), tags: { phase: 'scan' } },
  )

  shiftOutcomes.add(1, { phase: 'scan', action: outcomeOf(response) })

  check(response, {
    'el fichaje se registra o el borde encola (regla dura 19)': (r) =>
      r.status === 200 || unanswered(r),
  })
}

// --- resend: idempotencia bajo carga (regla dura 8, RQ-03, RF-AT-07) ---------

export function idempotentResend() {
  const iteration = exec.scenario.iterationInTest
  const scanId = uuidv7()
  const body = scanBody(scanId, CARDS.resend[iteration % CARDS.resend.length], utc(Date.now()))
  const headers = kioskHeaders(deviceToken(iteration), scanId)

  const original = http.post(`${BASE}/api/v1/scan`, body, {
    headers,
    tags: { phase: 'resend_original' },
  })

  shiftOutcomes.add(1, { phase: 'resend_original', action: outcomeOf(original) })

  // Dos segundos fijos, que es lo que tarda la cola offline en reintentar un
  // envio que creyo perdido. FIJO Y NO ALEATORIO: con un retardo variable, dos
  // pasadas ofrecen un perfil de llegada distinto al borde y la comparacion
  // entre ellas deja de ser limpia. No es sincronizacion entre procesos: es el
  // modelo de carga, y por eso el retardo esta aqui y no en `run.sh`.
  sleep(2)

  // MISMO cuerpo, MISMO `scan_id` y MISMA `Idempotency-Key`: un reenvio que
  // cambiara algo no probaria la idempotencia, probaria otra cosa.
  const replay = http.post(`${BASE}/api/v1/scan`, body, {
    headers,
    tags: { phase: 'resend_replay' },
  })

  shiftOutcomes.add(1, { phase: 'resend_replay', action: outcomeOf(replay) })

  // RQ-03 solo se puede juzgar cuando el SERVIDOR contesto a los dos envios. Si
  // el borde freno el original no hay respuesta que repetir; si freno el reenvio
  // —o el servidor devolvio un 5xx— el caso de uso ni se ejecuto, y comparar
  // cuerpos ahi mediria el limitador de tasa, no la idempotencia. Los dos casos
  // se cuentan aparte en lugar de darlos por buenos o por malos.
  if (original.status !== 200 || unanswered(replay)) {
    resendMatches.add(1, { match: 'skipped' })

    return
  }

  const identical = replay.status === original.status && replay.body === original.body

  resendMatches.add(1, { match: identical ? 'yes' : 'no' })

  check(replay, {
    'el reenvio devuelve el mismo codigo y el mismo cuerpo (regla dura 8)': () => identical,
  })
}

// --- reject: los tres rechazos, indistinguibles entre si (RS-03) -------------

/**
 * Las tres clases, a partes iguales y en el mismo orden en todas las
 * instancias. Se comparan ENTRE SI y nunca contra un acierto: `ConstantTimeFloor`
 * iguala rechazos entre si a proposito, y un fichaje aceptado hace mas trabajo
 * —escribe tramo, proyeccion y asiento—, asi que exigirle el mismo tiempo seria
 * exigir un suelo que se notaria en el cambio de turno.
 */
const REJECT_CLASSES = ['signature', 'unknown', 'revoked']

function rejectPayload(rejectClass, iteration) {
  if (rejectClass === 'revoked') {
    // Tarjetas de empleados RESERVADOS por el aprovisionamiento: no estan en
    // ninguna rebanada, asi que nadie ficha con ellos ni entra en el cuadre.
    return REVOKED_PAYLOADS[iteration % REVOKED_PAYLOADS.length]
  }

  const payload = UNKNOWN_PAYLOADS[iteration % UNKNOWN_PAYLOADS.length]

  // Firma alterada: el ultimo caracter del payload, que es el final de la firma.
  return rejectClass === 'signature'
    ? payload.slice(0, -1) + (payload.slice(-1) === 'A' ? 'B' : 'A')
    : payload
}

export function genericRejection() {
  const iteration = exec.scenario.iterationInTest
  const rejectClass = REJECT_CLASSES[iteration % REJECT_CLASSES.length]
  const scanId = uuidv7()

  const response = http.post(
    `${BASE}/api/v1/scan`,
    scanBody(scanId, rejectPayload(rejectClass, iteration), utc(Date.now())),
    {
      headers: kioskHeaders(deviceToken(iteration), scanId),
      tags: { phase: 'reject', reject_class: rejectClass },
    },
  )

  const body = response.status === 422 ? response.json() : null

  check(response, {
    'el rechazo es 422 problem+json': (r) => r.status === 422 || unanswered(r),
    'el cuerpo es el generico, con el scan_id como unica variacion': (r) =>
      r.status !== 422 ||
      (body !== null &&
        body.type === 'urn:kronoqr:problem:scan-rejected' &&
        body.status === 422 &&
        body.scan_id === scanId &&
        Object.keys(body).sort().join(',') === 'detail,scan_id,status,title,type'),
  })
}

// --- batch: el quiosco que recupera la red (RF-KI-04) ------------------------

export function offlineBatch() {
  const iteration = exec.scenario.iterationInTest
  const now = Date.now()

  // Ventana deslizante sobre un contador GLOBAL del escenario, y no `iteration
  // % 2`: con una duracion larga, dos mitades fijas volverian a la misma tarjeta
  // cada dos minutos y el lote chocaria con su propio anti-rebote. `run.sh`
  // dimensiona `batch_cards` para que la ventana no de la vuelta dentro de la
  // pasada.
  //
  // El origen depende del MINUTO en que arranca la pasada para que dos pasadas
  // seguidas no empiecen por la misma tarjeta: los `occurred_at` de un lote son
  // de los diez minutos anteriores, asi que repetir tarjeta entre pasadas caeria
  // dentro del anti-rebote y el par saldria `debounced` en lugar de abrir y
  // cerrar tramo.
  const start = (BATCH_START + iteration * BATCH_PAIRS) % CARDS.batch.length

  // --- El elemento IMPOSIBLE del lote (RN-18) --------------------------------
  //
  // Un elemento que no se puede reconciliar —una salida cuyo `occurred_at` es
  // ANTERIOR a la entrada del turno que tendria que cerrar— ya no responde
  // `503`, que significaba «no lo he procesado, conservalo en la cola»: responde
  // `422` con el cuerpo generico y deja fila `rejected_out_of_order` marcada
  // para revision. La diferencia no es cosmetica. Con el `503`, la cola de un
  // quiosco se quedaba reintentando **para siempre** un elemento que jamas
  // podria cuadrar, y ese bucle es el origen de RN-18.
  //
  // COMO SE FABRICA, Y POR QUE HACE FALTA UNA PETICION PREVIA. Dentro de un
  // mismo lote no se puede: el servidor lo ordena por `occurred_at`, asi que un
  // cierre nunca llega antes que la entrada que le toca. La contradiccion tiene
  // que venir de FUERA del lote, que es exactamente lo que pasa en la vida real
  // —alguien ficha en el quiosco de al lado mientras este drena una cola vieja—.
  // Por eso se abre antes un tramo con un escaneo normal, fechado ENTRE los dos
  // elementos del par sacrificado:
  //
  //   impossibleAt ......... cierre imposible (anterior a la entrada del tramo)
  //   seedAt = +D+10 s ..... el escaneo que abre el tramo
  //   validCloseAt = +2D+20  el cierre que si cuadra
  //
  // Las separaciones superan la ventana del anti-rebote (D) en los dos saltos:
  // con menos, el segundo elemento saldria `debounced` y no cerraria nada.
  const impossibleAt = now - BATCH_BACKLOG_SECONDS * 1000
  const seedAt = impossibleAt + (DEBOUNCE_SECONDS + 10) * 1000
  const validCloseAt = impossibleAt + (2 * DEBOUNCE_SECONDS + 20) * 1000
  const sacrificialPayload = CARDS.batch[start % CARDS.batch.length]
  const seedScanId = uuidv7()

  const seed = http.post(
    `${BASE}/api/v1/scan`,
    scanBody(seedScanId, sacrificialPayload, utc(seedAt)),
    { headers: kioskHeaders(deviceToken(iteration), seedScanId), tags: { phase: 'batch_seed' } },
  )

  // Si el borde freno el escaneo previo, o si no abrio tramo, el elemento de
  // abajo deja de ser imposible: no hay nada que contradecir. El desenlace se
  // cuenta aparte y no se juzga, en vez de darlo por bueno o por malo.
  const seedOpened = seed.status === 200 && ['clock_in', 'break_start'].includes(outcomeOf(seed))
  const scans = []
  let impossibleScanId = null

  for (let pair = 0; pair < BATCH_PAIRS; pair++) {
    const payload = CARDS.batch[(start + pair) % CARDS.batch.length]
    const sacrificed = pair === 0
    const clockInAt = sacrificed
      ? impossibleAt
      : now - (BATCH_BACKLOG_SECONDS - pair * BATCH_PAIR_SPACING_SECONDS) * 1000
    const clockOutAt = sacrificed ? validCloseAt : clockInAt + BATCH_PAIR_GAP_SECONDS * 1000
    const firstScanId = uuidv7()

    if (sacrificed) {
      impossibleScanId = firstScanId
    }

    scans.push(
      { scan_id: firstScanId, occurred_at: utc(clockInAt), qr_payload: payload, intent: 'auto', at: clockInAt },
      { scan_id: uuidv7(), occurred_at: utc(clockOutAt), qr_payload: payload, intent: 'auto', at: clockOutAt },
    )
  }

  // ORDEN INVERSO AL DE `occurred_at`, y determinista. Es lo unico que prueba
  // RF-KI-04 de verdad: la SALIDA de cada persona llega antes que su ENTRADA, y
  // si el servidor procesara en orden de llegada intentaria cerrar un tramo que
  // no existe. `verify-after-load.php` comprueba despues que esos empleados
  // acabaron con el tramo CERRADO, que es la firma de que ordeno por
  // `occurred_at`.
  scans.sort((a, b) => b.at - a.at)

  const response = http.post(
    `${BASE}/api/v1/scan/batch`,
    JSON.stringify({ scans: scans.map(({ at, ...scan }) => scan) }),
    { headers: kioskHeaders(deviceToken(iteration), uuidv7()), tags: { phase: 'batch' } },
  )

  const results = response.status === 207 ? (response.json().results ?? []) : []

  for (const result of results) {
    const action = result.status === 200 ? String(result.outcome.action) : `http_${result.status}`

    batchOutcomes.add(1, { action })

    // Al pico de RNF-P-06 solo van las respuestas que producen tramo; un
    // irreconciliable no lo produce y ya se cuenta por su lado.
    shiftOutcomes.add(1, { phase: 'batch', action })

    if (result.scan_id === impossibleScanId) {
      batchImpossible.add(1, { outcome: outcomeOfImpossible(result, seedOpened) })
    }
  }

  check(response, {
    'el lote responde 207 con un resultado por elemento': (r) =>
      unanswered(r) || (r.status === 207 && results.length === BATCH_SIZE),
    // TRES DESENLACES Y NO DOS. El `503` significa «no lo he procesado,
    // conservalo en la cola» y es la degradacion que pide la regla dura 19. El
    // `422` de RN-18 significa lo contrario: SI lo he procesado, queda
    // registrado como irreconciliable y marcado para revision, y el quiosco
    // tiene que SACARLO de la cola. Solo el resto de los `4xx` es un fichaje que
    // se pierde sin dejar rastro, y solo eso falla aqui.
    'ningun elemento del lote se pierde sin registrar': () =>
      results.every((result) => result.status < 400 || result.status >= 500 || result.status === 422),
  })
}

/**
 * Que le paso al elemento imposible.
 *
 * `retried` es el desenlace que RN-18 vino a eliminar: un `503` devuelve el
 * elemento a la cola de un quiosco que lo reintentara indefinidamente, porque
 * jamas va a poder cuadrar.
 */
function outcomeOfImpossible(result, seedOpened) {
  // EL `422` SE CUENTA SIEMPRE, mire lo que mire el escaneo previo: es la prueba
  // de que el caso existio —el servidor no responde `rejected_out_of_order` a un
  // elemento que se pueda reconciliar—. La comprobacion de que el guion monto el
  // caso solo hace falta para los OTROS desenlaces, donde un tramo que no se
  // abrio los explicaria sin que el producto tenga culpa. (La tarjeta puede
  // llegar con un tramo abierto de una pasada anterior, y entonces el escaneo
  // previo cierra en vez de abrir.)
  if (result.status === 422) {
    return 'unreconcilable'
  }

  if (!seedOpened) {
    return 'not_set_up'
  }

  if (result.status >= 500) {
    return 'retried'
  }

  // Un `debounced` significa que las separaciones de arriba no dieron de si en
  // esta instalacion: el guion no monto el caso, y eso no se le imputa al
  // producto.
  return result.status === 200 && String(result.outcome.action) === 'debounced' ? 'inconclusive' : 'accepted'
}

// --- compliance: la vista de cumplimiento en paralelo (RF-PA-06) -------------

export function complianceSummary() {
  const response = http.get(`${BASE}/api/v1/compliance/summary`, {
    headers: MANAGEMENT_HEADERS,
    tags: { phase: 'compliance' },
  })

  check(response, {
    'la vista de cumplimiento responde 200': (r) => r.status === 200 || unanswered(r),
  })
}
