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
import LoadingPanel from '@kronoqr/web-kit/components/LoadingPanel.vue'
import { isApiError } from '@kronoqr/web-kit/http'
import type { QrPath } from '@kronoqr/web-kit/qr/renderQrPath'
import { computed, nextTick, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import type { TwoFactorChallenge, TwoFactorEnrolment } from '@/shared/api/types'
import { confirmTwoFactor, enrolTwoFactor, isTwoFactorChallenge, verifyTwoFactor } from './auth.api'
import { useSessionStore } from './session.store'

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
const step = ref<Step>('credentials')
const challengeToken = ref<string | null>(null)
const code = ref('')
const codeSubmitting = ref(false)
const enrolment = ref<TwoFactorEnrolment | null>(null)
const enrolLoading = ref(false)
const qr = ref<QrPath | null>(null)
const qrFailed = ref(false)

const codeInputRef = ref<HTMLInputElement | null>(null)
const enrolHeadingRef = ref<HTMLHeadingElement | null>(null)

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

async function focusEnrolHeading(): Promise<void> {
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

async function loadEnrolment(token: string): Promise<void> {
  enrolLoading.value = true
  error.value = null

  try {
    const issued = await enrolTwoFactor(token)

    enrolment.value = issued
    void focusCode()
    await loadQr(issued.otpauth_uri)
  } catch (caught) {
    if (isApiError(caught) && caught.kind === 'unauthenticated') {
      backToCredentials(caught)

      return
    }

    error.value = caught
  } finally {
    enrolLoading.value = false
  }
}

/** Vuelve al primer paso. Con `notice`, explica por que (reto caducado o invalido). */
function backToCredentials(notice: unknown = null): void {
  step.value = 'credentials'
  challengeToken.value = null
  code.value = ''
  enrolment.value = null
  enrolLoading.value = false
  qr.value = null
  qrFailed.value = false
  error.value = notice
  password.value = ''
}

function beginChallenge(challenge: TwoFactorChallenge): void {
  challengeToken.value = challenge.challenge_token
  code.value = ''
  error.value = null

  if (challenge.enrolment_required) {
    step.value = 'enrol'
    announce(t('auth.twoFactor.enrolStepAnnouncement'))
    void focusEnrolHeading()
    void loadEnrolment(challenge.challenge_token)
  } else {
    step.value = 'code'
    announce(t('auth.twoFactor.codeStepAnnouncement'))
    void focusCode()
  }
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

async function submitEnrolment(): Promise<void> {
  if (challengeToken.value === null) return

  codeSubmitting.value = true
  error.value = null

  try {
    const issued = await confirmTwoFactor(challengeToken.value, code.value)

    session.applySession(issued)
    await router.replace(redirectTarget())
  } catch (caught) {
    handleChallengeError(caught)
  } finally {
    codeSubmitting.value = false
  }
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

      <!-- Paso 3: alta del segundo factor, primera vez ---------------------- -->
      <template v-else>
        <h2
          ref="enrolHeadingRef"
          tabindex="-1"
          class="mt-4 text-lg font-semibold text-kq-text focus:outline-none"
        >
          {{ t('auth.twoFactor.enrolHeading') }}
        </h2>
        <p class="mt-1 text-sm text-kq-text-muted">{{ t('auth.twoFactor.enrolInstructions') }}</p>

        <ErrorNotice v-if="error !== null" :error="error" class="mt-4" />

        <LoadingPanel v-if="enrolLoading" :label="t('auth.twoFactor.enrolLoading')" class="mt-4" />

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

          <form class="mt-4 flex flex-col gap-4" novalidate @submit.prevent="submitEnrolment">
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
                codeSubmitting
                  ? t('auth.twoFactor.enrolSubmitting')
                  : t('auth.twoFactor.enrolSubmit')
              }}
            </button>
          </form>
        </template>

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
