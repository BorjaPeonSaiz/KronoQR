// Alias sobre los tipos GENERADOS del contrato (`schema.d.ts`).
//
// Este fichero no define ninguna forma: solo pone nombre corto a lo que ya dice
// `docs/api/openapi.yaml`, que es la fuente de verdad (ADR-013, orden de
// autoridad 2 de CLAUDE.md). Si el contrato cambia, `npm run api:generate`
// regenera el esquema y `vue-tsc` senala aqui todo lo que ha dejado de encajar.

import type { components } from './schema'

export type ScanId = components['schemas']['ScanId']
export type UtcTimestamp = components['schemas']['UtcTimestamp']
export type ScanIntent = components['schemas']['ScanIntent']
export type ScanAction = components['schemas']['ScanAction']

export type ScanRequest = components['schemas']['ScanRequest']
export type PinScanRequest = components['schemas']['PinScanRequest']
export type ScanAccepted = components['schemas']['ScanAccepted']
export type ScanDebounced = components['schemas']['ScanDebounced']
export type ScanRejected = components['schemas']['ScanRejected']

/** Las dos formas que puede tener un `200` de `POST /api/v1/scan` (ADR-031). */
export type ScanOk = ScanAccepted | ScanDebounced

/** Accion que SI creo o cerro un tramo. `debounced` queda fuera a proposito. */
export type ScanAcceptedAction = ScanAccepted['action']

export type ScanBatchRequest = components['schemas']['ScanBatchRequest']
export type ScanBatchResponse = components['schemas']['ScanBatchResponse']
export type ScanBatchEntry = components['schemas']['ScanBatchEntry']
/** `503` elemento a elemento: no se decidio nada, el quiosco lo conserva y reintenta. */
export type ScanNotProcessed = components['schemas']['ScanNotProcessed']

export type KioskRoster = components['schemas']['KioskRoster']
export type KioskRosterEntry = components['schemas']['KioskRosterEntry']
export type KioskHeartbeatRequest = components['schemas']['KioskHeartbeatRequest']
export type KioskHeartbeat = components['schemas']['KioskHeartbeat']
