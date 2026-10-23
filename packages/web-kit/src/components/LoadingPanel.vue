<script setup lang="ts">
// Estado de carga con voz propia: `role="status"` para que un lector de pantalla
// anuncie que se esta esperando, en lugar de dejar un hueco silencioso.
// Componente compartido por las SPA del panel y del portal (ADR-036).
//
// Si la espera pasa de `slowAfterMs` añade «esto tarda mas de lo normal»: sin
// ello, un servidor lento es indistinguible de una pantalla colgada (V3-UX-02).
// El aviso es un nodo aparte, con su propia region viva. La salida
// definitiva es el tiempo maximo del cliente HTTP (`DEFAULT_READ_TIMEOUT_MS`),
// que convierte la espera en un error con «Reintentar».
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

const props = withDefaults(defineProps<{ label: string; slowAfterMs?: number }>(), {
  slowAfterMs: 10_000,
})

const { t } = useI18n()
const slow = ref(false)
const announced = ref(false)
let timer: ReturnType<typeof setTimeout> | null = null

onMounted(() => {
  announced.value = true
  timer = setTimeout(() => {
    slow.value = true
  }, props.slowAfterMs)
})

onBeforeUnmount(() => {
  if (timer !== null) {
    clearTimeout(timer)
  }
})
</script>

<template>
  <!-- Regiones vivas que existen VACIAS y se rellenan: una region que aparece ya con su
       texto no se anuncia de forma fiable. El texto visible va aparte, oculto al lector. -->
  <div class="rounded-kq border border-kq-border bg-kq-surface-raised p-4 text-kq-text-muted">
    <span aria-hidden="true">{{ label }}</span>
    <span role="status" class="sr-only">{{ announced ? label : '' }}</span>
    <span role="status" data-test="loading-slow">
      <template v-if="slow">{{ ' ' + t('common.loadingSlow') }}</template>
    </span>
  </div>
</template>
