<script setup lang="ts">
// Aviso con acciones: un mensaje que no es un fallo y lleva consigo las salidas
// (descargar en otro formato, reintentar). Antes el aviso de «motor de PDF ocupado» estaba escrito
// a mano en el panel y otra vez en el portal; aqui hay una sola forma.
//
// El nombre accesible (`message`) es FIJO: lo que cambia cada segundo (una cuenta atras) va en el
// slot por omision, fuera de `aria-labelledby`, para que ni el lector ni el nombre se muevan.
//
// Una sola señal para un lector de pantalla: el aviso es un `group` cuyo nombre accesible es el
// mensaje y, con `focusOnMount`, recibe el foco al aparecer. Sin `role="alert"` ni `announce()`,
// que lo harian sonar dos veces. Las acciones van en el slot `actions`; el detalle
// opcional, en el slot por omision.
import { onMounted, ref, useId } from 'vue'

export type NoticeTone = 'warning' | 'danger'

const props = withDefaults(
  defineProps<{
    message: string
    tone?: NoticeTone
    /** Lleva el foco al aviso cuando aparece. */
    focusOnMount?: boolean
  }>(),
  { tone: 'warning', focusOnMount: false },
)

const TONES: Readonly<Record<NoticeTone, string>> = {
  warning: 'border-kq-warning bg-kq-warning-soft text-kq-warning',
  danger: 'border-kq-danger bg-kq-danger-soft text-kq-danger',
}

const root = ref<HTMLElement | null>(null)
const messageId = useId()

onMounted(() => {
  if (props.focusOnMount) root.value?.focus()
})

defineExpose({ focus: (): void => root.value?.focus() })
</script>

<template>
  <div
    ref="root"
    role="group"
    :aria-labelledby="messageId"
    tabindex="-1"
    :class="`max-w-prose rounded-kq border p-4 ${TONES[tone]}`"
  >
    <p :id="messageId" class="font-semibold">{{ message }}</p>
    <slot />
    <div v-if="$slots['actions']" class="mt-3 flex flex-wrap gap-3">
      <slot name="actions" />
    </div>
  </div>
</template>
