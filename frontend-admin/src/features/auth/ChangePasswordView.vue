<script setup lang="ts">
// Cambio de la contrasena PROPIA (RF-ID-10, `POST /auth/password`).
//
// Dos usos, una sola pantalla:
//  - **Obligado**: la cuenta entra con una contrasena temporal (la del alta o la
//    de un restablecimiento). Mientras no la cambie, la guarda del router no le
//    deja ver nada mas y el marco oculta el menu: solo puede cambiarla o cerrar
//    sesion.
//  - **Voluntario**: cualquier rol, desde el enlace del marco.
//
// Validacion en el cliente = solo lo que se puede saber sin conocer la politica:
// que no falte nada, que la confirmacion coincida y que la nueva no sea la
// actual. La politica de robustez (`IDENTITY_PASSWORD_MIN_LENGTH`, clases de
// caracteres) es configuracion de CADA instalacion (regla dura 13): el contrato
// no declara una longitud minima a proposito y la comprueba el servidor, que
// responde `422` con la regla que falta en `errors.new_password`.
//
// Una contrasena actual incorrecta es un `422` y no un `401`: la sesion sigue
// siendo valida y aqui no se vuelve al acceso.
import { announce } from '@kronoqr/web-kit/announcer'
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import FormField from '@kronoqr/web-kit/components/FormField.vue'
import { isApiError } from '@kronoqr/web-kit/http'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { changeOwnPassword } from './auth.api'
import { useSessionStore } from './session.store'

const MAX_LENGTH = 200
// bcrypt solo mira los primeros 72 bytes: el servidor rechaza mas, y se avisa antes.
const MAX_BYTES = 72

const { t } = useI18n()
const router = useRouter()
const session = useSessionStore()

const currentPassword = ref('')
const newPassword = ref('')
const confirmation = ref('')
const submitting = ref(false)
const error = ref<unknown>(null)
const done = ref(false)

const headingRef = ref<HTMLElement | null>(null)

// Si hay que cambiarla, se calcula al abrir: al terminar la marca desaparece y
// el texto de «hecho» no debe mutar a mitad de lectura.
const forced = ref(session.passwordChangeRequired)

onMounted(() => {
  headingRef.value?.focus()
})

const mismatch = computed(
  () => confirmation.value !== '' && confirmation.value !== newPassword.value,
)
const sameAsCurrent = computed(
  () => newPassword.value !== '' && newPassword.value === currentPassword.value,
)
const tooLong = computed(() => new TextEncoder().encode(newPassword.value).length > MAX_BYTES)
const canSubmit = computed(
  () =>
    currentPassword.value !== '' &&
    newPassword.value !== '' &&
    confirmation.value === newPassword.value &&
    !sameAsCurrent.value &&
    !tooLong.value &&
    !submitting.value,
)

function serverErrors(field: string): readonly string[] {
  return isApiError(error.value) && error.value.kind === 'validation'
    ? (error.value.fieldErrors[field] ?? [])
    : []
}

const currentErrors = computed(() => serverErrors('current_password'))
const newErrors = computed(() => [
  ...serverErrors('new_password'),
  ...(sameAsCurrent.value ? [t('changePassword.sameAsCurrent')] : []),
  ...(tooLong.value ? [t('changePassword.tooLong')] : []),
])
const confirmErrors = computed(() => (mismatch.value ? [t('changePassword.mismatch')] : []))

// Un `422` ya se pinta junto a cada campo; el resto de causas (red, bloqueo por
// intentos, 5xx) se explican arriba, con que hacer.
const conflict = computed(() => isApiError(error.value) && error.value.kind === 'conflict')
const showNotice = computed(
  () =>
    error.value !== null &&
    !conflict.value &&
    !(isApiError(error.value) && error.value.kind === 'validation'),
)

async function submit(): Promise<void> {
  if (!canSubmit.value) {
    return
  }

  submitting.value = true
  error.value = null

  try {
    await changeOwnPassword({
      current_password: currentPassword.value,
      new_password: newPassword.value,
    })
    currentPassword.value = ''
    newPassword.value = ''
    confirmation.value = ''
    // La marca de contrasena temporal desaparece en el servidor en la misma
    // transaccion: se relee para que la guarda deje de retener a la persona.
    await session.refreshUser()
    done.value = true
    announce(t('changePassword.announce.changed'))

    if (forced.value) {
      await router.replace({ name: 'home' })
    }
  } catch (caught) {
    error.value = caught
    currentPassword.value =
      isApiError(caught) && caught.kind === 'validation' ? '' : currentPassword.value
    announce(t('changePassword.announce.failed'))
  } finally {
    submitting.value = false
  }
}

const inputClass =
  'rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text'
</script>

<template>
  <section class="max-w-xl">
    <h1 ref="headingRef" tabindex="-1" class="text-2xl font-bold">
      {{ forced ? t('changePassword.forcedHeading') : t('changePassword.heading') }}
    </h1>
    <p class="mt-1 text-kq-text-muted">
      {{ forced ? t('changePassword.forcedIntro') : t('changePassword.intro') }}
    </p>

    <p
      v-if="done && !forced"
      role="status"
      class="mt-4 rounded-kq-sm border border-kq-border-strong bg-kq-surface-alt p-3"
      data-test="password-changed"
    >
      {{ t('changePassword.done') }}
    </p>

    <ErrorNotice v-if="showNotice" :error="error" class="mt-4" />
    <p
      v-if="conflict"
      role="alert"
      class="mt-4 rounded-kq border border-kq-danger bg-kq-danger-soft p-4 text-kq-danger"
      data-test="password-conflict"
    >
      {{ t('changePassword.conflict') }}
    </p>

    <form class="mt-4 flex flex-col gap-4" novalidate @submit.prevent="submit">
      <FormField
        v-slot="field"
        :label="t('changePassword.current')"
        :errors="currentErrors"
        required
      >
        <input
          :id="field.id"
          v-model="currentPassword"
          type="password"
          autocomplete="current-password"
          :maxlength="MAX_LENGTH"
          required
          :class="inputClass"
          :aria-invalid="field.invalid"
          :aria-describedby="field.describedBy"
          data-test="current-password"
        />
      </FormField>

      <FormField
        v-slot="field"
        :label="t('changePassword.new')"
        :hint="t('changePassword.policyHint')"
        :errors="newErrors"
        required
      >
        <input
          :id="field.id"
          v-model="newPassword"
          type="password"
          autocomplete="new-password"
          :maxlength="MAX_LENGTH"
          required
          :class="inputClass"
          :aria-invalid="field.invalid"
          :aria-describedby="field.describedBy"
          data-test="new-password"
        />
      </FormField>

      <FormField
        v-slot="field"
        :label="t('changePassword.confirm')"
        :errors="confirmErrors"
        required
      >
        <input
          :id="field.id"
          v-model="confirmation"
          type="password"
          autocomplete="new-password"
          :maxlength="MAX_LENGTH"
          required
          :class="inputClass"
          :aria-invalid="field.invalid"
          :aria-describedby="field.describedBy"
          data-test="confirm-password"
        />
      </FormField>

      <p class="text-sm text-kq-text-muted">{{ t('changePassword.otherSessions') }}</p>

      <div>
        <button
          type="submit"
          :disabled="!canSubmit"
          :aria-busy="submitting"
          class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 font-semibold text-kq-on-primary disabled:opacity-60"
        >
          {{ submitting ? t('common.saving') : t('changePassword.submit') }}
        </button>
      </div>
    </form>
  </section>
</template>
