<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'

const { t } = useI18n()
</script>

<template>
  <section>
    <h1 class="text-2xl font-bold">{{ t('notFound.heading') }}</h1>
    <p class="mt-2 max-w-prose text-slate-700">{{ t('notFound.description') }}</p>
    <RouterLink
      :to="{ name: 'employees' }"
      class="mt-4 inline-block rounded bg-slate-900 px-4 py-2 font-semibold text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900"
    >
      {{ t('notFound.backToEmployees') }}
    </RouterLink>
  </section>
</template>
