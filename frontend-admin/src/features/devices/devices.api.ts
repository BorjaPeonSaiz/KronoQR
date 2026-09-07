// Quioscos de la instalacion: la flota (RF-PA-07) y su vinculacion por codigo
// de emparejamiento (RF-PD-06, tarea 5.6).
//
// Las formas salen del contrato; aqui no se inventa ninguna. `PairingRequested`
// y `PairingClaim*` son cosa de la tablet (`frontend-kiosk`) y no tienen
// funcion aqui: el panel solo teclea el ultimo paso, `confirm`.
import { requestJson } from '@kronoqr/web-kit/http'
import type {
  Device,
  DeviceList,
  PairingConfirmed,
  PairingConfirmRequest,
} from '@/shared/api/types'

/** `GET /api/v1/devices`: la flota completa, activa y revocada. Sin paginar (ADR-040). */
export function fetchDevices(): Promise<DeviceList> {
  return requestJson<DeviceList>('/api/v1/devices')
}

/**
 * `POST /api/v1/kiosk/pair/confirm`: vincula (o reactiva) el quiosco que
 * muestra `code`. No devuelve el token: lo recoge la tablet en su siguiente
 * sondeo.
 */
export function confirmPairing(request: PairingConfirmRequest): Promise<PairingConfirmed> {
  return requestJson<PairingConfirmed>('/api/v1/kiosk/pair/confirm', {
    method: 'POST',
    body: request,
  })
}

/**
 * `POST /api/v1/devices/{uuid}/unpair`: revoca el token y deja la fila en
 * `revoked`. Idempotente (el contrato lo garantiza): desvincular uno ya
 * revocado devuelve `200` con el mismo cuerpo.
 */
export function unpairDevice(uuid: string): Promise<Device> {
  return requestJson<Device>(`/api/v1/devices/${uuid}/unpair`, { method: 'POST' })
}
