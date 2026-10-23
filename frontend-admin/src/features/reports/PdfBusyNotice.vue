<script setup lang="ts">
// Aviso de «motor de PDF ocupado» (503 `pdf-rendering-busy`), junto a las salidas
// que no pasan por el motor (CSV, XLSX). No es un fallo del informe: hay un tope de
// PDF simultaneos y basta esperar `Retry-After` o llevarse el mismo informe en otro
// formato. Con el motor ausente (`service-unavailable`) NO se usa este aviso.
//
// La cuenta atras baja de verdad (V21-3-UX-2) y, al llegar a 0, el mensaje cambia a «ya puedes
// volver a intentarlo». El foco al aparecer y la señal unica (V21-3-PA-7, V21-1-UX-2) los da
// `NoticeWithActions`; el boton «PDF» ya no pierde el foco al bloquearse (`aria-disabled`), de
// modo que el aviso lo recoge siempre y el siguiente Tab lleva a las alternativas.
import BaseButton from '@kronoqr/web-kit/components/BaseButton.vue'
import NoticeWithActions from '@kronoqr/web-kit/components/NoticeWithActions.vue'
import { useCountdown } from '@kronoqr/web-kit/countdown'
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
const remaining = useCountdown(() => props.retryAfterSeconds)

const message = computed(() => {
  if (remaining.value === null) return t('reports.pdfBusy.messageNoWait')

  return remaining.value === 0
    ? t('reports.pdfBusy.messageReady')
    : t('reports.pdfBusy.message', { seconds: remaining.value })
})
</script>

<template>
  <NoticeWithActions :message="message" focus-on-mount class="mt-3" data-test="pdf-busy">
    <template #actions>
      <BaseButton
        v-for="format of formats"
        :key="format"
        variant="secondary"
        :busy="disabled"
        :data-test="`pdf-busy-use-${format}`"
        @click="emit('use', format)"
      >
        {{ t('reports.pdfBusy.useFormat', { format: format.toUpperCase() }) }}
      </BaseButton>
    </template>
  </NoticeWithActions>
</template>
