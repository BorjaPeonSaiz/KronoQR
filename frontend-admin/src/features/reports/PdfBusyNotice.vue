<script setup lang="ts">
// Aviso de «motor de PDF ocupado» (503 `pdf-rendering-busy`), junto a las salidas
// que no pasan por el motor (CSV, XLSX). No es un fallo del informe: hay un tope de
// PDF simultaneos y basta esperar `Retry-After` o llevarse el mismo informe en otro
// formato. Con el motor ausente (`service-unavailable`) NO se usa este aviso.
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{
  /** `Retry-After` del servidor; `null` si no lo mando. */
  retryAfterSeconds: number | null
  /** Los formatos que la pantalla ofrece sin motor de PDF. */
  formats: readonly string[]
  disabled: boolean
}>()

const emit = defineEmits<{ use: [format: string] }>()

const { t } = useI18n()

const message = computed(() =>
  props.retryAfterSeconds === null
    ? t('reports.pdfBusy.messageNoWait')
    : t('reports.pdfBusy.message', { seconds: props.retryAfterSeconds }),
)
</script>

<template>
  <div
    role="alert"
    data-test="pdf-busy"
    class="mt-3 max-w-prose rounded-kq border border-kq-warning bg-kq-warning-soft p-4 text-kq-warning"
  >
    <p class="font-semibold">{{ message }}</p>
    <div class="mt-3 flex flex-wrap gap-3">
      <button
        v-for="format of formats"
        :key="format"
        type="button"
        :disabled="disabled"
        :data-test="`pdf-busy-use-${format}`"
        class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-1.5 font-semibold text-kq-text hover:bg-kq-surface-alt disabled:opacity-50"
        @click="emit('use', format)"
      >
        {{ t('reports.pdfBusy.useFormat', { format: format.toUpperCase() }) }}
      </button>
    </div>
  </div>
</template>
