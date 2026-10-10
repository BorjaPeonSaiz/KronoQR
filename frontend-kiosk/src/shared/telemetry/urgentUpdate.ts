// Estado del MODO URGENTE de actualizacion (RF-KI-07, RF-KI-08).
//
// Une tres cosas que viven en sitios distintos: la version minima que declaro
// el ultimo latido (`deviceIdentity.ts`), la version de esta PWA (`APP_VERSION`)
// y lo ya intentado (recargas y comprobaciones sin version nueva). La decision
// pura esta en `features/offline/domain/minimumVersion.ts`; aqui solo se lee el
// disco y se avisa a quien escucha (el registro del service worker, `sw/`),
// porque el latido lo crean tres pantallas y el service worker se registra en
// `main.ts`.
//
// EL REGISTRO TAMBIEN VIVE EN MEMORIA. `localStorage` puede negarse a escribir
// (cuota, modo privado): sin una copia en memoria el contador no avanzaria en
// toda la sesion y el corte del bucle no cortaria nada. La copia de sesion y la
// del disco se funden quedandose con la que mas haya avanzado.

import {
  nextUrgentAttempts,
  nextUrgentChecks,
  urgentGiveUpReason,
  urgentUpdateState as decideUrgentUpdateMode,
  withoutUrgentChecks,
} from '@/features/offline/domain/minimumVersion'
import type {
  MinimumVersionReading,
  UrgentGiveUpMotive,
  UrgentUpdateAttempts,
  UrgentUpdateMode,
} from '@/features/offline/domain/minimumVersion'
import {
  APP_VERSION,
  readMinimumAppVersion,
  readUrgentUpdateAttempts,
  storeMinimumAppVersion,
  storeUrgentUpdateAttempts,
} from './deviceIdentity'

export type { UrgentGiveUpMotive, UrgentUpdateMode }

let sessionAttempts: UrgentUpdateAttempts | null = null

function progress(record: UrgentUpdateAttempts): number {
  return record.count + record.checks
}

/** Lo mas avanzado entre el disco y la memoria de esta sesion, para ESTA version. */
function currentAttempts(): UrgentUpdateAttempts | null {
  const disk = readUrgentUpdateAttempts()
  const memory = sessionAttempts
  if (disk === null) return memory
  if (memory === null) return disk
  if (memory.from !== disk.from) return memory.from === APP_VERSION ? memory : disk
  return progress(memory) > progress(disk) ? memory : disk
}

function saveAttempts(next: UrgentUpdateAttempts | null): void {
  sessionAttempts = next
  storeUrgentUpdateAttempts(next)
}

/** Estado actual: `none`, `urgent` o `gave_up` (ver `minimumVersion.ts`). */
export function currentUrgentUpdateMode(): UrgentUpdateMode {
  return decideUrgentUpdateMode({
    current: APP_VERSION,
    minimum: readMinimumAppVersion(),
    attempts: currentAttempts(),
  })
}

/** Por que se rindio, o `null` si no se ha rendido. Lo usa el diagnostico. */
export function currentUrgentGiveUpMotive(): UrgentGiveUpMotive | null {
  return urgentGiveUpReason(APP_VERSION, currentAttempts())
}

/** Anota una recarga urgente ANTES de hacerla: si la pagina muere, el intento ya cuenta. */
export function recordUrgentUpdateAttempt(): void {
  const minimum = readMinimumAppVersion()
  if (minimum === null) return
  saveAttempts(nextUrgentAttempts(APP_VERSION, minimum, currentAttempts()))
}

/**
 * Una comprobacion urgente (`registration.update()`) RESUELTA sin que apareciera
 * version nueva. Las que fallan por red no cuentan: una tablet sin conexion no
 * puede rendirse por eso.
 */
export function recordUrgentUpdateCheck(): void {
  const minimum = readMinimumAppVersion()
  if (minimum === null) return
  saveAttempts(nextUrgentChecks(APP_VERSION, minimum, currentAttempts()))
}

/** Ha aparecido una version nueva: las comprobaciones en vacio dejan de contar. */
export function resetUrgentUpdateChecks(): void {
  const next = withoutUrgentChecks(APP_VERSION, currentAttempts())
  if (next !== null) saveAttempts(next)
}

/** Solo pruebas. */
export function resetUrgentUpdateSession(): void {
  sessionAttempts = null
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
  if (currentUrgentUpdateMode() === 'none') saveAttempts(null)
  notifyMinimumAppVersionReceived()
}
