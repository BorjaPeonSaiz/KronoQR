// Zonas horarias IANA que ofrece el campo de zona del centro (V21-1-UX-1).
//
// `Intl.supportedValuesOf('timeZone')` da la lista del navegador. Sin esa funcion (navegadores
// antiguos) o si lanza, se cae a las zonas de los mercados del producto: un campo de texto libre
// sigue funcionando y la validacion real es del servidor.

/** Zonas de los mercados habituales: abren la lista y son la caida si el navegador no sabe listar. */
export const COMMON_TIME_ZONES: readonly string[] = [
  'Europe/Madrid',
  'Atlantic/Canary',
  'Europe/Lisbon',
  'Atlantic/Madeira',
  'Atlantic/Azores',
  'Europe/Paris',
  'Europe/London',
  'Europe/Dublin',
  'Europe/Berlin',
  'Europe/Rome',
  'America/Mexico_City',
  'America/Bogota',
  'America/Argentina/Buenos_Aires',
  'America/Santiago',
  'America/New_York',
  'UTC',
]

interface IntlWithSupportedValues {
  readonly supportedValuesOf?: (key: 'timeZone') => string[]
}

/** Las comunes primero (sin repetir) y despues el resto, en el orden del navegador. */
export function timezoneOptions(
  intl: IntlWithSupportedValues = Intl as unknown as IntlWithSupportedValues,
): readonly string[] {
  let supported: readonly string[] = []
  try {
    supported = intl.supportedValuesOf?.('timeZone') ?? []
  } catch {
    supported = []
  }
  if (supported.length === 0) return COMMON_TIME_ZONES

  const all = new Set(supported)
  const common = COMMON_TIME_ZONES.filter((zone) => all.has(zone) || zone === 'UTC')
  const rest = supported.filter((zone) => !common.includes(zone))
  return [...common, ...rest]
}
