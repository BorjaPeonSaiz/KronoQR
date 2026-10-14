<script setup lang="ts">
// Los hallazgos de la vista de cumplimiento (RF-PA-06): una seccion por regla,
// con su cabecera y el numero de hallazgos, y debajo la tabla de esa regla
// (`ComplianceFindingsGroupTable`, virtualizada: el contrato no pagina esta vista
// y el techo lo pone el rango de fechas, no el volumen de filas —
// `REPORTING_COMPLIANCE_MAX_RANGE_DAYS`—, asi que un mes de toda la plantilla
// puede dar miles de hallazgos).
import { useI18n } from 'vue-i18n'
import ComplianceFindingsGroupTable from './ComplianceFindingsGroupTable.vue'
import { ruleLabelKey, type ComplianceFindingsGroup } from './compliancePresentation'

defineProps<{
  groups: readonly ComplianceFindingsGroup[]
  timeZone: string
}>()

const { t } = useI18n()
</script>

<template>
  <div class="mt-4 flex flex-col gap-8">
    <section v-for="group of groups" :key="group.rule.rule" data-test="compliance-group">
      <h3 class="text-lg font-semibold">
        {{ t(ruleLabelKey(group.rule.rule)) }}
        <span class="font-normal text-kq-text-muted">({{ group.findings.length }})</span>
      </h3>

      <ComplianceFindingsGroupTable :group="group" :time-zone="timeZone" />
    </section>
  </div>
</template>
