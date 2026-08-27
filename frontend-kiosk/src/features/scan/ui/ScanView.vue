<script setup lang="ts">
// Pantalla de escaneo del quiosco. Es la unica pantalla que ve un empleado.
//
// Disposicion pensada para una tablet de pared operada con UNA MANO y con
// GUANTES (RF-KI-06, doc 01 §6.5):
//
//   arriba    estado de conexion e idioma — informacion, se mira, no se toca
//   centro    la camara, grande, y encima la confirmacion a pantalla completa
//   abajo     aviso de privacidad y linterna — lo unico que se toca, al alcance
//             del pulgar, con objetivos de 48 px
//
// Nada de esto exige interaccion para fichar: la camara arranca sola y decodifica
// en continuo (RF-KI-02). Los botones son accesorios.
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { createApiClient } from '@/shared/api/client'
import { readPrivacyNoticeConfig } from '@/shared/config/privacy'
import { useConnectivity } from '@/shared/connectivity/useConnectivity'
import { APP_VERSION, resolveDeviceId } from '@/shared/telemetry/deviceIdentity'
import { createErrorReporter } from '@/shared/telemetry/errorReporter'
import { createHeartbeatScheduler } from '@/shared/telemetry/heartbeat'
import ConnectionStatusBadge from '@/shared/ui/ConnectionStatusBadge.vue'
import LanguageSelector from '@/shared/ui/LanguageSelector.vue'
import PrivacyNoticePanel from '@/shared/ui/PrivacyNoticePanel.vue'
import { useOfflineQueue } from '@/features/offline/useOfflineQueue'
import { createScanPipeline } from '../application/scanPipeline'
import { useQrScanner } from '../composables/useQrScanner'
import { useScanSessionWithCleanup } from '../composables/useScanSession'
import { useScanSound } from '../composables/useScanSound'
import { useWakeLock } from '../composables/useWakeLock'
import ScanConfirmationPanel from './ScanConfirmationPanel.vue'

const { t } = useI18n()

const video = ref<HTMLVideoElement | null>(null)

const deviceId = resolveDeviceId()
const reporter = createErrorReporter({ appVersion: APP_VERSION, deviceId })
const connectivity = useConnectivity()
const privacyConfig = readPrivacyNoticeConfig()

const api = createApiClient({
  ...(import.meta.env.VITE_API_BASE_URL === undefined
    ? {}
    : { baseUrl: import.meta.env.VITE_API_BASE_URL }),
})

// La cola de IndexedDB (tarea 1.9). El escaneo se encola ANTES de confirmar y
// el contador de pendientes del indicador sale del tamano real de la cola, no
// de una cuenta en memoria que un reinicio se llevaria por delante.
const offline = useOfflineQueue({ api, reporter, connectivity })

const sound = useScanSound({
  onBlocked: (context) => reporter.report('kiosk.audio.blocked', context),
})

const pipeline = createScanPipeline({
  submission: offline.submission,
  deviceId,
  // Padron cacheado y CIFRADO (RL-12). Si no reconoce la tarjeta devuelve
  // `null`, el quiosco encola igual y confirma «pendiente de validar»: es la
  // «degradacion honesta» del §6, nunca un rechazo.
  roster: offline.roster,
  onSettled: (confirmation) => session.settle(confirmation),
  onError: (_code, context) => reporter.report('kiosk.scan.submit_failed', context),
})

const session = useScanSessionWithCleanup({ pipeline, sound })

const scanner = useQrScanner({
  video,
  onDecoded: (text) => session.accept(text),
  onDiagnostic: (code, context) => {
    switch (code) {
      case 'camera.permission_denied':
        reporter.report('kiosk.camera.permission_denied', context)
        return
      case 'camera.unavailable':
        reporter.report('kiosk.camera.unavailable', context)
        return
      case 'scanner.start_failed':
        reporter.report('kiosk.scanner.start_failed', context)
        return
      case 'scanner.decoder_load_failed':
        reporter.report('kiosk.scanner.decoder_load_failed', context)
        return
      case 'scanner.watchdog_restart':
        reporter.report('kiosk.scanner.watchdog_restart', context)
        return
    }
  },
})

const wakeLock = useWakeLock({
  onDenied: (context) => reporter.report('kiosk.wake_lock.denied', context),
})

const heartbeat = createHeartbeatScheduler({
  api,
  reporter,
  // `pending_queue_size` y `oldest_pending_at` salen de la cola real: es lo que
  // convierte «hay 37 pendientes» en «el mas antiguo es de hace tres horas»,
  // que es la diferencia entre una sincronizacion en curso y un quiosco que
  // lleva media jornada incomunicado.
  snapshot: () => offline.telemetry(APP_VERSION),
})

const cameraFailed = computed(
  () => scanner.state.value === 'denied' || scanner.state.value === 'unavailable',
)

onMounted(() => {
  void scanner.start()
  void wakeLock.request()
  heartbeat.start()
})

// El escaner y el bloqueo de pantalla se limpian solos en sus composables. El
// latido no: es un `setInterval` propio de esta pantalla y hay que pararlo, o
// sobrevive a la navegacion y late dos veces por minuto por cada montaje.
onUnmounted(() => {
  heartbeat.stop()
  scanner.stop()
})
</script>

<template>
  <main class="flex h-dvh w-full flex-col bg-kiosk-surface text-kiosk-text">
    <h1 class="kiosk-sr-only">{{ t('app.title') }}</h1>

    <header class="flex items-center justify-between gap-4 px-6 py-4">
      <ConnectionStatusBadge
        :status="connectivity.status.value"
        :pending-count="connectivity.pendingCount.value"
        :syncing="offline.syncing.value"
      />
      <LanguageSelector />
    </header>

    <section class="relative min-h-0 flex-1 overflow-hidden">
      <video
        ref="video"
        class="h-full w-full object-cover"
        playsinline
        muted
        autoplay
        aria-hidden="true"
        data-testid="scan-video"
      ></video>

      <!-- Instrucciones. Se ocultan cuando hay confirmacion en pantalla. -->
      <div
        v-if="session.confirmation.value === null && !cameraFailed"
        class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center gap-4 bg-slate-950/55 px-10 text-center"
        data-testid="scan-idle"
      >
        <p class="text-confirm-lg font-bold">{{ t('scan.idle.title') }}</p>
        <p class="text-confirm-sm text-kiosk-text-muted">{{ t('scan.idle.subtitle') }}</p>
        <p v-if="scanner.state.value === 'starting'" class="text-confirm-sm">
          {{ t('scan.camera.starting') }}
        </p>
        <p
          v-if="connectivity.status.value === 'offline'"
          class="text-confirm-sm text-kiosk-text-muted"
        >
          {{ t('connection.offlineHint') }}
        </p>
      </div>

      <!-- Camara caida. NO bloquea nada: se avisa y se ofrece reintentar; el
           fichaje de respaldo por PIN es la tarea 1.12. -->
      <div
        v-if="cameraFailed"
        class="absolute inset-0 flex flex-col items-center justify-center gap-6 bg-kiosk-error px-10 text-center"
        role="alert"
        data-testid="camera-failure"
      >
        <p class="text-confirm-lg font-bold">
          {{
            scanner.state.value === 'denied'
              ? t('scan.camera.deniedTitle')
              : t('scan.camera.unavailableTitle')
          }}
        </p>
        <p class="text-confirm-sm">
          {{
            scanner.state.value === 'denied'
              ? t('scan.camera.deniedBody')
              : t('scan.camera.unavailableBody')
          }}
        </p>
        <button
          type="button"
          class="kiosk-touch rounded-lg bg-white px-8 text-confirm-sm font-semibold text-slate-900"
          @click="scanner.start()"
        >
          {{ t('scan.camera.retry') }}
        </button>
      </div>

      <ScanConfirmationPanel
        v-if="session.confirmation.value !== null"
        class="absolute inset-0"
        :confirmation="session.confirmation.value"
      />
    </section>

    <footer class="flex items-end justify-between gap-4 px-6 py-4">
      <PrivacyNoticePanel class="min-w-0 flex-1" :config="privacyConfig" />

      <button
        v-if="scanner.torchAvailable.value"
        type="button"
        class="kiosk-touch shrink-0 rounded-lg bg-slate-700 px-5 text-base font-medium text-slate-50"
        :aria-pressed="scanner.torchOn.value"
        data-testid="torch-toggle"
        @click="scanner.toggleTorch()"
      >
        {{ scanner.torchOn.value ? t('scan.camera.torchOff') : t('scan.camera.torchOn') }}
      </button>
    </footer>

    <!-- Sonda del presupuesto de rendimiento (Anexo A): el E2E lee de aqui los
         milisegundos entre decodificar y confirmar. RNF-P-03 exige < 300 ms. -->
    <span
      v-if="session.lastLatencyMs.value !== null"
      class="kiosk-sr-only"
      data-testid="scan-latency-ms"
      >{{ session.lastLatencyMs.value }}</span
    >
  </main>
</template>
