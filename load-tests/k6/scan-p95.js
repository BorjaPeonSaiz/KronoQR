// Prueba de carga del fichaje (RNF-P-02: p95 < 150 ms, p99 < 400 ms;
// RNF-P-06: 50 fichajes/s sostenidos).
//
// UNA instancia de k6 es UNA IP, y el producto limita por IP en el borde y en
// la aplicacion (600 r/m en la zona de la VLAN del quiosco): el techo por
// origen son ~10 fichajes/s, igual que a un quiosco real. Los 50/s de
// RNF-P-06 se alcanzan como en un hotel de verdad: varios origenes a la vez.
// `run.sh` lanza N contenedores (N IPs) y agrega las muestras crudas; este
// script solo genera su cuota (RATE por segundo, DURATION).
//
// Los payloads y los tokens de dispositivo vienen de provision-fixtures.php,
// firmados por el propio servidor: aqui no hay criptografia que pueda
// desviarse de la del backend. Cada instancia usa su rebanada de credenciales
// (INSTANCE/INSTANCES) para que dos instancias no fichen al mismo empleado.
//
// La mezcla de resultados es la de un pico real de las 06:00: entradas,
// salidas y algun anti-rebote (RF-AT-06, que es un desenlace ACEPTADO). Un
// rechazo (4xx que no sea 429) si es fallo de la prueba.

import http from 'k6/http'
import { check } from 'k6'
import { Counter } from 'k6/metrics'
import { SharedArray } from 'k6/data'

const fixtures = new SharedArray('fixtures', () => {
  const raw = JSON.parse(open('/fixtures/k6-fixtures.json'))
  return [{ payloads: raw.payloads, deviceTokens: raw.device_tokens }]
})

const RATE = Number(__ENV.RATE || 10)
const DURATION = __ENV.DURATION || '120s'
const INSTANCE = Number(__ENV.INSTANCE || 0)
const INSTANCES = Number(__ENV.INSTANCES || 1)
const BASE = __ENV.BASE_URL || 'https://nginx:8443'

export const options = {
  insecureSkipTLSVerify: true, // certificado autofirmado del entorno de desarrollo
  scenarios: {
    scan: {
      executor: 'constant-arrival-rate',
      rate: RATE,
      timeUnit: '1s',
      duration: DURATION,
      preAllocatedVUs: 30,
      maxVUs: 120,
    },
  },
  thresholds: {
    // RNF-P-02. Cada instancia los evalua sobre sus propias muestras; el
    // veredicto agregado lo calcula run.sh sobre las muestras crudas de todas.
    http_req_duration: ['p(95)<150', 'p(99)<400'],
    checks: ['rate>0.99'],
  },
}

const outcomes = new Counter('scan_outcomes')

// UUID v7: 48 bits de milisegundos + version + aleatorio, como exige
// RegisterScanRequest (regla dura 8: el scan_id lo genera el cliente).
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

export default function () {
  const { payloads, deviceTokens } = fixtures[0]

  // Rebanada propia de credenciales; los dispositivos se reparten round-robin
  // entre iteraciones (el limite de la aplicacion es de 2 r/s por dispositivo).
  const sliceSize = Math.floor(payloads.length / INSTANCES)
  const offset = INSTANCE * sliceSize
  const payload = payloads[offset + (__ITER % sliceSize)]
  const token = deviceTokens[__ITER % deviceTokens.length]

  const scanId = uuidv7()
  const res = http.post(
    `${BASE}/api/v1/scan`,
    JSON.stringify({
      scan_id: scanId,
      occurred_at: new Date().toISOString().replace(/\.\d{3}Z$/, 'Z'),
      qr_payload: payload,
    }),
    {
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
        'Idempotency-Key': scanId,
      },
    },
  )

  outcomes.add(1, { status: String(res.status) })

  check(res, {
    'fichaje aceptado (200/201) o limite de tasa (429)': (r) =>
      r.status === 200 || r.status === 201 || r.status === 429,
  })
}
