<script setup lang="ts">
// Un vacio explicado. «No hay resultados» a secas deja a quien mira sin saber si
// el filtro esta mal o si de verdad no hay nada. Componente compartido por las
// SPA del panel y del portal (ADR-036).
defineProps<{ title: string; description: string }>()
</script>

<template>
  <div
    class="rounded-kq border border-dashed border-kq-border bg-kq-surface-raised p-6 text-center"
  >
    <p class="font-semibold text-kq-text">{{ title }}</p>
    <p class="mt-1 text-kq-text-muted">{{ description }}</p>
    <!-- Vacio por omision: no toda pantalla tiene una accion que ofrecer desde
         un vacio (el portal no la usa hoy; el panel si, para «dar de alta»). -->
    <div class="mt-4 flex justify-center">
      <slot />
    </div>
  </div>
</template>
