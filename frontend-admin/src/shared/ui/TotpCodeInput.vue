<script setup lang="ts">
// Campo del codigo de seis cifras del segundo factor (RS-06). Una sola copia
// para el acceso, el alta del segundo factor y la reautenticacion de quien
// actua: el saneado y los atributos del campo no pueden divergir.
//
// Se quitan espacios y separadores: algunos autenticadores muestran el codigo
// en dos grupos de tres. `id`, `name`, `aria-*`, `data-test` y las clases de
// presentacion llegan por atributos al `<input>`.
import { ref } from 'vue'

const code = defineModel<string>({ required: true })

const input = ref<HTMLInputElement | null>(null)

function onInput(event: Event): void {
  code.value = (event.target as HTMLInputElement).value.replace(/\D/g, '').slice(0, 6)
}

defineExpose({ focus: (): void => input.value?.focus() })
</script>

<template>
  <input
    ref="input"
    :value="code"
    type="text"
    inputmode="numeric"
    autocomplete="one-time-code"
    maxlength="6"
    required
    class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text"
    @input="onInput"
  />
</template>
