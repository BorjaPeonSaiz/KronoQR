<script setup lang="ts">
// Acceso al portal personal (RF-ID-06, ADR-015).
//
// Codigo de empleado y PIN de 6 a 8 cifras (ADR-050), los mismos que sirven de respaldo
// en el quiosco. Nada de correo ni de contraseña (regla dura 12): quien
// necesite recuperar el acceso pide a RRHH que le restablezca el PIN, nunca un
// enlace por correo.
//
// **Un solo mensaje para cualquier rechazo** (RS-03, regla dura 17). El
// servidor no distingue codigo inexistente, PIN incorrecto, PIN nunca emitido,
// baja o bloqueo por intentos activo, y esta pantalla no lo desune: el error
// que se pinta es siempre el mismo, `errors.invalidCredentials`, venga lo que
// venga en `problem.type`.
import BrandMark from '@kronoqr/web-kit/components/BrandMark.vue'
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import FormField from '@kronoqr/web-kit/components/FormField.vue'
import { isApiError } from '@kronoqr/web-kit/http'
import { computed, onBeforeUnmount, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useBrandingStore } from '@/shared/branding/branding.store'
import { useSessionStore } from './session.store'

const PIN_PATTERN = /^\d{6,8}$/

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const session = useSessionStore()
const branding = useBrandingStore()

const employeeCode = ref('')
const pin = ref('')
const submitting = ref(false)
const error = ref<unknown>(null)

const PORTAL_ORIGIN_LOCKED = 'urn:kronoqr:problem:portal-origin-locked'
const FALLBACK_LOCK_SECONDS = 60

// Bloqueo del origen (ADR-050): cuenta atras de presentacion a partir de
// `Retry-After`, sin reloj del dominio. No dice nada del codigo ni del PIN.
const lockedSeconds = ref(0)
const lockedMinutes = ref(1)
let lockTimer: ReturnType<typeof setInterval> | null = null

function stopLockTimer(): void {
  if (lockTimer !== null) {
    clearInterval(lockTimer)
    lockTimer = null
  }
}

function startLock(seconds: number): void {
  stopLockTimer()
  lockedSeconds.value = Math.max(1, seconds)
  lockedMinutes.value = Math.ceil(lockedSeconds.value / 60)
  lockTimer = setInterval(() => {
    lockedSeconds.value -= 1

    if (lockedSeconds.value <= 0) {
      lockedSeconds.value = 0
      stopLockTimer()
    }
  }, 1000)
}

const isLocked = computed(() => lockedSeconds.value > 0)
const lockedClock = computed(() => {
  const minutes = Math.floor(lockedSeconds.value / 60)
  const seconds = String(lockedSeconds.value % 60).padStart(2, '0')

  return `${minutes}:${seconds}`
})

onBeforeUnmount(stopLockTimer)

/**
 * Formato completo, no si el codigo o el PIN son correctos: eso solo lo sabe
 * el servidor, y decidirlo aqui seria empezar a distinguir lo que RS-03 exige
 * mantener unido.
 */
const canSubmit = computed(
  () =>
    !submitting.value &&
    !isLocked.value &&
    employeeCode.value.trim() !== '' &&
    PIN_PATTERN.test(pin.value),
)

function redirectTarget(): string {
  const redirect = route.query['redirect']

  return typeof redirect === 'string' && redirect.startsWith('/') ? redirect : '/records'
}

async function submit(): Promise<void> {
  if (!canSubmit.value) {
    return
  }

  submitting.value = true
  error.value = null
  lockedSeconds.value = 0
  stopLockTimer()

  try {
    await session.logIn({ employee_code: employeeCode.value.trim(), pin: pin.value })
    await router.replace(redirectTarget())
  } catch (caught) {
    if (
      isApiError(caught) &&
      caught.status === 429 &&
      caught.problem?.type === PORTAL_ORIGIN_LOCKED
    ) {
      startLock(caught.retryAfterSeconds ?? FALLBACK_LOCK_SECONDS)
    } else {
      error.value = caught
    }
  } finally {
    // El PIN nunca se deja escrito en pantalla, acierte o falle (regla dura 21).
    pin.value = ''
    submitting.value = false
  }
}
</script>

<template>
  <main class="flex min-h-dvh items-center justify-center bg-kq-surface p-4">
    <div
      class="w-full max-w-md rounded-kq border border-kq-border bg-kq-surface-raised p-6 shadow-kq-soft"
    >
      <div class="mb-4 flex justify-center">
        <BrandMark :branding="branding.current" size="lg" />
      </div>

      <h1 class="font-heading text-2xl font-bold text-kq-text">{{ t('login.heading') }}</h1>

      <ErrorNotice v-if="error !== null" :error="error" class="mt-4" />

      <div
        v-if="isLocked"
        role="alert"
        class="mt-4 rounded-kq border border-kq-danger bg-kq-danger-soft p-4 text-kq-danger"
      >
        <p class="font-semibold">{{ t('login.originLocked.title') }}</p>
        <p class="mt-1">{{ t('login.originLocked.advice', { minutes: lockedMinutes }) }}</p>
      </div>

      <form class="mt-6 flex flex-col gap-5" novalidate @submit.prevent="submit">
        <FormField
          v-slot="field"
          :label="t('login.employeeCode')"
          label-class="text-lg font-medium text-kq-text"
          required
        >
          <input
            :id="field.id"
            v-model="employeeCode"
            type="text"
            name="employee_code"
            autocomplete="username"
            autocapitalize="characters"
            maxlength="32"
            required
            :aria-describedby="field.describedBy"
            class="min-h-12 rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-lg"
          />
        </FormField>

        <FormField
          v-slot="field"
          :label="t('login.pin')"
          :hint="t('login.pinHint')"
          label-class="text-lg font-medium text-kq-text"
          required
        >
          <input
            :id="field.id"
            v-model="pin"
            type="password"
            name="pin"
            inputmode="numeric"
            autocomplete="off"
            maxlength="8"
            required
            :aria-describedby="field.describedBy"
            class="min-h-12 rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-lg tracking-widest"
          />
        </FormField>

        <button
          type="submit"
          :disabled="!canSubmit"
          :aria-busy="submitting"
          :aria-describedby="isLocked ? 'login-locked-note' : undefined"
          class="min-h-12 rounded-kq-sm bg-kq-primary-strong px-4 py-2 text-lg font-semibold text-kq-on-primary disabled:opacity-60"
        >
          {{ submitting ? t('login.submitting') : t('login.submit') }}
        </button>
        <p v-if="isLocked" id="login-locked-note" class="text-base text-kq-text-muted">
          {{ t('login.originLocked.countdown', { time: lockedClock }) }}
        </p>
      </form>

      <p class="mt-6 text-base text-kq-text-muted">{{ t('login.forgotPin') }}</p>
    </div>
  </main>
</template>
