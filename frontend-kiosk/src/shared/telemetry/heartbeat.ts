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
// HUECO DE CONTRATO, CONSCIENTE Y DOCUMENTADO
// -------------------------------------------
// El paso 13 de la tarea 1.8 pide preparar el canal de errores del cliente «en
// el latido». La parte de cliente esta hecha (`errorReporter.ts`), pero
// `KioskHeartbeatRequest` declara `additionalProperties: false` y no tiene
// ningun campo donde meterlos: enviarlos hoy produciria un 400 y romperia la
// prueba de contrato del backend.
//
// Por eso el planificador expone `pendingClientErrors()` en lugar de meterlos en
// el cuerpo. Cuando el contrato gane su campo (RF-PD-15, tarea 5.12), lo unico
// que cambia es `buildHeartbeatBody`, y `acknowledge()` ya esta escrito para
// vaciar el buffer solo tras confirmacion del servidor.

import type { ApiClient } from '@/shared/api/client'
import type { KioskHeartbeatRequest } from '@/shared/api/types'
import type { Clock } from '@/shared/time/clock'
import { systemClock } from '@/shared/time/clock'
import type { ClientErrorEvent, ErrorReporter } from './errorReporter'

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

export function buildHeartbeatBody(snapshot: KioskTelemetrySnapshot): KioskHeartbeatRequest {
  const body: KioskHeartbeatRequest = {
    app_version: snapshot.appVersion,
    pending_queue_size: snapshot.pendingQueueSize,
  }
  // `exactOptionalPropertyTypes`: la clave no se escribe si no hay valor, en vez
  // de escribirse con `undefined`. El contrato dice «ausente cuando la cola esta
  // vacia», no «presente y nulo».
  return snapshot.oldestPendingAt === undefined
    ? body
    : { ...body, oldest_pending_at: snapshot.oldestPendingAt }
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
}

export interface HeartbeatScheduler {
  start(): void
  stop(): void
  /** Envia uno ahora. Devuelve el desfase medido, o `null` si no hubo respuesta. */
  beat(): Promise<number | null>
  /** Errores de cliente a la espera de un campo en el contrato. Ver cabecera. */
  pendingClientErrors(): readonly ClientErrorEvent[]
}

export function createHeartbeatScheduler(options: HeartbeatSchedulerOptions): HeartbeatScheduler {
  const clock = options.clock ?? systemClock
  const intervalMs = options.intervalMs ?? DEFAULT_HEARTBEAT_INTERVAL_MS
  let timer: ReturnType<typeof setInterval> | null = null

  async function beat(): Promise<number | null> {
    const result = await options.api.sendHeartbeat(buildHeartbeatBody(options.snapshot()))

    if (result.outcome !== 'ok') {
      // Un latido perdido no es una averia: puede ser el hotel sin ADSL. Se
      // anota y se sigue. Nunca se reintenta agresivamente ni se bloquea nada.
      if (result.outcome === 'failed' && result.cause !== 'offline') {
        options.reporter.report('kiosk.heartbeat.failed', {
          cause: result.cause,
          http_status: result.httpStatus ?? 0,
        })
      }
      return null
    }

    const skew = clockSkewSeconds(clock.now(), result.data.server_time)
    if (skew === null) return null

    if (Math.abs(skew) >= CLOCK_SKEW_WARNING_SECONDS) {
      options.reporter.report('kiosk.clock.skew_detected', { skew_seconds: skew })
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
    pendingClientErrors: () => options.reporter.pending(),
  }
}
