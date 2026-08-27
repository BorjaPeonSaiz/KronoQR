<script setup lang="ts">
// Acceso al panel de gestion (RF-ID-01).
//
// Solo personal de gestion. El empleado NO entra por aqui: su portal usa codigo
// de empleado y PIN (ADR-015, regla dura 12), y esta pantalla lo dice en voz
// alta para que nadie llame a RRHH preguntando por su contraseña.
//
// Sin segundo factor: el 2FA obligatorio es de la Fase 2 y llegara como un
// desenlace nuevo de este mismo endpoint (ADR-012).
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { isApiError } from '@/shared/api/http'
import ErrorNotice from '@/shared/ui/ErrorNotice.vue'
import FormField from '@/shared/ui/FormField.vue'
import { useSessionStore } from './session.store'

/** Nombre con el que queda registrada la sesion. Sin PII, como exige el contrato. */
const DEVICE_NAME = 'Panel de gestion'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const session = useSessionStore()

const email = ref('')
const password = ref('')
const submitting = ref(false)
const error = ref<unknown>(null)

function fieldErrors(field: string): readonly string[] {
  return isApiError(error.value) ? (error.value.fieldErrors[field] ?? []) : []
}

function redirectTarget(): string {
  const redirect = route.query['redirect']

  return typeof redirect === 'string' && redirect.startsWith('/') ? redirect : '/employees'
}

async function submit(): Promise<void> {
  submitting.value = true
  error.value = null

  try {
    await session.logIn({
      email: email.value,
      password: password.value,
      device_name: DEVICE_NAME,
    })
    await router.replace(redirectTarget())
  } catch (caught) {
    error.value = caught
    password.value = ''
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <main class="flex min-h-dvh items-center justify-center bg-slate-100 p-4">
    <div class="w-full max-w-md rounded-lg border border-slate-300 bg-white p-6 shadow-sm">
      <h1 class="text-2xl font-bold text-slate-900">{{ t('auth.heading') }}</h1>
      <p class="mt-2 text-slate-700">{{ t('auth.intro') }}</p>

      <ErrorNotice v-if="error !== null" :error="error" class="mt-4" />

      <form class="mt-6 flex flex-col gap-4" novalidate @submit.prevent="submit">
        <FormField v-slot="field" :label="t('auth.email')" :errors="fieldErrors('email')" required>
          <input
            :id="field.id"
            v-model="email"
            type="email"
            name="email"
            autocomplete="username"
            required
            :aria-describedby="field.describedBy"
            :aria-invalid="field.invalid"
            class="rounded border border-slate-400 px-3 py-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900"
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
            class="rounded border border-slate-400 px-3 py-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900"
          />
        </FormField>

        <button
          type="submit"
          :disabled="submitting"
          :aria-busy="submitting"
          class="rounded bg-slate-900 px-4 py-2 font-semibold text-white disabled:opacity-60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900"
        >
          {{ submitting ? t('auth.submitting') : t('auth.submit') }}
        </button>
      </form>

      <p class="mt-6 text-sm text-slate-600">{{ t('auth.employeeNotice') }}</p>
    </div>
  </main>
</template>
