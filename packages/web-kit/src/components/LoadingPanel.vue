<script setup lang="ts">
// Estado de carga con voz propia: `role="status"` para que un lector de pantalla
// anuncie que se esta esperando, en lugar de dejar un hueco silencioso.
// Componente compartido por las SPA del panel y del portal (ADR-036).
defineProps<{ label: string }>()
</script>

<template>
  <p
    role="status"
    class="rounded-kq border border-kq-border bg-kq-surface-raised p-4 text-kq-text-muted"
  >
    {{ label }}
  </p>
</template>
