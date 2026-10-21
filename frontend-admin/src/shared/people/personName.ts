// Nombre visible de una persona (ADR-060).
//
// La ficha de una persona dada de baja se minimiza en sitio al vencer su plazo:
// el servidor deja `first_name` y `last_name` en `''`. Pintar eso tal cual da un
// hueco que parece un fallo de carga; aqui se sustituye por un texto que dice lo
// que es. Un unico punto, para que ningun listado invente su propia version.
import { useI18n } from 'vue-i18n'

/** Verdadero cuando ni el nombre ni los apellidos tienen contenido: ficha minimizada. */
export function isMinimisedPerson(firstName: string, lastName: string): boolean {
  return firstName.trim() === '' && lastName.trim() === ''
}

/** El nombre completo, o `placeholder` si la ficha esta minimizada. */
export function visibleName(firstName: string, lastName: string, placeholder: string): string {
  return isMinimisedPerson(firstName, lastName) ? placeholder : `${firstName} ${lastName}`.trim()
}

/** Igual que `visibleName`, para los recursos que ya traen `full_name` compuesto. */
export function visibleFullName(fullName: string | null | undefined, placeholder: string): string {
  const value = (fullName ?? '').trim()

  return value === '' ? placeholder : value
}

export function usePersonName(): {
  personName: (firstName: string, lastName: string) => string
  personFullName: (fullName: string | null | undefined) => string
  isMinimised: (firstName: string, lastName: string) => boolean
} {
  const { t } = useI18n()

  return {
    personName: (firstName, lastName) => visibleName(firstName, lastName, t('people.minimised')),
    personFullName: (fullName) => visibleFullName(fullName, t('people.minimised')),
    isMinimised: isMinimisedPerson,
  }
}
