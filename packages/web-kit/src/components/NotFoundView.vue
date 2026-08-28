<script setup lang="ts">
// Cualquier direccion que no case con ninguna ruta declarada. Componente
// compartido por las SPA del panel y del portal (ADR-036).
//
// El titulo y la explicacion usan las mismas claves de i18n en las dos
// aplicaciones (`notFound.heading`, `notFound.description`) porque decian
// exactamente lo mismo ya antes de compartir el componente. Lo que SI es propio
// de cada SPA es a donde vuelve el enlace de rescate —la plantilla en el panel,
// «mi registro» en el portal— y eso se pasa por `props` desde la ruta:
//
// ```ts
// { path: ':pathMatch(.*)*', name: 'not-found', component: NotFoundView,
//   props: { backToRouteName: 'employees', backToLabelKey: 'notFound.backToEmployees' } }
// ```
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'

defineProps<{
  /** Nombre de la ruta a la que vuelve el enlace de rescate. */
  backToRouteName: string
  /** Clave de i18n del texto del enlace, propia de cada SPA. */
  backToLabelKey: string
}>()

const { t } = useI18n()
</script>

<template>
  <section>
    <h1 class="text-2xl font-bold font-heading">{{ t('notFound.heading') }}</h1>
    <p class="mt-2 max-w-prose text-kq-text-muted">{{ t('notFound.description') }}</p>
    <RouterLink
      :to="{ name: backToRouteName }"
      class="mt-4 inline-block rounded-kq-sm bg-kq-primary-strong px-4 py-2 font-semibold text-kq-on-primary"
    >
      {{ t(backToLabelKey) }}
    </RouterLink>
  </section>
</template>
