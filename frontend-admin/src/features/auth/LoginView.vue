<script setup lang="ts">
// Acceso al panel de gestion (RF-ID-01) y su segundo factor obligatorio (RS-06).
//
// Solo personal de gestion. El empleado NO entra por aqui: su portal usa codigo
// de empleado y PIN (ADR-015, regla dura 12), y esta pantalla lo dice en voz
// alta para que nadie llame a RRHH preguntando por su contraseña.
//
// TRES PASOS, UNA SOLA PANTALLA. `credentials` (correo y contraseña) puede
// desembocar en `code` (la cuenta ya tiene TOTP activo: solo falta el codigo)
// o en `enrol` (primera vez: hay que dar de alta el segundo factor antes de
// poder entrar). El `challenge_token` que abre esos dos pasos vive SOLO en el
// estado de este componente — nunca en la tienda de sesion, nunca en
// `sessionStorage` — asi que recargar la pagina a mitad del reto no deja nada
// a medias: se vuelve al primer paso y hay que teclear la contraseña otra vez.
import { announcement, announce } from '@kronoqr/web-kit/announcer'
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import FormField from '@kronoqr/web-kit/components/FormField.vue'
import { isApiError } from '@kronoqr/web-kit/http'
import { computed, nextTick, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import type { Session, TwoFactorChallenge } from '@/shared/api/types'
import { isTwoFactorChallenge, verifyTwoFactor } from './auth.api'
import { useSessionStore } from './session.store'
import TwoFactorEnrolPanel from './TwoFactorEnrolPanel.vue'

/** Nombre con el que queda registrada la sesion. Sin PII, como exige el contrato. */
const DEVICE_NAME = 'Panel de gestion'

type Step = 'credentials' | 'code' | 'enrol'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const session = useSessionStore()

// --- Paso 1: contrasena --------------------------------------------------
const email = ref('')
const password = ref('')
const submitting = ref(false)
const error = ref<unknown>(null)

// --- Segundo factor (pasos 2 y 3) -----------------------------------------
// El alta del secreto TOTP (paso 3) vive en `TwoFactorEnrolPanel`, que ademas
// usa el asistente de puesta en marcha (tarea 5.5) para el primer
// administrador: es la misma pantalla, no una copia.
const step = ref<Step>('credentials')
const challengeToken = ref<string | null>(null)
const code = ref('')
const codeSubmitting = ref(false)

const codeInputRef = ref<HTMLInputElement | null>(null)

const codeIsValid = computed(() => /^[0-9]{6}$/.test(code.value))

function fieldErrors(field: string): readonly string[] {
  return isApiError(error.value) ? (error.value.fieldErrors[field] ?? []) : []
}

function redirectTarget(): string {
  const redirect = route.query['redirect']

  return typeof redirect === 'string' && redirect.startsWith('/') ? redirect : '/employees'
}

async function focusCode(): Promise<void> {
  await nextTick()
  codeInputRef.value?.focus()
}

/** Se quitan espacios y separadores: algunos autenticadores muestran el codigo en dos grupos de tres. */
function onCodeInput(event: Event): void {
  code.value = (event.target as HTMLInputElement).value.replace(/\D/g, '').slice(0, 6)
}

/** Vuelve al primer paso. Con `notice`, explica por que (reto caducado o invalido). */
function backToCredentials(notice: unknown = null): void {
  step.value = 'credentials'
  challengeToken.value = null
  code.value = ''
  error.value = notice
  password.value = ''
}

function beginChallenge(challenge: TwoFactorChallenge): void {
  challengeToken.value = challenge.challenge_token
  code.value = ''
  error.value = null

  if (challenge.enrolment_required) {
    // `TwoFactorEnrolPanel` anuncia el paso, mueve el foco y carga el secreto
    // por su cuenta al montarse: no se duplica aqui.
    step.value = 'enrol'
  } else {
    step.value = 'code'
    announce(t('auth.twoFactor.codeStepAnnouncement'))
    void focusCode()
  }
}

function onEnrolled(issued: Session): void {
  session.applySession(issued)
  void router.replace(redirectTarget())
}

async function submitCredentials(): Promise<void> {
  submitting.value = true
  error.value = null

  try {
    const outcome = await session.logIn({
      email: email.value,
      password: password.value,
      device_name: DEVICE_NAME,
    })

    if (isTwoFactorChallenge(outcome)) {
      beginChallenge(outcome)

      return
    }

    await router.replace(redirectTarget())
  } catch (caught) {
    error.value = caught
    password.value = ''
  } finally {
    submitting.value = false
  }
}

/**
 * Ante un rechazo del codigo (verificar o confirmar): un `401` de reto
 * caducado o invalido no tiene nada que reintentar aqui y vuelve al primer
 * paso con aviso; cualquier otra causa —codigo equivocado, bloqueo, red— se
 * enseña en el propio paso y vacia el codigo para teclearlo de nuevo.
 */
function handleChallengeError(caught: unknown): void {
  code.value = ''

  if (isApiError(caught) && caught.kind === 'unauthenticated') {
    backToCredentials(caught)

    return
  }

  error.value = caught
  void focusCode()
}

async function submitCode(): Promise<void> {
  if (challengeToken.value === null) return

  codeSubmitting.value = true
  error.value = null

  try {
    const issued = await verifyTwoFactor(challengeToken.value, code.value)

    session.applySession(issued)
    await router.replace(redirectTarget())
  } catch (caught) {
    handleChallengeError(caught)
  } finally {
    codeSubmitting.value = false
  }
}

/** El reto ha caducado o no vale mientras se daba de alta el segundo factor. */
function onChallengeInvalid(caught: unknown): void {
  backToCredentials(caught)
}
</script>

<template>
  <main class="flex min-h-dvh items-center justify-center bg-kq-surface p-4">
    <div
      class="w-full max-w-md rounded-kq border border-kq-border bg-kq-surface-raised p-6 shadow-kq-soft"
    >
      <h1 class="text-2xl font-bold text-kq-text">{{ t('auth.heading') }}</h1>

      <!-- Region viva propia: esta pantalla vive fuera de `AppShellView`, que es
           donde el resto del panel tiene la suya (WCAG 2.2 AA, 4.1.3). -->
      <p role="status" aria-live="polite" class="sr-only">{{ announcement }}</p>

      <!-- Paso 1: correo y contrasena ------------------------------------- -->
      <template v-if="step === 'credentials'">
        <ErrorNotice v-if="error !== null" :error="error" class="mt-4" />

        <form class="mt-6 flex flex-col gap-4" novalidate @submit.prevent="submitCredentials">
          <FormField
            v-slot="field"
            :label="t('auth.email')"
            :errors="fieldErrors('email')"
            required
          >
            <input
              :id="field.id"
              v-model="email"
              type="email"
              name="email"
              autocomplete="username"
              required
              :aria-describedby="field.describedBy"
              :aria-invalid="field.invalid"
              class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text placeholder:text-kq-text-muted"
            />
          </FormField>

          <FormField
            v-slot="field"
            :label="t('auth.password')"
            :errors="fieldErrors('password')"
            required
          >
            <input
              :id="field.id"
              v-model="password"
              type="password"
              name="password"
              autocomplete="current-password"
              required
              :aria-describedby="field.describedBy"
              :aria-invalid="field.invalid"
              class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text placeholder:text-kq-text-muted"
            />
          </FormField>

          <button
            type="submit"
            :disabled="submitting"
            :aria-busy="submitting"
            class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 font-semibold text-kq-on-primary disabled:opacity-60"
          >
            {{ submitting ? t('auth.submitting') : t('auth.submit') }}
          </button>
        </form>
      </template>

      <!-- Paso 2: codigo TOTP, cuenta con segundo factor ya activo --------- -->
      <template v-else-if="step === 'code'">
        <h2 class="mt-4 text-lg font-semibold text-kq-text">
          {{ t('auth.twoFactor.codeHeading') }}
        </h2>
        <p class="mt-1 text-sm text-kq-text-muted">{{ t('auth.twoFactor.codeInstructions') }}</p>

        <ErrorNotice v-if="error !== null" :error="error" class="mt-4" />

        <form class="mt-4 flex flex-col gap-4" novalidate @submit.prevent="submitCode">
          <FormField
            v-slot="field"
            :label="t('auth.twoFactor.codeLabel')"
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
              codeSubmitting ? t('auth.twoFactor.codeSubmitting') : t('auth.twoFactor.codeSubmit')
            }}
          </button>
        </form>

        <button
          type="button"
          class="mt-4 text-sm font-medium text-kq-primary-strong underline"
          @click="backToCredentials()"
        >
          {{ t('auth.twoFactor.back') }}
        </button>
      </template>

      <!-- Paso 3: alta del segundo factor, primera vez. Misma pantalla que usa
           el primer administrador del asistente de puesta en marcha. -->
      <template v-else>
        <TwoFactorEnrolPanel
          v-if="challengeToken !== null"
          class="mt-4"
          :challenge-token="challengeToken"
          @enrolled="onEnrolled"
          @challenge-invalid="onChallengeInvalid"
        />

        <button
          type="button"
          class="mt-4 text-sm font-medium text-kq-primary-strong underline"
          @click="backToCredentials()"
        >
          {{ t('auth.twoFactor.back') }}
        </button>
      </template>
    </div>
  </main>
</template>
