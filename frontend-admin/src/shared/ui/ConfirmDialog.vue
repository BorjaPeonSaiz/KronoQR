<script setup lang="ts">
// Confirmacion de un acto con consecuencias.
//
// No es un «¿seguro?». El cuerpo del dialogo lo pone quien lo abre y tiene que
// decir QUE se va a registrar y QUE efecto tiene, porque casi todo lo que pasa
// por aqui escribe en `audit_log` y no se puede deshacer: una entrega, una
// revocacion, una baja, un PIN restablecido.
import { useI18n } from 'vue-i18n'
import BaseDialog from './BaseDialog.vue'
import ErrorNotice from './ErrorNotice.vue'

withDefaults(
  defineProps<{
    title: string
    confirmLabel: string
    busy?: boolean
    /**
     * Obligatorio, y `null` cuando no hay fallo: un dialogo que confirma un acto
     * con consecuencias no puede olvidarse de enseñar por que no se hizo.
     */
    error: unknown
    /** `danger` para lo irreversible: baja, revocacion, impresion de un QR. */
    tone?: 'normal' | 'danger'
    size?: 'normal' | 'wide'
    /** Deshabilita el boton de confirmar mientras falte un dato obligatorio. */
    confirmDisabled?: boolean
  }>(),
  { busy: false, tone: 'normal', size: 'normal', confirmDisabled: false },
)

const emit = defineEmits<{ confirm: []; cancel: [] }>()

const { t } = useI18n()
</script>

<template>
  <BaseDialog :title="title" :size="size" @close="emit('cancel')">
    <slot />
    <ErrorNotice v-if="error !== null && error !== undefined" :error="error" class="mt-4" />

    <template #actions>
      <button
        type="button"
        class="rounded border border-slate-400 px-4 py-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900"
        @click="emit('cancel')"
      >
        {{ t('common.cancel') }}
      </button>
      <button
        type="button"
        :disabled="busy || confirmDisabled"
        :aria-busy="busy"
        class="rounded px-4 py-2 font-semibold text-white disabled:opacity-60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900"
        :class="tone === 'danger' ? 'bg-red-700' : 'bg-slate-900'"
        @click="emit('confirm')"
      >
        {{ busy ? t('common.saving') : confirmLabel }}
      </button>
    </template>
  </BaseDialog>
</template>
