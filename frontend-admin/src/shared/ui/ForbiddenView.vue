<script setup lang="ts">
// A donde va quien escribe a mano la URL de una seccion que su rol no usa. La
// pantalla existe porque el enlace esta oculto, no bloqueado, y porque el
// servidor respondera 403 de todas formas (regla dura 18).
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<template>
  <section>
    <h1 class="text-2xl font-bold">{{ t('forbidden.heading') }}</h1>
    <p class="mt-2 max-w-prose text-kq-text-muted">{{ t('forbidden.description') }}</p>
  </section>
</template>
