// Latido del quiosco (`POST /api/v1/kiosk/heartbeat`).
//
// Es lo que hace visible un quiosco averiado ANTES de que alguien reclame una
// jornada. Alimenta `devices.last_seen_at`, `devices.app_version` y
// `devices.pending_queue_size`, y con ellos la alerta «quiosco sin latido».
//
// La respuesta trae `server_time`: con ella la tablet mide su propio desfase de
// reloj y avisa (RF-AT-10). **Nunca le impide fichar** (regla dura 19); el
// desfase se registra escaneo a escaneo y se corrige despues.
//
// CANAL DE ERRORES DE CLIENTE (RF-PD-15, tarea 5.12). El mismo cuerpo lleva
// `client_errors` cuando el reporter tiene algo pendiente (`buildHeartbeatBody`,
// maximo 50, los mas antiguos primero, sin `device_id`: el servidor lo sabe
// por el token). La respuesta trae `client_errors_accepted`, y solo ESO es lo
// que `acknowledge()` vacia del buffer -un latido que llega pero no pudo
// escribir nada (`0`) no pierde ni un error, vuelve en el siguiente-.
//
// Regla dura 19 aplicada al reves: reportar un error NUNCA puede dejar la
// tablet sin latido. Si el servidor rechaza el propio `client_errors` con
// `400` -su unica parte variable, y por tanto la unica que un fallo de forma
// puede tumbar-, el buffer se vacia igualmente (perder unos pocos errores
// tecnicos es preferible a repetir el mismo `400` en cada ciclo para
// siempre) y se dice por que en un nuevo `kiosk.heartbeat.failed`.

import type { ApiClient } from '@/shared/api/client'
import type { ClientErrorReport, KioskHeartbeatRequest } from '@/shared/api/types'
import type { Clock } from '@/shared/time/clock'
import { systemClock } from '@/shared/time/clock'
import type { ClientErrorEvent, ErrorReporter } from './errorReporter'

/**
 * Tope de `client_errors` por latido (contrato,
 * `KioskHeartbeatRequest.client_errors.maxItems`). El reporter ya no deja
 * crecer su buffer mas alla de esto, pero el corte se repite aqui: quien
 * construye el cuerpo no debe fiarse de un limite ajeno para cumplir el
 * contrato.
 */
const MAX_CLIENT_ERRORS_PER_HEARTBEAT = 50

/** Cada minuto. La alerta del doc 01 §9.3 dispara a los 10 min sin latido. */
export const DEFAULT_HEARTBEAT_INTERVAL_MS = 60_000

/** ATTENDANCE_MAX_CLOCK_SKEW_MINUTES por defecto (doc 02, Anexo B). */
export const CLOCK_SKEW_WARNING_SECONDS = 15 * 60

/** Lo que el quiosco sabe de si mismo en el momento de latir. */
export interface KioskTelemetrySnapshot {
  readonly appVersion: string
  readonly pendingQueueSize: number
  /** `occurred_at` del elemento mas antiguo de la cola, si hay cola (tarea 1.9). */
  readonly oldestPendingAt?: string | undefined
}

/**
 * Traduce la forma INTERNA del quiosco a la del contrato. `device_id` nunca
 * sale de aqui: el servidor decide `source` (`kiosk`) por el token, no por lo
 * que diga el cuerpo (RS-03, regla dura 21).
 */
function toClientErrorReport(event: ClientErrorEvent): ClientErrorReport {
  return {
    code: event.code,
    occurred_at: event.occurred_at,
    app_version: event.app_version,
    context: event.context,
  }
}

export function buildHeartbeatBody(
  snapshot: KioskTelemetrySnapshot,
  clientErrors: readonly ClientErrorEvent[] = [],
): KioskHeartbeatRequest {
  const body: KioskHeartbeatRequest = {
    app_version: snapshot.appVersion,
    pending_queue_size: snapshot.pendingQueueSize,
  }
  // `exactOptionalPropertyTypes`: la clave no se escribe si no hay valor, en vez
  // de escribirse con `undefined`. El contrato dice «ausente cuando la cola esta
  // vacia», no «presente y nulo». Mismo criterio para `client_errors`: ausente
  // cuando no hay nada pendiente, no una lista vacia.
  const withOldest: KioskHeartbeatRequest =
    snapshot.oldestPendingAt === undefined
      ? body
      : { ...body, oldest_pending_at: snapshot.oldestPendingAt }

  if (clientErrors.length === 0) return withOldest

  return {
    ...withOldest,
    client_errors: clientErrors.slice(0, MAX_CLIENT_ERRORS_PER_HEARTBEAT).map(toClientErrorReport),
  }
}

/**
 * Desfase en segundos entre el reloj del dispositivo y el del servidor.
 * Positivo = la tablet va adelantada.
 */
export function clockSkewSeconds(deviceNow: Date, serverTimeIso: string): number | null {
  const serverMs = Date.parse(serverTimeIso)
  if (Number.isNaN(serverMs)) return null
  return Math.round((deviceNow.getTime() - serverMs) / 1000)
}

export interface HeartbeatSchedulerOptions {
  readonly api: ApiClient
  readonly reporter: ErrorReporter
  readonly snapshot: () => KioskTelemetrySnapshot
  readonly clock?: Clock
  readonly intervalMs?: number
  readonly onSkew?: (seconds: number) => void
  /**
   * Alimenta `features/pairing/application/deviceRevocation.ts` (RF-PD-06,
   * tarea 5.6). Mismo contrato que en `syncRunner.ts` y `cachedRoster.ts`:
   * `true` solo en un `401`/`403` real, `false` en un latido que SI llega a
   * contestar, nunca por un fallo de red (el hotel sin ADSL no es una
   * desvinculacion).
   */
  readonly onAuthOutcome?: (unauthorized: boolean) => void
}

export interface HeartbeatScheduler {
  start(): void
  stop(): void
  /** Envia uno ahora. Devuelve el desfase medido, o `null` si no hubo respuesta. */
  beat(): Promise<number | null>
}

export function createHeartbeatScheduler(options: HeartbeatSchedulerOptions): HeartbeatScheduler {
  const clock = options.clock ?? systemClock
  const intervalMs = options.intervalMs ?? DEFAULT_HEARTBEAT_INTERVAL_MS
  let timer: ReturnType<typeof setInterval> | null = null

  async function beat(): Promise<number | null> {
    // Lo pendiente en el momento de construir el cuerpo, no en el de recibir
    // la respuesta: si algo se reporta MIENTRAS este latido esta en el aire,
    // se queda para el siguiente ciclo en vez de perderse (`acknowledge` solo
    // vacia lo que de verdad viajo).
    const pendingErrors = options.reporter.pending()
    const result = await options.api.sendHeartbeat(
      buildHeartbeatBody(options.snapshot(), pendingErrors),
    )

    if (result.outcome !== 'ok') {
      // Un latido perdido no es una averia: puede ser el hotel sin ADSL. Se
      // anota y se sigue. Nunca se reintenta agresivamente ni se bloquea nada.
      if (result.outcome === 'failed') {
        if (result.cause === 'unauthorized') options.onAuthOutcome?.(true)

        // `400` que NOMBRA `client_errors` entre los campos invalidos (no
        // cualquier `400`: `app_version` o `pending_queue_size` tambien
        // pueden fallar la validacion, y esos no tienen nada que ver con el
        // buffer de errores). El servidor ha rechazado el propio
        // `client_errors` (la unica parte de este cuerpo que el quiosco no
        // controla del todo, decision 7 de la tarea 5.12). Sin esto la
        // tablet reenviaria el mismo lote invalido en cada ciclo y se
        // quedaria muda para siempre (regla dura 19, al reves: un fallo al
        // REPORTAR no puede impedir que el latido siga sirviendo para lo
        // demas). Se descarta solo lo que se intento enviar en ESTE latido
        // -no `size()`, que podria incluir algo reportado mientras la
        // peticion estaba en el aire- y queda dicho por que. Un `400` por
        // otro campo (o sin cuerpo `ValidationProblem` reconocible) CONSERVA
        // el buffer: no hay motivo para creer que el problema sea el mismo
        // lote de errores.
        if (
          result.httpStatus === 400 &&
          pendingErrors.length > 0 &&
          result.invalidFields?.includes('client_errors') === true
        ) {
          const attempted = Math.min(pendingErrors.length, MAX_CLIENT_ERRORS_PER_HEARTBEAT)
          options.reporter.acknowledge(attempted)
          options.reporter.report('kiosk.heartbeat.failed', {
            cause: 'client_errors_rejected',
            http_status: 400,
            message: 'client_errors_rejected',
          })
          return null
        }

        if (result.cause !== 'offline') {
          options.reporter.report('kiosk.heartbeat.failed', {
            cause: result.cause,
            http_status: result.httpStatus ?? 0,
            message: result.cause,
          })
        }
      }
      return null
    }

    options.onAuthOutcome?.(false)
    // Solo AHORA, confirmado por el servidor, se vacia lo enviado -y solo lo
    // que declara `client_errors_accepted`-. `0` (no se enviaron, o la base
    // de datos no pudo guardarlos) no toca el buffer: vuelve integro en el
    // siguiente latido.
    options.reporter.acknowledge(result.data.client_errors_accepted)

    const skew = clockSkewSeconds(clock.now(), result.data.server_time)
    if (skew === null) return null

    if (Math.abs(skew) >= CLOCK_SKEW_WARNING_SECONDS) {
      options.reporter.report('kiosk.clock.skew_detected', {
        skew_seconds: skew,
        message: 'clock_skew_detected',
      })
    }
    options.onSkew?.(skew)
    return skew
  }

  return {
    start() {
      if (timer !== null) return
      void beat()
      timer = setInterval(() => void beat(), intervalMs)
    },
    stop() {
      if (timer === null) return
      clearInterval(timer)
      timer = null
    },
    beat,
  }
}
