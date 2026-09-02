<script setup lang="ts">
// Paso 1: el primer administrador (RF-PD-03, regla dura 6).
//
// Va PRIMERO y no quinto, aunque el requisito lo enumere en quinto lugar: el
// centro, los departamentos y todo lo demas quedan en `audit_log`, y un
// asiento sin actor no responde a la pregunta para la que existe el rastro
// (decision de la 5.5, ver HANDOFF). Es tambien la UNICA escritura publica de
// todo el producto: `POST /setup/administrator` deja de aceptar peticiones en
// cuanto existe una cuenta, y a partir de ahi la recuperacion es `/auth/login`.
//
// El segundo factor se da de alta con `TwoFactorEnrolPanel`, la MISMA pantalla
// que usa el acceso normal (RS-06): el `challenge_token` que devuelve este
// endpoint es exactamente el mismo `TwoFactorChallenge` que el `202` de
// `/auth/login`.
import { isApiError } from '@kronoqr/web-kit/http'
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import FormField from '@kronoqr/web-kit/components/FormField.vue'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import TwoFactorEnrolPanel from '@/features/auth/TwoFactorEnrolPanel.vue'
import { useSessionStore } from '@/features/auth/session.store'
import type { Session } from '@/shared/api/types'
import { createFirstAdministrator } from '../setup.api'
import { useSetupStore } from '../setup.store'

/** Nombre con el que queda registrada la sesion. Sin PII, como exige el contrato. */
const DEVICE_NAME = 'Panel de gestion'

const { t, locale } = useI18n()
const session = useSessionStore()
const setup = useSetupStore()

const name = ref('')
const email = ref('')
const password = ref('')
const submitting = ref(false)
const error = ref<unknown>(null)
const challengeToken = ref<string | null>(null)

/** `409`: ya existe una cuenta. No hay nada que reintentar aqui (regla dura 6). */
const accountAlreadyExists = computed(() => isApiError(error.value) && error.value.status === 409)

function fieldErrors(field: string): readonly string[] {
  return isApiError(error.value) ? (error.value.fieldErrors[field] ?? []) : []
}

async function submit(): Promise<void> {
  submitting.value = true
  error.value = null

  try {
    const challenge = await createFirstAdministrator({
      name: name.value,
      email: email.value,
      password: password.value,
      // El idioma del propio asistente: es el que tendra esta cuenta al
      // entrar por primera vez al panel.
      locale: locale.value,
      device_name: DEVICE_NAME,
    })

    challengeToken.value = challenge.challenge_token
  } catch (caught) {
    error.value = caught
    password.value = ''
  } finally {
    submitting.value = false
  }
}

async function onEnrolled(issued: Session): Promise<void> {
  session.applySession(issued)
  // El paso `administrator` es DERIVADO (no se marca): se relee el estado para
  // recoger que ya hay una cuenta con el segundo factor confirmado.
  await setup.refresh()
}

/**
 * El reto ha caducado o no vale mientras se daba de alta el TOTP. No hay
 * callejon sin salida: la cuenta ya existe, asi que se explica el camino de
 * vuelta por `/auth/login`, que emite el mismo reto (decision de la 5.5).
 */
function onChallengeInvalid(): void {
  challengeToken.value = null
  error.value = null
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <h2 tabindex="-1" class="text-lg font-semibold text-kq-text focus:outline-none">
      {{ t('onboarding.steps.administrator.heading') }}
    </h2>
    <p class="text-sm text-kq-text-muted">{{ t('onboarding.steps.administrator.intro') }}</p>

    <template v-if="challengeToken === null">
      <ErrorNotice
        v-if="error !== null"
        :error="error"
        :field-labels="{
          name: t('onboarding.steps.administrator.fields.name'),
          email: t('onboarding.steps.administrator.fields.email'),
          password: t('onboarding.steps.administrator.fields.password'),
        }"
      />

      <p
        v-if="accountAlreadyExists"
        class="rounded-kq border border-kq-warning bg-kq-warning-soft p-4 text-kq-warning"
        role="note"
      >
        {{ t('onboarding.steps.administrator.alreadyExists') }}
        <RouterLink :to="{ name: 'login' }" class="font-semibold underline">{{
          t('onboarding.steps.administrator.goToLogin')
        }}</RouterLink>
      </p>

      <form v-else class="flex flex-col gap-4" novalidate @submit.prevent="submit">
        <FormField
          v-slot="field"
          :label="t('onboarding.steps.administrator.fields.name')"
          :hint="t('onboarding.steps.administrator.hints.name')"
          :errors="fieldErrors('name')"
          required
        >
          <input
            :id="field.id"
            v-model="name"
            type="text"
            autocomplete="name"
            required
            :aria-describedby="field.describedBy"
            :aria-invalid="field.invalid"
            class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text"
          />
        </FormField>

        <FormField
          v-slot="field"
          :label="t('onboarding.steps.administrator.fields.email')"
          :hint="t('onboarding.steps.administrator.hints.email')"
          :errors="fieldErrors('email')"
          required
        >
          <input
            :id="field.id"
            v-model="email"
            type="email"
            autocomplete="username"
            required
            :aria-describedby="field.describedBy"
            :aria-invalid="field.invalid"
            class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text"
          />
        </FormField>

        <FormField
          v-slot="field"
          :label="t('onboarding.steps.administrator.fields.password')"
          :hint="t('onboarding.steps.administrator.hints.password')"
          :errors="fieldErrors('password')"
          required
        >
          <input
            :id="field.id"
            v-model="password"
            type="password"
            autocomplete="new-password"
            minlength="12"
            required
            :aria-describedby="field.describedBy"
            :aria-invalid="field.invalid"
            class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text"
          />
        </FormField>

        <div>
          <button
            type="submit"
            :disabled="submitting"
            :aria-busy="submitting"
            class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 font-semibold text-kq-on-primary disabled:opacity-60"
          >
            {{
              submitting
                ? t('onboarding.steps.administrator.submitting')
                : t('onboarding.steps.administrator.submit')
            }}
          </button>
        </div>
      </form>
    </template>

    <TwoFactorEnrolPanel
      v-else
      :challenge-token="challengeToken"
      @enrolled="onEnrolled"
      @challenge-invalid="onChallengeInvalid"
    />
  </div>
</template>
