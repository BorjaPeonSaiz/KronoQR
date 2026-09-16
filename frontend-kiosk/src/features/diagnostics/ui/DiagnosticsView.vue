<script setup lang="ts">
// Pantalla de diagnostico del quiosco (RF-KI-08, tarea 3.3).
//
// SE ABRE CON UNA PULSACION LARGA sobre el reloj de `ScanView`/`PairingView`
// (`ClockDiagnosticsTrigger.vue`) y NUNCA bloquea el fichaje (regla dura 19):
// tiene su propio boton de vuelta y se cierra sola a los 120 s sin
// interaccion. Ruta exceptuada del guard de emparejamiento (`router/index.ts`,
// decision 7 de la tarea): una tablet sin vincular tambien se diagnostica.
//
// CODIGO DE SERVICIO EN LOCAL, SIN RED (decision 6). Si no hay huella cacheada
// (`readServiceCodeHash`) la pantalla se abre directamente: o esta instalacion
// no tiene codigo configurado, o esta tablet todavia no ha latido nunca -los
// dos casos son «nada que proteger todavia» (decision 7)-.
//
// NUNCA UN NOMBRE (regla dura 21). Todo lo de aqui se identifica por
// `device_id`; el token nunca aparece en claro, solo sus ocho primeros hex.
//
// SIN TOKEN, SIN CONTROLADOR (revision de la 3.3, segunda vuelta). Una tablet
// vista desde `/pair` no tiene nada que sincronizar ni ningun padron que
// pedir: llamar a `getOfflineQueueController` igualmente arrancaria el
// `SyncRunner` y el refresco periodico del padron sin proposito. Con
// `paired === false` esta pantalla ni siquiera pide el controlador; lee lo
// que YA hay en disco con `pendingScanCount()` (`0` si ninguna pantalla lo ha
// montado todavia en esta sesion) y muestra «sin emparejar» donde toca.
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { createApiClient } from '@/shared/api/client'
import { readPrivacyNoticeConfig } from '@/shared/config/privacy'
import { sha256Hex } from '@/shared/crypto/sha256'
import { useConnectivity } from '@/shared/connectivity/useConnectivity'
import { useBatteryStatus } from '@/shared/media/useBatteryStatus'
import {
  APP_VERSION,
  clearDeviceToken,
  readDeviceName,
  readDeviceToken,
  readDeviceTokenExpiresAt,
  readServiceCodeHash,
  resolveDeviceId,
} from '@/shared/telemetry/deviceIdentity'
import { getErrorReporter } from '@/shared/telemetry/errorReporter'
import { createHeartbeatScheduler, getLastHeartbeatResult } from '@/shared/telemetry/heartbeat'
import LanguageSelector from '@/shared/ui/LanguageSelector.vue'
import { getOfflineQueueController, pendingScanCount } from '@/features/offline/useOfflineQueue'
import type { QueueStats } from '@/features/offline/application/scanQueue'
import { EMPTY_STATS } from '@/features/offline/application/scanQueue'
import { useCamera } from '@/features/scan/composables/useCamera'
import { useWakeLock } from '@/features/scan/composables/useWakeLock'
import PinNumericKeypad from '@/features/pin/ui/PinNumericKeypad.vue'
import type { ServiceCodeAttemptResult } from '../application/serviceCodeGate'
import { createServiceCodeGate } from '../application/serviceCodeGate'
import { buildDiagnosticsSnapshot } from '../application/diagnosticsSnapshot'

const { t, locale } = useI18n()
const router = useRouter()

const MIN_CODE_LENGTH = 8
const MAX_CODE_LENGTH = 12
/** Vuelve sola al fichaje si nadie toca nada (decision 8 de la tarea 3.3). */
const AUTO_RETURN_MS = 120_000
/**
 * Techo absoluto desde la APERTURA, interaccion o no (revision de la 3.3,
 * segunda vuelta): sin esto, alguien podria dejar la pantalla abierta
 * indefinidamente con solo tocar algo cada dos minutos. Cinco veces el
 * intervalo de inactividad es margen de sobra para leer todas las secciones
 * con calma sin que la pantalla se convierta en algo que se pueda dejar
 * abierto para siempre.
 */
const AUTO_RETURN_ABSOLUTE_CAP_MS = 5 * 60_000

const deviceId = resolveDeviceId()
const reporter = getErrorReporter({ appVersion: APP_VERSION, deviceId })
const connectivity = useConnectivity()

const api = createApiClient({
  ...(import.meta.env.VITE_API_BASE_URL === undefined
    ? {}
    : { baseUrl: import.meta.env.VITE_API_BASE_URL }),
  deviceToken: readDeviceToken,
})

const token = readDeviceToken()
const paired = token !== null

// MISMO controlador que `ScanView.vue`/`PinView.vue` (singleton por tablet,
// `useOfflineQueue.ts`): si ya esta montado por la pantalla de la que se
// vino, esto devuelve el mismo objeto -y esta pantalla se suscribe la suya a
// `onDeviceRevoked`, sin importar si es la primera o la segunda en llegar
// (revision de la 3.3, segunda vuelta)-. `null` si la tablet no esta
// emparejada: ver la nota de cabecera.
const offlineController = paired ? getOfflineQueueController({ api, reporter }) : null
const queueStats = ref<QueueStats>(
  offlineController?.stats() ?? { ...EMPTY_STATS, size: pendingScanCount() },
)
const rosterGeneratedAt = ref(offlineController?.rosterGeneratedAt() ?? null)
const rosterEntryCount = ref(offlineController?.rosterEntryCount() ?? 0)
const pinAvailable = ref(offlineController?.pinSealingPublicKey() !== null && paired)
const syncing = ref(false)
/**
 * Senal VIVA del `SyncRunner` (`onReachability`), no una deduccion del ultimo
 * latido (fallo corregido en la revision de la 3.3: antes se congelaba en lo
 * que dijera `getLastHeartbeatResult()` en el instante de abrir la pantalla y
 * ya no se movia mientras estaba abierta).
 */
const reachable = ref<boolean | null>(null)

const unsubscribes: Array<() => void> = []
if (offlineController !== null) {
  unsubscribes.push(
    offlineController.subscribe((stats) => {
      queueStats.value = stats
    }),
    offlineController.onSyncing((value) => {
      syncing.value = value
    }),
    offlineController.onRosterUpdated(() => {
      rosterGeneratedAt.value = offlineController.rosterGeneratedAt()
      rosterEntryCount.value = offlineController.rosterEntryCount()
      pinAvailable.value = offlineController.pinSealingPublicKey() !== null
    }),
    offlineController.onReachability((value) => {
      reachable.value = value
    }),
    // Dispositivo desvinculado desde el panel (RF-PD-06, tarea 5.6): mismo
    // listener que `ScanView.vue`/`PinView.vue`. Si esta pantalla es la que
    // esta viva cuando la revocacion dispare, es ella la que limpia el token
    // y vuelve a `/pair`.
    offlineController.onDeviceRevoked(() => {
      clearDeviceToken()
      void router.replace({ name: 'pair' })
    }),
  )
}

// --- Puerta del codigo de servicio (decision 6) -----------------------------

const expectedHash = readServiceCodeHash()
type Phase = 'code' | 'locked' | 'content'
const phase = ref<Phase>(expectedHash === null ? 'content' : 'code')

const gate = expectedHash === null ? null : createServiceCodeGate({ deviceId, expectedHash })
const codeValue = ref('')
const attemptsLeft = ref<number | null>(null)
const rejected = ref(false)
const lockRemainingSeconds = ref(0)
let lockTicker: ReturnType<typeof setInterval> | null = null

const canSubmitCode = computed(
  () => codeValue.value.length >= MIN_CODE_LENGTH && codeValue.value.length <= MAX_CODE_LENGTH,
)

function pressDigit(digit: string): void {
  noteInteraction()
  if (codeValue.value.length >= MAX_CODE_LENGTH) return
  codeValue.value += digit
  rejected.value = false
}
function backspaceCode(): void {
  noteInteraction()
  codeValue.value = codeValue.value.slice(0, -1)
}
function clearCode(): void {
  noteInteraction()
  codeValue.value = ''
}

function stopLockTicker(): void {
  if (lockTicker === null) return
  clearInterval(lockTicker)
  lockTicker = null
}

function startLockTicker(): void {
  stopLockTicker()
  lockTicker = setInterval(() => {
    if (gate === null || !gate.isLocked()) {
      stopLockTicker()
      lockRemainingSeconds.value = 0
      phase.value = 'code'
      return
    }
    lockRemainingSeconds.value = Math.ceil(gate.remainingLockoutMs() / 1_000)
  }, 1_000)
}

function applyGateResult(result: ServiceCodeAttemptResult): void {
  if (result.outcome === 'accepted') {
    phase.value = 'content'
    return
  }
  if (result.outcome === 'rejected') {
    attemptsLeft.value = result.attemptsLeft
    rejected.value = true
    return
  }
  phase.value = 'locked'
  lockRemainingSeconds.value = Math.ceil(result.retryAfterMs / 1_000)
  startLockTicker()
}

function submitCode(): void {
  noteInteraction()
  if (gate === null || !canSubmitCode.value) return
  // El codigo sale del teclado y no vuelve: se limpia YA, antes de saber el
  // desenlace (mismo criterio que el PIN en `PinView.vue`).
  const attempted = codeValue.value
  codeValue.value = ''
  applyGateResult(gate.attempt(attempted))
}

// --- Camara (decision 9): flujo PROPIO de esta pantalla, se abre y se cierra
// aqui (`ScanView` ya se desmonto al navegar). ------------------------------

const camera = useCamera()
const cameraSettings = ref<MediaTrackSettings | null>(null)
// `label` es de `MediaStreamTrack`, no de `MediaTrackSettings`: dos objetos
// distintos del mismo track, y `getSettings()` no lo incluye.
const cameraLabel = ref<string | null>(null)

watch(
  () => camera.stream.value,
  (stream) => {
    cameraSettings.value = camera.settings()
    const label = stream?.getVideoTracks()[0]?.label ?? ''
    cameraLabel.value = label === '' ? null : label
  },
)

const wakeLock = useWakeLock({
  onDenied: (context) => reporter.report('kiosk.wake_lock.denied', context),
})
const battery = useBatteryStatus()

watch(
  phase,
  (value) => {
    if (value !== 'content') return
    void camera.start()
    void wakeLock.request()
  },
  { immediate: true },
)

// --- Latido PROPIO de esta pantalla (revision de la 3.3, segunda vuelta) ---
//
// Mientras el diagnostico esta abierto, el quiosco tiene que seguir
// latiendo -si no, un empleado con la tablet colgada de la pared y alguien
// mirando el diagnostico durante varios minutos apareceria como «sin latido»
// en el panel-, y `heartbeat.start()` hace tambien de «lanza un beat() al
// abrir» (su primera llamada es sincrona con el montaje, no espera al primer
// ciclo del intervalo): refresca hora, desfase y `service_code_hash` en
// cuanto se entra. Solo si hay token: sin el, un latido solo conseguiria un
// `401` predecible. Sin red, `beat()` no cambia nada de lo que ya se mostraba
// (regla dura 19: nunca un fallo de red convierte una pantalla informativa en
// una pantalla rota).
const lastHeartbeat = ref(getLastHeartbeatResult())

const heartbeat = paired
  ? createHeartbeatScheduler({
      api,
      reporter,
      snapshot: () => ({
        ...(offlineController?.telemetry(APP_VERSION) ?? {
          appVersion: APP_VERSION,
          pendingQueueSize: pendingScanCount(),
        }),
        ...(battery.level.value === null ? {} : { batteryLevel: battery.level.value }),
        ...(battery.charging.value === null ? {} : { batteryCharging: battery.charging.value }),
      }),
      onAuthOutcome: (unauthorized) => offlineController?.reportAuthOutcome(unauthorized),
      // Se recalcula tras CADA latido con exito, no solo al abrir: es lo que
      // mantiene «hora del ultimo latido correcto» y «desfase» vivos mientras
      // la pantalla sigue montada.
      onSkew: () => {
        lastHeartbeat.value = getLastHeartbeatResult()
      },
    })
  : null

// --- Otras fuentes, leidas una vez al abrir: informativas, no legales -------

const tokenShortId = token === null ? null : sha256Hex(token).slice(0, 8)
const serviceWorkerSupported = typeof navigator !== 'undefined' && 'serviceWorker' in navigator
const serviceWorkerActive =
  serviceWorkerSupported && typeof navigator !== 'undefined'
    ? navigator.serviceWorker.controller !== null
    : null
const privacyConfig = readPrivacyNoticeConfig()

const snapshot = computed(() =>
  buildDiagnosticsSnapshot({
    deviceId,
    paired,
    camera: {
      state: camera.state.value,
      settings: cameraSettings.value,
      torchAvailable: camera.torchAvailable.value,
    },
    network: {
      online: connectivity.status.value === 'online',
      reachable: reachable.value,
      lastHeartbeat: lastHeartbeat.value,
    },
    queue: {
      size: queueStats.value.size,
      oldestOccurredAt: queueStats.value.oldestOccurredAt,
      durable: queueStats.value.durable,
      syncing: syncing.value,
    },
    roster: {
      generatedAt: rosterGeneratedAt.value,
      entryCount: rosterEntryCount.value,
      pinAvailable: pinAvailable.value,
    },
    token: {
      present: token !== null,
      expiresAt: readDeviceTokenExpiresAt(),
      deviceName: readDeviceName(),
      shortId: tokenShortId,
    },
    appVersion: APP_VERSION,
    serviceWorkerActive,
    battery: {
      supported: battery.supported,
      level: battery.level.value,
      charging: battery.charging.value,
    },
    wakeLock: { supported: wakeLock.supported, active: wakeLock.active.value },
    pendingErrors: reporter.size(),
    privacyControllerConfigured: privacyConfig.controller !== null,
  }),
)

function formatInstant(iso: string | null): string {
  if (iso === null) return t('diagnostics.values.notAvailable')
  const parsed = new Date(iso)
  if (Number.isNaN(parsed.getTime())) return t('diagnostics.values.notAvailable')
  return new Intl.DateTimeFormat(locale.value, { dateStyle: 'short', timeStyle: 'medium' }).format(
    parsed,
  )
}

function yesNo(value: boolean | null): string {
  if (value === null) return t('diagnostics.values.unknown')
  return value ? t('diagnostics.values.yes') : t('diagnostics.values.no')
}

// --- Vuelta a fichar, sola o a mano (decision 8) ----------------------------
//
// EL REINICIO DE LOS 120 s SOLO CUENTA COMO INTERACCION SOBRE UN CONTROL REAL
// (revision de la 3.3, segunda vuelta): antes, un `@pointerdown.capture` en
// `<main>` reiniciaba la cuenta con CUALQUIER toque -incluido arrastrar el
// dedo por una zona vacia mientras se lee, que no es "seguir usando la
// pantalla" en el sentido que RF-KI-08 quiere proteger-. Ahora `noteInteraction`
// se llama solo desde el teclado numerico y los botones (`@click`), y el
// selector de idioma cuenta tambien porque solo contiene botones (delegacion
// por burbujeo desde el `<div>` que lo envuelve).
//
// Y UN TECHO ABSOLUTO (`AUTO_RETURN_ABSOLUTE_CAP_MS`), independiente de la
// interaccion: sin el, tocar algo cada minuto y medio dejaria la pantalla
// abierta para siempre.

let alive = true
let returnTimer: ReturnType<typeof setTimeout> | null = null
let absoluteCapTimer: ReturnType<typeof setTimeout> | null = null

function goBack(): void {
  // Si esta tablet no esta emparejada, el guard del router manda esto mismo
  // a `/pair` (`router/index.ts`): no hace falta distinguir aqui los dos casos.
  void router.replace({ name: 'home' })
}

function scheduleAutoReturn(): void {
  if (!alive) return
  if (returnTimer !== null) clearTimeout(returnTimer)
  returnTimer = setTimeout(() => {
    if (alive) goBack()
  }, AUTO_RETURN_MS)
}

function noteInteraction(): void {
  scheduleAutoReturn()
}

onMounted(() => {
  scheduleAutoReturn()
  absoluteCapTimer = setTimeout(() => {
    if (alive) goBack()
  }, AUTO_RETURN_ABSOLUTE_CAP_MS)
  heartbeat?.start()
})

onBeforeUnmount(() => {
  alive = false
  if (returnTimer !== null) clearTimeout(returnTimer)
  if (absoluteCapTimer !== null) clearTimeout(absoluteCapTimer)
  stopLockTicker()
  camera.stop()
  heartbeat?.stop()
  for (const unsubscribe of unsubscribes) unsubscribe()
})
</script>

<template>
  <main
    class="flex h-dvh w-full flex-col overflow-y-auto bg-kq-kiosk-surface text-kq-kiosk-text"
    data-testid="diagnostics-view"
  >
    <h1 class="kiosk-sr-only">{{ t('diagnostics.title') }}</h1>

    <header class="flex items-center justify-between gap-4 px-6 py-4">
      <p class="font-heading text-2xl font-bold" data-testid="diagnostics-heading">
        {{ t('diagnostics.title') }}
      </p>
      <!-- El selector solo contiene botones: un click aqui SIEMPRE es un
           control real burbujeando hasta este `div` (revision de la 3.3). -->
      <div @click="noteInteraction">
        <LanguageSelector />
      </div>
    </header>

    <!-- Codigo de servicio (decision 6): en LOCAL, sin red. -->
    <section
      v-if="phase === 'code' || phase === 'locked'"
      class="flex flex-1 flex-col items-center justify-center gap-6 px-10"
      data-testid="diagnostics-gate"
    >
      <p class="text-confirm-sm font-heading text-center font-bold">
        {{ t('diagnostics.gate.heading') }}
      </p>
      <p class="text-confirm-sm text-kq-kiosk-text-muted text-center">
        {{ t('diagnostics.gate.hint') }}
      </p>

      <template v-if="phase === 'code'">
        <div
          class="flex flex-wrap justify-center gap-2"
          role="status"
          :aria-label="t('diagnostics.gate.codeLabel')"
          data-testid="diagnostics-code-dots"
        >
          <span
            v-for="index in MAX_CODE_LENGTH"
            :key="index"
            aria-hidden="true"
            class="h-4 w-4 rounded-full border-2 border-kq-kiosk-border"
            :class="index <= codeValue.length ? 'bg-kq-kiosk-text' : 'bg-transparent'"
          ></span>
        </div>

        <p v-if="rejected" role="alert" class="text-confirm-sm font-semibold text-kiosk-notice">
          {{ t('diagnostics.gate.rejected', { count: attemptsLeft ?? 0 }, attemptsLeft ?? 0) }}
        </p>

        <PinNumericKeypad @digit="pressDigit" @backspace="backspaceCode" @clear="clearCode" />

        <button
          type="button"
          class="kiosk-touch w-full max-w-sm rounded-kq-sm bg-kq-kiosk-primary-strong text-xl font-semibold text-kq-kiosk-on-primary disabled:opacity-40"
          :disabled="!canSubmitCode"
          data-testid="diagnostics-code-confirm"
          @click="submitCode"
        >
          {{ t('diagnostics.gate.confirm') }}
        </button>
      </template>

      <p
        v-else
        role="status"
        aria-live="polite"
        class="text-confirm-sm font-semibold text-kiosk-notice"
        data-testid="diagnostics-locked"
      >
        {{ t('diagnostics.gate.locked', { seconds: lockRemainingSeconds }) }}
      </p>

      <button
        type="button"
        class="kiosk-touch rounded-kq-sm border border-kq-kiosk-border bg-kq-kiosk-surface-raised px-6 text-base font-medium text-kq-kiosk-text"
        data-testid="diagnostics-gate-back"
        @click="goBack"
      >
        {{ t('diagnostics.back') }}
      </button>
    </section>

    <!-- Contenido (decision 9): filas etiqueta -> valor, agrupadas por seccion. -->
    <section
      v-else
      class="flex flex-1 flex-col gap-6 overflow-y-auto px-6 py-4"
      data-testid="diagnostics-content"
    >
      <p
        v-if="expectedHash === null"
        role="status"
        class="text-confirm-sm text-kq-kiosk-text-muted"
        data-testid="diagnostics-no-code"
      >
        {{ t('diagnostics.gate.noCodeConfigured') }}
      </p>

      <article data-testid="diagnostics-section-camera">
        <h2 class="text-lg font-bold">{{ t('diagnostics.sections.camera') }}</h2>
        <dl class="text-confirm-sm grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1">
          <dt>{{ t('diagnostics.rows.camera.state') }}</dt>
          <dd data-testid="diagnostics-camera-state">{{ snapshot.camera.state }}</dd>
          <template v-if="snapshot.camera.settings !== null">
            <dt>{{ t('diagnostics.rows.camera.label') }}</dt>
            <dd>{{ cameraLabel ?? t('diagnostics.values.notAvailable') }}</dd>
            <dt>{{ t('diagnostics.rows.camera.resolution') }}</dt>
            <dd>
              {{ snapshot.camera.settings.width ?? '—' }}×{{
                snapshot.camera.settings.height ?? '—'
              }}
            </dd>
            <dt>{{ t('diagnostics.rows.camera.frameRate') }}</dt>
            <dd>
              {{ snapshot.camera.settings.frameRate ?? t('diagnostics.values.notAvailable') }}
            </dd>
            <dt>{{ t('diagnostics.rows.camera.facingMode') }}</dt>
            <dd>
              {{ snapshot.camera.settings.facingMode ?? t('diagnostics.values.notAvailable') }}
            </dd>
            <dt>{{ t('diagnostics.rows.camera.focusMode') }}</dt>
            <dd>
              {{ snapshot.camera.settings.focusMode ?? t('diagnostics.values.notAvailable') }}
            </dd>
            <dt>{{ t('diagnostics.rows.camera.zoom') }}</dt>
            <dd>{{ snapshot.camera.settings.zoom ?? t('diagnostics.values.notAvailable') }}</dd>
            <dt>{{ t('diagnostics.rows.camera.backgroundBlur') }}</dt>
            <dd>{{ yesNo(snapshot.camera.settings.backgroundBlur ?? null) }}</dd>
          </template>
          <dt>{{ t('diagnostics.rows.camera.torch') }}</dt>
          <dd>{{ yesNo(snapshot.camera.torchAvailable) }}</dd>
        </dl>
        <p
          v-for="warning in snapshot.camera.warnings"
          :key="warning"
          role="status"
          class="text-confirm-sm mt-2 font-semibold text-kiosk-notice"
          data-testid="diagnostics-camera-warning"
        >
          ⚠ {{ t(`diagnostics.warnings.${warning}`) }}
        </p>
      </article>

      <article data-testid="diagnostics-section-network">
        <h2 class="text-lg font-bold">{{ t('diagnostics.sections.network') }}</h2>
        <dl class="text-confirm-sm grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1">
          <dt>{{ t('diagnostics.rows.network.connection') }}</dt>
          <dd>
            {{
              snapshot.network.online
                ? t('diagnostics.values.online')
                : t('diagnostics.values.offline')
            }}
          </dd>
          <dt>{{ t('diagnostics.rows.network.reachable') }}</dt>
          <dd data-testid="diagnostics-network-reachable">
            {{ yesNo(snapshot.network.reachable) }}
          </dd>
          <dt>{{ t('diagnostics.rows.network.lastHeartbeat') }}</dt>
          <dd>{{ formatInstant(snapshot.network.lastHeartbeat?.beatAt ?? null) }}</dd>
          <dt>{{ t('diagnostics.rows.network.clockSkew') }}</dt>
          <dd>
            {{
              snapshot.network.lastHeartbeat?.skewSeconds === null ||
              snapshot.network.lastHeartbeat?.skewSeconds === undefined
                ? t('diagnostics.values.notAvailable')
                : t('diagnostics.rows.network.clockSkewValue', {
                    seconds: snapshot.network.lastHeartbeat.skewSeconds,
                  })
            }}
          </dd>
        </dl>
      </article>

      <article data-testid="diagnostics-section-queue">
        <h2 class="text-lg font-bold">{{ t('diagnostics.sections.queue') }}</h2>
        <dl class="text-confirm-sm grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1">
          <dt>{{ t('diagnostics.rows.queue.size') }}</dt>
          <dd data-testid="diagnostics-queue-size">{{ snapshot.queue.size }}</dd>
          <dt>{{ t('diagnostics.rows.queue.oldest') }}</dt>
          <dd>{{ formatInstant(snapshot.queue.oldestOccurredAt) }}</dd>
          <dt>{{ t('diagnostics.rows.queue.storage') }}</dt>
          <dd>
            {{
              snapshot.queue.durable
                ? t('diagnostics.values.durable')
                : t('diagnostics.values.memory')
            }}
          </dd>
          <dt>{{ t('diagnostics.rows.queue.syncing') }}</dt>
          <dd>{{ yesNo(snapshot.queue.syncing) }}</dd>
        </dl>
      </article>

      <article data-testid="diagnostics-section-roster">
        <h2 class="text-lg font-bold">{{ t('diagnostics.sections.roster') }}</h2>
        <p
          v-if="!snapshot.paired"
          role="status"
          class="text-confirm-sm text-kq-kiosk-text-muted"
          data-testid="diagnostics-roster-unpaired"
        >
          {{ t('diagnostics.values.unpaired') }}
        </p>
        <dl v-else class="text-confirm-sm grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1">
          <dt>{{ t('diagnostics.rows.roster.generatedAt') }}</dt>
          <dd>{{ formatInstant(snapshot.roster.generatedAt) }}</dd>
          <dt>{{ t('diagnostics.rows.roster.entries') }}</dt>
          <dd>{{ snapshot.roster.entryCount }}</dd>
          <dt>{{ t('diagnostics.rows.roster.pinAvailable') }}</dt>
          <dd>{{ yesNo(snapshot.roster.pinAvailable) }}</dd>
        </dl>
      </article>

      <article data-testid="diagnostics-section-token">
        <h2 class="text-lg font-bold">{{ t('diagnostics.sections.token') }}</h2>
        <dl class="text-confirm-sm grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1">
          <dt>{{ t('diagnostics.rows.token.present') }}</dt>
          <dd>
            {{
              snapshot.token.present
                ? t('diagnostics.values.present')
                : t('diagnostics.values.absent')
            }}
          </dd>
          <template v-if="snapshot.token.present">
            <dt>{{ t('diagnostics.rows.token.id') }}</dt>
            <dd data-testid="diagnostics-token-short-id">
              {{ snapshot.token.shortId ?? t('diagnostics.values.notAvailable') }}
            </dd>
            <dt>{{ t('diagnostics.rows.token.expiresAt') }}</dt>
            <dd>{{ formatInstant(snapshot.token.expiresAt) }}</dd>
            <dt>{{ t('diagnostics.rows.token.deviceId') }}</dt>
            <dd>{{ snapshot.deviceId }}</dd>
            <dt>{{ t('diagnostics.rows.token.deviceName') }}</dt>
            <dd>{{ snapshot.token.deviceName ?? t('diagnostics.values.notAvailable') }}</dd>
          </template>
        </dl>
      </article>

      <article data-testid="diagnostics-section-version">
        <h2 class="text-lg font-bold">{{ t('diagnostics.sections.version') }}</h2>
        <dl class="text-confirm-sm grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1">
          <dt>{{ t('diagnostics.rows.version.app') }}</dt>
          <dd data-testid="diagnostics-app-version">{{ snapshot.appVersion }}</dd>
          <dt>{{ t('diagnostics.rows.version.serviceWorker') }}</dt>
          <dd>{{ yesNo(snapshot.serviceWorkerActive) }}</dd>
        </dl>
      </article>

      <article data-testid="diagnostics-section-battery">
        <h2 class="text-lg font-bold">{{ t('diagnostics.sections.battery') }}</h2>
        <dl class="text-confirm-sm grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1">
          <dt>{{ t('diagnostics.rows.battery.level') }}</dt>
          <dd>
            {{
              snapshot.battery.level === null
                ? t('diagnostics.values.notAvailable')
                : `${snapshot.battery.level}%`
            }}
          </dd>
          <dt>{{ t('diagnostics.rows.battery.charging') }}</dt>
          <dd>{{ yesNo(snapshot.battery.charging) }}</dd>
        </dl>
      </article>

      <article data-testid="diagnostics-section-wakelock">
        <h2 class="text-lg font-bold">{{ t('diagnostics.sections.wakeLock') }}</h2>
        <dl class="text-confirm-sm grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1">
          <dt>{{ t('diagnostics.rows.wakeLock.supported') }}</dt>
          <dd>{{ yesNo(snapshot.wakeLock.supported) }}</dd>
          <dt>{{ t('diagnostics.rows.wakeLock.active') }}</dt>
          <dd>{{ yesNo(snapshot.wakeLock.active) }}</dd>
        </dl>
      </article>

      <article data-testid="diagnostics-section-errors">
        <h2 class="text-lg font-bold">{{ t('diagnostics.sections.errors') }}</h2>
        <p class="text-confirm-sm">{{ snapshot.pendingErrors }}</p>
      </article>

      <article data-testid="diagnostics-section-privacy">
        <h2 class="text-lg font-bold">{{ t('diagnostics.sections.privacy') }}</h2>
        <p class="text-confirm-sm">
          {{
            snapshot.privacyControllerConfigured
              ? t('diagnostics.values.configured')
              : t('diagnostics.values.notConfigured')
          }}
        </p>
      </article>

      <p class="text-confirm-sm text-kq-kiosk-text-muted">{{ t('diagnostics.autoReturnHint') }}</p>

      <button
        type="button"
        class="kiosk-touch w-full max-w-sm self-center rounded-kq-sm bg-kq-kiosk-primary-strong text-xl font-semibold text-kq-kiosk-on-primary"
        data-testid="diagnostics-back"
        @click="goBack"
      >
        {{ t('diagnostics.back') }}
      </button>
    </section>
  </main>
</template>
