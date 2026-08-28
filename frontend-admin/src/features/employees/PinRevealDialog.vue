<script setup lang="ts">
// Visualizacion UNICA del PIN (RF-ID-09, RL-05).
//
// Reglas que este componente hace cumplir, no que documenta:
//
//  - El PIN llega por `props` desde el estado efimero de la pantalla que lo
//    pidio y **no se escribe en ningun sitio**: ni `localStorage`, ni
//    `sessionStorage`, ni la tienda de Pinia, ni la cache de consultas. Al
//    cerrar el dialogo, el padre pone su `ref` a `null` y el valor desaparece.
//  - No se puede cerrar por descuido: `dismissible: false` desactiva Escape y
//    el velo. Solo se sale por una accion explicita, y esa accion dice
//    literalmente que el PIN no se va a poder volver a consultar.
//  - No hay boton de copiar. Un PIN en el portapapeles acaba pegado en un chat.
//  - La entrega en mano es un acto aparte, con su propia casilla de
//    confirmacion: marcarla escribe en `audit_log` con fecha y responsable, y no
//    se puede repetir (el servidor responde 409).
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { IssuedPin } from '@/shared/api/types'
import BaseDialog from '@/shared/ui/BaseDialog.vue'
import { deliverEmployeePin } from './employees.api'

const props = defineProps<{
  pin: IssuedPin
  employeeName: string
  employeeCode: string
}>()

const emit = defineEmits<{ acknowledged: []; delivered: [] }>()

const { t } = useI18n()

const handedOver = ref(false)
const submitting = ref(false)
const error = ref<unknown>(null)

async function registerDelivery(): Promise<void> {
  submitting.value = true
  error.value = null

  try {
    await deliverEmployeePin(props.pin.employee_uuid)
    emit('delivered')
  } catch (caught) {
    error.value = caught
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseDialog :title="t('pin.reveal.heading')" :dismissible="false">
    <p class="text-kq-text-muted">
      {{ t('pin.reveal.forEmployee', { name: employeeName, code: employeeCode }) }}
    </p>

    <p
      class="mt-4 rounded-kq border-2 border-kq-border-strong bg-kq-surface-alt py-6 text-center font-mono text-4xl tracking-[0.4em] text-kq-text"
      data-test="pin-value"
    >
      {{ pin.pin }}
    </p>

    <p
      role="alert"
      class="mt-4 rounded-kq-sm border border-kq-warning bg-kq-warning-soft p-3 text-kq-warning"
    >
      {{ t('pin.reveal.onlyOnce') }}
    </p>

    <p class="mt-3 text-sm text-kq-text-muted">{{ t('pin.reveal.handDelivery') }}</p>

    <ErrorNotice v-if="error !== null" :error="error" class="mt-4" />

    <label class="mt-4 flex items-start gap-2">
      <input v-model="handedOver" type="checkbox" class="mt-1" />
      <span>{{ t('pin.reveal.handedOverLabel', { name: employeeName }) }}</span>
    </label>

    <template #actions>
      <button
        type="button"
        class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-4 py-2 text-kq-text hover:bg-kq-surface-alt"
        @click="emit('acknowledged')"
      >
        {{ t('pin.reveal.acknowledge') }}
      </button>
      <button
        type="button"
        :disabled="!handedOver || submitting"
        :aria-busy="submitting"
        class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 font-semibold text-kq-on-primary disabled:opacity-60"
        @click="registerDelivery"
      >
        {{ submitting ? t('common.saving') : t('pin.reveal.registerDelivery') }}
      </button>
    </template>
  </BaseDialog>
</template>
