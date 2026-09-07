// Detector de desvinculacion (RF-PD-06, doc 01 §8.1).

import { describe, expect, it, vi } from 'vitest'
import { createDeviceRevocationWatcher } from '@/features/pairing/application/deviceRevocation'

describe('vigia de revocacion del dispositivo', () => {
  it('no dispara con un solo 401: podria ser una rotacion de token', () => {
    const onRevoked = vi.fn()
    const watcher = createDeviceRevocationWatcher({ onRevoked })

    watcher.reportUnauthorized()

    expect(onRevoked).not.toHaveBeenCalled()
  })

  it('dispara al segundo 401 SEGUIDO', () => {
    const onRevoked = vi.fn()
    const watcher = createDeviceRevocationWatcher({ onRevoked })

    watcher.reportUnauthorized()
    watcher.reportUnauthorized()

    expect(onRevoked).toHaveBeenCalledTimes(1)
  })

  it('un exito de por medio reinicia el conteo: dos 401 NO seguidos no disparan', () => {
    const onRevoked = vi.fn()
    const watcher = createDeviceRevocationWatcher({ onRevoked })

    watcher.reportUnauthorized()
    watcher.reportAuthenticated()
    watcher.reportUnauthorized()

    expect(onRevoked).not.toHaveBeenCalled()
  })

  it('nunca por un fallo de red: quien no reporta nada no cuenta ni resetea', () => {
    const onRevoked = vi.fn()
    const watcher = createDeviceRevocationWatcher({ onRevoked })

    watcher.reportUnauthorized()
    // Un fallo de red no es ni un 401 ni un exito: no se reporta nada.
    watcher.reportUnauthorized()

    expect(onRevoked).toHaveBeenCalledTimes(1)
  })

  it('solo dispara una vez, aunque sigan llegando 401', () => {
    const onRevoked = vi.fn()
    const watcher = createDeviceRevocationWatcher({ onRevoked })

    watcher.reportUnauthorized()
    watcher.reportUnauthorized()
    watcher.reportUnauthorized()
    watcher.reportUnauthorized()

    expect(onRevoked).toHaveBeenCalledTimes(1)
  })

  it('un emparejamiento nuevo (un exito real) rearma la deteccion para el siguiente episodio', () => {
    // El controlador de la cola es un singleton que sobrevive a la
    // navegacion (cabecera de `deviceRevocation.ts`): en el mismo turno cabe
    // desvincular, volver a emparejar, y desvincular otra vez.
    const onRevoked = vi.fn()
    const watcher = createDeviceRevocationWatcher({ onRevoked })

    watcher.reportUnauthorized()
    watcher.reportUnauthorized()
    expect(onRevoked).toHaveBeenCalledTimes(1)

    watcher.reportAuthenticated() // token nuevo, tras un emparejamiento nuevo
    watcher.reportUnauthorized()
    watcher.reportUnauthorized()

    expect(onRevoked).toHaveBeenCalledTimes(2)
  })

  it('el umbral es configurable', () => {
    const onRevoked = vi.fn()
    const watcher = createDeviceRevocationWatcher({ onRevoked, threshold: 3 })

    watcher.reportUnauthorized()
    watcher.reportUnauthorized()
    expect(onRevoked).not.toHaveBeenCalled()

    watcher.reportUnauthorized()
    expect(onRevoked).toHaveBeenCalledTimes(1)
  })
})
