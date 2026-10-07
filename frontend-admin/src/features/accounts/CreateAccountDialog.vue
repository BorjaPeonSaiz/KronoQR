<script setup lang="ts">
// Alta de una cuenta de gestion (RF-ID-10, RF-ID-02).
//
// Lo que el formulario dice en voz alta porque son decisiones del producto:
//  - No hay campo de contrasena: la genera el servidor, es temporal y se
//    entrega en mano (regla dura 12). Aqui no se envia nada por correo.
//  - Tampoco hay departamentos: un responsable de departamento nace sin
//    alcance y se le asigna en el propio departamento.
//
// El alta devuelve un secreto que se muestra UNA vez: por eso emite `created`
// con la respuesta entera (cuenta y contrasena) y no solo con la cuenta.
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import FormField from '@kronoqr/web-kit/components/FormField.vue'
import { isApiError } from '@kronoqr/web-kit/http'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ManagementAccountProvisioned, ManagementRole } from '@/shared/api/types'
import BaseDialog from '@/shared/ui/BaseDialog.vue'
import ActorReauthFields from './ActorReauthFields.vue'
import { createManagementAccount } from './accounts.api'
import { useActorReauth, useRetryCountdown } from './actorReauth'

const ROLES: readonly ManagementRole[] = ['admin', 'rrhh', 'responsable_departamento', 'auditor']

const emit = defineEmits<{ close: []; created: [ManagementAccountProvisioned] }>()

const { t, locale: uiLocale } = useI18n()

const name = ref('')
const email = ref('')
const role = ref<ManagementRole>('rrhh')
const locale = ref(uiLocale.value === 'en' ? 'en' : 'es')
const submitting = ref(false)
const error = ref<unknown>(null)
const reauth = useActorReauth()
const { remaining } = useRetryCountdown(error)

const canSubmit = computed(
  () =>
    name.value.trim() !== '' &&
    email.value.trim() !== '' &&
    reauth.valid.value &&
    remaining.value === 0 &&
    !submitting.value,
)

const conflict = computed(() => isApiError(error.value) && error.value.kind === 'conflict')

function fieldErrors(field: string): readonly string[] {
  return isApiError(error.value) ? (error.value.fieldErrors[field] ?? []) : []
}

const emailErrors = computed(() => [
  ...fieldErrors('email'),
  ...(conflict.value ? [t('accounts.create.emailTaken')] : []),
])

async function submit(): Promise<void> {
  if (!canSubmit.value) {
    return
  }

  submitting.value = true
  error.value = null

  try {
    emit(
      'created',
      await createManagementAccount({
        name: name.value.trim(),
        email: email.value.trim(),
        role: role.value,
        locale: locale.value,
        ...reauth.payload(),
      }),
    )
  } catch (caught) {
    error.value = caught
    reauth.value.value = ''
  } finally {
    submitting.value = false
  }
}

const inputClass =
  'rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text'
</script>

<template>
  <BaseDialog :title="t('accounts.create.heading')" @close="emit('close')">
    <form id="account-create-form" class="grid gap-4" novalidate @submit.prevent="submit">
      <ErrorNotice
        v-if="error !== null && !conflict"
        :error="error"
        :field-labels="{
          name: t('accounts.fields.name'),
          email: t('accounts.fields.email'),
          role: t('accounts.fields.role'),
          locale: t('accounts.fields.locale'),
          actor_totp_code: t('accounts.reauth.totpLabel'),
          actor_current_password: t('accounts.reauth.passwordLabel'),
        }"
      />

      <FormField
        v-slot="field"
        :label="t('accounts.fields.name')"
        :hint="t('accounts.fields.nameHint')"
        required
      >
        <input
          :id="field.id"
          v-model="name"
          type="text"
          required
          maxlength="120"
          autocomplete="off"
          :class="inputClass"
          :aria-describedby="field.describedBy"
          :aria-invalid="field.invalid"
          data-test="account-name"
        />
      </FormField>

      <FormField
        v-slot="field"
        :label="t('accounts.fields.email')"
        :hint="t('accounts.fields.emailHint')"
        :errors="emailErrors"
        required
      >
        <input
          :id="field.id"
          v-model="email"
          type="email"
          required
          maxlength="190"
          autocomplete="off"
          :class="inputClass"
          :aria-describedby="field.describedBy"
          :aria-invalid="field.invalid"
          data-test="account-email"
        />
      </FormField>

      <FormField
        v-slot="field"
        :label="t('accounts.fields.role')"
        :hint="t('accounts.fields.roleHint')"
        required
      >
        <select
          :id="field.id"
          v-model="role"
          :class="inputClass"
          :aria-describedby="field.describedBy"
          data-test="account-role"
        >
          <option v-for="option of ROLES" :key="option" :value="option">
            {{ t(`app.roles.${option}`) }}
          </option>
        </select>
      </FormField>

      <FormField v-slot="field" :label="t('accounts.fields.locale')">
        <select :id="field.id" v-model="locale" :class="inputClass" data-test="account-locale">
          <option value="es">{{ t('common.locales.es') }}</option>
          <option value="en">{{ t('common.locales.en') }}</option>
        </select>
      </FormField>

      <ActorReauthFields
        v-model="reauth.value.value"
        :uses-password="reauth.usesPassword.value"
        :errors="fieldErrors(reauth.field.value)"
      />

      <p v-if="remaining > 0" class="text-sm font-medium" data-test="retry-countdown">
        {{ t('accounts.reauth.wait', { seconds: remaining }) }}
      </p>

      <p class="text-sm text-kq-text-muted">{{ t('accounts.create.passwordNotice') }}</p>
    </form>

    <template #actions>
      <button
        type="button"
        class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-4 py-2 text-kq-text hover:bg-kq-surface-alt"
        @click="emit('close')"
      >
        {{ t('common.cancel') }}
      </button>
      <button
        type="submit"
        form="account-create-form"
        :disabled="!canSubmit"
        :aria-busy="submitting"
        class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 font-semibold text-kq-on-primary disabled:opacity-60"
      >
        {{ submitting ? t('common.saving') : t('accounts.create.submit') }}
      </button>
    </template>
  </BaseDialog>
</template>
