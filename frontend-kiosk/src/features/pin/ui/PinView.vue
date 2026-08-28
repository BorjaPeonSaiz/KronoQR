<script setup lang="ts">
// Fichaje de respaldo por PIN (RF-AT-11, RS-12, tarea 1.12).
//
// Cuando existe: solo si `pin_sealing_public_key` del padron no es `null`
// (ADR-017). El boton que trae aqui, en `ScanView.vue`, ya comprueba eso; esta
// pantalla lo vuelve a comprobar por si alguien llega sin ese boton (recarga,
// navegacion directa) y se redirige sola a la pantalla de tarjeta en vez de
// ofrecer un teclado que rechazaria siempre.
//
// DOS PASOS, DOS TECLADOS. El codigo de empleado es alfanumerico y opaco (por
// ejemplo `E7QK2MXPR`): se teclea con el teclado nativo del dispositivo, que
// tiene todos los caracteres posibles y no deja a nadie con un codigo antiguo
// sin forma de escribirlo. El PIN es siempre 6 digitos: para eso hay un
// teclado numerico dedicado, grande y operable con guantes.
//
// EL PIN NUNCA SE VE EN PANTALLA. El paso 2 muestra puntos, no cifras: quien
// mira por encima del hombro en una recepcion no lee el PIN de otra persona.
//
// FEEDBACK REUTILIZADO de la 1.8: mismo `ScanConfirmationPanel`, mismo sonido,
// mismo mensaje generico de rechazo (regla dura 17) — el empleado no deberia
// notar que esta via es distinta de la tarjeta.
import { computed, onMounted, onUnmounted, ref, watchEffect } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRouter } from 'vue-router'
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
import ScanConfirmationPanel from '@/features/scan/ui/ScanConfirmationPanel.vue'
import { useScanSessionWithCleanup } from '@/features/scan/composables/useScanSession'
import { useScanSound } from '@/features/scan/composables/useScanSound'
import { useWakeLock } from '@/features/scan/composables/useWakeLock'
import type { ScanConfirmation } from '@/features/scan/domain/scanOutcome'
import { CONFIRMATION_DISPLAY_MS } from '@/features/scan/domain/scanOutcome'
import { createPinPipeline } from '../application/pinPipeline'
import { hasEmployeeCodeShape, normalizeEmployeeCode } from '../domain/pinCode'
import { warmUpSealing } from '../infrastructure/pinSealing'
import { usePinKeypad } from '../composables/usePinKeypad'
import PinNumericKeypad from './PinNumericKeypad.vue'

const { t } = useI18n()
const router = useRouter()

const deviceId = resolveDeviceId()
const reporter = createErrorReporter({ appVersion: APP_VERSION, deviceId })
const connectivity = useConnectivity()
const privacyConfig = readPrivacyNoticeConfig()

const api = createApiClient({
  ...(import.meta.env.VITE_API_BASE_URL === undefined
    ? {}
    : { baseUrl: import.meta.env.VITE_API_BASE_URL }),
  // Ver ScanView: sin el token no hay `Authorization` y todo acaba en 401.
  deviceToken: readDeviceToken,
})

// Mismo controlador de cola UNICO que la pantalla de escaneo (tarea 1.9): el
// fichaje por PIN se encola exactamente igual, con el mismo drenaje.
const offline = useOfflineQueue({ api, reporter, connectivity })

// Si esta instalacion no ofrece PIN, esta pantalla no tiene nada que hacer:
// se vuelve a la de tarjeta. No es un error (ADR-017). `pinSealingKnown`
// evita confundir «todavia no se sabe» (recien montado, padron sin cargar)
// con «no existe»: lo primero no puede expulsar a nadie de una via que si
// tiene, solo pasa mientras se resuelve.
watchEffect(() => {
  if (offline.pinSealingKnown.value && offline.pinSealingPublicKey.value === null) {
    void router.replace({ name: 'home' })
  }
})

const sound = useScanSound({
  onBlocked: (context) => reporter.report('kiosk.audio.blocked', context),
})

const session = useScanSessionWithCleanup({
  // Este flujo no decodifica una camara: `pipeline` de `useScanSession` no se
  // usa (se llama a `session.present()` directamente desde `confirm()`).
  pipeline: { handleDecoded: () => null },
  sound,
})

type Step = 'code' | 'pin' | 'submitting'
const step = ref<Step>('code')
const employeeCode = ref('')
const pin = usePinKeypad()

const codeIsValid = computed(() => hasEmployeeCodeShape(employeeCode.value))
// La clave puede tardar el primer instante tras un arranque en frio (recarga
// justo en esta pantalla): mientras no llega, «Confirmar» se queda inactivo
// en vez de fallar en silencio (rarisimo en marcha normal, donde el padron ya
// esta cargado desde que se pulso «Ficha con tu código y PIN» en la pantalla
// anterior).
const canConfirm = computed(
  () => pin.isComplete.value && offline.pinSealingPublicKey.value !== null,
)

function goToPinStep(): void {
  if (!codeIsValid.value) return
  step.value = 'pin'
}

function goBackToCodeStep(): void {
  pin.clear()
  step.value = 'code'
}

/** Vacia lo tecleado SIN tocar `step`: lo usa `confirm()`, que ya decide el paso a mano. */
function clearSensitiveInputs(): void {
  employeeCode.value = ''
  pin.clear()
}

// El `router` es un singleton de la aplicacion, no algo local a esta
// instancia de la pantalla: una clausura que sobrevive al desmontaje (por
// ejemplo `onSettled` de `pinPipeline`, que puede llegar segundos despues de
// que la persona ya se haya ido y otra este tecleando su PIN) no puede tocarlo.
// `alive` es la guarda: se pone a `false` en `onUnmounted`, ANTES que
// cualquier otra cosa, y toda funcion que programa algo sobre el router la
// comprueba primero.
let alive = true
let returnTimer: ReturnType<typeof setTimeout> | null = null

/**
 * Programa la vuelta a inicio segun el desenlace QUE HAYA en pantalla ahora
 * mismo, cancelando cualquier vuelta programada antes. Se llama tanto al
 * pintar la primera confirmacion como, mas tarde, desde `onSettled`: un
 * «Comprobando…» que se sustituye por «pendiente» o por el desenlace real no
 * puede dejar viva la cuenta atras pensada para el «Comprobando…», o la
 * pantalla volveria a inicio antes de que hubiera tiempo de leer lo que vino
 * despues.
 */
function scheduleReturn(kind: ScanConfirmation['kind']): void {
  // Defensa en profundidad: si algo llega a llamar aqui despues del
  // desmontaje (el guard de `alive` en `onSettled` ya deberia haberlo
  // evitado), no se programa nada sobre el `router` singleton.
  if (!alive) return
  if (returnTimer !== null) clearTimeout(returnTimer)
  returnTimer = setTimeout(() => {
    if (!alive) return
    void router.replace({ name: 'home' })
  }, CONFIRMATION_DISPLAY_MS[kind])
}

async function confirm(): Promise<void> {
  const publicKey = offline.pinSealingPublicKey.value
  if (!canConfirm.value || publicKey === null) return

  const pipeline = createPinPipeline({
    submission: offline.submission,
    deviceId,
    publicKey,
    isOffline: () => connectivity.status.value === 'offline',
    onSettled: (confirmation) => {
      // `onSettled` puede llegar segundos despues de que esta pantalla se
      // haya desmontado (Wi-Fi degradada, respuesta tardia del servidor): la
      // persona que la tecleo ya se fue y puede haber otra en su lugar. Nada
      // de lo que sigue puede tocar el `session` de esta instancia (ya
      // limpiado) ni, sobre todo, programar una vuelta a inicio sobre el
      // `router` — que es un singleton compartido con quien haya despues.
      if (!alive) return

      // El PIN no se puede validar en local (regla dura 19 + RS-03: viaja
      // sellado). Mientras estaba «Comprobando…» no ha sonado nada todavia,
      // asi que el desenlace que llega ahora — real o «pendiente» por plazo
      // vencido — se pinta CON su propio sonido (`present`), no en silencio.
      // Si lo que habia en pantalla ya era «pendiente» (sin red, o una
      // respuesta tardia tras el plazo), el sonido de reconocimiento ya sono
      // entonces: repetirlo aqui daria dos pitidos por un unico fichaje.
      if (session.confirmation.value?.kind === 'verifying') {
        session.present(confirmation)
      } else {
        session.settle(confirmation)
      }
      scheduleReturn(confirmation.kind)
    },
    onError: (code, context) =>
      reporter.report(
        code === 'seal_failed' ? 'kiosk.pin.seal_failed' : 'kiosk.scan.submit_failed',
        context,
      ),
  })

  const normalizedCode = normalizeEmployeeCode(employeeCode.value)
  const pinValue = pin.value.value

  // El PIN sale de aqui y no vuelve: el buffer del teclado se vacia YA, antes
  // incluso de saber si el sellado tardara. No hay ninguna ventana en la que
  // los 6 digitos sigan vivos en la pantalla mas tiempo del imprescindible.
  step.value = 'submitting'
  clearSensitiveInputs()

  const confirmation = await pipeline.submit(normalizedCode, pinValue)
  session.present(confirmation)

  // Si esto es «Comprobando…», la vuelta a inicio programada aqui es solo la
  // red de seguridad por si `onSettled` no llegara nunca (no deberia: la
  // propia tuberia se rinde a «pendiente» al vencer `PIN_VERIFY_TIMEOUT_MS`).
  // En cuanto `onSettled` dispare, `scheduleReturn` la sustituye por la
  // correcta para el desenlace de verdad.
  scheduleReturn(confirmation.kind)
}

const wakeLock = useWakeLock({
  onDenied: (context) => reporter.report('kiosk.wake_lock.denied', context),
})

const heartbeat = createHeartbeatScheduler({
  api,
  reporter,
  snapshot: () => offline.telemetry(APP_VERSION),
})

onMounted(() => {
  warmUpSealing()
  void wakeLock.request()
  heartbeat.start()
})

onUnmounted(() => {
  // Primero: nada programado despues de este punto puede navegar. El resto
  // de la limpieza (temporizador vivo, latido) va detras a proposito.
  alive = false
  heartbeat.stop()
  if (returnTimer !== null) clearTimeout(returnTimer)
})
</script>

<template>
  <main class="flex h-dvh w-full flex-col bg-kq-kiosk-surface text-kq-kiosk-text">
    <h1 class="kiosk-sr-only">{{ t('pin.title') }}</h1>

    <header class="flex items-center justify-between gap-4 px-6 py-4">
      <ConnectionStatusBadge
        :status="connectivity.status.value"
        :pending-count="connectivity.pendingCount.value"
        :syncing="offline.syncing.value"
      />
      <LanguageSelector />
    </header>

    <section class="relative flex min-h-0 flex-1 flex-col items-center justify-center gap-8 px-10">
      <RouterLink
        :to="{ name: 'home' }"
        class="kiosk-touch absolute top-0 left-6 flex items-center rounded-kq-sm border border-kq-kiosk-border bg-kq-kiosk-surface-raised px-5 text-base font-medium text-kq-kiosk-text"
        data-testid="pin-back-to-card"
      >
        {{ t('pin.backToCard') }}
      </RouterLink>

      <template v-if="session.confirmation.value === null">
        <div
          v-if="step === 'code'"
          class="flex w-full max-w-md flex-col gap-6"
          data-testid="pin-step-code"
        >
          <p class="text-confirm-sm font-heading text-center font-bold">
            {{ t('pin.code.title') }}
          </p>
          <label class="flex flex-col gap-2">
            <span class="kiosk-sr-only">{{ t('pin.code.label') }}</span>
            <input
              v-model="employeeCode"
              type="text"
              inputmode="text"
              autocapitalize="characters"
              autocomplete="off"
              autocorrect="off"
              spellcheck="false"
              maxlength="32"
              data-testid="pin-code-input"
              class="kiosk-touch rounded-kq-sm border border-kq-kiosk-border bg-kq-kiosk-surface-raised px-4 text-center text-3xl font-semibold tracking-widest text-kq-kiosk-text"
              @keyup.enter="goToPinStep"
            />
          </label>
          <button
            type="button"
            class="kiosk-touch rounded-kq-sm bg-kq-kiosk-primary-strong text-2xl font-semibold text-kq-kiosk-on-primary disabled:opacity-40"
            :disabled="!codeIsValid"
            data-testid="pin-code-continue"
            @click="goToPinStep"
          >
            {{ t('pin.code.continue') }}
          </button>
        </div>

        <div
          v-else-if="step === 'pin'"
          class="flex w-full max-w-sm flex-col gap-6"
          data-testid="pin-step-pin"
        >
          <p class="text-confirm-sm font-heading text-center font-bold">
            {{ t('pin.pin.title') }}
          </p>

          <div
            class="flex justify-center gap-3"
            role="status"
            :aria-label="t('pin.pin.progress', { entered: pin.value.value.length, total: 6 })"
            data-testid="pin-dots"
          >
            <span
              v-for="index in 6"
              :key="index"
              aria-hidden="true"
              class="h-5 w-5 rounded-full border-2 border-kq-kiosk-border"
              :class="index <= pin.value.value.length ? 'bg-kq-kiosk-text' : 'bg-transparent'"
            ></span>
          </div>

          <PinNumericKeypad @digit="pin.pressDigit" @backspace="pin.backspace" @clear="pin.clear" />

          <div class="flex gap-3">
            <button
              type="button"
              class="kiosk-touch flex-1 rounded-kq-sm border border-kq-kiosk-border bg-kq-kiosk-surface-raised text-xl font-semibold text-kq-kiosk-text"
              data-testid="pin-back-to-code"
              @click="goBackToCodeStep"
            >
              {{ t('pin.pin.back') }}
            </button>
            <button
              type="button"
              class="kiosk-touch flex-1 rounded-kq-sm bg-kq-kiosk-primary-strong text-xl font-semibold text-kq-kiosk-on-primary disabled:opacity-40"
              :disabled="!canConfirm"
              data-testid="pin-confirm"
              @click="confirm"
            >
              {{ t('pin.pin.confirm') }}
            </button>
          </div>
        </div>
      </template>

      <ScanConfirmationPanel
        v-if="session.confirmation.value !== null"
        class="absolute inset-0"
        :confirmation="session.confirmation.value"
      />
    </section>

    <footer class="flex items-end justify-between gap-4 px-6 py-4">
      <PrivacyNoticePanel class="min-w-0 flex-1" :config="privacyConfig" />
    </footer>
  </main>
</template>
