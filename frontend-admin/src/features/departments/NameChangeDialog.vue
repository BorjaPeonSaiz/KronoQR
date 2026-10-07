<script setup lang="ts">
// Renombrado de un departamento o del centro, con su «antes → despues».
//
// Un nombre acaba en listados, informes y exportaciones: antes de guardar se
// enseña que cambia, desde que valor y hacia cual (`ChangePreview`). Un `422`
// (nombre repetido, vacio o demasiado largo) se pinta junto al campo; el resto de
// fallos, en el aviso del dialogo.
import { announce } from '@kronoqr/web-kit/announcer'
import FormField from '@kronoqr/web-kit/components/FormField.vue'
import { isApiError } from '@kronoqr/web-kit/http'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import ChangePreview from '@/shared/ui/ChangePreview.vue'
import type { Change } from '@/shared/ui/change'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'

const NAME_MAX_LENGTH = 120

const props = defineProps<{
  title: string
  fieldLabel: string
  currentName: string
  /** Hace la peticion; lanza si falla. */
  save: (name: string) => Promise<void>
}>()

const emit = defineEmits<{ saved: [name: string]; cancel: [] }>()

const { t } = useI18n()

const name = ref(props.currentName)
const submitting = ref(false)
const error = ref<unknown>(null)

const trimmed = computed(() => name.value.trim())
const unchanged = computed(() => trimmed.value === props.currentName)
const valid = computed(() => trimmed.value.length >= 1 && trimmed.value.length <= NAME_MAX_LENGTH)

const changes = computed<Change[]>(() => [
  { label: props.fieldLabel, from: props.currentName, to: trimmed.value || t('common.empty') },
])

const nameErrors = computed(() =>
  isApiError(error.value) ? (error.value.fieldErrors['name'] ?? []) : [],
)
const dialogError = computed(() =>
  isApiError(error.value) && error.value.kind === 'validation' && nameErrors.value.length > 0
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
    error.value = caught
    announce(t('departments.announce.renameFailed'))
  } finally {
    submitting.value = false
  }
}

const inputClass =
  'rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text'
</script>

<template>
  <ConfirmDialog
    :title="title"
    :confirm-label="t('departments.rename.confirm')"
    :busy="submitting"
    :error="dialogError"
    :confirm-disabled="!valid || unchanged"
    @cancel="emit('cancel')"
    @confirm="confirm"
  >
    <FormField v-slot="field" :label="fieldLabel" :errors="nameErrors" required>
      <input
        :id="field.id"
        v-model="name"
        type="text"
        required
        :maxlength="NAME_MAX_LENGTH"
        autocomplete="off"
        :class="inputClass"
        :aria-invalid="field.invalid"
        :aria-describedby="field.describedBy"
        data-test="rename-input"
        @keydown.enter.prevent="confirm"
      />
    </FormField>

    <ChangePreview
      :changes="changes"
      :caption="t('departments.rename.previewCaption')"
      class="mt-4"
      data-test="rename-preview"
    />
  </ConfirmDialog>
</template>
