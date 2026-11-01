<script setup lang="ts">
// La tarjeta nueva de una readmision, ofrecida para imprimir en el acto
// (RN-27, ADR-062 §6, ADR-034).
//
// La readmision deja una credencial NUEVA pendiente de imprimir; las tarjetas del
// periodo anterior siguen revocadas. Imprimirla es el acto que acuña el QR y no
// tiene vuelta atras (no hay reimpresion), asi que no se imprime sola: aqui se
// ofrece, con el aviso, y se puede dejar para mas tarde desde Credenciales.
//
// Una persona: su tarjeta (`POST /credentials/{uuid}/print`). Varias: la hoja A4
// con TODAS las pendientes de la instalacion (`POST /credentials/print-batch`);
// el contrato no admite elegir cuales, y el aviso lo dice para que nadie crea que
// solo salen las de esta lista. Quien no lleva `credentials:*` no ve los botones
// (cortesia: el servidor responde 403) y se le dice a quien pedirlo.
import { announce } from '@kronoqr/web-kit/announcer'
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import { downloadDocument } from '@kronoqr/web-kit/downloadDocument'
import { useQueryClient } from '@tanstack/vue-query'
import { computed, nextTick, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { CREDENTIALS_MANAGE } from '@/features/auth/abilities'
import { useSessionStore } from '@/features/auth/session.store'
import { printCredential, printCredentialBatch } from '@/features/credentials/credentials.api'
import BaseDialog from '@/shared/ui/BaseDialog.vue'
import type { RehireCardItem } from './rehirePlan'

const props = defineProps<{ items: readonly RehireCardItem[] }>()

const emit = defineEmits<{ done: [] }>()

const { t } = useI18n()
const queryClient = useQueryClient()
const session = useSessionStore()

const mayPrint = computed(() => session.can(CREDENTIALS_MANAGE))
const single = computed(() => props.items.length === 1)

const busy = ref(false)
const error = ref<unknown>(null)
/** `printed`: se genero el PDF. `nothing`: el lote respondio 204 (no habia nada pendiente). */
const outcome = ref<'printed' | 'nothing' | null>(null)
const closeButton = ref<HTMLButtonElement | null>(null)

async function print(): Promise<void> {
  busy.value = true
  error.value = null

  try {
    const [first] = props.items
    const document_ =
      single.value && first !== undefined
        ? await printCredential(first.credentialUuid)
        : await printCredentialBatch({})

    if (document_ === null) {
      // 204: no habia nada pendiente. Es la idempotencia del lote, no un fallo.
      outcome.value = 'nothing'
      announce(t('credentials.announce.batchEmpty'))
    } else {
      downloadDocument(document_)
      outcome.value = 'printed'
      // El lote dice cuantas lleva (la cabecera del servidor); una sola, que se ha descargado.
      announce(
        single.value
          ? t('employees.rehire.card.announcePrinted')
          : t(
              'employees.rehire.card.announcePrintedMany',
              document_.printedCount ?? props.items.length,
            ),
      )
    }

    // Imprimir cambia el estado de las tarjetas (pendiente de imprimir -> pendiente de entrega): el
    // tablero y la fila de la ficha de cada persona se releen, o seguirian diciendo lo de antes.
    await queryClient.invalidateQueries({ queryKey: ['credential-board'] })

    // El boton de imprimir desaparece al imprimir y con el se iria el foco: pasa a «Cerrar»,
    // que es lo unico que queda por hacer.
    await nextTick()
    closeButton.value?.focus()
  } catch (caught) {
    error.value = caught
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <BaseDialog :title="t('employees.rehire.card.heading')" size="wide" @close="emit('done')">
    <p class="max-w-prose">
      {{
        single
          ? t('employees.rehire.card.explanationOne', { name: items[0]?.name ?? '' })
          : t('employees.rehire.card.explanationMany', { count: items.length })
      }}
    </p>

    <ul v-if="!single" class="mt-3 list-disc pl-5" data-test="rehire-card-list">
      <li v-for="item of items" :key="item.credentialUuid">{{ item.name }}</li>
    </ul>

    <p
      v-if="mayPrint && outcome === null"
      class="mt-4 rounded-kq-sm border border-kq-warning bg-kq-warning-soft p-3 text-kq-warning"
      data-test="rehire-card-notice"
    >
      {{ single ? t('credentials.confirm.print.notice') : t('credentials.batch.warning') }}
      <span v-if="!single" class="mt-1 block" data-test="rehire-card-batch-scope">
        {{ t('employees.rehire.card.batchScope') }}
      </span>
    </p>
    <p
      v-if="mayPrint && outcome === null"
      class="mt-2 text-sm text-kq-text-muted"
      data-test="rehire-card-bearer"
    >
      {{ t('credentials.batch.bearerNotice') }}
    </p>

    <p
      v-if="!mayPrint"
      class="mt-4 rounded-kq-sm border border-kq-border-strong bg-kq-surface-alt p-3 text-kq-text"
      data-test="rehire-card-no-ability"
    >
      {{ t('employees.rehire.card.noAbility') }}
    </p>

    <p
      v-if="outcome === 'printed'"
      role="status"
      class="mt-4 rounded-kq-sm border border-kq-success bg-kq-success-soft p-3 text-kq-success"
      data-test="rehire-card-printed"
    >
      {{ t('employees.rehire.card.printed') }}
    </p>
    <p
      v-else-if="outcome === 'nothing'"
      role="status"
      class="mt-4 rounded-kq-sm border border-kq-border-strong bg-kq-surface-alt p-3 text-kq-text"
      data-test="rehire-card-nothing"
    >
      {{ t('employees.rehire.card.nothing') }}
    </p>

    <ErrorNotice v-if="error !== null" :error="error" class="mt-4" />

    <p v-if="outcome === null && mayPrint" class="mt-4 text-sm text-kq-text-muted">
      {{ t('employees.rehire.card.later') }}
      <RouterLink :to="{ name: 'credentials' }" class="text-kq-primary-strong underline">
        {{ t('employees.rehire.card.laterLink') }}
      </RouterLink>
    </p>

    <template #actions>
      <button
        ref="closeButton"
        type="button"
        data-test="rehire-card-close"
        class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-4 py-2 text-kq-text hover:bg-kq-surface-alt"
        @click="emit('done')"
      >
        {{
          outcome === null && mayPrint ? t('employees.rehire.card.printLater') : t('common.close')
        }}
      </button>
      <button
        v-if="mayPrint && outcome === null"
        v-aria-disabled.busy="busy"
        type="button"
        data-test="rehire-card-print"
        :aria-busy="busy"
        class="rounded-kq-sm bg-kq-danger px-4 py-2 font-semibold text-kq-on-danger aria-disabled:opacity-60"
        @click="print"
      >
        {{
          busy
            ? t('common.saving')
            : single
              ? t('employees.rehire.card.printNow')
              : t('employees.rehire.card.printBatchNow')
        }}
      </button>
    </template>
  </BaseDialog>
</template>
