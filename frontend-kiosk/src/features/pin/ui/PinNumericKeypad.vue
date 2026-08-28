<script setup lang="ts">
// Teclado numerico dedicado para el PIN (RF-AT-11, doc 01 §6.5).
//
// Doce objetivos, los doce >= 48 px y con texto >= 24 px: alcanza con un solo
// pulgar y funciona con guantes. A proposito NO ensena los digitos tecleados
// (ver `PinView.vue`): quien mira por encima del hombro en una recepcion no
// tiene que poder leer el PIN de otra persona.
import { useI18n } from 'vue-i18n'

const props = defineProps<{
  disabled?: boolean
}>()

const emit = defineEmits<{
  digit: [value: string]
  backspace: []
  clear: []
}>()

const { t } = useI18n()

const DIGITS = ['1', '2', '3', '4', '5', '6', '7', '8', '9']
</script>

<template>
  <div class="grid grid-cols-3 gap-3" role="group" :aria-label="t('pin.keypad.label')">
    <button
      v-for="digit in DIGITS"
      :key="digit"
      type="button"
      class="kiosk-touch rounded-kq-sm border border-kq-kiosk-border bg-kq-kiosk-surface-raised text-confirm-sm font-semibold text-kq-kiosk-text disabled:opacity-40"
      :disabled="props.disabled"
      @click="emit('digit', digit)"
    >
      {{ digit }}
    </button>

    <button
      type="button"
      class="kiosk-touch rounded-kq-sm border border-kq-kiosk-border bg-kq-kiosk-surface-raised text-lg font-semibold text-kq-kiosk-text-muted disabled:opacity-40"
      :disabled="props.disabled"
      @click="emit('clear')"
    >
      {{ t('pin.keypad.clear') }}
    </button>

    <button
      type="button"
      class="kiosk-touch rounded-kq-sm border border-kq-kiosk-border bg-kq-kiosk-surface-raised text-confirm-sm font-semibold text-kq-kiosk-text disabled:opacity-40"
      :disabled="props.disabled"
      @click="emit('digit', '0')"
    >
      0
    </button>

    <button
      type="button"
      class="kiosk-touch rounded-kq-sm border border-kq-kiosk-border bg-kq-kiosk-surface-raised text-lg font-semibold text-kq-kiosk-text-muted disabled:opacity-40"
      :disabled="props.disabled"
      :aria-label="t('pin.keypad.backspace')"
      data-testid="pin-backspace"
      @click="emit('backspace')"
    >
      ⌫
    </button>
  </div>
</template>
