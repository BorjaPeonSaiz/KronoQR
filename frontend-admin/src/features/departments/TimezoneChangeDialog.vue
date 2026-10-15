<script setup lang="ts">
// Cambio de la zona horaria del centro, con su «antes → despues» (RN-23, ADR-056).
//
// La zona decide a que jornada pertenece cada turno (RN-05), asi que se puede
// cambiar solo hasta el primer fichaje. Si mientras el dialogo estaba abierto se
// registra ese primer fichaje, el servidor responde `409 site-time-zone-locked`:
// el dialogo no lo pinta como un fallo cualquiera, avisa a quien lo abrio
// (`locked`) para que relea el centro y explique que ya no se puede.
// Un `422` (zona no reconocida) se pinta junto al campo.
import { announce } from '@kronoqr/web-kit/announcer'
import { isApiError } from '@kronoqr/web-kit/http'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { isSiteTimeZoneLocked } from '@/shared/api/problems'
import ChangePreview from '@/shared/ui/ChangePreview.vue'
import type { Change } from '@/shared/ui/change'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import TimezoneField from '@/shared/ui/TimezoneField.vue'

const props = defineProps<{
  currentTimezone: string
  /** Hace la peticion; lanza si falla. */
  save: (timezone: string) => Promise<void>
}>()

const emit = defineEmits<{ saved: [timezone: string]; locked: []; cancel: [] }>()

const { t } = useI18n()

const timezone = ref(props.currentTimezone)
const submitting = ref(false)
const error = ref<unknown>(null)

const trimmed = computed(() => timezone.value.trim())
const unchanged = computed(() => trimmed.value === props.currentTimezone)
const valid = computed(() => trimmed.value.length > 0)

const changes = computed<Change[]>(() => [
  {
    label: t('site.timezone'),
    from: props.currentTimezone,
    to: trimmed.value || t('common.empty'),
  },
])

const timezoneErrors = computed(() =>
  isApiError(error.value) ? (error.value.fieldErrors['timezone'] ?? []) : [],
)
const dialogError = computed(() =>
  isApiError(error.value) && error.value.kind === 'validation' && timezoneErrors.value.length > 0
    ? null
    : error.value,
)

async function confirm(): Promise<void> {
  if (!valid.value || unchanged.value || submitting.value) {
    return
  }

  submitting.value = true
  error.value = null

  try {
    await props.save(trimmed.value)
    emit('saved', trimmed.value)
  } catch (caught) {
    if (isSiteTimeZoneLocked(caught)) {
      emit('locked')
    } else {
      error.value = caught
      announce(t('site.announce.failed'))
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <ConfirmDialog
    :title="t('site.timezoneTitle')"
    :confirm-label="t('site.changeTimezone')"
    :busy="submitting"
    :error="dialogError"
    :confirm-disabled="!valid || unchanged"
    @cancel="emit('cancel')"
    @confirm="confirm"
  >
    <TimezoneField
      v-model="timezone"
      :label="t('site.timezone')"
      :hint="t('site.timezoneOpen')"
      :errors="timezoneErrors"
      @keydown.enter.prevent="confirm"
    />

    <ChangePreview
      :changes="changes"
      :caption="t('site.timezoneTitle')"
      class="mt-4"
      data-test="timezone-preview"
    />
  </ConfirmDialog>
</template>
