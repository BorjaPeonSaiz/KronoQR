<script setup lang="ts">
// El campo de reautenticacion de quien actua (ver `actorReauth.ts`): codigo de
// su segundo factor, o su contrasena actual si no tiene ninguno confirmado. Un
// `422` en este campo se pinta aqui, junto a el, y no en un aviso generico.
import FormField from '@kronoqr/web-kit/components/FormField.vue'
import { useI18n } from 'vue-i18n'
import TotpCodeInput from '@/shared/ui/TotpCodeInput.vue'

defineProps<{
  usesPassword: boolean
  errors: readonly string[]
}>()

const value = defineModel<string>({ required: true })

const { t } = useI18n()

const inputClass =
  'rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text'
</script>

<template>
  <FormField
    v-slot="field"
    :label="usesPassword ? t('accounts.reauth.passwordLabel') : t('accounts.reauth.totpLabel')"
    :hint="usesPassword ? t('accounts.reauth.passwordHint') : t('accounts.reauth.totpHint')"
    :errors="errors"
    required
  >
    <input
      v-if="usesPassword"
      :id="field.id"
      v-model="value"
      type="password"
      autocomplete="current-password"
      maxlength="200"
      required
      :class="inputClass"
      :aria-invalid="field.invalid"
      :aria-describedby="field.describedBy"
      data-test="actor-reauth"
    />
    <TotpCodeInput
      v-else
      :id="field.id"
      v-model="value"
      :aria-invalid="field.invalid"
      :aria-describedby="field.describedBy"
      data-test="actor-reauth"
    />
  </FormField>
</template>
