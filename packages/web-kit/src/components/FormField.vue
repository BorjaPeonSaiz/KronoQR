<script setup lang="ts">
// Etiqueta, pista y error atados al control por `id` y `aria-describedby`. Un
// `placeholder` no es una etiqueta y un mensaje de error suelto al lado del
// campo no lo lee nadie que navegue con teclado. Componente compartido por las
// SPA del panel y del portal (ADR-036).
//
// `labelClass` existe porque el portal, pensado para leerse desde un movil,
// usa una etiqueta mas grande (`text-lg`) que el panel: es la unica diferencia
// visual real que tenian las dos copias antes de esta migracion, y no era una
// divergencia accidental sino una decision de legibilidad. Con un valor por
// omision igual al que ya tenia el panel, migrar `frontend-admin` a este
// componente no cambia nada en pantalla.
import { computed, useId } from 'vue'

const props = withDefaults(
  defineProps<{
    label: string
    hint?: string
    errors?: readonly string[]
    required?: boolean
    labelClass?: string
  }>(),
  { hint: '', errors: () => [], required: false, labelClass: 'font-medium text-kq-text' },
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
    <label :for="fieldId" :class="labelClass">
      {{ label }}
      <span v-if="required" aria-hidden="true">*</span>
    </label>
    <slot :id="fieldId" :described-by="describedBy" :invalid="invalid" />
    <p v-if="hint !== ''" :id="hintId" class="text-sm text-kq-text-muted">{{ hint }}</p>
    <p v-if="invalid" :id="errorId" class="text-sm font-medium text-kq-danger">
      {{ errors.join(' ') }}
    </p>
  </div>
</template>
