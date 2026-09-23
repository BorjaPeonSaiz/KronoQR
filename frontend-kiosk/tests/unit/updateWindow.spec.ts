// La puerta de la actualizacion diferida (RF-KI-07, tarea 3.12).
//
// Ya no hay ventanas de cambio de turno fijas en el codigo: la ventana es
// configuracion del centro. Estas pruebas fijan los limites exactos de la
// puerta con ventanas pasadas por parametro, y los valores de serie cuando
// no se pasa ninguna.

import { describe, expect, it } from 'vitest'
import {
  canApplyUpdate,
  DEFAULT_UPDATE_QUIET_MINUTES,
  DEFAULT_UPDATE_WINDOW,
  isValidUpdateWindow,
  isWithinUpdateWindow,
} from '@/features/offline/domain/updateWindow'

/** Hora LOCAL de la tablet: es la del reloj de la pared la que trae la cola de gente. */
const at = (hour: number, minute: number): Date => new Date(2026, 7, 14, hour, minute, 0)

describe('ventana de actualizacion', () => {
  it('los valores de serie son 03:00-05:00 y 10 minutos de silencio', () => {
    expect(DEFAULT_UPDATE_WINDOW).toEqual({ start: '03:00', end: '05:00' })
    expect(DEFAULT_UPDATE_QUIET_MINUTES).toBe(10)
  })

  it('respeta los limites exactos de la ventana de serie', () => {
    expect(isWithinUpdateWindow(at(2, 59))).toBe(false)
    expect(isWithinUpdateWindow(at(3, 0))).toBe(true)
    expect(isWithinUpdateWindow(at(4, 59))).toBe(true)
    expect(isWithinUpdateWindow(at(5, 0))).toBe(false)
  })

  it('admite una ventana declarada por el centro que cruza la medianoche', () => {
    const window = { start: '23:00', end: '02:00' }
    expect(isWithinUpdateWindow(at(22, 59), window)).toBe(false)
    expect(isWithinUpdateWindow(at(23, 0), window)).toBe(true)
    expect(isWithinUpdateWindow(at(23, 45), window)).toBe(true)
    expect(isWithinUpdateWindow(at(1, 59), window)).toBe(true)
    expect(isWithinUpdateWindow(at(2, 0), window)).toBe(false)
  })

  it('una ventana con horas mal formadas nunca esta abierta', () => {
    expect(isWithinUpdateWindow(at(4, 0), { start: '25:00', end: '05:00' })).toBe(false)
    expect(isWithinUpdateWindow(at(4, 0), { start: '03:00', end: 'nope' })).toBe(false)
  })

  it('`start === end` nunca esta abierta -no "todo el dia"-', () => {
    const window = { start: '04:00', end: '04:00' }
    expect(isWithinUpdateWindow(at(3, 59), window)).toBe(false)
    expect(isWithinUpdateWindow(at(4, 0), window)).toBe(false)
    expect(isWithinUpdateWindow(at(4, 1), window)).toBe(false)
    expect(isWithinUpdateWindow(at(0, 0), window)).toBe(false)
    expect(isWithinUpdateWindow(at(23, 59), window)).toBe(false)
  })

  it('valida la forma de la ventana declarada por el centro', () => {
    expect(isValidUpdateWindow({ start: '03:00', end: '05:00' })).toBe(true)
    expect(isValidUpdateWindow({ start: '23:00', end: '02:00' })).toBe(true)
    expect(isValidUpdateWindow({ start: '25:00', end: '05:00' })).toBe(false)
    expect(isValidUpdateWindow({ start: '03:00' })).toBe(false)
    expect(isValidUpdateWindow(null)).toBe(false)
    expect(isValidUpdateWindow('03:00-05:00')).toBe(false)
  })
})

describe('puerta de la actualizacion (canApplyUpdate)', () => {
  it('no se aplica con fichajes sin sincronizar, aunque la ventana este abierta', () => {
    expect(canApplyUpdate({ now: at(4, 0), pendingScans: 1, lastScanAt: null })).toBe(false)
  })

  it('se aplica con la cola vacia, sin escaneo conocido y dentro de la ventana', () => {
    expect(canApplyUpdate({ now: at(4, 0), pendingScans: 0, lastScanAt: null })).toBe(true)
  })

  it('no se aplica fuera de la ventana, aunque la cola este vacia', () => {
    expect(canApplyUpdate({ now: at(11, 0), pendingScans: 0, lastScanAt: null })).toBe(false)
  })

  it('`lastScanAt` nulo cuenta como "sin escaneo": no bloquea por si solo', () => {
    expect(
      canApplyUpdate({ now: at(4, 0), pendingScans: 0, lastScanAt: null, quietMinutes: 10 }),
    ).toBe(true)
  })

  it('no se aplica dentro de los minutos de silencio tras el ultimo escaneo', () => {
    const now = at(4, 0)
    const lastScanAt = new Date(now.getTime() - 5 * 60_000) // hace 5 min
    expect(canApplyUpdate({ now, pendingScans: 0, lastScanAt, quietMinutes: 10 })).toBe(false)
  })

  it('se aplica en cuanto pasan los minutos de silencio exactos', () => {
    const now = at(4, 0)
    const justOutside = new Date(now.getTime() - 10 * 60_000) // hace exactamente 10 min
    expect(
      canApplyUpdate({ now, pendingScans: 0, lastScanAt: justOutside, quietMinutes: 10 }),
    ).toBe(true)

    const justInside = new Date(now.getTime() - (10 * 60_000 - 1)) // un ms menos
    expect(canApplyUpdate({ now, pendingScans: 0, lastScanAt: justInside, quietMinutes: 10 })).toBe(
      false,
    )
  })

  it('con `quietMinutes: 0`, un escaneo simultaneo no bloquea', () => {
    const now = at(4, 0)
    expect(canApplyUpdate({ now, pendingScans: 0, lastScanAt: now, quietMinutes: 0 })).toBe(true)
  })

  it('un `lastScanAt` en el FUTURO (reloj de la tablet adelantado, RF-AT-10) cuenta como "sin escaneo reciente"', () => {
    const now = at(4, 0)
    // El reloj de la tablet se desvio hacia adelante y luego se corrigio: el
    // ultimo escaneo registrado queda, con el reloj YA corregido, en el
    // futuro. Bloquear aqui dejaria la puerta cerrada para siempre -ningun
    // instante futuro real la abriria-, que es justo lo que la regla dura 19
    // prohibe.
    const lastScanAtInTheFuture = new Date(now.getTime() + 30 * 60_000)
    expect(
      canApplyUpdate({
        now,
        pendingScans: 0,
        lastScanAt: lastScanAtInTheFuture,
        quietMinutes: 10,
      }),
    ).toBe(true)
  })

  it('usa la ventana y los minutos de serie cuando no se pasan', () => {
    expect(canApplyUpdate({ now: at(4, 0), pendingScans: 0, lastScanAt: null })).toBe(true)
    expect(canApplyUpdate({ now: at(6, 0), pendingScans: 0, lastScanAt: null })).toBe(false)
  })

  it('admite una ventana del centro que cruza la medianoche', () => {
    const window = { start: '23:00', end: '02:00' }
    expect(canApplyUpdate({ now: at(23, 30), pendingScans: 0, lastScanAt: null, window })).toBe(
      true,
    )
    expect(canApplyUpdate({ now: at(11, 0), pendingScans: 0, lastScanAt: null, window })).toBe(
      false,
    )
  })
})
