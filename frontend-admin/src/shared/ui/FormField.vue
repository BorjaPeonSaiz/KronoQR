<script setup lang="ts">
// Etiqueta, pista y error atados al control por `id` y `aria-describedby`. Un
// `placeholder` no es una etiqueta y un mensaje de error suelto al lado del
// campo no lo lee nadie que navegue con teclado.
import { computed, useId } from 'vue'

const props = withDefaults(
  defineProps<{
    label: string
    hint?: string
    errors?: readonly string[]
    required?: boolean
  }>(),
  { hint: '', errors: () => [], required: false },
)

const fieldId = useId()
const hintId = useId()
const errorId = useId()

const invalid = computed(() => props.errors.length > 0)

const describedBy = computed(() => {
  const ids: string[] = []

  if (props.hint !== '') {
    ids.push(hintId)
  }

  if (invalid.value) {
    ids.push(errorId)
  }

  return ids.length > 0 ? ids.join(' ') : undefined
})
</script>

<template>
  <div class="flex flex-col gap-1">
    <label :for="fieldId" class="font-medium text-slate-900">
      {{ label }}
      <span v-if="required" aria-hidden="true">*</span>
    </label>
    <slot :id="fieldId" :described-by="describedBy" :invalid="invalid" />
    <p v-if="hint !== ''" :id="hintId" class="text-sm text-slate-600">{{ hint }}</p>
    <p v-if="invalid" :id="errorId" class="text-sm font-medium text-red-700">
      {{ errors.join(' ') }}
    </p>
  </div>
</template>
