import { describe, expect, it } from 'vitest'
import {
  buildDiagnosticsSnapshot,
  cameraWarnings,
} from '@/features/diagnostics/application/diagnosticsSnapshot'
import type { DiagnosticsSources } from '@/features/diagnostics/application/diagnosticsSnapshot'

function baseSources(): DiagnosticsSources {
  return {
    deviceId: '0199f3c9-1b7d-7a44-8e02-3c4d5e6f7a81',
    paired: true,
    camera: { state: 'running', settings: null, torchAvailable: false },
    network: { online: true, reachable: null, lastHeartbeat: null },
    queue: { size: 0, oldestOccurredAt: null, durable: true, syncing: false },
    roster: { generatedAt: null, entryCount: 0, pinAvailable: false },
    token: { present: false, expiresAt: null, deviceName: null, shortId: null },
    appVersion: '2.4.0',
    serviceWorkerActive: null,
    battery: { supported: false, level: null, charging: null },
    wakeLock: { supported: true, active: true },
    pendingErrors: 0,
    privacyControllerConfigured: false,
  }
}

describe('avisos de camara (RF-KI-08, tarea 3.3)', () => {
  it('sin ajustes (getSettings ausente, o camara cerrada), ningun aviso', () => {
    expect(cameraWarnings(null)).toEqual([])
  })

  it('desenfoque de fondo activo', () => {
    expect(cameraWarnings({ backgroundBlur: true, width: 1280, height: 720 })).toEqual([
      'background_blur',
    ])
  })

  it('enfoque distinto de continuo', () => {
    expect(cameraWarnings({ focusMode: 'manual', width: 1280, height: 720 })).toEqual([
      'focus_not_continuous',
    ])
  })

  it('sin `focusMode` en absoluto no avisa: el navegador simplemente no lo expone', () => {
    expect(cameraWarnings({ width: 1280, height: 720 })).toEqual([])
  })

  it('resolucion por debajo de 1280x720', () => {
    expect(cameraWarnings({ width: 640, height: 480 })).toEqual(['low_resolution'])
  })

  it('resolucion exacta al umbral no avisa', () => {
    expect(cameraWarnings({ width: 1280, height: 720 })).toEqual([])
  })

  it('los tres avisos pueden darse a la vez', () => {
    expect(
      cameraWarnings({ backgroundBlur: true, focusMode: 'manual', width: 640, height: 480 }),
    ).toEqual(['background_blur', 'focus_not_continuous', 'low_resolution'])
  })

  it('sin resolucion conocida (0x0 o ausente) no se afirma nada sobre ella', () => {
    expect(cameraWarnings({})).toEqual([])
  })
})

describe('ensamblado del diagnostico (RF-KI-08, tarea 3.3)', () => {
  it('calcula los avisos de camara a partir de los ajustes', () => {
    const snapshot = buildDiagnosticsSnapshot({
      ...baseSources(),
      camera: { state: 'running', settings: { backgroundBlur: true }, torchAvailable: true },
    })

    expect(snapshot.camera.warnings).toEqual(['background_blur'])
    expect(snapshot.camera.torchAvailable).toBe(true)
  })

  it('la alcanzabilidad llega YA resuelta de las fuentes, sin deducirla de nada', () => {
    const unknown = buildDiagnosticsSnapshot(baseSources())
    expect(unknown.network.reachable).toBeNull()

    const reachable = buildDiagnosticsSnapshot({
      ...baseSources(),
      network: { online: true, reachable: true, lastHeartbeat: null },
    })
    expect(reachable.network.reachable).toBe(true)

    // Nunca se infiere `false` de un latido ausente: viene tal cual de la
    // fuente (senal viva de `onReachability`, revision de la 3.3).
    const unreachable = buildDiagnosticsSnapshot({
      ...baseSources(),
      network: { online: true, reachable: false, lastHeartbeat: null },
    })
    expect(unreachable.network.reachable).toBe(false)
  })

  it('pasa `lastHeartbeat` (hora y desfase) tal cual', () => {
    const snapshot = buildDiagnosticsSnapshot({
      ...baseSources(),
      network: {
        online: true,
        reachable: true,
        lastHeartbeat: { beatAt: '2026-09-16T06:00:00.000Z', skewSeconds: -3 },
      },
    })

    expect(snapshot.network.lastHeartbeat).toEqual({
      beatAt: '2026-09-16T06:00:00.000Z',
      skewSeconds: -3,
    })
  })

  it('propaga `paired`: una tablet sin token abre sin nada que proteger', () => {
    const snapshot = buildDiagnosticsSnapshot({ ...baseSources(), paired: false })
    expect(snapshot.paired).toBe(false)
  })

  it('nunca lleva el token: solo lo que ya le paso quien llama (`shortId`)', () => {
    const snapshot = buildDiagnosticsSnapshot({
      ...baseSources(),
      token: {
        present: true,
        expiresAt: '2026-12-06T10:07:00Z',
        deviceName: 'Recepcion',
        shortId: 'a1b2c3d4',
      },
    })

    expect(snapshot.token).toEqual({
      present: true,
      expiresAt: '2026-12-06T10:07:00Z',
      deviceName: 'Recepcion',
      shortId: 'a1b2c3d4',
    })
  })

  it('pasa el resto de secciones tal cual, sin reinterpretarlas', () => {
    const sources = baseSources()
    const snapshot = buildDiagnosticsSnapshot(sources)

    expect(snapshot.queue).toEqual(sources.queue)
    expect(snapshot.roster).toEqual(sources.roster)
    expect(snapshot.battery).toEqual(sources.battery)
    expect(snapshot.wakeLock).toEqual(sources.wakeLock)
    expect(snapshot.pendingErrors).toBe(0)
    expect(snapshot.appVersion).toBe('2.4.0')
  })
})
