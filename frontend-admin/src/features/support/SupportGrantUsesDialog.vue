<script setup lang="ts">
// Usos de un acceso de soporte (RF-PD-11, ADR-020): cada fila es el INICIO de
// una ventana de 15 minutos de uso, no una peticion. La lista es de solo
// lectura y la lee solo `admin`; el servidor registra la consulta.
import EmptyState from '@kronoqr/web-kit/components/EmptyState.vue'
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import LoadingPanel from '@kronoqr/web-kit/components/LoadingPanel.vue'
import { formatInstantWithZone } from '@kronoqr/web-kit/datetime'
import { keepPreviousData, useQuery } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import BaseDialog from '@/shared/ui/BaseDialog.vue'
import PaginationBar from '@/shared/ui/PaginationBar.vue'
import { listSupportGrantUses } from './support.api'

const props = defineProps<{
  grantUuid: string
  timezone: string
}>()

const emit = defineEmits<{ close: [] }>()

const { t, locale } = useI18n()

const page = ref(1)

const {
  data: uses,
  error,
  isPending,
  isFetching,
  refetch,
} = useQuery({
  queryKey: computed(() => ['support-grant-uses', props.grantUuid, page.value] as const),
  queryFn: () => listSupportGrantUses(props.grantUuid, page.value),
  placeholderData: keepPreviousData,
})

const rows = computed(() => uses.value?.data ?? [])
const meta = computed(() => uses.value?.meta ?? null)
</script>

<template>
  <BaseDialog :title="t('support.grant.uses.heading')" size="wide" @close="emit('close')">
    <div class="flex flex-col gap-3" data-test="grant-uses">
      <p class="text-sm text-kq-text-muted" data-test="grant-uses-note">
        {{ t('support.grant.uses.windowNote') }}
      </p>

      <LoadingPanel v-if="isPending && error === null" :label="t('support.grant.uses.loading')" />
      <ErrorNotice v-else-if="error !== null" :error="error" @retry="refetch()" />
      <EmptyState
        v-else-if="rows.length === 0"
        :title="t('support.grant.uses.empty.title')"
        :description="t('support.grant.uses.empty.description')"
      />
      <template v-else>
        <div
          tabindex="0"
          role="region"
          :aria-label="t('support.grant.uses.caption')"
          class="overflow-x-auto rounded-kq border border-kq-border"
        >
          <table class="w-full border-collapse text-left">
            <caption class="px-3 py-2 text-left text-sm text-kq-text-muted">
              {{
                t('support.grant.uses.caption')
              }}
            </caption>
            <thead class="border-b border-kq-border bg-kq-surface-alt">
              <tr>
                <th scope="col" class="px-3 py-2">{{ t('support.grant.uses.columns.start') }}</th>
                <th scope="col" class="px-3 py-2">{{ t('support.grant.uses.columns.method') }}</th>
                <th scope="col" class="px-3 py-2">{{ t('support.grant.uses.columns.route') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="entry of rows"
                :key="`${entry.started_at}-${entry.method}-${entry.route}`"
                class="border-b border-kq-border"
                data-test="grant-use-row"
              >
                <td class="px-3 py-2">
                  {{ formatInstantWithZone(entry.started_at, timezone, locale) }}
                </td>
                <td class="px-3 py-2">{{ entry.method ?? t('common.empty') }}</td>
                <td class="px-3 py-2 font-mono text-sm break-all">
                  {{ entry.route ?? t('common.empty') }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <PaginationBar
          v-if="meta !== null"
          :page="meta.page"
          :per-page="meta.per_page"
          :total="meta.total"
          :total-pages="meta.total_pages"
          :fetching="isFetching"
          :label="t('support.grant.uses.pagination')"
          @update:page="page = $event"
        />
      </template>
    </div>

    <template #actions>
      <button
        type="button"
        class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-4 py-2 text-kq-text hover:bg-kq-surface-alt"
        data-test="grant-uses-close"
        @click="emit('close')"
      >
        {{ t('common.close') }}
      </button>
    </template>
  </BaseDialog>
</template>
