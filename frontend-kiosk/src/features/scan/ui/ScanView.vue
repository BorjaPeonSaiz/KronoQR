<script setup lang="ts">
// Pantalla de escaneo del quiosco. Es la unica pantalla que ve un empleado.
//
// Disposicion pensada para una tablet de pared operada con UNA MANO y con
// GUANTES (RF-KI-06, doc 01 §6.5):
//
//   arriba    estado de conexion e idioma — informacion, se mira, no se toca
//   centro    la camara, grande, con la confirmacion a pantalla completa
//             encima. Bajo el subtitulo de instrucciones vive el acceso al
//             fichaje por PIN («Ficha con tu código y PIN»): es un
//             RESPALDO (RF-AT-11 lo marca para revision), asi que se pinta
//             en estilo secundario y SOLO cuando no hay confirmacion en
//             pantalla — nunca compite con ella. Si la camara cae, ese mismo
//             enlace reaparece dentro del aviso de fallo, porque ahi deja de
//             ser un respaldo y pasa a ser la unica via (regla dura 19).
//   abajo     aviso de privacidad y linterna, al alcance del pulgar, con
//             objetivos de 48 px
//
// Nada de esto exige interaccion para fichar: la camara arranca sola y decodifica
// en continuo (RF-KI-02). Los botones son accesorios.
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { createApiClient } from '@/shared/api/client'
import { readPrivacyNoticeConfig } from '@/shared/config/privacy'
import { useConnectivity } from '@/shared/connectivity/useConnectivity'
import { APP_VERSION, readDeviceToken, resolveDeviceId } from '@/shared/telemetry/deviceIdentity'
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
  // Sin esto el quiosco nunca manda `Authorization`: padron, latido y fichajes
  // reciben 401 aunque la tablet este emparejada. Se lee en cada peticion
  // porque el token rota (doc 02 §7.3).
  deviceToken: readDeviceToken,
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
  <main class="flex h-dvh w-full flex-col bg-kq-kiosk-surface text-kq-kiosk-text">
    <h1 class="kiosk-sr-only">{{ t('app.title') }}</h1>

    <header class="flex items-center justify-between gap-4 px-6 py-4">
      <ConnectionStatusBadge
        :status="connectivity.status.value"
        :pending-count="connectivity.pendingCount.value"
        :syncing="offline.syncing.value"
      />
      <LanguageSelector />
    </header>

    <section class="relative min-h-0 flex-1 overflow-hidden" data-testid="scan-camera-section">
      <video
        ref="video"
        class="h-full w-full object-cover"
        playsinline
        muted
        autoplay
        aria-hidden="true"
        data-testid="scan-video"
      ></video>

      <!-- Instrucciones. Se ocultan cuando hay confirmacion en pantalla. SIN
           VELO: la camara se ve limpia, nada de "agujero" con `box-shadow`
           ni capa oscura sobre el fotograma entero (el cliente lo pidio
           explicitamente). En su lugar, texto y visor van en COLUMNA, cada
           bloque de texto sobre su propia banda semitransparente
           (`bg-kq-kiosk-surface/80`) SOLO detras de ese bloque, para que se
           lea sin tapar la imagen de la camara. Orden de arriba a abajo:
           titulo -> subtitulo -> enlace de PIN -> visor -> pista corta.
           El visor ocupa el espacio restante (`flex-1 min-h-0`) y su lado es
           `min(55vmin, alto-disponible)` via `h-[min(55vmin,100%)]`, para
           que quepa entero por debajo del bloque de texto tanto en tablets
           apaisadas (1280x800, 1024x768) como en vertical. -->
      <div
        v-if="session.confirmation.value === null && !cameraFailed"
        class="pointer-events-none absolute inset-0 flex flex-col items-center gap-4 px-6 py-4 text-center"
        data-testid="scan-idle"
      >
        <div
          class="flex flex-none flex-col items-center gap-3 rounded-kq bg-kq-kiosk-surface/80 px-6 py-4"
        >
          <p class="text-confirm-md font-heading font-bold" data-testid="scan-idle-title">
            {{ t('scan.idle.title') }}
          </p>
          <p class="text-confirm-sm text-kq-kiosk-text-muted" data-testid="scan-idle-subtitle">
            {{ t('scan.idle.subtitle') }}
          </p>

          <!-- Solo si la instalacion ofrece fichaje por PIN (ADR-017, tarea 1.12).
               Nunca deshabilitado con explicacion: si no hay clave, esta via no
               existe en esta instalacion, no existe "de momento". El bloque
               padre es `pointer-events-none`: el enlace necesita recuperar los
               eventos para poder tocarse. -->
          <RouterLink
            v-if="offline.pinSealingPublicKey.value !== null"
            :to="{ name: 'pin' }"
            class="kiosk-touch pointer-events-auto mt-1 inline-flex items-center justify-center rounded-kq-sm border border-kq-kiosk-border bg-kq-kiosk-surface-raised px-6 text-base font-semibold text-kq-kiosk-text"
            data-testid="pin-entry-link"
          >
            {{ t('pin.entryButton') }}
          </RouterLink>

          <p v-if="scanner.state.value === 'starting'" class="text-confirm-sm">
            {{ t('scan.camera.starting') }}
          </p>
          <p
            v-if="connectivity.status.value === 'offline'"
            class="text-confirm-sm text-kq-kiosk-text-muted"
          >
            {{ t('connection.offlineHint') }}
          </p>
        </div>

        <!-- El visor: un cuadrado que guia donde poner la tarjeta, SIN
             relleno, solo las cuatro esquinas. Decorativo (`aria-hidden`):
             quien usa lector de pantalla ya tiene la misma idea en
             `scan.idle.subtitle`, que si se anuncia. -->
        <div
          class="flex w-full min-h-0 flex-1 flex-col items-center justify-center gap-3"
          aria-hidden="true"
        >
          <div
            class="relative aspect-square h-[min(55vmin,100%)] max-h-full max-w-full"
            data-testid="scan-viewfinder"
          >
            <span
              class="absolute -top-1 -left-1 h-10 w-10 rounded-tl-kq-lg border-t-4 border-l-4 border-kq-kiosk-primary"
            ></span>
            <span
              class="absolute -top-1 -right-1 h-10 w-10 rounded-tr-kq-lg border-t-4 border-r-4 border-kq-kiosk-primary"
            ></span>
            <span
              class="absolute -bottom-1 -left-1 h-10 w-10 rounded-bl-kq-lg border-b-4 border-l-4 border-kq-kiosk-primary"
            ></span>
            <span
              class="absolute -right-1 -bottom-1 h-10 w-10 rounded-br-kq-lg border-r-4 border-b-4 border-kq-kiosk-primary"
            ></span>
          </div>
          <p class="flex-none rounded-kq bg-kq-kiosk-surface/80 px-6 py-2 text-base">
            {{ t('scan.idle.viewfinderHint') }}
          </p>
        </div>
      </div>

      <!-- Camara caida. NO bloquea nada: se avisa, se ofrece reintentar y, con
           la tarjeta inutilizable, el PIN pasa a ser la unica via de fichaje
           (regla dura 19). -->
      <div
        v-if="cameraFailed"
        class="absolute inset-0 flex flex-col items-center justify-center gap-6 bg-kiosk-error px-10 text-center"
        role="alert"
        data-testid="camera-failure"
      >
        <p class="text-confirm-lg font-heading font-bold">
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
          class="kiosk-touch rounded-kq-sm bg-kq-kiosk-primary-strong px-8 text-confirm-sm font-semibold text-kq-kiosk-on-primary"
          @click="scanner.start()"
        >
          {{ t('scan.camera.retry') }}
        </button>

        <!-- Con la camara caida, el PIN deja de ser una via alternativa y pasa a
             ser la UNICA para fichar (regla dura 19): se ofrece aqui tambien.
             Testid distinto del de `scan-idle` para que, con los dos bloques
             excluyentes entre si, nunca haya dos enlaces visibles a la vez. -->
        <RouterLink
          v-if="offline.pinSealingPublicKey.value !== null"
          :to="{ name: 'pin' }"
          class="kiosk-touch mt-2 inline-flex items-center justify-center rounded-kq-sm border border-kq-kiosk-border bg-kq-kiosk-surface-raised px-6 text-confirm-sm font-semibold text-kq-kiosk-text"
          data-testid="pin-entry-link-fallback"
        >
          {{ t('pin.entryButton') }}
        </RouterLink>
      </div>

      <ScanConfirmationPanel
        v-if="session.confirmation.value !== null"
        class="absolute inset-0"
        :confirmation="session.confirmation.value"
      />
    </section>

    <footer class="flex items-end justify-between gap-4 px-6 py-4">
      <!-- El acceso por PIN vive ahora en el centro de la pantalla de escaneo
           (junto al subtitulo, y como respaldo cuando la camara falla), no
           aqui: el aviso de privacidad se queda solo y ocupa todo el ancho. -->
      <PrivacyNoticePanel class="min-w-0 flex-1" :config="privacyConfig" />

      <button
        v-if="scanner.torchAvailable.value"
        type="button"
        class="kiosk-touch shrink-0 rounded-kq-sm border border-kq-kiosk-border bg-kq-kiosk-surface-raised px-5 text-base font-medium text-kq-kiosk-text"
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
