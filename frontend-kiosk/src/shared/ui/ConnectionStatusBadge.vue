<script setup lang="ts">
// Indicador permanente de estado de conexion (RF-KI-04).
//
// Discreto pero SIEMPRE presente. Y honesto: cuando no hay red lo dice, y dice
// tambien que se puede fichar igual, que es la informacion que evita que alguien
// se vaya sin fichar por miedo a que «no haya funcionado».
//
// No se comunica solo por color: hay texto y un punto con forma distinta, para
// quien no distingue el verde del ambar.
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ConnectivityStatus } from '@/shared/connectivity/useConnectivity'

const props = withDefaults(
  defineProps<{
    status: ConnectivityStatus
    pendingCount?: number
    /** Hay un drenaje de la cola en curso (tarea 1.9). */
    syncing?: boolean
  }>(),
  { pendingCount: 0, syncing: false },
)

const { t } = useI18n()

const isOffline = computed(() => props.status === 'offline')

// «Sincronizando» solo cuando de verdad se esta enviando algo. Decirlo con la
// cola vacia convertiria el indicador en ruido, y el valor de este indicador es
// justamente que se pueda creer lo que dice.
const isSyncing = computed(() => props.syncing && !isOffline.value && props.pendingCount > 0)

const label = computed(() => {
  if (isOffline.value) return t('connection.offline')
  return isSyncing.value ? t('connection.syncing') : t('connection.online')
})
</script>

<template>
  <div
    class="flex items-center gap-3 rounded-kq-sm px-4 py-2 text-lg font-medium"
    :class="
      isOffline
        ? 'bg-kiosk-notice text-white'
        : 'border border-kq-kiosk-border bg-kq-kiosk-surface-raised text-kq-kiosk-text'
    "
    role="status"
    aria-live="polite"
    data-testid="connection-status"
    :data-status="isOffline ? 'offline' : 'online'"
    :data-pending="props.pendingCount"
  >
    <span aria-hidden="true" class="text-xl">{{ isOffline ? '⚠' : isSyncing ? '↻' : '●' }}</span>
    <span>{{ label }}</span>
    <span v-if="props.pendingCount > 0" class="font-semibold">
      · {{ t('connection.pending', { count: props.pendingCount }, props.pendingCount) }}
    </span>
  </div>
</template>
