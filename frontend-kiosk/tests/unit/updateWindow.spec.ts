// La puerta de la actualizacion diferida (paso 11 de la tarea 1.9).
//
// Aplicar una version nueva recarga la pagina. Estas pruebas dicen cuando NO
// puede pasar eso.

import { describe, expect, it, vi } from 'vitest'
import {
  canApplyUpdate,
  DEFAULT_SHIFT_CHANGE_WINDOWS,
  isWithinShiftChange,
} from '@/features/offline/domain/updateWindow'
import { registerServiceWorker } from '@/sw/registerServiceWorker'

/** Hora LOCAL de la tablet: es la del reloj de la pared la que trae la cola de gente. */
const at = (hour: number, minute: number): Date => new Date(2026, 7, 14, hour, minute, 0)

describe('ventana de cambio de turno', () => {
  it('el cambio de turno de las 06:00 esta protegido', () => {
    expect(isWithinShiftChange(at(5, 29))).toBe(false)
    expect(isWithinShiftChange(at(5, 30))).toBe(true)
    expect(isWithinShiftChange(at(6, 0))).toBe(true)
    expect(isWithinShiftChange(at(6, 29))).toBe(true)
    expect(isWithinShiftChange(at(6, 30))).toBe(false)
  })

  it('tambien los de tarde y noche', () => {
    expect(isWithinShiftChange(at(14, 0))).toBe(true)
    expect(isWithinShiftChange(at(22, 0))).toBe(true)
    expect(isWithinShiftChange(at(11, 0))).toBe(false)
  })

  it('admite una ventana que cruza la medianoche', () => {
    const windows = [{ startMinute: 23 * 60 + 30, endMinute: 30 }]
    expect(isWithinShiftChange(at(23, 45), windows)).toBe(true)
    expect(isWithinShiftChange(at(0, 15), windows)).toBe(true)
    expect(isWithinShiftChange(at(1, 0), windows)).toBe(false)
  })

  it('las ventanas por defecto son tres y son configuracion, no ley', () => {
    expect(DEFAULT_SHIFT_CHANGE_WINDOWS).toHaveLength(3)
    expect(isWithinShiftChange(at(6, 0), [])).toBe(false)
  })
})

describe('puerta de la actualizacion', () => {
  it('no se actualiza en un cambio de turno', () => {
    expect(canApplyUpdate({ now: at(6, 0), pendingScans: 0 })).toBe(false)
  })

  it('no se actualiza con fichajes sin sincronizar', () => {
    expect(canApplyUpdate({ now: at(11, 0), pendingScans: 3 })).toBe(false)
  })

  it('se actualiza a media manana con la cola vacia', () => {
    expect(canApplyUpdate({ now: at(11, 0), pendingScans: 0 })).toBe(true)
  })
})

describe('registro del service worker', () => {
  it('sin soporte de service worker no rompe nada y no aplica nada', async () => {
    const original = Object.getOwnPropertyDescriptor(globalThis, 'navigator')
    // @ts-expect-error se elimina a proposito para simular un navegador sin soporte
    delete globalThis.navigator

    const registration = await registerServiceWorker({ canApply: vi.fn(() => true) })

    expect(registration.needsRefresh()).toBe(false)
    expect(await registration.applyUpdate()).toBe(false)

    if (original !== undefined) Object.defineProperty(globalThis, 'navigator', original)
  })
})
