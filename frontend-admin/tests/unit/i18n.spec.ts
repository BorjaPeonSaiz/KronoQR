import { readFileSync, readdirSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import en from '@/shared/i18n/locales/en.json'
import es from '@/shared/i18n/locales/es.json'
import { DEFAULT_LOCALE, isSupportedLocale, resolveLocale } from '@/shared/i18n'

/** Aplana un arbol de mensajes a la lista de sus claves con punto. */
function flatKeys(value: unknown, prefix = ''): string[] {
  if (typeof value !== 'object' || value === null) {
    return [prefix]
  }

  return Object.entries(value as Record<string, unknown>).flatMap(([key, nested]) =>
    flatKeys(nested, prefix === '' ? key : prefix + '.' + key),
  )
}

/** Aplana un arbol de mensajes a la lista de sus textos. */
function flatValues(value: unknown): string[] {
  if (typeof value !== 'object' || value === null) {
    return [String(value)]
  }

  return Object.values(value as Record<string, unknown>).flatMap(flatValues)
}

// La raiz de vitest es la carpeta de la aplicacion (`vitest.config.ts`).
const SOURCE_ROOT = resolve(process.cwd(), 'src')

function sourceFiles(directory: string): string[] {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const path = join(directory, entry.name)

    if (entry.isDirectory()) {
      return sourceFiles(path)
    }

    return entry.name.endsWith('.vue') || entry.name.endsWith('.ts') ? [path] : []
  })
}

/**
 * Claves usadas con `t('...')` literal. Las dinamicas (`t(`x.${valor}`)`) no
 * salen aqui: se comprueban una a una mas abajo, familia por familia.
 */
function usedKeys(): string[] {
  const pattern = /\bt\(\s*'([a-zA-Z][\w.]*)'/g

  return sourceFiles(SOURCE_ROOT).flatMap((path) => {
    const content = readFileSync(path, 'utf8')

    return [...content.matchAll(pattern)].flatMap((match) =>
      match[1] === undefined ? [] : [match[1]],
    )
  })
}

describe('idiomas de la aplicacion', () => {
  it('sirve espanol e ingles con exactamente las mismas claves', () => {
    expect(flatKeys(en).sort()).toEqual(flatKeys(es).sort())
  })

  it('no deja ningun texto vacio en ninguno de los dos idiomas', () => {
    expect(flatValues(es).every((text) => text.trim().length > 0)).toBe(true)
    expect(flatValues(en).every((text) => text.trim().length > 0)).toBe(true)
  })

  it('no deja ninguna clave usada en el codigo sin texto', () => {
    const available = new Set(flatKeys(es))
    const missing = [...new Set(usedKeys())].filter((key) => !available.has(key))

    expect(missing).toEqual([])
  })

  it('tiene texto para cada valor de los enumerados del contrato', () => {
    const available = new Set(flatKeys(es))
    const required = [
      ...['admin', 'rrhh', 'responsable_departamento', 'auditor', 'empleado', 'kiosk'].map(
        (role) => `app.roles.${role}`,
      ),
      ...['active', 'suspended', 'terminated'].map((status) => `employees.status.${status}`),
      ...['pending', 'issued', 'delivered'].flatMap((status) => [
        `pin.status.${status}`,
        `pin.statusHint.${status}`,
      ]),
      ...['no_credential', 'pending_print', 'pending_delivery', 'delivered', 'revoked'].map(
        (status) => `credentials.status.${status}`,
      ),
      ...['issue', 'print', 'deliver', 'revoke'].flatMap((kind) => [
        `credentials.confirm.${kind}.heading`,
        `credentials.confirm.${kind}.action`,
        `credentials.confirm.${kind}.explanation`,
        `credentials.confirm.${kind}.notice`,
      ]),
      ...['lost', 'stolen', 'damaged', 'offboarding', 'printFailed', 'other'].map(
        (reason) => `credentials.revoke.reasons.${reason}`,
      ),
      ...['endOfContract', 'resignation', 'dismissal', 'retirement', 'other'].map(
        (reason) => `employees.offboard.reasons.${reason}`,
      ),
      // Los nueve codigos del Anexo C del documento 01 y las cuatro acciones de
      // RF-PA-04. El catalogo es cerrado y viaja tal cual en la API: un codigo
      // sin texto se pintaria en pantalla como `OLVIDO_FICHAJE_SALIDA`, que es
      // lo que el i18n existe para evitar. Y cada uno lleva ademas su pista:
      // quien corrige tiene que poder elegir bien sin consultar el Anexo C.
      ...[
        'OLVIDO_FICHAJE_ENTRADA',
        'OLVIDO_FICHAJE_SALIDA',
        'FALLO_TECNICO_QUIOSCO',
        'TARJETA_NO_DISPONIBLE',
        'CREDENCIAL_NO_ENTREGADA',
        'ERROR_DE_ESCANEO_DUPLICADO',
        'AJUSTE_ACORDADO_CON_RRHH',
        'ALTA_RETROACTIVA',
        'OTROS',
      ].flatMap((reason) => [`corrections.reasons.${reason}`, `corrections.reasonHints.${reason}`]),
      ...['created', 'modified', 'closed', 'voided'].map(
        (action) => `corrections.action.${action}`,
      ),
      // Origen y estado de cada tramo en el detalle de jornada (RF-PA-03). Los
      // dos enumerados viajan tal cual en la respuesta: sin texto, la pantalla
      // pintaria `qr_kiosk` y `superseded` a quien revisa una nomina.
      ...['qr_kiosk', 'pin_kiosk', 'manual_admin', 'import'].map(
        (source) => `workdays.sources.${source}`,
      ),
      ...['open', 'closed', 'anomalous', 'voided', 'superseded'].map(
        (status) => `workdays.entryStatus.${status}`,
      ),
      // La bandeja de incidencias (RF-PA-05): los cuatro enumerados del
      // contrato viajan tal cual en la respuesta y se traducen dinamicamente
      // (`t(`incidents.types.${type}`)`), asi que `usedKeys()` no los detecta.
      ...[
        'open_shift_expired',
        'short_shift',
        'long_shift',
        'missing_break',
        'insufficient_rest',
        'clock_skew',
        'missing_clock_out',
        'anomalous_pattern',
      ].map((type) => `incidents.types.${type}`),
      ...['high', 'medium', 'low'].map((severity) => `incidents.severities.${severity}`),
      ...['open', 'resolved', 'dismissed'].map((status) => `incidents.status.${status}`),
      ...['resolved', 'dismissed'].map((outcome) => `incidents.outcomes.${outcome}`),
      ...[
        'network',
        'unauthenticated',
        'invalidCredentials',
        'forbidden',
        'notFound',
        'conflict',
        'validation',
        'rateLimited',
        'unavailable',
        'unexpected',
      ].flatMap((kind) => [`errors.${kind}.title`, `errors.${kind}.advice`]),
    ]

    expect(required.filter((key) => !available.has(key))).toEqual([])
  })

  it('avisa de que el PIN no se puede volver a consultar, en los dos idiomas', () => {
    expect(es.pin.reveal.onlyOnce).toContain('una sola vez')
    expect(en.pin.reveal.onlyOnce).toContain('once')
  })

  it('elige el primer idioma soportado de las preferencias del navegador', () => {
    expect(resolveLocale(['en-GB', 'es-ES'])).toBe('en')
    expect(resolveLocale(['fr-FR', 'es-ES'])).toBe('es')
  })

  it('cae al idioma por defecto cuando ninguna preferencia encaja', () => {
    expect(resolveLocale(['fr-FR', 'de-DE'])).toBe(DEFAULT_LOCALE)
    expect(resolveLocale([])).toBe(DEFAULT_LOCALE)
  })

  it('rechaza lo que no es un idioma soportado sin suponer que es texto', () => {
    expect(isSupportedLocale('es')).toBe(true)
    expect(isSupportedLocale('pt')).toBe(false)
    expect(isSupportedLocale(42)).toBe(false)
    expect(isSupportedLocale(undefined)).toBe(false)
  })
})
