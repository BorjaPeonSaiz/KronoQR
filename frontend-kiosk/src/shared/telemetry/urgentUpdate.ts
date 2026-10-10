// Estado del MODO URGENTE de actualizacion (RF-KI-07, RF-KI-08).
//
// Une tres cosas que viven en sitios distintos: la version minima que declaro
// el ultimo latido (`deviceIdentity.ts`), la version de esta PWA (`APP_VERSION`)
// y los intentos de recarga ya hechos. La decision pura esta en
// `features/offline/domain/minimumVersion.ts`; aqui solo se lee el disco y se
// avisa a quien escucha (el registro del service worker, `sw/`), porque el
// latido lo crean tres pantallas y el service worker se registra en `main.ts`.

import {
  nextUrgentAttempts,
  urgentUpdateState as decideUrgentUpdateMode,
} from '@/features/offline/domain/minimumVersion'
import type {
  MinimumVersionReading,
  UrgentUpdateMode,
} from '@/features/offline/domain/minimumVersion'
import {
  APP_VERSION,
  readMinimumAppVersion,
  readUrgentUpdateAttempts,
  storeMinimumAppVersion,
  storeUrgentUpdateAttempts,
} from './deviceIdentity'

export type { UrgentUpdateMode }

/** Estado actual: `none`, `urgent` o `gave_up` (ver `minimumVersion.ts`). */
export function currentUrgentUpdateMode(): UrgentUpdateMode {
  return decideUrgentUpdateMode({
    current: APP_VERSION,
    minimum: readMinimumAppVersion(),
    attempts: readUrgentUpdateAttempts(),
  })
}

/** Anota una recarga urgente ANTES de hacerla: si la pagina muere, el intento ya cuenta. */
export function recordUrgentUpdateAttempt(): void {
  const minimum = readMinimumAppVersion()
  if (minimum === null) return
  storeUrgentUpdateAttempts(nextUrgentAttempts(APP_VERSION, minimum, readUrgentUpdateAttempts()))
}

type Listener = () => void
const listeners = new Set<Listener>()

/** Quien quiera enterarse de que el latido ha dejado una minima nueva. Devuelve la baja. */
export function onMinimumAppVersionReceived(listener: Listener): () => void {
  listeners.add(listener)
  return () => {
    listeners.delete(listener)
  }
}

/** Lo llama SOLO el planificador del latido, tras guardar la minima. Un oyente roto no rompe el latido. */
export function notifyMinimumAppVersionReceived(): void {
  for (const listener of [...listeners]) {
    try {
      listener()
    } catch {
      // Un fallo al reaccionar nunca puede tumbar el latido (regla dura 19).
    }
  }
}

/**
 * Guarda lo leido del latido y avisa. `absent` BORRA la minima cacheada (el
 * servidor dice que no declara ninguna); `invalid` no toca nada (lector
 * tolerante, como `update_window`). Si la tablet ya cumple la minima, el
 * registro de intentos se descarta: el bucle, si lo hubo, ha terminado.
 */
export function storeMinimumAppVersionReading(reading: MinimumVersionReading): void {
  if (reading.status === 'invalid') return
  storeMinimumAppVersion(reading.status === 'valid' ? reading.version : null)
  if (currentUrgentUpdateMode() === 'none') storeUrgentUpdateAttempts(null)
  notifyMinimumAppVersionReceived()
}
