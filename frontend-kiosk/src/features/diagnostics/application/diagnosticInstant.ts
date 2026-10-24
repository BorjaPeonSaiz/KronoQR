// La fecha y hora de una fila del diagnostico (ultimo latido, fichaje mas antiguo
// de la cola, padron, caducidad del token).
//
// Con segundos y en 24 h, con el formateador de web-kit: el reloj que abre esta
// pantalla escribe `07:14` y la fecha de al lado tiene que leerse igual. En la
// zona del CENTRO (`site_time_zone` del ultimo latido); sin ella -tablet que
// aun no ha latido- en la de la tablet, que es la unica que hay. Nunca bloquea:
// sin dato legible devuelve `null` y la pantalla dice «no disponible».

import { formatInstant, resolveTimeZone } from '@kronoqr/web-kit/datetime'

export function formatDiagnosticInstant(
  iso: string | null,
  siteTimeZone: string | null,
  locale: string,
): string | null {
  if (iso === null) return null

  const zone = resolveTimeZone(siteTimeZone) ?? Intl.DateTimeFormat().resolvedOptions().timeZone
  const label = formatInstant(iso, zone, locale, { seconds: true })

  return label === '' ? null : label
}
