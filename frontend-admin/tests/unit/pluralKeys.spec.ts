// Plurales (V7-PA-6, V21-7-PA-2): «El informe tiene 1 filas», «1 incidencias en la
// bandeja», «1 correcciones». Cada clave que lleva `{count}` distingue singular y
// plural en español y en inglés, salvo las pocas cuya frase no se declina y que se
// declaran aqui con su razon. Una clave nueva con `{count}` sin plural falla.
import { describe, expect, it } from 'vitest'
import { createAppI18n } from '@/shared/i18n'
import en from '@/shared/i18n/locales/en.json'
import es from '@/shared/i18n/locales/es.json'
import { testMessages } from './support/i18n'

function flatten(value: unknown, prefix = ''): Array<[string, string]> {
  if (typeof value !== 'object' || value === null) {
    return [[prefix, String(value)]]
  }

  return Object.entries(value as Record<string, unknown>).flatMap(([key, nested]) =>
    flatten(nested, prefix === '' ? key : prefix + '.' + key),
  )
}

const ES = new Map(flatten(es))
const EN = new Map(flatten(en))

/** Claves cuya frase no cambia con la cantidad, y por que. */
const INVARIANT: Readonly<Record<string, string>> = {
  'employees.announce.results': 'rotulo con dos puntos: «Resultados de la plantilla: 1.»',
  'absences.announce.results': 'rotulo con dos puntos: «Ausencias encontradas: 1.»',
  'credentials.summary.pendingPrint': 'rotulo con dos puntos: «Pendientes de imprimir: 1.»',
  'credentials.announce.batchPrinted': 'rotulo con dos puntos: «Tarjetas incluidas: 1.»',
  'credentials.announce.results': 'rotulo con dos puntos: «Resultados del panel: 1.»',
  'credentials.rotation.showPending':
    'cifra entre parentesis tras un verbo: «Ver a quién le falta (1)»',
  'incidents.context.kioskCoincidence.counterpartLinkManyMore':
    '«y {count} más»: no se declina (y solo se usa con 2 o mas)',
  'incidents.dialog.noteCounter': 'contador «12/500 caracteres»: el maximo manda, no el recuento',
  'operationalSettings.pinLengthOptions': 'longitudes de PIN de 4 a 8 cifras: nunca 1',
  'errorEvents.table.occurrencesValue': 'solo la cifra',
}

/** Toda clave con {count} o con formas plurales (« | »): ninguna se queda a medias en un idioma. */
const countKeys = [...new Set([...ES.keys(), ...EN.keys()])].filter((key) =>
  [ES.get(key) ?? '', EN.get(key) ?? ''].some(
    (text) => text.includes('{count}') || text.includes(' | '),
  ),
)

function forms(text: string | undefined): number {
  return (text ?? '').split(' | ').length
}

describe('plurales de las claves con {count} (V7-PA-6)', () => {
  it('hay claves con {count} que comprobar', () => {
    expect(countKeys.length).toBeGreaterThanOrEqual(30)
  })

  it.each(countKeys.filter((key) => INVARIANT[key] === undefined))(
    '%s distingue singular y plural en es y en en',
    (key) => {
      expect(forms(ES.get(key)), `es: ${key}`).toBeGreaterThanOrEqual(2)
      expect(forms(EN.get(key)), `en: ${key}`).toBeGreaterThanOrEqual(2)
      expect(forms(ES.get(key))).toBe(forms(EN.get(key)))
    },
  )

  it('la lista de claves invariables no se queda obsoleta', () => {
    for (const key of Object.keys(INVARIANT)) {
      expect(countKeys, `${key} ya no lleva {count}`).toContain(key)
      expect(forms(ES.get(key)), `${key} ya tiene plural: quitalo de INVARIANT`).toBe(1)
      expect(forms(EN.get(key)), `${key} ya tiene plural: quitalo de INVARIANT`).toBe(1)
    }
  })
})

describe('el plural se elige de verdad', () => {
  const spanish = createAppI18n('es', testMessages).global
  const english = createAppI18n('en', testMessages).global

  it('un singular, un cero y un plural', () => {
    expect(spanish.t('reports.period.announce.results', { count: 1 })).toBe(
      'El informe tiene 1 fila.',
    )
    expect(spanish.t('reports.period.announce.results', { count: 0 })).toBe(
      'El informe tiene 0 filas.',
    )
    expect(spanish.t('reports.period.announce.results', { count: 7 })).toBe(
      'El informe tiene 7 filas.',
    )
    expect(spanish.t('incidents.announce.results', { count: 1 })).toBe(
      '1 incidencia en la bandeja.',
    )
    expect(spanish.t('errorEvents.announce.results', { count: 1 })).toBe('1 error en el histórico.')
    expect(spanish.t('accounts.announce.results', { count: 1 })).toBe('1 cuenta')
    expect(english.t('reports.period.announce.results', { count: 1 })).toBe('The report has 1 row.')
    expect(english.t('incidents.announce.results', { count: 2 })).toBe('2 incidents in the inbox.')
  })

  it('«1 correcciones» de la Inspeccion ya no existe', () => {
    const singular = spanish.t('devices.unpair.pendingQueueWarning', { count: 1 })

    expect(singular).toContain('1 fichaje sin sincronizar')
    expect(singular).toContain('ese fichaje solo llegará')
    expect(english.t('onboarding.completion.credentialsPending', { count: 1 })).toBe(
      'There is still 1 card to issue and print.',
    )
  })
})
