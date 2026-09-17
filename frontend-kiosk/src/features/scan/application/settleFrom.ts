// Traduce la respuesta del servidor (o su ausencia) a lo que se pinta en
// pantalla. Compartida por las dos tuberias que encolan un fichaje —
// `scanPipeline` (QR) y `features/pin/application/pinPipeline` (PIN,
// RF-AT-11) — porque ambas confirman en local ANTES de saber que dira el
// servidor y solo difieren en como llegan hasta aqui (sincrono vs. con un
// sellado previo async).
//
// El caso `rejected` no trae `response`: el servidor no ha podido asociar
// nada a ese `scan_id`, así que no hay un instante suyo que usar. Se pinta el
// instante del INTENTO (el que ya vive en `occurredAt`, capturado al decodificar
// el QR o al pulsar «confirmar» en el PIN), nunca el de la respuesta: es lo que
// la persona vivio delante de la tablet, y es ademas el unico instante
// disponible quando el escaneo viajo en la cola offline y el servidor tardo en
// contestar.
//
// DESFASE DE RELOJ EN LA CONFIRMACION (RF-AT-10, decision 6 de la tarea 3.5).
// `settleFrom` SOLO se llama con un desenlace que llego DENTRO del propio
// envio -nunca con el de un lote que el drenaje de la cola confirmo en
// segundo plano, ver `syncRunner.submit()`-, asi que cualquier `accepted` que
// llega aqui es, por construccion, «un escaneo respondido en linea» y no uno
// consolidado horas despues desde la cola: es la distincion que el plan pide
// y `settleFrom` ya la tenia gratis, sin nada que anadir para reconocerla.
// Si `|recorded_at - occurred_at|` supera `clockSkewToleranceSeconds`, la
// confirmacion lleva el desfase para que `ScanConfirmationPanel` avise con la
// misma frase que la banda de `ScanView`.

import { clockSkewSeconds } from '@/shared/telemetry/heartbeat'
import { exceedsClockSkewTolerance } from '../domain/clockSkewMessage'
import type { ScanConfirmation } from '../domain/scanOutcome'
import type { ScanSubmissionResult } from './ports'

/**
 * @param result     lo que devolvio (o no) el envio al servidor.
 * @param scanId     el `scan_id` generado al encolar (regla dura 8).
 * @param occurredAt el instante del intento, para la rama `rejected`.
 * @param clockSkewToleranceSeconds tolerancia de la instalacion
 *        (`KioskHeartbeat.clock_skew_tolerance_seconds`), o `null` si esta
 *        tablet no ha latido todavia: sin umbral, sin aviso (decision 6).
 * @returns la confirmacion a pintar, o `null` si sigue «pendiente» en cola
 *          (`deferred`): la pantalla ya dice eso y no hay que tocarla.
 */
export function settleFrom(
  result: ScanSubmissionResult,
  scanId: string,
  occurredAt: Date,
  clockSkewToleranceSeconds: number | null = null,
): ScanConfirmation | null {
  switch (result.kind) {
    case 'accepted': {
      // `clockSkewSeconds` mide `deviceNow - serverTime`; aqui el "reloj del
      // dispositivo" es el instante que declaro el propio escaneo
      // (`occurred_at`) y el "reloj del servidor" es cuando lo recibio
      // (`recorded_at`): la misma formula, aplicada a los dos instantes que
      // ya trae la respuesta en vez de a la hora `Date.now()` del latido.
      const skew = clockSkewSeconds(
        new Date(result.response.occurred_at),
        result.response.recorded_at,
      )
      // Un solo operador para "supera el umbral" (revision de la segunda
      // vuelta): `exceedsClockSkewTolerance` es tambien quien decide en
      // `heartbeat.ts`, con el mismo `>` estricto que `ReviewPolicy` en el
      // servidor. Antes esta comparacion se repetia a mano aqui.
      const exceeds = skew !== null && exceedsClockSkewTolerance(skew, clockSkewToleranceSeconds)

      return {
        kind: 'accepted',
        scanId,
        occurredAt: new Date(result.response.occurred_at),
        action: result.response.action,
        displayName: result.response.employee_display_name,
        workedMinutes: result.response.worked_minutes,
        workDate: result.response.work_date,
        ...(exceeds && skew !== null ? { clockSkewSeconds: skew } : {}),
      }
    }
    case 'debounced':
      return {
        kind: 'debounced',
        scanId,
        occurredAt: new Date(result.response.occurred_at),
        displayName: result.response.employee_display_name,
        workedMinutes: result.response.worked_minutes,
        lastAcceptedAt: new Date(result.response.last_accepted_at),
      }
    case 'rejected':
      return { kind: 'rejected', scanId, occurredAt }
    case 'deferred':
      return null
  }
}
