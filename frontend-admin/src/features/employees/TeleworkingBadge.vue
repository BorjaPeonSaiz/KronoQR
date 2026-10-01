<script setup lang="ts">
// Marca de teletrabajo de una fila (RF-GP-01). Siempre lleva texto: el color
// solo refuerza, nunca es el unico portador del dato (WCAG 1.4.1). Informativo.
import { useI18n } from 'vue-i18n'

defineProps<{ teleworking: boolean }>()

const { t } = useI18n()
</script>

<template>
  <span
    v-if="teleworking"
    class="rounded-full bg-kq-primary-soft px-2 py-0.5 text-sm font-medium text-kq-on-primary-soft"
    data-test="teleworking-badge"
  >
    {{ t('employees.teleworking.yes') }}
  </span>
  <span v-else class="text-kq-text-muted" data-test="teleworking-none">
    {{ t('employees.teleworking.no') }}
  </span>
</template>
