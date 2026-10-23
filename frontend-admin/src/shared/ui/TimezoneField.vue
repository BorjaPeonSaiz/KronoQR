<script setup lang="ts">
// Campo de zona horaria IANA del centro, compartido por el asistente de puesta en
// marcha y por el dialogo «Cambiar zona» del centro (RN-23).
//
// Texto libre con sugerencias: una zona IANA («Europe/Madrid»), nunca un desfase
// fijo, que no sabe de cambios de hora. La validacion real es del servidor.
import FormField from '@kronoqr/web-kit/components/FormField.vue'
import { useId } from 'vue'
import { timezoneOptions } from './timezoneOptions'

const props = withDefaults(
  defineProps<{
    label: string
    hint?: string
    errors?: readonly string[]
  }>(),
  { hint: '', errors: () => [] },
)

const model = defineModel<string>({ required: true })

const listId = useId()
const zones = timezoneOptions()
const inputClass =
  'rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text'
</script>

<template>
  <FormField v-slot="field" :label="props.label" :hint="props.hint" :errors="props.errors" required>
    <input
      :id="field.id"
      v-model="model"
      type="text"
      :list="listId"
      required
      autocomplete="off"
      :aria-describedby="field.describedBy"
      :aria-invalid="field.invalid"
      :class="inputClass"
      data-test="timezone-input"
    />
    <datalist :id="listId">
      <option v-for="zone in zones" :key="zone" :value="zone" />
    </datalist>
  </FormField>
</template>
