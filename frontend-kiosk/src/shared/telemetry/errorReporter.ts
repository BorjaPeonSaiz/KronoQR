// Canal de reporte de errores del cliente (RF-PD-15, regla dura 21).
//
// POR QUE EXISTE. Una tablet que falla en un hotel, sin nadie mirandola, es
// invisible de cualquier otra forma. El navegador no tiene a quien contarselo:
// el error se queda en una consola que nadie abre hasta que alguien reclama una
// jornada que no se registro.
//
// COMO VIAJA. En el latido (`POST /api/v1/kiosk/heartbeat`), no en una llamada
// propia: el quiosco no debe abrir otro canal de red que compita con la
// sincronizacion de la cola en el cambio de turno. Del latido acaban en
// `error_events` del servidor.
//
// QUE NO PUEDE LLEVAR NUNCA. Datos personales (regla dura 21). Ni nombres, ni
// el `qr_payload`, ni el token de la tarjeta, ni el hash del padron. Solo
// codigo, version, `device_id` y contexto tecnico. Eso no es una buena
// intencion: `sanitizeContext` descarta por nombre de clave y por tipo, y hay
// una prueba unitaria que lo comprueba.
//
// ESTADO DEL CANAL. El buffer y el saneado estan terminados; lo que falta es el
// campo en el contrato. `KioskHeartbeatRequest` declara hoy
// `additionalProperties: false` y no tiene sitio donde meter esto, asi que
// anadirlo al cuerpo lo haria rechazable por el validador de contrato. Ver
// `heartbeat.ts` -> `pendingClientErrors()`.

const MAX_BUFFERED_ERRORS = 50
const MAX_CONTEXT_KEYS = 12
const MAX_STRING_LENGTH = 200

/**
 * Claves cuyo valor NO se envia nunca, se llame como se llame el sitio desde el
 * que se reporta. Es una red de seguridad contra el descuido de manana, no
 * contra el codigo de hoy.
 */
const FORBIDDEN_CONTEXT_KEYS = [
  'name',
  'employee',
  'display_name',
  'displayname',
  'payload',
  'qr',
  'qr_payload',
  'token',
  'secret',
  'hash',
  'pin',
  'email',
  'phone',
  'dni',
] as const

export type ContextValue = string | number | boolean
export type ErrorContext = Readonly<Record<string, ContextValue>>

/**
 * Codigos estables. Son un enum cerrado a proposito: un codigo libre acaba
 * siendo una frase, y una frase acaba llevando un nombre dentro.
 */
export type ClientErrorCode =
  | 'kiosk.camera.permission_denied'
  | 'kiosk.camera.unavailable'
  | 'kiosk.camera.stream_lost'
  | 'kiosk.scanner.start_failed'
  | 'kiosk.scanner.decoder_load_failed'
  | 'kiosk.scanner.watchdog_restart'
  | 'kiosk.wake_lock.denied'
  | 'kiosk.audio.blocked'
  | 'kiosk.scan.submit_failed'
  | 'kiosk.scan.malformed_payload'
  // Cola offline (tarea 1.9). Ninguno lleva `scan_id` ni `qr_payload`: un
  // `scan_id` no es PII, pero correlacionado con `scan_events` identifica a una
  // persona concreta en una hora concreta, y `error_events` viaja al fabricante
  // dentro del paquete de diagnostico (regla dura 21, ADR-020).
  | 'kiosk.offline.storage_unavailable'
  | 'kiosk.offline.sync_failed'
  | 'kiosk.offline.sync_unauthorized'
  | 'kiosk.offline.sync_throttled'
  | 'kiosk.offline.item_not_processed'
  | 'kiosk.offline.malformed_batch_response'
  | 'kiosk.offline.confirm_not_persisted'
  | 'kiosk.roster.decrypt_failed'
  | 'kiosk.roster.fetch_failed'
  | 'kiosk.roster.not_cacheable'
  | 'kiosk.heartbeat.failed'
  | 'kiosk.clock.skew_detected'
  | 'kiosk.unhandled_error'
  | 'kiosk.service_worker.failed'
  // Fichaje de respaldo por PIN (tarea 1.12). Nunca lleva el PIN, ni sellado ni
  // en claro: solo dice que el sellado en si ha fallado (RF-AT-11, RL-12).
  | 'kiosk.pin.seal_failed'

export interface ClientErrorEvent {
  readonly code: ClientErrorCode
  readonly occurred_at: string
  readonly app_version: string
  readonly device_id: string
  readonly context: ErrorContext
}

export function sanitizeContext(input: Readonly<Record<string, unknown>>): ErrorContext {
  const output: Record<string, ContextValue> = {}
  let kept = 0

  for (const [key, value] of Object.entries(input)) {
    if (kept >= MAX_CONTEXT_KEYS) break

    const normalizedKey = key.toLowerCase()
    if (FORBIDDEN_CONTEXT_KEYS.some((forbidden) => normalizedKey.includes(forbidden))) continue

    if (typeof value === 'number') {
      if (!Number.isFinite(value)) continue
      output[key] = value
    } else if (typeof value === 'boolean') {
      output[key] = value
    } else if (typeof value === 'string') {
      output[key] = value.slice(0, MAX_STRING_LENGTH)
    } else {
      // Objetos, arrays, funciones y `null` no entran: son la via por la que se
      // cuela una estructura entera con datos dentro.
      continue
    }
    kept += 1
  }

  return output
}

export interface ErrorReporterOptions {
  readonly appVersion: string
  readonly deviceId: string
  readonly now?: () => Date
  readonly maxBuffered?: number
}

export interface ErrorReporter {
  report(code: ClientErrorCode, context?: Readonly<Record<string, unknown>>): void
  /** Copia de lo pendiente. No vacia el buffer: eso lo hace `acknowledge`. */
  pending(): readonly ClientErrorEvent[]
  /** Descarta los `count` mas antiguos, una vez confirmados por el servidor. */
  acknowledge(count: number): void
  size(): number
}

export function createErrorReporter(options: ErrorReporterOptions): ErrorReporter {
  const now = options.now ?? (() => new Date())
  const limit = options.maxBuffered ?? MAX_BUFFERED_ERRORS
  const buffer: ClientErrorEvent[] = []

  return {
    report(code, context = {}) {
      buffer.push({
        code,
        occurred_at: now().toISOString(),
        app_version: options.appVersion,
        device_id: options.deviceId,
        context: sanitizeContext(context),
      })
      // Techo duro. El bucle de decodificacion corre 8 h: un reporte por
      // fotograma fallido llenaria la memoria de la tablet en minutos, y ese
      // seria un fallo peor que el que se intentaba diagnosticar.
      while (buffer.length > limit) buffer.shift()
    },

    pending() {
      return [...buffer]
    },

    acknowledge(count) {
      buffer.splice(0, Math.max(0, count))
    },

    size() {
      return buffer.length
    },
  }
}
