<script setup lang="ts">
// Acceso al portal personal (RF-ID-06, ADR-015).
//
// Codigo de empleado y PIN de seis digitos, los mismos que sirven de respaldo
// en el quiosco. Nada de correo ni de contraseña (regla dura 12): quien
// necesite recuperar el acceso pide a RRHH que le restablezca el PIN, nunca un
// enlace por correo.
//
// **Un solo mensaje para cualquier rechazo** (RS-03, regla dura 17). El
// servidor no distingue codigo inexistente, PIN incorrecto, PIN nunca emitido,
// baja o bloqueo por intentos activo, y esta pantalla no lo desune: el error
// que se pinta es siempre el mismo, `errors.invalidCredentials`, venga lo que
// venga en `problem.type`.
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import FormField from '@kronoqr/web-kit/components/FormField.vue'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useSessionStore } from './session.store'

const PIN_PATTERN = /^\d{6}$/

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const session = useSessionStore()

const employeeCode = ref('')
const pin = ref('')
const submitting = ref(false)
const error = ref<unknown>(null)

/**
 * Formato completo, no si el codigo o el PIN son correctos: eso solo lo sabe
 * el servidor, y decidirlo aqui seria empezar a distinguir lo que RS-03 exige
 * mantener unido.
 */
const canSubmit = computed(
  () => !submitting.value && employeeCode.value.trim() !== '' && PIN_PATTERN.test(pin.value),
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

  try {
    await session.logIn({ employee_code: employeeCode.value.trim(), pin: pin.value })
    await router.replace(redirectTarget())
  } catch (caught) {
    error.value = caught
  } finally {
    // El PIN nunca se deja escrito en pantalla, acierte o falle (regla dura 21).
    pin.value = ''
    submitting.value = false
  }
}
</script>

<template>
  <main class="flex min-h-dvh items-center justify-center bg-slate-100 p-4">
    <div class="w-full max-w-md rounded-lg border border-slate-300 bg-white p-6 shadow-sm">
      <h1 class="text-2xl font-bold text-slate-900">{{ t('login.heading') }}</h1>
      <p class="mt-2 text-lg text-slate-700">{{ t('login.intro') }}</p>

      <ErrorNotice v-if="error !== null" :error="error" class="mt-4" />

      <form class="mt-6 flex flex-col gap-5" novalidate @submit.prevent="submit">
        <FormField
          v-slot="field"
          :label="t('login.employeeCode')"
          label-class="text-lg font-medium text-slate-900"
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
            class="min-h-12 rounded border border-slate-400 px-3 py-2 text-lg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900"
          />
        </FormField>

        <FormField
          v-slot="field"
          :label="t('login.pin')"
          :hint="t('login.pinHint')"
          label-class="text-lg font-medium text-slate-900"
          required
        >
          <input
            :id="field.id"
            v-model="pin"
            type="password"
            name="pin"
            inputmode="numeric"
            autocomplete="off"
            maxlength="6"
            required
            :aria-describedby="field.describedBy"
            class="min-h-12 rounded border border-slate-400 px-3 py-2 text-lg tracking-widest focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900"
          />
        </FormField>

        <button
          type="submit"
          :disabled="!canSubmit"
          :aria-busy="submitting"
          class="min-h-12 rounded bg-slate-900 px-4 py-2 text-lg font-semibold text-white disabled:opacity-60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900"
        >
          {{ submitting ? t('login.submitting') : t('login.submit') }}
        </button>
      </form>

      <p class="mt-6 text-base text-slate-600">{{ t('login.forgotPin') }}</p>
    </div>
  </main>
</template>
