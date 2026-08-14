<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<template>
  <main class="flex min-h-dvh flex-col items-center justify-center gap-6 bg-slate-900 p-8">
    <h1 class="text-center text-4xl font-bold text-slate-50">{{ t('app.title') }}</h1>
    <p class="max-w-prose text-center text-xl text-slate-200">{{ t('app.skeletonNotice') }}</p>
  </main>
</template>
