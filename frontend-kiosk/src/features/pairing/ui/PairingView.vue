<script setup lang="ts">
// Pantalla de emparejamiento de la tablet (RF-PD-06, tarea 5.6).
//
// A donde llega quien la ve: una tablet recien instalada, o una que el panel
// acaba de desvincular (`deviceRevocation.ts`). Nunca un empleado en marcha
// normal — el guard del router (`router/index.ts`) es lo que lo garantiza.
//
// POR QUE NO HAY UN ESTADO DE ERROR EN PANTALLA. La maquina de
// `pairingFlow.ts` no tiene un estado «fallo»: una caducidad, un rechazo o un
// fallo de red pidiendo codigo se resuelven SOLOS, sin que nadie toque nada
// (regla dura 19). Lo unico que ve quien mira la pantalla es el texto que
// cambia («Generando codigo nuevo…») y, un instante despues, un codigo nuevo.
//
// NUNCA `Authorization` AQUI. Las dos rutas de emparejamiento son publicas
// (RF-PD-06): `requestPairing`/`claimPairing` en `shared/api/client.ts` nunca
// adjuntan el bearer, pase lo que pase con `deviceToken` (defensa en
// profundidad: la garantia vive en el cliente HTTP, no aqui). Ademas, esta
// pantalla ni siquiera se lo pasa, para que quede doblemente claro que un
// token residual de un emparejamiento anterior en `localStorage` —el caso
// real de una tablet revocada que ha vuelto aqui— no puede filtrarse.
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { createPairingFlow } from '../application/pairingFlow'
import type { PairingState } from '../application/pairingFlow'
import { createApiClient } from '@/shared/api/client'
import type { PairingCompleted } from '@/shared/api/types'
import { APP_VERSION, persistPairedDevice } from '@/shared/telemetry/deviceIdentity'
import LanguageSelector from '@/shared/ui/LanguageSelector.vue'

const { t } = useI18n()
const router = useRouter()

const api = createApiClient({
  ...(import.meta.env.VITE_API_BASE_URL === undefined
    ? {}
    : { baseUrl: import.meta.env.VITE_API_BASE_URL }),
  // Sin `deviceToken`: ver la cabecera. Nunca se manda `Authorization` desde
  // esta pantalla, ni siquiera si quedara un token viejo en `localStorage`.
})

const state = ref<PairingState>({ kind: 'idle' })

function handlePaired(result: PairingCompleted): void {
  // El `device.uuid` sustituye al identificador local en la MISMA clave
  // (`deviceIdentity.ts`); el token es la unica vez que sale del servidor.
  persistPairedDevice(result.token.value, result.device.uuid)
  void router.replace({ name: 'home' })
}

const flow = createPairingFlow({
  api,
  appVersion: APP_VERSION,
  onStateChange: (next) => {
    state.value = next
  },
  onPaired: handlePaired,
})

/** «483 921»: agrupado para leerse de lejos y tecleable sin ambigüedad (doc 02). */
const formattedCode = computed(() => {
  if (state.value.kind !== 'waiting') return ''
  const code = state.value.code
  return `${code.slice(0, 3)} ${code.slice(3)}`
})

// Cuenta atras de caducidad. Solo hay temporizador MIENTRAS se espera un
// codigo: en cualquier otro estado se para, para no dejar un `setInterval`
// corriendo sin nada que mostrar (el bucle de esta pantalla puede vivir horas
// si nadie viene a emparejar la tablet).
const nowMs = ref(Date.now())
let tickTimer: ReturnType<typeof setInterval> | null = null

function ensureTicking(): void {
  if (tickTimer !== null) return
  nowMs.value = Date.now()
  tickTimer = setInterval(() => {
    nowMs.value = Date.now()
  }, 1_000)
}

function stopTicking(): void {
  if (tickTimer === null) return
  clearInterval(tickTimer)
  tickTimer = null
}

watch(
  () => state.value.kind,
  (kind) => {
    if (kind === 'waiting') ensureTicking()
    else stopTicking()
  },
  { immediate: true },
)

const remainingSeconds = computed(() => {
  if (state.value.kind !== 'waiting') return 0
  const diffMs = Date.parse(state.value.expiresAt) - nowMs.value
  return Math.max(0, Math.ceil(diffMs / 1_000))
})

const remainingLabel = computed(() => {
  const total = remainingSeconds.value
  const minutes = Math.floor(total / 60)
  const seconds = total % 60
  return `${minutes}:${seconds.toString().padStart(2, '0')}`
})

/**
 * Un solo texto para lo que se VE y lo que se ANUNCIA: si divergieran, quien
 * usa lector de pantalla oiria algo distinto de lo que hay escrito en
 * pantalla, que es peor que no anunciar nada.
 */
const statusText = computed(() => {
  if (state.value.kind === 'waiting') return t('pairing.waiting.status')
  if (state.value.kind === 'paired') return t('pairing.paired.status')
  if (state.value.kind === 'requesting') {
    if (state.value.reason === 'expired') return t('pairing.requesting.afterExpiry')
    if (state.value.reason === 'rejected') return t('pairing.requesting.afterRejection')
    return t('pairing.requesting.initial')
  }
  return t('pairing.requesting.initial')
})

function requestNewCode(): void {
  flow.requestNewCode()
}

onMounted(() => {
  flow.start()
})

onUnmounted(() => {
  flow.stop()
  stopTicking()
})
</script>

<template>
  <main
    class="relative flex h-dvh w-full flex-col items-center justify-center gap-8 bg-kq-kiosk-surface px-10 text-center text-kq-kiosk-text"
  >
    <h1 class="kiosk-sr-only">{{ t('pairing.title') }}</h1>

    <div class="absolute top-6 right-6">
      <LanguageSelector />
    </div>

    <div
      v-if="state.kind === 'waiting'"
      class="flex flex-col items-center gap-6"
      data-testid="pairing-waiting"
    >
      <p
        class="rounded-kq-lg bg-kq-kiosk-surface-raised px-12 py-8 font-heading text-confirm-xl leading-none font-bold tracking-[0.2em] text-kq-kiosk-text"
        data-testid="pairing-code"
      >
        {{ formattedCode }}
      </p>

      <p class="text-confirm-sm text-kq-kiosk-text-muted" data-testid="pairing-countdown">
        {{ t('pairing.waiting.expiresIn', { time: remainingLabel }) }}
      </p>

      <button
        type="button"
        class="kiosk-touch rounded-kq-sm border border-kq-kiosk-border bg-kq-kiosk-surface-raised px-6 text-base font-medium text-kq-kiosk-text"
        data-testid="pairing-new-code"
        @click="requestNewCode"
      >
        {{ t('pairing.waiting.newCode') }}
      </button>
    </div>

    <p
      role="status"
      aria-live="polite"
      class="text-confirm-md font-heading font-bold"
      data-testid="pairing-status"
    >
      {{ statusText }}
    </p>
  </main>
</template>
