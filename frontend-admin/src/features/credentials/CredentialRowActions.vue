<script setup lang="ts">
// Botones y diálogos de acción de UNA fila de credencial (RF-QR-04, RF-QR-06,
// RF-QR-08). Fuente única de esta lógica: la usan tanto el tablero
// (`CredentialBoardView`) como la sección «Tarjeta QR» de la ficha de
// empleado (`EmployeeDetailView`), para que emitir, imprimir, entregar y
// revocar se comporten y se expliquen igual en las dos pantallas.
//
// No decide cuándo refrescar: eso es cosa de quien la usa. Al terminar una
// acción, emite `changed` y quien la contenga decide qué volver a pedir.
import { announce } from '@kronoqr/web-kit/announcer'
import { downloadDocument } from '@kronoqr/web-kit/downloadDocument'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Change } from '@/shared/ui/change'
import ChangePreview from '@/shared/ui/ChangePreview.vue'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import type { CredentialStatusRow } from '@/shared/api/types'
import {
  deliverCredential,
  issueCredential,
  printCredential,
  revokeCredential,
} from './credentials.api'

const props = defineProps<{
  row: CredentialStatusRow
  /**
   * Zona horaria del centro de la fila. No formatea ninguna fecha aqui hoy
   * —eso lo hace quien pinta «impresa el» / «entregada el»—, pero forma
   * parte del contrato del componente para cuando un dialogo necesite decir
   * «se emitio el…» en la zona correcta, y no en la del navegador de quien
   * mira (regla dura del panel: la zona se muestra, no se adivina).
   */
  timeZone: string
}>()

const emit = defineEmits<{ changed: [] }>()

type RowActionKind = 'issue' | 'print' | 'deliver' | 'revoke'

/** Motivos de revocacion. Un catalogo evita el «se perdio» sin mas contexto. */
const REVOCATION_REASONS = ['lost', 'stolen', 'damaged', 'offboarding', 'printFailed'] as const

const { t } = useI18n()

const action = ref<RowActionKind | null>(null)
const busy = ref(false)
const actionError = ref<unknown>(null)
const revocationReasonKey = ref('')
const revocationReasonText = ref('')

const revocationReason = computed(() =>
  revocationReasonKey.value === 'other'
    ? revocationReasonText.value.trim()
    : revocationReasonKey.value === ''
      ? ''
      : t(`credentials.revoke.reasons.${revocationReasonKey.value}`),
)

function openAction(kind: RowActionKind): void {
  actionError.value = null
  revocationReasonKey.value = ''
  revocationReasonText.value = ''
  action.value = kind
}

function closeAction(): void {
  action.value = null
  actionError.value = null
}

const changes = computed<Change[]>(() => {
  if (action.value === null) {
    return []
  }

  const to: Record<RowActionKind, string> = {
    issue: t('credentials.status.pending_print'),
    print: t('credentials.status.pending_delivery'),
    deliver: t('credentials.status.delivered'),
    revoke: t('credentials.status.revoked'),
  }

  return [
    {
      label: t('credentials.table.status'),
      from: t(`credentials.status.${props.row.status}`),
      to: to[action.value],
    },
  ]
})

async function perform(kind: RowActionKind): Promise<void> {
  const row = props.row

  if (kind === 'issue') {
    // `reissue: false` siempre: aqui solo se emite a quien NO tiene credencial
    // activa —sin ninguna, o con la ultima revocada—. Una reemision revoca la
    // anterior y exige motivo, y ese camino pasa por «revocar» primero.
    await issueCredential({ employee_uuid: row.employee_uuid, reissue: false })
    announce(t('credentials.announce.issued', { name: row.full_name }))

    return
  }

  const credentialUuid = row.credential?.uuid

  if (credentialUuid === undefined) {
    return
  }

  if (kind === 'print') {
    const document_ = await printCredential(credentialUuid)

    if (document_ !== null) {
      downloadDocument(document_)
    }

    announce(t('credentials.announce.printed', { name: row.full_name }))

    return
  }

  if (kind === 'deliver') {
    await deliverCredential(credentialUuid)
    announce(t('credentials.announce.delivered', { name: row.full_name }))

    return
  }

  await revokeCredential(credentialUuid, { reason: revocationReason.value })
  announce(t('credentials.announce.revoked', { name: row.full_name }))
}

async function runAction(): Promise<void> {
  const kind = action.value

  if (kind === null) {
    return
  }

  busy.value = true
  actionError.value = null

  try {
    await perform(kind)
    closeAction()
    emit('changed')
  } catch (caught) {
    actionError.value = caught
  } finally {
    busy.value = false
  }
}

const buttonClass =
  'rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-2 py-1 text-sm text-kq-text hover:bg-kq-surface-alt'
const dangerButtonClass =
  'rounded-kq-sm bg-kq-danger px-2 py-1 text-sm font-semibold text-kq-on-danger'
const selectClass =
  'rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text'
</script>

<template>
  <div class="flex flex-wrap gap-2">
    <button
      v-if="row.status === 'no_credential' || row.status === 'revoked'"
      type="button"
      :class="buttonClass"
      @click="openAction('issue')"
    >
      {{ t('credentials.actions.issue') }}
    </button>
    <button
      v-if="row.status === 'pending_print'"
      type="button"
      :class="buttonClass"
      @click="openAction('print')"
    >
      {{ t('credentials.actions.print') }}
    </button>
    <button
      v-if="row.status === 'pending_delivery'"
      type="button"
      :class="buttonClass"
      @click="openAction('deliver')"
    >
      {{ t('credentials.actions.deliver') }}
    </button>
    <button
      v-if="row.credential !== null && row.status !== 'revoked' && row.status !== 'no_credential'"
      type="button"
      :class="dangerButtonClass"
      @click="openAction('revoke')"
    >
      {{ t('credentials.actions.revoke') }}
    </button>
  </div>

  <ConfirmDialog
    v-if="action !== null"
    :title="t(`credentials.confirm.${action}.heading`)"
    :confirm-label="t(`credentials.confirm.${action}.action`)"
    :tone="action === 'issue' ? 'normal' : 'danger'"
    :busy="busy"
    :error="actionError"
    size="wide"
    :confirm-disabled="action === 'revoke' && revocationReason === ''"
    @cancel="closeAction"
    @confirm="runAction"
  >
    <p class="mb-4">
      {{ t(`credentials.confirm.${action}.explanation`, { name: row.full_name }) }}
    </p>

    <div v-if="action === 'revoke'" class="mb-4 grid gap-4 sm:grid-cols-2">
      <div class="flex flex-col gap-1">
        <label for="revocation-reason" class="font-medium">
          {{ t('credentials.revoke.reasonLabel') }}
        </label>
        <select id="revocation-reason" v-model="revocationReasonKey" :class="selectClass">
          <option value="">{{ t('credentials.revoke.reasonPlaceholder') }}</option>
          <option v-for="key of REVOCATION_REASONS" :key="key" :value="key">
            {{ t(`credentials.revoke.reasons.${key}`) }}
          </option>
          <option value="other">{{ t('credentials.revoke.reasons.other') }}</option>
        </select>
      </div>
      <div v-if="revocationReasonKey === 'other'" class="flex flex-col gap-1">
        <label for="revocation-reason-text" class="font-medium">
          {{ t('credentials.revoke.reasonOtherLabel') }}
        </label>
        <input
          id="revocation-reason-text"
          v-model="revocationReasonText"
          type="text"
          maxlength="190"
          :class="selectClass"
        />
      </div>
    </div>

    <ChangePreview :changes="changes" :caption="t(`credentials.confirm.${action}.heading`)" />

    <p class="mt-4 text-sm text-kq-text-muted">
      {{ t(`credentials.confirm.${action}.notice`) }}
    </p>
  </ConfirmDialog>
</template>
