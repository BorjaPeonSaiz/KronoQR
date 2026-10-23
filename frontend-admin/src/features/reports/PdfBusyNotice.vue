<script setup lang="ts">
// Aviso de «motor de PDF ocupado» (503 `pdf-rendering-busy`), junto a las salidas
// que no pasan por el motor (CSV, XLSX). No es un fallo del informe: hay un tope de
// PDF simultaneos y basta esperar `Retry-After` o llevarse el mismo informe en otro
// formato. Con el motor ausente (`service-unavailable`) NO se usa este aviso.
import { computed, onMounted, ref, useId } from 'vue'
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
const root = ref<HTMLElement | null>(null)
const messageId = useId()

const message = computed(() =>
  props.retryAfterSeconds === null
    ? t('reports.pdfBusy.messageNoWait')
    : t('reports.pdfBusy.message', { seconds: props.retryAfterSeconds }),
)
// UNA sola señal (V21-3-PA-7): el aviso recibe el foco al aparecer y su nombre accesible
// es el mensaje, asi que un lector de pantalla lo lee al llegar. Sin `role="alert"` ni
// `announce()`, que lo hacian sonar dos veces. El boton «PDF» ya no pierde el foco al
// bloquearse (`aria-disabled`), de modo que el aviso lo recoge siempre: el siguiente Tab
// lleva a las alternativas y no al enlace de salto, ~30 paradas atras (V21-1-UX-2).
onMounted(() => {
  root.value?.focus()
})
</script>

<template>
  <div
    ref="root"
    role="group"
    :aria-labelledby="messageId"
    tabindex="-1"
    data-test="pdf-busy"
    class="mt-3 max-w-prose rounded-kq border border-kq-warning bg-kq-warning-soft p-4 text-kq-warning"
  >
    <p :id="messageId" class="font-semibold">{{ message }}</p>
    <div class="mt-3 flex flex-wrap gap-3">
      <button
        v-for="format of formats"
        :key="format"
        v-aria-disabled.busy="disabled"
        type="button"
        :data-test="`pdf-busy-use-${format}`"
        class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-1.5 font-semibold text-kq-text hover:bg-kq-surface-alt aria-disabled:opacity-50"
        @click="emit('use', format)"
      >
        {{ t('reports.pdfBusy.useFormat', { format: format.toUpperCase() }) }}
      </button>
    </div>
  </div>
</template>
