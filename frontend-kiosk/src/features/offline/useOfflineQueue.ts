// El montaje de la cola offline y su enchufe a la pantalla.
//
// EL CONTROLADOR ES UNICO POR TABLET, y eso no es comodidad: dos controladores
// serian dos drenajes sobre la misma cola, enviando los mismos escaneos a la
// vez. El servidor los deduplicaria por `scan_id` —regla dura 8— pero el
// quiosco estaria gastando radio y bateria por duplicado en el peor momento
// posible. Una tablet, una cola, un drenaje.
//
// QUE SE LIMPIA AL DESMONTAR Y QUE NO. La SUSCRIPCION de la pantalla y los
// escuchadores de eventos, si: son de la vista y se van con ella. El DRENAJE,
// no. Parar de sincronizar porque alguien navegue a otra pantalla dejaria
// fichajes retenidos sin que nadie lo pidiera. El drenaje solo para en
// `disposeOfflineQueue()`, que hoy solo usan las pruebas.

import type { Ref } from 'vue'
import { onUnmounted, ref } from 'vue'
import type { RosterLookupPort, ScanSubmissionPort } from '@/features/scan/application/ports'
import type { ApiClient } from '@/shared/api/client'
import type { ConnectivityController } from '@/shared/connectivity/useConnectivity'
import type { ClientErrorCode, ErrorReporter } from '@/shared/telemetry/errorReporter'
import { readDeviceToken } from '@/shared/telemetry/deviceIdentity'
import type { KioskTelemetrySnapshot } from '@/shared/telemetry/heartbeat'
import type { CachedRoster, RosterDiagnostic } from './application/cachedRoster'
import { createCachedRoster, ROSTER_REFRESH_MS } from './application/cachedRoster'
import type { QueueStats, ScanQueue } from './application/scanQueue'
import { createScanQueue } from './application/scanQueue'
import type { SyncDiagnostic, SyncRunner } from './application/syncRunner'
import { createSyncRunner } from './application/syncRunner'
import { canApplyUpdate } from './domain/updateWindow'
import { createDexieQueueStorage, openKioskDatabase } from './infrastructure/dexieStorage'

export interface OfflineQueueController {
  readonly submission: ScanSubmissionPort
  readonly roster: RosterLookupPort
  /** Ver `CachedRoster.pinSealingPublicKey()`. `null` = sin fichaje por PIN. */
  pinSealingPublicKey(): string | null
  /** Ver `CachedRoster.settled()`: si `pinSealingPublicKey()` en `null` es definitivo. */
  rosterSettled(): boolean
  stats(): QueueStats
  subscribe(listener: (stats: QueueStats) => void): () => void
  onSyncing(listener: (syncing: boolean) => void): () => void
  onReachability(listener: (reachable: boolean) => void): () => void
  /**
   * Se llama tras cada `load()`/`refresh()` del padron, con exito o sin el: es
   * lo que permite que la pantalla reaccione en cuanto se sepa si esta
   * instalacion ofrece fichaje por PIN, sin que nadie tenga que sondear.
   */
  onRosterUpdated(listener: () => void): () => void
  /** Lo que el latido declara de la cola: `pending_queue_size` y `oldest_pending_at`. */
  telemetry(appVersion: string): KioskTelemetrySnapshot
  /** Puerta del paso 11: una version nueva no se aplica en un cambio de turno. */
  canUpdateNow(now?: Date): boolean
  wakeNow(): void
  dispose(): Promise<void>
}

export interface OfflineQueueOptions {
  readonly api: ApiClient
  readonly reporter: ErrorReporter
  readonly deviceToken?: () => string | null
  readonly databaseName?: string
}

const SYNC_DIAGNOSTIC_CODES = {
  'sync.transport_failed': 'kiosk.offline.sync_failed',
  'sync.unauthorized': 'kiosk.offline.sync_unauthorized',
  'sync.throttled': 'kiosk.offline.sync_throttled',
  'sync.malformed_response': 'kiosk.offline.malformed_batch_response',
  'sync.item_not_processed': 'kiosk.offline.item_not_processed',
  'sync.confirm_not_persisted': 'kiosk.offline.confirm_not_persisted',
} as const satisfies Record<SyncDiagnostic, ClientErrorCode>

const ROSTER_DIAGNOSTIC_CODES = {
  'roster.decrypt_failed': 'kiosk.roster.decrypt_failed',
  'roster.fetch_failed': 'kiosk.roster.fetch_failed',
  'roster.not_cacheable': 'kiosk.roster.not_cacheable',
} as const satisfies Record<RosterDiagnostic, ClientErrorCode>

export function createOfflineQueueController(options: OfflineQueueOptions): OfflineQueueController {
  const reporter = options.reporter
  const syncingListeners = new Set<(syncing: boolean) => void>()
  const reachabilityListeners = new Set<(reachable: boolean) => void>()
  const rosterUpdateListeners = new Set<() => void>()
  const notifyRosterUpdated = (): void => {
    for (const listener of rosterUpdateListeners) listener()
  }

  const queue: ScanQueue = createScanQueue({
    openStorage: () => createDexieQueueStorage(openKioskDatabase(options.databaseName)),
    onStorageFailure: (reason) =>
      reporter.report('kiosk.offline.storage_unavailable', { reason, durable: false }),
  })

  const runner: SyncRunner = createSyncRunner({
    api: options.api,
    queue,
    onSyncing: (syncing) => {
      for (const listener of syncingListeners) listener(syncing)
    },
    onReachability: (reachable) => {
      for (const listener of reachabilityListeners) listener(reachable)
    },
    onDiagnostic: (code, context) => reporter.report(SYNC_DIAGNOSTIC_CODES[code], context),
  })

  const roster: CachedRoster = createCachedRoster({
    api: options.api,
    storage: () => queue.storage(),
    deviceToken: options.deviceToken ?? readDeviceToken,
    onDiagnostic: (code, context) => reporter.report(ROSTER_DIAGNOSTIC_CODES[code], context),
  })

  const wake = (): void => runner.wakeNow()
  let rosterTimer: ReturnType<typeof setInterval> | null = null

  if (typeof window !== 'undefined') {
    window.addEventListener('online', wake)
    document.addEventListener('visibilitychange', wake)
  }

  runner.start()
  // OJO: el aviso solo sale DESPUES de `refresh()`, no tras `load()` a secas.
  // `load()` es una lectura de disco que en un dispositivo recien emparejado
  // no tiene nada que leer todavia; avisar en ese punto diria «esta
  // instalacion no ofrece PIN» cuando lo unico que pasa es que aun no se ha
  // preguntado al servidor (ver `pinSealingKnown` en `useOfflineQueue`).
  void roster
    .load()
    .then(() => roster.refresh())
    .then(() => notifyRosterUpdated())
  rosterTimer = setInterval(() => {
    void roster.refresh().then(() => notifyRosterUpdated())
  }, ROSTER_REFRESH_MS)

  return {
    submission: { submit: (scan) => runner.submit(scan) },
    roster: roster.port,
    pinSealingPublicKey: () => roster.pinSealingPublicKey(),
    rosterSettled: () => roster.settled(),

    stats: () => queue.stats(),
    subscribe: (listener) => queue.subscribe(listener),

    onSyncing(listener) {
      syncingListeners.add(listener)
      return () => {
        syncingListeners.delete(listener)
      }
    },

    onReachability(listener) {
      reachabilityListeners.add(listener)
      return () => {
        reachabilityListeners.delete(listener)
      }
    },

    onRosterUpdated(listener) {
      rosterUpdateListeners.add(listener)
      return () => {
        rosterUpdateListeners.delete(listener)
      }
    },

    telemetry(appVersion) {
      const stats = queue.stats()
      return {
        appVersion,
        pendingQueueSize: stats.size,
        oldestPendingAt: stats.oldestOccurredAt ?? undefined,
      }
    },

    canUpdateNow(now = new Date()) {
      return canApplyUpdate({ now, pendingScans: queue.stats().size })
    },

    wakeNow: wake,

    async dispose() {
      runner.stop()
      if (rosterTimer !== null) {
        clearInterval(rosterTimer)
        rosterTimer = null
      }
      if (typeof window !== 'undefined') {
        window.removeEventListener('online', wake)
        document.removeEventListener('visibilitychange', wake)
      }
      syncingListeners.clear()
      reachabilityListeners.clear()
      rosterUpdateListeners.clear()
      queue.storage().close()
    },
  }
}

let singleton: OfflineQueueController | null = null

/** Una tablet, una cola. Ver la cabecera de este fichero. */
export function getOfflineQueueController(options: OfflineQueueOptions): OfflineQueueController {
  singleton ??= createOfflineQueueController(options)
  return singleton
}

/**
 * Fichajes sin sincronizar, sin abrir nada. Devuelve `0` si la cola todavia no
 * se ha montado: en ese caso no hay ninguna, y decir otra cosa seria inventar.
 * La usa la puerta de actualizacion del service worker desde `main.ts`.
 */
export function pendingScanCount(): number {
  return singleton?.stats().size ?? 0
}

/** Cierra el controlador unico. Solo pruebas y desvinculacion del dispositivo. */
export async function disposeOfflineQueue(): Promise<void> {
  if (singleton === null) return
  const current = singleton
  singleton = null
  await current.dispose()
}

export interface UseOfflineQueueOptions extends OfflineQueueOptions {
  readonly connectivity: ConnectivityController
}

export interface UseOfflineQueue {
  readonly submission: ScanSubmissionPort
  readonly roster: RosterLookupPort
  readonly pendingCount: Readonly<Ref<number>>
  readonly syncing: Readonly<Ref<boolean>>
  telemetry(appVersion: string): KioskTelemetrySnapshot
  /**
   * Reactivo, no un metodo: la pantalla principal decide si ofrece «¿Sin
   * tarjeta?» (y la de PIN si tiene algo que teclear) en cuanto el padron
   * llega, sin sondear nada (RF-AT-11, ADR-017).
   */
  readonly pinSealingPublicKey: Readonly<Ref<string | null>>
  /**
   * Espejo reactivo de `CachedRoster.settled()` (via
   * `OfflineQueueController.rosterSettled()`): `false` mientras el padron no
   * ha completado NI UN `refresh()` contra el servidor. Antes de eso,
   * `pinSealingPublicKey` en `null` no significa «esta instalacion no ofrece
   * PIN»: significa «todavia no se sabe». La pantalla de PIN lo usa para no
   * redirigirse sola a la de tarjeta en el instante de montarse tras una
   * recarga (ADR-017): confundir «aun no lo se» con «no existe» expulsaria a
   * alguien de una via que si tiene.
   *
   * Se lee del CONTROLADOR, no se infiere de `pinSealingPublicKey`: el
   * controlador es un singleton por tablet (una cola, un drenaje) que puede
   * llevar ya un ciclo completo resuelto cuando esta pantalla se monta —
   * llegar aqui tras haber estado antes en la de escaneo, por ejemplo — y en
   * ese caso no hay nada que esperar.
   */
  readonly pinSealingKnown: Readonly<Ref<boolean>>
}

/**
 * Enchufa la cola a la pantalla: contador de pendientes e indicador de red
 * (RF-KI-04). Solo desengancha lo que es de la vista al desmontar.
 */
export function useOfflineQueue(options: UseOfflineQueueOptions): UseOfflineQueue {
  const controller = getOfflineQueueController(options)
  const pendingCount = ref(controller.stats().size)
  const syncing = ref(false)
  const pinSealingPublicKey = ref(controller.pinSealingPublicKey())
  // Se pregunta al controlador si YA completo un ciclo de resolucion, no se
  // infiere de si hay clave: una instalacion sin PIN tiene la clave en `null`
  // tanto si no se sabe todavia como si ya se sabe con certeza, y son dos
  // cosas distintas (ver el comentario de `pinSealingKnown` en la interfaz).
  const pinSealingKnown = ref(controller.rosterSettled())

  const unsubscribes = [
    controller.subscribe((stats) => {
      pendingCount.value = stats.size
      options.connectivity.setPendingCount(stats.size)
    }),
    controller.onSyncing((value) => {
      syncing.value = value
    }),
    controller.onReachability((reachable) => {
      options.connectivity.reportReachability(reachable)
    }),
    controller.onRosterUpdated(() => {
      pinSealingPublicKey.value = controller.pinSealingPublicKey()
      pinSealingKnown.value = controller.rosterSettled()
    }),
  ]

  onUnmounted(() => {
    for (const unsubscribe of unsubscribes) unsubscribe()
  })

  return {
    submission: controller.submission,
    roster: controller.roster,
    pendingCount,
    syncing,
    telemetry: (appVersion) => controller.telemetry(appVersion),
    pinSealingPublicKey,
    pinSealingKnown,
  }
}
