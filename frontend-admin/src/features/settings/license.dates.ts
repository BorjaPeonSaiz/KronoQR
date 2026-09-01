// Como se enseña una fecha de licencia (RF-PD-04).
//
// **En UTC y a proposito.** La vigencia de una licencia la fija el fabricante al
// emitirla y viaja en UTC dentro de la clave firmada (regla dura 3): «caduca el
// 31 de diciembre» es el 31 de diciembre en la factura, en el correo del
// proveedor y en `license:show`. Convertirla a la zona del centro haria que un
// hotel de Canarias viera un dia distinto del que dice su contrato, que es
// exactamente el tipo de discrepancia que acaba en una llamada.
//
// Es la diferencia con los instantes del registro horario, que SI se convierten
// a la zona del centro: aquellos describen cuando fichó una persona.
//
// Se usa `formatCivilDate` de `@kronoqr/web-kit` —que formatea en UTC— sobre los
// diez primeros caracteres del instante, que son su dia UTC. **No se usa el `d()`
// de vue-i18n**: esta aplicacion no declara `datetimeFormats`, asi que `d()`
// devuelve cadena vacia y el aviso diria «caducó el , hace 12 dias».
import { formatCivilDate } from '@kronoqr/web-kit/datetime'

/** Un instante ISO en UTC como dia legible, o `—` si no hay fecha que dar. */
export function formatLicenseDay(instant: string | null | undefined, locale: string): string {
  if (instant === null || instant === undefined || instant === '') {
    return '—'
  }

  const day = formatCivilDate(instant.slice(0, 10), locale)

  return day === '' ? '—' : day
}
