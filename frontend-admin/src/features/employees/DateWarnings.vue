<script setup lang="ts">
// Los avisos de fecha de un alta o una readmision, en UNA sola caja (RN-27, ADR-062 §6).
//
// Pueden coincidir dos (alta futura y baja reciente). Dos cajas ambar una debajo de
// otra gritan mas que una caja con dos parrafos, y dos regiones vivas hacen que el
// lector de pantalla las lea como si fueran dos asuntos. Aqui hay una region viva
// SIEMPRE presente (vacia no ocupa sitio) a la que se añade la caja: un aviso
// insertado ya relleno no se anuncia de forma fiable, uno que se escribe dentro de
// una region que ya existia, si.
//
// Cada parrafo lo pone quien usa el componente, con su `data-test`. Son avisos, no
// bloqueos: el servidor decide y la tablet nunca bloquea al empleado (regla dura 19).
defineProps<{
  /** Hay al menos un aviso que enseñar. */
  visible: boolean
}>()
</script>

<template>
  <div role="status" class="empty:hidden" data-test="date-warnings">
    <div
      v-if="visible"
      class="flex flex-col gap-2 rounded-kq-sm border border-kq-warning bg-kq-warning-soft p-3 text-kq-warning"
    >
      <slot />
    </div>
  </div>
</template>
