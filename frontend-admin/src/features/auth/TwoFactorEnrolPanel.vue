<script setup lang="ts">
// Alta del segundo factor (RS-06): secreto TOTP y QR, mostrados UNA sola vez.
//
// Extraido de `LoginView` para que exista un unico sitio que pinta un secreto
// TOTP en pantalla. El asistente de puesta en marcha (tarea 5.5) lo reutiliza
// para el primer administrador: `POST /api/v1/setup/administrator` devuelve el
// mismo `TwoFactorChallenge` que el `202` de `/auth/login`, asi que el mismo
// `challenge_token` abre exactamente este mismo paso — no hay una segunda
// pantalla que pudiera enseñar el secreto de otra forma o divergir con el
// tiempo.
//
// No incluye un boton de "volver": quien la usa decide que hacer si se
// abandona (en el acceso, vuelve a pedir credenciales; en el asistente, no hay
// donde volver porque la cuenta ya existe — se explica en `challenge-invalid`).
import { announce } from '@kronoqr/web-kit/announcer'
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import FormField from '@kronoqr/web-kit/components/FormField.vue'
import { isApiError } from '@kronoqr/web-kit/http'
import type { QrPath } from '@kronoqr/web-kit/qr/renderQrPath'
import { computed, nextTick, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Session, TwoFactorEnrolment } from '@/shared/api/types'
import { confirmTwoFactor, enrolTwoFactor } from './auth.api'

const props = defineProps<{
  challengeToken: string
}>()

const emit = defineEmits<{
  enrolled: [session: Session]
  /** El reto ha caducado o no vale: quien lo use decide adonde ir. */
  'challenge-invalid': [error: unknown]
}>()

const { t } = useI18n()

const code = ref('')
const codeSubmitting = ref(false)
const enrolment = ref<TwoFactorEnrolment | null>(null)
const enrolLoading = ref(false)
const qr = ref<QrPath | null>(null)
const qrFailed = ref(false)
const error = ref<unknown>(null)

const codeInputRef = ref<HTMLInputElement | null>(null)
const enrolHeadingRef = ref<HTMLHeadingElement | null>(null)

const codeIsValid = computed(() => /^[0-9]{6}$/.test(code.value))

function fieldErrors(field: string): readonly string[] {
  return isApiError(error.value) ? (error.value.fieldErrors[field] ?? []) : []
}

async function focusCode(): Promise<void> {
  await nextTick()
  codeInputRef.value?.focus()
}

async function focusHeading(): Promise<void> {
  await nextTick()
  enrolHeadingRef.value?.focus()
}

/** Se quitan espacios y separadores: algunos autenticadores muestran el codigo en dos grupos de tres. */
function onCodeInput(event: Event): void {
  code.value = (event.target as HTMLInputElement).value.replace(/\D/g, '').slice(0, 6)
}

async function loadQr(otpauthUri: string): Promise<void> {
  const { renderQrPath } = await import('@kronoqr/web-kit/qr/renderQrPath')
  const rendered = await renderQrPath(otpauthUri)

  qr.value = rendered
  qrFailed.value = rendered === null
}

async function loadEnrolment(): Promise<void> {
  enrolLoading.value = true
  error.value = null

  try {
    const issued = await enrolTwoFactor(props.challengeToken)

    enrolment.value = issued
    void focusCode()
    await loadQr(issued.otpauth_uri)
  } catch (caught) {
    if (isApiError(caught) && caught.kind === 'unauthenticated') {
      emit('challenge-invalid', caught)

      return
    }

    error.value = caught
  } finally {
    enrolLoading.value = false
  }
}

async function submit(): Promise<void> {
  codeSubmitting.value = true
  error.value = null

  try {
    const session = await confirmTwoFactor(props.challengeToken, code.value)

    emit('enrolled', session)
  } catch (caught) {
    code.value = ''

    if (isApiError(caught) && caught.kind === 'unauthenticated') {
      emit('challenge-invalid', caught)

      return
    }

    error.value = caught
    void focusCode()
  } finally {
    codeSubmitting.value = false
  }
}

onMounted(() => {
  announce(t('auth.twoFactor.enrolStepAnnouncement'))
  void focusHeading()
  void loadEnrolment()
})
</script>

<template>
  <div>
    <h2
      ref="enrolHeadingRef"
      tabindex="-1"
      class="text-lg font-semibold text-kq-text focus:outline-none"
    >
      {{ t('auth.twoFactor.enrolHeading') }}
    </h2>
    <p class="mt-1 text-sm text-kq-text-muted">{{ t('auth.twoFactor.enrolInstructions') }}</p>

    <ErrorNotice v-if="error !== null" :error="error" class="mt-4" />

    <p v-if="enrolLoading" class="mt-4 text-kq-text-muted" role="status">
      {{ t('auth.twoFactor.enrolLoading') }}
    </p>

    <template v-else-if="enrolment !== null">
      <svg
        v-if="qr !== null"
        class="mx-auto mt-4 h-48 w-48 bg-white p-2"
        :viewBox="`0 0 ${qr.size} ${qr.size}`"
        role="img"
        :aria-label="t('auth.twoFactor.enrolQrAlt')"
        shape-rendering="crispEdges"
      >
        <path :d="qr.path" fill="#000000" />
      </svg>
      <p v-else-if="qrFailed" class="mt-4 text-sm text-kq-text-muted">
        {{ t('auth.twoFactor.enrolQrUnavailable') }}
      </p>

      <p class="mt-4 text-sm font-medium text-kq-text">
        {{ t('auth.twoFactor.enrolSecretLabel') }}
      </p>
      <p
        class="mt-1 select-all break-all rounded-kq-sm border border-kq-border-strong bg-kq-surface-alt px-3 py-2 font-mono text-sm text-kq-text"
        data-test="two-factor-secret"
      >
        {{ enrolment.secret }}
      </p>

      <form class="mt-4 flex flex-col gap-4" novalidate @submit.prevent="submit">
        <FormField
          v-slot="field"
          :label="t('auth.twoFactor.enrolCodeLabel')"
          :errors="fieldErrors('code')"
          required
        >
          <input
            :id="field.id"
            ref="codeInputRef"
            :value="code"
            type="text"
            name="code"
            inputmode="numeric"
            autocomplete="one-time-code"
            pattern="[0-9]{6}"
            maxlength="6"
            required
            :aria-describedby="field.describedBy"
            :aria-invalid="field.invalid"
            class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-center text-lg tracking-[0.5em] text-kq-text placeholder:text-kq-text-muted"
            @input="onCodeInput"
          />
        </FormField>

        <button
          type="submit"
          :disabled="codeSubmitting || !codeIsValid"
          :aria-busy="codeSubmitting"
          class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 font-semibold text-kq-on-primary disabled:opacity-60"
        >
          {{
            codeSubmitting ? t('auth.twoFactor.enrolSubmitting') : t('auth.twoFactor.enrolSubmit')
          }}
        </button>
      </form>
    </template>
  </div>
</template>
