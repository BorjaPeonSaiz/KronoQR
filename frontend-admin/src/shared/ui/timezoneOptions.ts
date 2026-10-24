// Zonas horarias IANA que ofrece el campo de zona del centro.
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

/**
 * Alias de CLDR/ICU que devuelve `Intl.supportedValuesOf` y que el servidor rechaza: PHP
 * (`DateTimeZone::listIdentifiers()`, `SiteTimezone`) solo acepta los nombres canonicos de tzdb.
 * Se traducen al canonico; una prueba compara el resultado con el volcado de PHP.
 */
export const CANONICAL_TIME_ZONES: Readonly<Record<string, string>> = {
  'Africa/Asmera': 'Africa/Asmara',
  'America/Buenos_Aires': 'America/Argentina/Buenos_Aires',
  'America/Catamarca': 'America/Argentina/Catamarca',
  'America/Coral_Harbour': 'America/Atikokan',
  'America/Cordoba': 'America/Argentina/Cordoba',
  'America/Godthab': 'America/Nuuk',
  'America/Indianapolis': 'America/Indiana/Indianapolis',
  'America/Jujuy': 'America/Argentina/Jujuy',
  'America/Louisville': 'America/Kentucky/Louisville',
  'America/Mendoza': 'America/Argentina/Mendoza',
  'Asia/Calcutta': 'Asia/Kolkata',
  'Asia/Katmandu': 'Asia/Kathmandu',
  'Asia/Rangoon': 'Asia/Yangon',
  'Asia/Saigon': 'Asia/Ho_Chi_Minh',
  'Atlantic/Faeroe': 'Atlantic/Faroe',
  'Europe/Kiev': 'Europe/Kyiv',
  'Pacific/Enderbury': 'Pacific/Kanton',
  'Pacific/Ponape': 'Pacific/Pohnpei',
  'Pacific/Truk': 'Pacific/Chuuk',
}

interface IntlWithSupportedValues {
  readonly supportedValuesOf?: (key: 'timeZone') => string[]
}

/** Las comunes primero y despues el resto en nombre canonico, sin repetir. */
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

  // Las comunes se conservan SIEMPRE (aunque el motor las llame de otra forma); el resto, en canonico.
  const common = new Set(COMMON_TIME_ZONES)
  const rest = [...new Set(supported.map((zone) => CANONICAL_TIME_ZONES[zone] ?? zone))].filter(
    (zone) => !common.has(zone),
  )
  return [...COMMON_TIME_ZONES, ...rest]
}
