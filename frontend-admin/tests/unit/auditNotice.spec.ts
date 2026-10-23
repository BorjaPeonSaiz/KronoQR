// Aviso de que la consulta queda anotada (R7-PA-02, RS-05, ADR-037).
//
// El panel muestra datos de terceros y el backend escribe un asiento de lectura por
// cada consulta; la persona que consulta tiene que poder saberlo. Esta prueba
// cruza el catalogo de lecturas con asiento del BACKEND con las pantallas del panel:
// cada conjunto de datos que deja asiento tiene su pantalla con el aviso, o esta
// declarado aqui por que no es una pantalla.
import { existsSync, readFileSync, readdirSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import AuditNotice from '@/shared/ui/AuditNotice.vue'
import en from '@/shared/i18n/locales/en.json'
import es from '@/shared/i18n/locales/es.json'
import { mountView } from './support/harness'

const FRONTEND_SRC = resolve(process.cwd(), 'src/features')
const BACKEND_MODULES = resolve(process.cwd(), '../backend/app/Modules')

/** Conjunto de datos del `audit_log` → pantalla del panel que lo muestra y marca que lleva el aviso. */
const SCREEN_BY_DATASET: Readonly<Record<string, { file: string; marker: string }>> = {
  employee_directory: { file: 'employees/EmployeeListView.vue', marker: '<AuditNotice' },
  absence_register: { file: 'absences/AbsenceListView.vue', marker: '<AuditNotice' },
  live_presence: { file: 'live/LivePresenceView.vue', marker: '<AuditNotice' },
  live_presence_stream: { file: 'live/LivePresenceView.vue', marker: '<AuditNotice' },
  incident_board: { file: 'incidents/IncidentsView.vue', marker: '<AuditNotice' },
  incident: { file: 'incidents/IncidentsView.vue', marker: '<AuditNotice' },
  compliance_summary: { file: 'compliance/ComplianceView.vue', marker: '<AuditNotice' },
  period_report: { file: 'reports/PeriodReportView.vue', marker: '<AuditNotice' },
  payroll_export: { file: 'reports/PayrollExportView.vue', marker: '<AuditNotice' },
  credential_status: { file: 'credentials/CredentialBoardView.vue', marker: '<AuditNotice' },
  management_accounts: { file: 'accounts/AccountsView.vue', marker: '<AuditNotice' },
  employee_workdays: { file: 'workdays/EmployeeWorkDaysView.vue', marker: '<AuditNotice' },
  // La exportacion para la Inspeccion ya lo decia con su propio texto, y la busqueda de la persona tambien.
  legal_export_employee_search: {
    file: 'reports/LegalExportEmployeePicker.vue',
    marker: 'reports.legalExport.picker.audited',
  },
}

/** Lecturas con asiento que no son una pantalla del panel. */
const NOT_A_SCREEN: Readonly<Record<string, string>> = {
  incident_digest: 'resumen de incidencias por correo, lo envia el planificador',
  weekly_summary: 'resumen semanal por correo, lo envia el planificador',
}

function phpFiles(directory: string): string[] {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const path = join(directory, entry.name)

    if (entry.isDirectory()) {
      return phpFiles(path)
    }

    return entry.name.endsWith('.php') ? [path] : []
  })
}

/** Los conjuntos de datos de lectura que declara el backend (`const string DATASET` y `ReportDataset`). */
function backendDatasets(): string[] {
  const found = new Set<string>()

  for (const path of phpFiles(BACKEND_MODULES)) {
    const source = readFileSync(path, 'utf8')

    for (const match of source.matchAll(/const string DATASET = '([a-z_]+)'/g)) {
      found.add(match[1] ?? '')
    }

    if (path.endsWith('ReportDataset.php')) {
      for (const match of source.matchAll(/case \w+ = '([a-z_]+)'/g)) {
        found.add(match[1] ?? '')
      }
    }
  }

  return [...found].sort()
}

describe.skipIf(!existsSync(BACKEND_MODULES))(
  'lecturas con asiento ↔ pantallas del panel (R7-PA-02)',
  () => {
    const datasets = backendDatasets()

    it('encuentra el catalogo del backend (la prueba no pasa en vacio)', () => {
      expect(datasets.length).toBeGreaterThanOrEqual(14)
    })

    it('cada conjunto de datos con asiento tiene su pantalla decidida', () => {
      const undecided = datasets.filter(
        (dataset) =>
          SCREEN_BY_DATASET[dataset] === undefined && NOT_A_SCREEN[dataset] === undefined,
      )

      expect(undecided, `decide su pantalla en SCREEN_BY_DATASET o NOT_A_SCREEN`).toEqual([])
    })

    it('no quedan entradas de una lectura que el backend ya no escribe', () => {
      const known = [...Object.keys(SCREEN_BY_DATASET), ...Object.keys(NOT_A_SCREEN)]

      expect(known.filter((dataset) => !datasets.includes(dataset))).toEqual([])
    })

    it.each(Object.entries(SCREEN_BY_DATASET))('%s: la pantalla avisa', (_dataset, screen) => {
      expect(readFileSync(join(FRONTEND_SRC, screen.file), 'utf8')).toContain(screen.marker)
    })
  },
)

describe('AuditNotice', () => {
  it('dice que la consulta queda anotada y que los datos no se copian, en es y en en', async () => {
    const spanish = await mountView(AuditNotice, { locale: 'es' })
    const english = await mountView(AuditNotice, { locale: 'en' })

    expect(spanish.text()).toContain(es.audit.noticeShort)
    expect(spanish.find('[data-test="audit-notice-detail"]').text()).toBe(es.audit.readNotice)
    expect(english.text()).toContain(en.audit.noticeShort)
    expect(english.find('[data-test="audit-notice-detail"]').text()).toBe(en.audit.readNotice)
    expect(es.audit.noticeShort).toContain('anotada')
    expect(es.audit.readNotice).toContain('no guarda los datos que ves')
  })

  it('es una nota, no una region viva: no suena en cada carga', async () => {
    const wrapper = await mountView(AuditNotice)

    expect(wrapper.attributes('role')).toBe('note')
    expect(wrapper.attributes('aria-live')).toBeUndefined()
  })

  it('admite el texto propio del registro horario', async () => {
    const wrapper = await mountView(AuditNotice, { props: { messageKey: 'workdays.auditNotice' } })

    expect(wrapper.find('[data-test="audit-notice-detail"]').text()).toBe(es.workdays.auditNotice)
  })
})
