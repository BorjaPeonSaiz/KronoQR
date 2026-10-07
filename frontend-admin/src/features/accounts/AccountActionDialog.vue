<script setup lang="ts">
// Baja y restablecimientos de una cuenta de gestion (RF-ID-10).
//
// Las tres son actos serios y auditados, asi que el dialogo dice QUE cambia,
// DESDE que valor y HACIA cual (`ChangePreview`) antes de confirmar, y pide el
// motivo donde el contrato lo exige (baja y restablecimiento del 2FA; el de la
// contrasena no lleva cuerpo).
//
//  - `deactivate`: estado `active` → `deactivated`. Nunca un borrado: la cuenta
//    y su historial se conservan (regla dura 5). `404` unico para «no existe» o
//    «ya estaba de baja»; `409` para la propia cuenta o la ultima admin.
//  - `resetPassword`: la contrasena pasa a ser una temporal que se enseña UNA
//    vez; cierra todas las sesiones de la cuenta. No toca el segundo factor.
//  - `resetTwoFactor`: retira el segundo factor; cierra todas las sesiones y
//    abre una ventana hasta que su titular lo active de nuevo (se dice).
//
// El resultado sube al padre con el tipo que corresponde; la contrasena
// temporal, si la hay, no se queda aqui.
import FormField from '@kronoqr/web-kit/components/FormField.vue'
import { isApiError } from '@kronoqr/web-kit/http'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ManagementAccount, TemporaryPasswordIssued } from '@/shared/api/types'
import ChangePreview from '@/shared/ui/ChangePreview.vue'
import type { Change } from '@/shared/ui/change'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import ActorReauthFields from './ActorReauthFields.vue'
import { useActorReauth, useRetryCountdown } from './actorReauth'
import {
  deactivateManagementAccount,
  resetManagementAccountPassword,
  resetManagementAccountTwoFactor,
} from './accounts.api'

export type AccountActionKind = 'deactivate' | 'resetPassword' | 'resetTwoFactor'

const REASON_MAX_LENGTH = 190

const props = defineProps<{ kind: AccountActionKind; account: ManagementAccount }>()
const emit = defineEmits<{
  cancel: []
  deactivated: [ManagementAccount]
  twoFactorReset: [ManagementAccount]
  passwordReset: [ManagementAccount, TemporaryPasswordIssued]
  /** `404`: la cuenta ya no existe o ya estaba de baja; el padre relee la lista. */
  gone: []
}>()

const { t } = useI18n()

const reason = ref('')
const submitting = ref(false)
const error = ref<unknown>(null)
const reauth = useActorReauth()
const { remaining } = useRetryCountdown(error)

// Baja: sin reautenticacion (la hace el rol y el candado del servidor). Los dos
// restablecimientos si: son los actos que mas se parecen a «dame esa cuenta».
const needsReauth = computed(() => props.kind !== 'deactivate')

const reasonValue = computed(() => reason.value.trim())
const reasonValid = computed(
  () => reasonValue.value.length >= 1 && reasonValue.value.length <= REASON_MAX_LENGTH,
)
const canConfirm = computed(
  () => reasonValid.value && (!needsReauth.value || reauth.valid.value) && remaining.value === 0,
)

// Un `409` es «no se admite sobre esta cuenta»: el servidor no dice cual de las causas
// (su `detail` es para quien depura), asi que se enseñan las posibles.
const conflictHintShown = computed(
  () =>
    props.kind !== 'resetPassword' && isApiError(error.value) && error.value.kind === 'conflict',
)

// Un `422` solo del campo de reautenticacion ya se pinta junto a el: repetirlo en el aviso
// generico (con el nombre de campo de la API) seria ruido.
const dialogError = computed(() => {
  if (isApiError(error.value) && error.value.kind === 'validation') {
    const fields = Object.keys(error.value.fieldErrors)

    if (fields.length > 0 && fields.every((field) => field === reauth.field.value)) {
      return null
    }
  }

  return error.value
})

const reauthErrors = computed(() =>
  isApiError(error.value) ? (error.value.fieldErrors[reauth.field.value] ?? []) : [],
)

const changes = computed<Change[]>(() => {
  const account = props.account

  if (props.kind === 'deactivate') {
    return [
      {
        label: t('accounts.table.status'),
        from: t(`accounts.status.${account.status}`),
        to: t('accounts.status.deactivated'),
      },
    ]
  }

  if (props.kind === 'resetPassword') {
    return [
      {
        label: t('accounts.table.password'),
        from: t(`accounts.password.${account.password_status}`),
        to: t('accounts.password.temporary'),
      },
    ]
  }

  return [
    {
      label: t('accounts.table.twoFactor'),
      from: t('accounts.twoFactor.enabled'),
      to: t('accounts.twoFactor.disabled'),
    },
  ]
})

async function confirm(): Promise<void> {
  if (!canConfirm.value || submitting.value) {
    return
  }

  submitting.value = true
  error.value = null

  try {
    if (props.kind === 'deactivate') {
      emit(
        'deactivated',
        await deactivateManagementAccount(props.account.uuid, { reason: reasonValue.value }),
      )
    } else if (props.kind === 'resetTwoFactor') {
      emit(
        'twoFactorReset',
        await resetManagementAccountTwoFactor(props.account.uuid, {
          reason: reasonValue.value,
          ...reauth.payload(),
        }),
      )
    } else {
      emit(
        'passwordReset',
        props.account,
        await resetManagementAccountPassword(props.account.uuid, {
          reason: reasonValue.value,
          ...reauth.payload(),
        }),
      )
    }
  } catch (caught) {
    if (isApiError(caught) && caught.kind === 'notFound') {
      // «No existe o ya estaba de baja»: no hay nada que reintentar, solo releer.
      emit('gone')

      return
    }

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
  <ConfirmDialog
    :title="t(`accounts.actions.${kind}.heading`)"
    :confirm-label="t(`accounts.actions.${kind}.confirm`)"
    :tone="kind === 'deactivate' ? 'danger' : 'normal'"
    size="wide"
    :busy="submitting"
    :error="dialogError"
    :confirm-disabled="!canConfirm"
    @cancel="emit('cancel')"
    @confirm="confirm"
  >
    <p class="mb-4">
      {{ t(`accounts.actions.${kind}.explanation`, { name: account.name, email: account.email }) }}
    </p>

    <ChangePreview
      :changes="changes"
      :caption="t('accounts.actions.previewCaption', { name: account.name })"
      class="mb-4"
    />

    <FormField
      v-slot="field"
      :label="t('accounts.actions.reasonLabel')"
      :hint="t('accounts.actions.reasonHint')"
      required
    >
      <textarea
        :id="field.id"
        v-model="reason"
        rows="3"
        :maxlength="REASON_MAX_LENGTH"
        required
        :class="inputClass"
        :aria-describedby="field.describedBy"
        data-test="account-reason"
      />
    </FormField>

    <ActorReauthFields
      v-if="needsReauth"
      v-model="reauth.value.value"
      class="mt-4"
      :uses-password="reauth.usesPassword.value"
      :errors="reauthErrors"
    />

    <p v-if="remaining > 0" class="mt-3 text-sm font-medium" data-test="retry-countdown">
      {{ t('accounts.reauth.wait', { seconds: remaining }) }}
    </p>

    <p class="mt-4 text-sm text-kq-text-muted">{{ t(`accounts.actions.${kind}.notice`) }}</p>

    <p v-if="conflictHintShown" class="mt-3 text-sm text-kq-text-muted" data-test="conflict-hint">
      {{ t(`accounts.actions.${kind}.conflictHint`) }}
    </p>
  </ConfirmDialog>
</template>
