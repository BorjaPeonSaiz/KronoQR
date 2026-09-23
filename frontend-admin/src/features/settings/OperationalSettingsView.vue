<script setup lang="ts">
// Ajustes operativos de la instalacion: los umbrales `ATTENDANCE_*` y los dos
// idiomas `LOCALE_*` (RF-PD-01, tarea 5.13, hallazgo B1 del cierre de la
// Fase 5). Hasta ahora estas seis claves solo se podian cambiar por
// `PATCH /api/v1/settings` a mano o por el asistente de puesta en marcha (una
// sola vez, solo tres de las seis): ninguna pantalla del panel las volvia a
// enseñar, y `docs/cliente/configuracion.md` §6.0 y §2.1 prometian «se editan
// desde el panel» sin que hubiera panel.
//
// `ATTENDANCE_PATTERN_WINDOW_SECONDS` y `ATTENDANCE_PATTERN_MIN_REPEATS`
// (RF-PR-06, RN-16, tarea 3.11, decision 7 de la ficha) se suman a las cuatro
// de siempre por el MISMO camino generico -`FormField` mas `errorsFor`,
// rango tomado de `constraints`, nunca copiado-. Las dos YA estan en el enum
// `SettingKey` del contrato (segunda vuelta de la tarea 3.11, decision 17):
// `ATTENDANCE_FIELDS` tipa `key` contra ese enum con `satisfies`, igual que
// `BREAK_CLOCKING_KEY` mas abajo, para que un cambio de nombre en el contrato
// falle aqui.
//
// Cuatro decisiones que esta pantalla dice en voz alta, en vez de dejarlas
// implicitas:
//
//  - **El rango de cada umbral NO se copia aqui.** Viene de
//    `constraints.minimum`/`constraints.maximum` de `GET /api/v1/settings`
//    (mismo criterio que `ComplianceProfileView`, que tampoco copia los
//    limites del servidor): el `422` de rango es el que manda, y el hint solo
//    interpola el numero que trae la respuesta.
//  - **El idioma por defecto es un desplegable entre TODOS los que trae el
//    producto** (`constraints.allowed` de `LOCALE_DEFAULT`/`LOCALE_AVAILABLE`,
//    nunca un catalogo hardcodeado como el paso de organizacion del asistente:
//    ver la nota que actualiza `packages/web-kit/src/datetime.ts`… no, esa es
//    otra; aqui la nota es que dos pantallas leen el mismo catalogo y no deben
//    divergir). Las casillas de disponibles reutilizan el patron ya probado de
//    `onboarding/steps/OrganisationStep.vue`: no se puede desmarcar el idioma
//    que esta activo por defecto.
//  - **`KIOSK_SERVICE_CODE` (RF-KI-08, tarea 3.3) es texto OPCIONAL, con la
//    forma fija en el propio panel** (`^[0-9]{8,12}$`), igual que
//    `BrandingView` fija `HEX_COLOR` para `BRANDING_ACCENT_COLOR`: no es un
//    umbral con rango que copiar del contrato, es una forma. Vacio es SIEMPRE
//    valido -«sin codigo, la pantalla se abre sin el»-, y por eso su error
//    local no se dispara con el campo en blanco (a diferencia de los cuatro
//    `ATTENDANCE_*`, que exigen `required`). El valor nunca se audita ni sale
//    de esta pantalla en claro (decision 6 de la ficha 3.3): el servidor solo
//    envia su huella SHA-256 al quiosco por el latido.
//  - **Guardado DEFENSIVO ante `KIOSK_SERVICE_CODE` redactada** (segunda
//    vuelta de la tarea 3.3, hallazgo de `revisor-codigo`): si el backend
//    empieza a servir esta clave con `value: null` para un actor de SOPORTE
//    que no debe leerla (ADR-020), el campo se deshabilita, se vacia y enseña
//    una nota en vez del hint normal, y la clave NUNCA entra en lo que se
//    manda al guardar -nada mas de la pantalla se rompe-. El contrato de hoy
//    no declara ese `null` (`SettingValue` es `number | string | string[]`);
//    la comprobacion pasa por `unknown` a proposito, para no dar por hecho el
//    tipo de una respuesta que `schema.d.ts` todavia no describe.
//  - **`ATTENDANCE_BREAK_CLOCKING` (RF-AT-12, tarea 3.5) es de tipo `text`
//    con un conjunto cerrado de dos valores** (`enabled`/`disabled`), igual
//    que `LOCALE_DEFAULT`: un desplegable sobre `constraints.allowed`, nunca
//    un catalogo propio. Ya esta en el enum `SettingKey` del contrato
//    (`BREAK_CLOCKING_KEY` la tipa una vez, para que un cambio de nombre en
//    el contrato falle aqui y no en `changes['ATTENDANCE_BREAK_CLOCKING']`).
//    Activarlo enseña el boton «Pausa» en la tablet y reactiva RN-12 (tramo
//    continuo sin pausa) desde la siguiente revision nocturna; desactivarlo
//    la vuelve a suspender sin cerrar ninguna incidencia ya abierta -las
//    tres consecuencias van en el hint del campo-.
import { announce } from '@kronoqr/web-kit/announcer'
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import FormField from '@kronoqr/web-kit/components/FormField.vue'
import LoadingPanel from '@kronoqr/web-kit/components/LoadingPanel.vue'
import { isApiError } from '@kronoqr/web-kit/http'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type {
  InstallationSetting,
  InstallationSettings,
  SettingKey,
  UpdateSettingsRequest,
} from '@/shared/api/types'
import { fetchInstallationSettings, stringValue, updateInstallationSettings } from './settings.api'

const { t } = useI18n()

/** La forma que exige el panel para `KIOSK_SERVICE_CODE` (RF-KI-08): 8 a 12 cifras. Vacio siempre vale. */
const SERVICE_CODE_PATTERN = /^[0-9]{8,12}$/

/** Tipada con el enum del contrato (RF-AT-12, tarea 3.5): un cambio de nombre en `SettingKey` falla aqui. */
const BREAK_CLOCKING_KEY: SettingKey = 'ATTENDANCE_BREAK_CLOCKING'

// --- Salida a nomina (RF-IN-07, tarea 3.9) -----------------------------------
//
// Las seis claves `PAYROLL_EXPORT_*` TODAVIA NO ESTAN en el enum `SettingKey`
// del contrato en el momento de escribir esta pantalla: dos agentes de
// `backend-laravel` lo redactan en paralelo (decision 5 de la ficha). Por eso
// aqui abajo se usan como cadenas sueltas y no como `SettingKey` -igual que ya
// hace `KIOSK_SERVICE_CODE` en el resto de este fichero, que tampoco se tipa
// contra el enum en cada sitio- y `entryOf`/`stringValue` aceptan `key:
// string` a proposito. En cuanto el contrato las declare y se regeneren los
// tipos, nada de esto cambia: sigue siendo una clave mas del catalogo.

/** El catalogo cerrado de columnas (decision 5 de la ficha): el mismo que valida el servidor. Un `id` fuera de esta lista es `422` al guardar, aqui y en el backend. */
const PAYROLL_COLUMN_IDS = [
  'employee_code',
  'employee_uuid',
  'last_name',
  'first_name',
  'full_name',
  'department',
  'period_from',
  'period_to',
  'days_in_period',
  'days_with_activity',
  'shift_count',
  'worked_hours',
  'contracted_hours',
  'deviation_hours',
  'overtime_hours',
  'absence_days',
  'holiday_days',
  'unjustified_absence_days',
  'days_without_contract',
  'time_zone',
] as const

/** Las columnas de serie (decision 5 de la ficha), para cuando el catalogo todavia no trae fila propia. */
const DEFAULT_PAYROLL_COLUMNS: readonly string[] = [
  'employee_code',
  'last_name',
  'first_name',
  'department',
  'period_from',
  'period_to',
  'worked_hours',
  'contracted_hours',
  'overtime_hours',
  'absence_days',
]

/**
 * Las seis claves `ATTENDANCE_*`, en el orden en que las declara el catalogo.
 * `key` se tipa contra `SettingKey` con `satisfies` (segunda vuelta de la
 * tarea 3.11, decision 17): las dos ultimas ya estan en el enum del contrato,
 * y un cambio de nombre ahi falla aqui en vez de en `changes[field.key]`. El
 * `as const` de al lado conserva los seis literales -no el tipo `SettingKey`
 * entero- para que `AttendanceKey` siga siendo solo estas seis, y `form` no
 * tenga que llevar una entrada por cada clave del catalogo.
 */
const ATTENDANCE_FIELDS = [
  { key: 'ATTENDANCE_MAX_SHIFT_HOURS', testId: 'max-shift-hours', i18n: 'maxShiftHours' },
  { key: 'ATTENDANCE_DEBOUNCE_SECONDS', testId: 'debounce-seconds', i18n: 'debounceSeconds' },
  {
    key: 'ATTENDANCE_MAX_CLOCK_SKEW_MINUTES',
    testId: 'max-clock-skew-minutes',
    i18n: 'maxClockSkewMinutes',
  },
  {
    key: 'ATTENDANCE_MIN_TRANSIT_SECONDS',
    testId: 'min-transit-seconds',
    i18n: 'minTransitSeconds',
  },
  // Los valores de serie del catalogo (decision 7 de la ficha 3.11): 10 s de
  // ventana, 3 dias de repeticion.
  {
    key: 'ATTENDANCE_PATTERN_WINDOW_SECONDS',
    testId: 'pattern-window-seconds',
    i18n: 'patternWindowSeconds',
  },
  {
    key: 'ATTENDANCE_PATTERN_MIN_REPEATS',
    testId: 'pattern-min-repeats',
    i18n: 'patternMinRepeats',
  },
] as const satisfies ReadonlyArray<{ key: SettingKey; testId: string; i18n: string }>

type AttendanceKey = (typeof ATTENDANCE_FIELDS)[number]['key']

const settings = ref<InstallationSettings | null>(null)
const loading = ref(true)
const saving = ref(false)
const error = ref<unknown>(null)
const saved = ref(false)

const form = ref<Record<AttendanceKey, number | string>>({
  ATTENDANCE_MAX_SHIFT_HOURS: '',
  ATTENDANCE_DEBOUNCE_SECONDS: '',
  ATTENDANCE_MAX_CLOCK_SKEW_MINUTES: '',
  ATTENDANCE_MIN_TRANSIT_SECONDS: '',
  ATTENDANCE_PATTERN_WINDOW_SECONDS: '',
  ATTENDANCE_PATTERN_MIN_REPEATS: '',
})
const localeDefault = ref('')
const localeAvailable = ref<string[]>([])
const serviceCode = ref('')
/** `enabled`/`disabled` (RF-AT-12, tarea 3.5). `disabled` de serie, como en el catalogo. */
const breakClocking = ref('disabled')
/**
 * Guardado DEFENSIVO (segunda vuelta de la tarea 3.3): el contrato de hoy
 * (`SettingValue = number | string | string[]`) no admite `null`, pero un
 * actor de SOPORTE (ADR-020) no deberia poder leer una clave confidencial
 * como `KIOSK_SERVICE_CODE`, y la forma prevista para eso es
 * `value: null` -la clave sigue en el catalogo, solo que sin valor legible-.
 * En cuanto el contrato lo declare, este guardado deja de ser defensivo y
 * pasa a ser el camino normal; hasta entonces, protege contra un servidor
 * que ya lo sirva asi sin que `schema.d.ts` lo sepa todavia.
 */
const serviceCodeRedacted = ref(false)

// --- Salida a nomina (RF-IN-07, tarea 3.9) -----------------------------------
//
// `payrollColumnsText` es UNA LINEA POR COLUMNA, no un `string[]` como
// `localeAvailable`: la lista importa el ORDEN -es el orden de las columnas
// del fichero- y cada entrada puede llevar su propia etiqueta (`id` o
// `id=Etiqueta`), que el editor de casillas de `LOCALE_AVAILABLE` no puede
// expresar. Un `<textarea>` con una entrada por linea es el control minimo
// que sostiene las dos cosas a la vez.
const payrollColumnsText = ref('')
const payrollDelimiter = ref('semicolon')
const payrollHoursFormat = ref('hhmm')
const payrollDateFormat = ref('iso')
const payrollEncoding = ref('utf8_bom')
/** `enabled`/`disabled`: si el fichero lleva fila de cabecera. `enabled` de serie. */
const payrollHeaderRow = ref('enabled')

/** La fila de una clave del catalogo ya cargado, o `undefined` si no llego a resolverse. */
function entryOf(catalog: InstallationSettings, key: string): InstallationSetting | undefined {
  return catalog.data.find((candidate) => candidate.key === key)
}

/** El valor numerico de una clave, como cadena para `v-model`. `'0'` si no es un numero: no debería pasar, el catalogo siempre la declara `integer`. */
function integerValue(catalog: InstallationSettings, key: string): string {
  const value = entryOf(catalog, key)?.value

  return typeof value === 'number' ? String(value) : '0'
}

/** Los idiomas que el PRODUCTO ofrece, del propio catalogo (`constraints.allowed`): nunca un catalogo duplicado en el cliente. */
const shippedLocales = computed<readonly string[]>(() => {
  const catalog = settings.value

  if (catalog === null) {
    return []
  }

  return (
    entryOf(catalog, 'LOCALE_DEFAULT')?.constraints?.allowed ??
    entryOf(catalog, 'LOCALE_AVAILABLE')?.constraints?.allowed ??
    []
  )
})

/** Los dos valores que el catalogo admite para `ATTENDANCE_BREAK_CLOCKING` (`constraints.allowed`), con el mismo criterio que `shippedLocales`: nunca un catalogo duplicado en el cliente. */
const breakClockingOptions = computed<readonly string[]>(() => {
  const catalog = settings.value

  return catalog === null ? [] : (entryOf(catalog, BREAK_CLOCKING_KEY)?.constraints?.allowed ?? [])
})

/**
 * `enabled`/`disabled` ya cargado, con `disabled` -el valor de serie del
 * catalogo (decision 7 de la ficha 3.5)- como respaldo mientras la clave no
 * tenga fila (instalacion recien puesta en marcha). Usada en `fill()` Y en
 * `pendingChanges` para que las dos comparen SIEMPRE la misma normalizacion:
 * sin esto, una cadena vacia del servidor se leeria como «disabled» en
 * pantalla pero como «cambio pendiente» al guardar, y el boton de guardar se
 * quedaria activo sin que nadie tocara nada.
 */
function breakClockingValueOf(catalog: InstallationSettings): string {
  const stored = stringValue(catalog, BREAK_CLOCKING_KEY)

  return stored === '' ? 'disabled' : stored
}

/**
 * El mismo patron que `breakClockingValueOf`, generalizado para las CUATRO
 * claves `PAYROLL_EXPORT_*` de conjunto cerrado (delimitador, formato de
 * horas, formato de fecha, codificacion y cabecera): `fallback` es el valor de
 * serie de cada una (decision 5 de la ficha), usado mientras la clave no tenga
 * fila propia. Usada en `fill()` y en `pendingChanges` para que las dos
 * comparen siempre la misma normalizacion.
 */
function closedTextValueOf(catalog: InstallationSettings, key: string, fallback: string): string {
  const stored = stringValue(catalog, key)

  return stored === '' ? fallback : stored
}

/** Los valores admitidos de una clave de conjunto cerrado (`constraints.allowed`), nunca un catalogo duplicado en el cliente. */
function allowedValuesOf(catalog: InstallationSettings | null, key: string): readonly string[] {
  return catalog === null ? [] : (entryOf(catalog, key)?.constraints?.allowed ?? [])
}

const payrollDelimiterOptions = computed(() =>
  allowedValuesOf(settings.value, 'PAYROLL_EXPORT_DELIMITER'),
)
const payrollHoursFormatOptions = computed(() =>
  allowedValuesOf(settings.value, 'PAYROLL_EXPORT_HOURS_FORMAT'),
)
const payrollDateFormatOptions = computed(() =>
  allowedValuesOf(settings.value, 'PAYROLL_EXPORT_DATE_FORMAT'),
)
const payrollEncodingOptions = computed(() =>
  allowedValuesOf(settings.value, 'PAYROLL_EXPORT_ENCODING'),
)
const payrollHeaderRowOptions = computed(() =>
  allowedValuesOf(settings.value, 'PAYROLL_EXPORT_HEADER_ROW'),
)

/** Las columnas ya guardadas, o las de serie mientras la clave no tenga fila propia. */
function payrollColumnsValueOf(catalog: InstallationSettings): readonly string[] {
  const value = entryOf(catalog, 'PAYROLL_EXPORT_COLUMNS')?.value

  return Array.isArray(value) && value.length > 0 ? value : DEFAULT_PAYROLL_COLUMNS
}

/** Una entrada por linea, sin lineas en blanco: la forma que exige el `<textarea>` del editor. */
function parsePayrollColumnsText(text: string): string[] {
  return text
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => line !== '')
}

/** El `id` de una entrada `id` o `id=Etiqueta`, para validarlo contra el catalogo cerrado. */
function payrollColumnId(entry: string): string {
  const separatorIndex = entry.indexOf('=')

  return separatorIndex === -1 ? entry : entry.slice(0, separatorIndex)
}

type PayrollColumnId = (typeof PAYROLL_COLUMN_IDS)[number]

function isKnownPayrollColumn(id: string): id is PayrollColumnId {
  return (PAYROLL_COLUMN_IDS as readonly string[]).includes(id)
}

/**
 * El catalogo cerrado de columnas, con su etiqueta legible (`payrollExport.columns.*`,
 * el mismo catalogo que previsualiza `PayrollExportView`): la ayuda en linea
 * que exige la ficha, sin duplicar los textos en dos sitios.
 */
const payrollExportColumnCatalog = computed<Readonly<Record<string, string>>>(() =>
  Object.fromEntries(PAYROLL_COLUMN_IDS.map((id) => [id, t(`payrollExport.columns.${id}`)])),
)

/** Como se llama un idioma, en el idioma de la interfaz. Sin traduccion propia, el codigo tal cual: no debería pasar con el catalogo de serie (`es`, `en`). */
function localeLabel(code: string): string {
  return code === 'es' || code === 'en' ? t(`common.locales.${code}`) : code
}

/** El rango admitido de una clave entera, para interpolarlo en el hint sin copiarlo. */
function rangeOf(
  catalog: InstallationSettings | null,
  key: string,
): { minimum: number; maximum: number } {
  const constraints = catalog === null ? undefined : entryOf(catalog, key)?.constraints

  return { minimum: constraints?.minimum ?? 0, maximum: constraints?.maximum ?? 0 }
}

/**
 * `true` si el servidor devolvio `KIOSK_SERVICE_CODE` redactada: es lo que
 * recibe un acceso de soporte del fabricante (`redacted: true`, `value: null`,
 * tarea 3.3). Se mira la marca y no el `value`, que es lo que el contrato
 * declara como señal (ver el comentario de `serviceCodeRedacted`).
 */
function isServiceCodeRedacted(catalog: InstallationSettings): boolean {
  const entry = entryOf(catalog, 'KIOSK_SERVICE_CODE')

  return entry?.redacted === true || entry?.value === null
}

function fill(catalog: InstallationSettings): void {
  settings.value = catalog

  for (const field of ATTENDANCE_FIELDS) {
    form.value[field.key] = integerValue(catalog, field.key)
  }

  const storedDefault = entryOf(catalog, 'LOCALE_DEFAULT')?.value
  const storedAvailable = entryOf(catalog, 'LOCALE_AVAILABLE')?.value

  localeDefault.value = typeof storedDefault === 'string' ? storedDefault : ''
  localeAvailable.value = Array.isArray(storedAvailable) ? [...storedAvailable] : []
  serviceCodeRedacted.value = isServiceCodeRedacted(catalog)
  serviceCode.value = serviceCodeRedacted.value ? '' : stringValue(catalog, 'KIOSK_SERVICE_CODE')

  breakClocking.value = breakClockingValueOf(catalog)

  payrollColumnsText.value = payrollColumnsValueOf(catalog).join('\n')
  payrollDelimiter.value = closedTextValueOf(catalog, 'PAYROLL_EXPORT_DELIMITER', 'semicolon')
  payrollHoursFormat.value = closedTextValueOf(catalog, 'PAYROLL_EXPORT_HOURS_FORMAT', 'hhmm')
  payrollDateFormat.value = closedTextValueOf(catalog, 'PAYROLL_EXPORT_DATE_FORMAT', 'iso')
  payrollEncoding.value = closedTextValueOf(catalog, 'PAYROLL_EXPORT_ENCODING', 'utf8_bom')
  payrollHeaderRow.value = closedTextValueOf(catalog, 'PAYROLL_EXPORT_HEADER_ROW', 'enabled')
}

async function load(): Promise<void> {
  loading.value = true
  error.value = null

  try {
    fill(await fetchInstallationSettings())
  } catch (failure) {
    error.value = failure
  } finally {
    loading.value = false
  }
}

onMounted(load)

/** El `422` cuelga el error de `settings.<CLAVE>`. La invariante ENTRE `LOCALE_DEFAULT` y `LOCALE_AVAILABLE` cuelga de `settings` a secas (ver `UpdateSettingsRequest` en el instalador de rutas del backend). */
function serverFieldErrors(key: string): readonly string[] {
  return isApiError(error.value) ? (error.value.fieldErrors[`settings.${key}`] ?? []) : []
}

const fieldLabels = computed<Record<string, string>>(() => ({
  settings: t('operationalSettings.heading'),
  'settings.ATTENDANCE_MAX_SHIFT_HOURS': t('operationalSettings.fields.maxShiftHours'),
  'settings.ATTENDANCE_DEBOUNCE_SECONDS': t('operationalSettings.fields.debounceSeconds'),
  'settings.ATTENDANCE_MAX_CLOCK_SKEW_MINUTES': t('operationalSettings.fields.maxClockSkewMinutes'),
  'settings.ATTENDANCE_MIN_TRANSIT_SECONDS': t('operationalSettings.fields.minTransitSeconds'),
  'settings.ATTENDANCE_PATTERN_WINDOW_SECONDS': t(
    'operationalSettings.fields.patternWindowSeconds',
  ),
  'settings.ATTENDANCE_PATTERN_MIN_REPEATS': t('operationalSettings.fields.patternMinRepeats'),
  'settings.KIOSK_SERVICE_CODE': t('operationalSettings.fields.kioskServiceCode'),
  'settings.ATTENDANCE_BREAK_CLOCKING': t('operationalSettings.fields.breakClocking'),
  'settings.LOCALE_DEFAULT': t('operationalSettings.fields.localeDefault'),
  'settings.LOCALE_AVAILABLE': t('operationalSettings.fields.localeAvailable'),
  'settings.PAYROLL_EXPORT_COLUMNS': t('operationalSettings.fields.payrollColumns'),
  'settings.PAYROLL_EXPORT_DELIMITER': t('operationalSettings.fields.payrollDelimiter'),
  'settings.PAYROLL_EXPORT_HOURS_FORMAT': t('operationalSettings.fields.payrollHoursFormat'),
  'settings.PAYROLL_EXPORT_DATE_FORMAT': t('operationalSettings.fields.payrollDateFormat'),
  'settings.PAYROLL_EXPORT_ENCODING': t('operationalSettings.fields.payrollEncoding'),
  'settings.PAYROLL_EXPORT_HEADER_ROW': t('operationalSettings.fields.payrollHeaderRow'),
}))

/**
 * Un entero, o `undefined` si lo escrito no lo es: el rango lo decide el
 * servidor (mismo criterio que `ComplianceProfileView::asInteger`).
 */
function asInteger(raw: number | string): number | undefined {
  if (typeof raw === 'number') {
    return Number.isInteger(raw) ? raw : undefined
  }

  const trimmed = raw.trim()

  return /^-?\d+$/.test(trimmed) ? Number.parseInt(trimmed, 10) : undefined
}

function issueOf(key: AttendanceKey): 'required' | 'notAWholeNumber' | null {
  const raw = form.value[key]

  if (raw === '' || raw === null) {
    return 'required'
  }

  return asInteger(raw) === undefined ? 'notAWholeNumber' : null
}

function errorsFor(key: AttendanceKey): readonly string[] {
  const issue = issueOf(key)
  const local = issue === null ? [] : [t(`operationalSettings.errors.${issue}`)]

  return [...local, ...serverFieldErrors(key)]
}

const invalidFields = computed(() =>
  ATTENDANCE_FIELDS.filter((field) => issueOf(field.key) !== null),
)

/**
 * `null` (valido) con el campo vacio -«sin codigo, la pantalla se abre sin
 * el», decision 6 de la ficha 3.3-, o si lo escrito son de 8 a 12 cifras. El
 * `422` del servidor sigue mandando (`serviceCodeErrors` lo añade).
 */
const serviceCodeLocalIssue = computed<'notAServiceCode' | null>(() => {
  const trimmed = serviceCode.value.trim()

  return trimmed === '' || SERVICE_CODE_PATTERN.test(trimmed) ? null : 'notAServiceCode'
})

const serviceCodeErrors = computed<readonly string[]>(() => {
  const local =
    serviceCodeLocalIssue.value === null
      ? []
      : [t(`operationalSettings.errors.${serviceCodeLocalIssue.value}`)]

  return [...local, ...serverFieldErrors('KIOSK_SERVICE_CODE')]
})

/** Las entradas escritas en el editor, una por linea (RF-IN-07). */
const payrollColumnsEntries = computed(() => parsePayrollColumnsText(payrollColumnsText.value))

/**
 * `null` (valido), `empty` sin ninguna columna, o `unknownColumn` si algun
 * `id` no esta en el catalogo cerrado. El `422` del servidor sigue mandando
 * (`serverFieldErrors('PAYROLL_EXPORT_COLUMNS')` lo añade), esto es solo para
 * no descargar la peticion con una lista que ya se sabe invalida.
 */
const payrollColumnsLocalIssue = computed<'empty' | 'unknownColumn' | null>(() => {
  const entries = payrollColumnsEntries.value

  if (entries.length === 0) {
    return 'empty'
  }

  return entries.some((entry) => !isKnownPayrollColumn(payrollColumnId(entry)))
    ? 'unknownColumn'
    : null
})

const payrollColumnsErrors = computed<readonly string[]>(() => {
  const local =
    payrollColumnsLocalIssue.value === null
      ? []
      : [t(`operationalSettings.errors.${payrollColumnsLocalIssue.value}`)]

  return [...local, ...serverFieldErrors('PAYROLL_EXPORT_COLUMNS')]
})

/** Un idioma no se puede desmarcar si es el que esta activo por defecto (mismo patron que `OrganisationStep`). */
function toggleLocale(code: string): void {
  if (localeAvailable.value.includes(code)) {
    if (code === localeDefault.value) {
      return
    }

    localeAvailable.value = localeAvailable.value.filter((entry) => entry !== code)
  } else {
    localeAvailable.value = [...localeAvailable.value, code]
  }
}

const pendingChanges = computed<UpdateSettingsRequest['settings']>(() => {
  const current = settings.value

  if (current === null) {
    return {}
  }

  const changes: UpdateSettingsRequest['settings'] = {}

  for (const field of ATTENDANCE_FIELDS) {
    const value = asInteger(form.value[field.key])
    const previous = entryOf(current, field.key)?.value

    if (value !== undefined && value !== previous) {
      changes[field.key] = value
    }
  }

  // Redactado para este actor (guardado defensivo, ver `serviceCodeRedacted`):
  // el campo esta deshabilitado y vacio, y NO es «la persona ha borrado el
  // codigo» -es que no lo ve-, asi que nunca entra en lo que se manda.
  if (!serviceCodeRedacted.value) {
    const trimmedServiceCode = serviceCode.value.trim()
    const previousServiceCode = stringValue(current, 'KIOSK_SERVICE_CODE')

    if (serviceCodeLocalIssue.value === null && trimmedServiceCode !== previousServiceCode) {
      changes['KIOSK_SERVICE_CODE'] = trimmedServiceCode
    }
  }

  const previousBreakClocking = breakClockingValueOf(current)

  if (breakClocking.value !== previousBreakClocking) {
    changes['ATTENDANCE_BREAK_CLOCKING'] = breakClocking.value
  }

  const trimmedDefault = localeDefault.value.trim()
  const previousDefault = entryOf(current, 'LOCALE_DEFAULT')?.value

  if (trimmedDefault !== '' && trimmedDefault !== previousDefault) {
    changes['LOCALE_DEFAULT'] = trimmedDefault
  }

  const previousAvailable = entryOf(current, 'LOCALE_AVAILABLE')?.value
  const previousAvailableList = Array.isArray(previousAvailable) ? previousAvailable : []

  if (
    localeAvailable.value.length > 0 &&
    JSON.stringify([...localeAvailable.value].sort()) !==
      JSON.stringify([...previousAvailableList].sort())
  ) {
    changes['LOCALE_AVAILABLE'] = localeAvailable.value
  }

  // Salida a nomina (RF-IN-07, tarea 3.9): las seis claves `PAYROLL_EXPORT_*`.
  // El ORDEN importa en `PAYROLL_EXPORT_COLUMNS` -es el orden del fichero-,
  // asi que la comparacion es posicional y no un conjunto ordenado como
  // `LOCALE_AVAILABLE`.
  const previousColumns = payrollColumnsValueOf(current)

  if (
    payrollColumnsLocalIssue.value === null &&
    JSON.stringify(payrollColumnsEntries.value) !== JSON.stringify(previousColumns)
  ) {
    changes['PAYROLL_EXPORT_COLUMNS'] = payrollColumnsEntries.value
  }

  const payrollClosedFields: ReadonlyArray<[key: string, value: string, previousValue: string]> = [
    [
      'PAYROLL_EXPORT_DELIMITER',
      payrollDelimiter.value,
      closedTextValueOf(current, 'PAYROLL_EXPORT_DELIMITER', 'semicolon'),
    ],
    [
      'PAYROLL_EXPORT_HOURS_FORMAT',
      payrollHoursFormat.value,
      closedTextValueOf(current, 'PAYROLL_EXPORT_HOURS_FORMAT', 'hhmm'),
    ],
    [
      'PAYROLL_EXPORT_DATE_FORMAT',
      payrollDateFormat.value,
      closedTextValueOf(current, 'PAYROLL_EXPORT_DATE_FORMAT', 'iso'),
    ],
    [
      'PAYROLL_EXPORT_ENCODING',
      payrollEncoding.value,
      closedTextValueOf(current, 'PAYROLL_EXPORT_ENCODING', 'utf8_bom'),
    ],
    [
      'PAYROLL_EXPORT_HEADER_ROW',
      payrollHeaderRow.value,
      closedTextValueOf(current, 'PAYROLL_EXPORT_HEADER_ROW', 'enabled'),
    ],
  ]

  for (const [key, value, previousValue] of payrollClosedFields) {
    if (value !== previousValue) {
      changes[key] = value
    }
  }

  return changes
})

const hasChanges = computed(() => Object.keys(pendingChanges.value).length > 0)

const canSave = computed(
  () =>
    hasChanges.value &&
    invalidFields.value.length === 0 &&
    serviceCodeLocalIssue.value === null &&
    localeAvailable.value.length > 0 &&
    payrollColumnsLocalIssue.value === null &&
    !saving.value,
)

/**
 * Si el cambio pendiente toca alguna clave que **afecta al calculo de
 * horas** (`affects_worked_hours` de `GET /api/v1/settings`, no una lista
 * copiada aqui: hoy es `ATTENDANCE_DEBOUNCE_SECONDS`, y si el catalogo
 * cambiara mañana el aviso seguiria acertando sin tocar esta pantalla).
 */
const affectsWorkedHoursPending = computed(() => {
  const current = settings.value

  if (current === null) {
    return false
  }

  return Object.keys(pendingChanges.value).some(
    (key) => entryOf(current, key)?.affects_worked_hours === true,
  )
})

async function save(): Promise<void> {
  if (!canSave.value) {
    return
  }

  saving.value = true
  error.value = null
  saved.value = false

  try {
    fill(await updateInstallationSettings(pendingChanges.value))
    saved.value = true
    announce(t('operationalSettings.saved'))
  } catch (failure) {
    error.value = failure
    announce(t('operationalSettings.failed'))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <section class="flex flex-col gap-6">
    <header class="flex flex-col gap-2">
      <h1 class="text-2xl font-semibold">{{ t('operationalSettings.heading') }}</h1>
      <p class="max-w-3xl text-kq-text-muted">{{ t('operationalSettings.intro') }}</p>
    </header>

    <LoadingPanel v-if="loading" :label="t('operationalSettings.loading')" data-test="loading" />

    <ErrorNotice v-if="error !== null" :error="error" :field-labels="fieldLabels" />

    <form
      v-if="settings !== null"
      class="flex max-w-3xl flex-col gap-6"
      novalidate
      @submit.prevent="save"
    >
      <fieldset class="flex flex-col gap-4">
        <legend class="text-lg font-medium text-kq-text">
          {{ t('operationalSettings.attendanceHeading') }}
        </legend>

        <FormField
          v-for="field of ATTENDANCE_FIELDS"
          :key="field.key"
          :label="t(`operationalSettings.fields.${field.i18n}`)"
          :hint="t(`operationalSettings.hints.${field.i18n}`, rangeOf(settings, field.key))"
          :errors="errorsFor(field.key)"
        >
          <template #default="{ id, describedBy, invalid }">
            <input
              :id="id"
              v-model="form[field.key]"
              type="number"
              inputmode="numeric"
              :data-test="field.testId"
              :aria-describedby="describedBy"
              :aria-invalid="invalid"
              class="w-32 rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text"
            />
          </template>
        </FormField>

        <FormField
          :label="t('operationalSettings.fields.breakClocking')"
          :hint="t('operationalSettings.hints.breakClocking')"
          :errors="serverFieldErrors(BREAK_CLOCKING_KEY)"
        >
          <template #default="{ id, describedBy, invalid }">
            <select
              :id="id"
              v-model="breakClocking"
              data-test="break-clocking"
              :aria-describedby="describedBy"
              :aria-invalid="invalid"
              class="w-48 rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text"
            >
              <option v-for="option of breakClockingOptions" :key="option" :value="option">
                {{ t(`operationalSettings.breakClockingOptions.${option}`) }}
              </option>
            </select>
          </template>
        </FormField>
      </fieldset>

      <p
        v-if="affectsWorkedHoursPending"
        role="alert"
        class="rounded-kq border border-kq-warning bg-kq-warning-soft p-4 text-kq-warning"
        data-test="affects-worked-hours-warning"
      >
        {{ t('operationalSettings.affectsWorkedHoursWarning') }}
      </p>

      <fieldset class="flex flex-col gap-4">
        <legend class="text-lg font-medium text-kq-text">
          {{ t('operationalSettings.diagnosticsHeading') }}
        </legend>

        <FormField
          :label="t('operationalSettings.fields.kioskServiceCode')"
          :hint="
            serviceCodeRedacted
              ? t('operationalSettings.hints.kioskServiceCodeRedacted')
              : t('operationalSettings.hints.kioskServiceCode')
          "
          :errors="serviceCodeErrors"
        >
          <template #default="{ id, describedBy, invalid }">
            <input
              :id="id"
              v-model="serviceCode"
              type="text"
              inputmode="numeric"
              autocomplete="off"
              spellcheck="false"
              maxlength="12"
              :disabled="serviceCodeRedacted"
              data-test="kiosk-service-code"
              :aria-describedby="describedBy"
              :aria-invalid="invalid"
              class="w-40 rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 font-mono text-kq-text disabled:cursor-not-allowed disabled:opacity-60"
            />
          </template>
        </FormField>
      </fieldset>

      <fieldset class="flex flex-col gap-4">
        <legend class="text-lg font-medium text-kq-text">
          {{ t('operationalSettings.localesHeading') }}
        </legend>

        <FormField
          :label="t('operationalSettings.fields.localeDefault')"
          :hint="t('operationalSettings.hints.localeDefault')"
          :errors="serverFieldErrors('LOCALE_DEFAULT')"
        >
          <template #default="{ id, describedBy, invalid }">
            <select
              :id="id"
              v-model="localeDefault"
              data-test="locale-default"
              :aria-describedby="describedBy"
              :aria-invalid="invalid"
              class="w-48 rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text"
            >
              <option v-for="code of shippedLocales" :key="code" :value="code">
                {{ localeLabel(code) }}
              </option>
            </select>
          </template>
        </FormField>

        <fieldset class="flex flex-col gap-2" data-test="locale-available">
          <legend class="font-medium text-kq-text">
            {{ t('operationalSettings.fields.localeAvailable') }}
          </legend>
          <p class="text-sm text-kq-text-muted">
            {{ t('operationalSettings.hints.localeAvailable') }}
          </p>
          <label v-for="code of shippedLocales" :key="code" class="flex items-center gap-2">
            <input
              type="checkbox"
              :checked="localeAvailable.includes(code)"
              :disabled="code === localeDefault"
              :data-test="`locale-available-${code}`"
              @change="toggleLocale(code)"
            />
            {{ localeLabel(code) }}
          </label>
          <p
            v-if="serverFieldErrors('LOCALE_AVAILABLE').length > 0"
            class="text-sm font-medium text-kq-danger"
            role="alert"
          >
            {{ serverFieldErrors('LOCALE_AVAILABLE').join(' ') }}
          </p>
        </fieldset>
      </fieldset>

      <!-- Salida a nomina (RF-IN-07, tarea 3.9): las seis claves
           `PAYROLL_EXPORT_*`. Ninguna cambia un calculo (impacto
           `presentation`, decision 5 de la ficha), asi que este bloque no
           dispara `affectsWorkedHoursWarning`. -->
      <fieldset class="flex flex-col gap-4" data-test="payroll-export-settings">
        <legend class="text-lg font-medium text-kq-text">
          {{ t('operationalSettings.payrollHeading') }}
        </legend>
        <p class="text-sm text-kq-text-muted">{{ t('operationalSettings.payrollIntro') }}</p>

        <FormField
          :label="t('operationalSettings.fields.payrollColumns')"
          :hint="t('operationalSettings.hints.payrollColumns')"
          :errors="payrollColumnsErrors"
        >
          <template #default="{ id, describedBy, invalid }">
            <textarea
              :id="id"
              v-model="payrollColumnsText"
              rows="6"
              data-test="payroll-columns"
              :aria-describedby="describedBy"
              :aria-invalid="invalid"
              class="w-full max-w-md rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 font-mono text-sm text-kq-text"
            ></textarea>
          </template>
        </FormField>

        <!-- El catalogo de columnas y su significado, para quien escribe el
             editor de arriba (requisito de la ficha: «ayuda en linea con el
             catalogo de columnas y su significado»). -->
        <details data-test="payroll-columns-catalog">
          <summary class="cursor-pointer text-sm font-medium text-kq-text">
            {{ t('operationalSettings.hints.payrollColumnsCatalogToggle') }}
          </summary>
          <dl
            class="mt-2 grid grid-cols-1 gap-x-4 gap-y-1 text-sm text-kq-text-muted sm:grid-cols-2"
          >
            <template v-for="key of Object.keys(payrollExportColumnCatalog)" :key="key">
              <dt class="font-mono">{{ key }}</dt>
              <dd>{{ payrollExportColumnCatalog[key] }}</dd>
            </template>
          </dl>
        </details>

        <div class="grid gap-4 sm:grid-cols-2">
          <FormField
            :label="t('operationalSettings.fields.payrollDelimiter')"
            :hint="t('operationalSettings.hints.payrollDelimiter')"
            :errors="serverFieldErrors('PAYROLL_EXPORT_DELIMITER')"
          >
            <template #default="{ id, describedBy, invalid }">
              <select
                :id="id"
                v-model="payrollDelimiter"
                data-test="payroll-delimiter"
                :aria-describedby="describedBy"
                :aria-invalid="invalid"
                class="w-full rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text"
              >
                <option v-for="option of payrollDelimiterOptions" :key="option" :value="option">
                  {{ t(`payrollExport.delimiter.${option}`) }}
                </option>
              </select>
            </template>
          </FormField>

          <FormField
            :label="t('operationalSettings.fields.payrollHoursFormat')"
            :hint="t('operationalSettings.hints.payrollHoursFormat')"
            :errors="serverFieldErrors('PAYROLL_EXPORT_HOURS_FORMAT')"
          >
            <template #default="{ id, describedBy, invalid }">
              <select
                :id="id"
                v-model="payrollHoursFormat"
                data-test="payroll-hours-format"
                :aria-describedby="describedBy"
                :aria-invalid="invalid"
                class="w-full rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text"
              >
                <option v-for="option of payrollHoursFormatOptions" :key="option" :value="option">
                  {{ t(`payrollExport.hoursFormat.${option}`) }}
                </option>
              </select>
            </template>
          </FormField>

          <FormField
            :label="t('operationalSettings.fields.payrollDateFormat')"
            :hint="t('operationalSettings.hints.payrollDateFormat')"
            :errors="serverFieldErrors('PAYROLL_EXPORT_DATE_FORMAT')"
          >
            <template #default="{ id, describedBy, invalid }">
              <select
                :id="id"
                v-model="payrollDateFormat"
                data-test="payroll-date-format"
                :aria-describedby="describedBy"
                :aria-invalid="invalid"
                class="w-full rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text"
              >
                <option v-for="option of payrollDateFormatOptions" :key="option" :value="option">
                  {{ t(`operationalSettings.payrollDateFormatOptions.${option}`) }}
                </option>
              </select>
            </template>
          </FormField>

          <FormField
            :label="t('operationalSettings.fields.payrollEncoding')"
            :hint="t('operationalSettings.hints.payrollEncoding')"
            :errors="serverFieldErrors('PAYROLL_EXPORT_ENCODING')"
          >
            <template #default="{ id, describedBy, invalid }">
              <select
                :id="id"
                v-model="payrollEncoding"
                data-test="payroll-encoding"
                :aria-describedby="describedBy"
                :aria-invalid="invalid"
                class="w-full rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text"
              >
                <option v-for="option of payrollEncodingOptions" :key="option" :value="option">
                  {{ t(`operationalSettings.payrollEncodingOptions.${option}`) }}
                </option>
              </select>
            </template>
          </FormField>

          <FormField
            :label="t('operationalSettings.fields.payrollHeaderRow')"
            :hint="t('operationalSettings.hints.payrollHeaderRow')"
            :errors="serverFieldErrors('PAYROLL_EXPORT_HEADER_ROW')"
          >
            <template #default="{ id, describedBy, invalid }">
              <select
                :id="id"
                v-model="payrollHeaderRow"
                data-test="payroll-header-row"
                :aria-describedby="describedBy"
                :aria-invalid="invalid"
                class="w-full rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text"
              >
                <option v-for="option of payrollHeaderRowOptions" :key="option" :value="option">
                  {{ t(`operationalSettings.payrollHeaderRowOptions.${option}`) }}
                </option>
              </select>
            </template>
          </FormField>
        </div>
      </fieldset>

      <p class="text-sm text-kq-text-muted" data-test="audited">
        {{ t('operationalSettings.audited') }}
      </p>

      <div class="flex items-center gap-3">
        <button
          type="submit"
          :disabled="!canSave"
          data-test="save"
          class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 text-kq-on-primary hover:brightness-95 disabled:opacity-50"
        >
          {{ t('operationalSettings.save') }}
        </button>
        <p v-if="saving" class="text-kq-text-muted">{{ t('operationalSettings.saving') }}</p>
        <p v-else-if="saved && !hasChanges" class="text-kq-success" role="status" data-test="saved">
          {{ t('operationalSettings.saved') }}
        </p>
      </div>
    </form>
  </section>
</template>
