// Comentarios sin «bloque N» (V21-1-RV-5, C8).
//
// POR QUE EXISTE. Un comentario que dice «(bloque 25)» o «bloque 14.4» cuenta en
// que tanda de correcciones se escribio el codigo, no por que es asi. Para
// quien lo lea dentro de un año, el bloque es un numero de un plan que ya esta
// cerrado y no explica nada; el motivo, en cambio, sigue valiendo: un
// requisito (`RF-*`, `RN-*`), un ADR o la decision del propietario con su
// fecha. La verificacion de la 2.2.1 encontro que estas referencias volvian
// despues de cada limpieza (de 66 a 89 en las SPA), porque ninguna herramienta
// lo impedia: una convencion que no verifica una herramienta es una sugerencia.
//
// QUE DETECTA. «bloque» o «bloques» seguido de un numero (`bloque 25`,
// `Bloque 12c`, `bloque 14.4`, `bloques 12 y 13`), sin distinguir mayusculas,
// en los comentarios de `.ts` y `.vue`, tambien en los de la `<template>`.
// «el bloque "que hacer"» o «bloqueo» no son una referencia al plan y pasan.
//
// QUE NO ALCANZA. Los `schema.d.ts` generados: sus comentarios vienen de las
// descripciones de `docs/api/openapi.yaml` y se corrigen alli. Para un caso
// suelto: `// eslint-disable-next-line kronoqr-comments/no-plan-references -- <motivo>`.

const PLAN_REFERENCE = /\bbloques?\s+\d+[a-z]?(?:\.\d+)?\b/i

const MESSAGE =
  'Comentario con referencia al plan («{{value}}»): cuenta cuando se escribio el codigo, no por que es asi. Sustituyela por el motivo: el requisito (`RF-*`, `RN-*`), el ADR o la decision del propietario con su fecha.'

/** @type {import('eslint').Rule.RuleModule} */
const rule = {
  meta: {
    type: 'suggestion',
    schema: [],
    messages: { planReference: MESSAGE },
  },
  create(context) {
    const sourceCode = context.sourceCode

    /** @param {{ value: string, loc: import('estree').SourceLocation }} comment */
    const check = (comment) => {
      const found = PLAN_REFERENCE.exec(comment.value)
      if (found !== null) {
        context.report({ loc: comment.loc, messageId: 'planReference', data: { value: found[0] } })
      }
    }

    return {
      'Program:exit'() {
        for (const comment of sourceCode.getAllComments()) check(comment)
        // Comentarios de la <template> (`<!-- Bloque 1 -->`): no estan en getAllComments().
        for (const comment of sourceCode.ast.templateBody?.comments ?? []) check(comment)
      },
    }
  },
}

export const noPlanReferencesPlugin = {
  meta: { name: 'kronoqr-comments' },
  rules: { 'no-plan-references': rule },
}

/**
 * Bloque de configuracion plana de ESLint para las tres SPA y web-kit.
 *
 * @param {string[]} files rutas a vigilar; por defecto `src/**`
 * @param {string[]} ignores rutas exentas; por defecto los `.d.ts` generados
 */
export function noPlanReferences(
  files = ['src/**/*.{ts,mts,tsx,vue,js,mjs}'],
  ignores = ['**/*.d.ts'],
) {
  return {
    name: 'kronoqr/sin-referencias-al-plan',
    files,
    ignores,
    plugins: { 'kronoqr-comments': noPlanReferencesPlugin },
    rules: { 'kronoqr-comments/no-plan-references': 'error' },
  }
}
