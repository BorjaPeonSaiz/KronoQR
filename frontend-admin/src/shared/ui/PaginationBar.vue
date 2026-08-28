<script setup lang="ts">
// Barra de paginacion reutilizable. La usan la plantilla (paginada en el
// servidor) y el panel de credenciales (paginado en el cliente): el
// componente no sabe ni le importa de donde sale la pagina, solo pinta el
// estado que le pasan y pide la siguiente.
//
// El resumen «de X a Y de Z» y el «pagina N de M» son las cifras que de verdad
// importan aqui: nunca un porcentaje ni un redondeo, para que quien mira sepa
// exactamente cuanto le falta por ver.
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = withDefaults(
  defineProps<{
    page: number
    perPage: number
    total: number
    totalPages: number
    /** Mientras se repite la consulta en segundo plano (TanStack Query). */
    fetching?: boolean
    /**
     * Texto del aria-label del `<nav>`: cada pantalla dice de que es esta
     * paginacion. No se llama `ariaLabel` a proposito: Vue no convierte
     * `aria-*` de kebab a camelCase al resolver props (es un atributo de
     * paso, como `data-*`), lo que rompe la comprobacion de tipos si el
     * nombre de la prop coincide con ese prefijo.
     */
    label: string
  }>(),
  { fetching: false },
)

const emit = defineEmits<{ 'update:page': [page: number] }>()

const { t } = useI18n()

const rangeStart = computed(() => (props.total === 0 ? 0 : (props.page - 1) * props.perPage + 1))
const rangeEnd = computed(() => Math.min(props.page * props.perPage, props.total))

function goToPage(next: number): void {
  const bound = Math.max(props.totalPages, 1)

  emit('update:page', Math.min(Math.max(next, 1), bound))
}
</script>

<template>
  <nav :aria-label="label" class="mt-4 flex flex-wrap items-center justify-between gap-3">
    <p>
      {{ t('common.pagination.summary', { from: rangeStart, to: rangeEnd, total }) }}
      <span v-if="fetching" class="text-kq-text-muted">{{ t('common.updating') }}</span>
    </p>
    <div class="flex gap-2">
      <button
        type="button"
        class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text hover:bg-kq-surface-alt disabled:opacity-50"
        :disabled="page <= 1"
        @click="goToPage(page - 1)"
      >
        {{ t('common.pagination.previous') }}
      </button>
      <p aria-current="page">
        {{ t('common.pagination.page', { page, pages: totalPages }) }}
      </p>
      <button
        type="button"
        class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text hover:bg-kq-surface-alt disabled:opacity-50"
        :disabled="page >= totalPages"
        @click="goToPage(page + 1)"
      >
        {{ t('common.pagination.next') }}
      </button>
    </div>
  </nav>
</template>
