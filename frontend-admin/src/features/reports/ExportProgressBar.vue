<script setup lang="ts">
// La barra de avance de una exportacion en curso. Honesta: sin porcentaje
// (`percent: null`, todo lo que no es la exportacion legal) es INDETERMINADA y no
// lleva `aria-valuenow`; con porcentaje, nunca llega a 100 antes de `completed`
// (lo garantiza el servidor). El tamaño lo pone quien la usa con `class`.
import { useI18n } from 'vue-i18n'

defineProps<{
  percent: number | null
  /** Lo que lee un lector de pantalla cuando no hay porcentaje: la fase. */
  stage: string
}>()

const { t } = useI18n()
</script>

<template>
  <div
    role="progressbar"
    :aria-label="t('reportExports.job.progressLabel')"
    aria-valuemin="0"
    aria-valuemax="100"
    :aria-valuenow="percent ?? undefined"
    :aria-valuetext="percent === null ? stage : `${percent} %`"
    class="overflow-hidden rounded-full bg-kq-surface-alt"
  >
    <div
      class="h-full rounded-full bg-kq-primary-strong"
      :class="percent === null ? 'w-1/3 animate-pulse' : ''"
      :style="percent === null ? undefined : { width: `${percent}%` }"
    ></div>
  </div>
</template>
