import type { ESLint, Linter, Rule } from 'eslint'

export declare const noPlanReferencesPlugin: ESLint.Plugin & {
  rules: { 'no-plan-references': Rule.RuleModule }
}

/** La primera referencia al plan o a una revision del texto de un comentario, o `null`. */
export declare function findPlanReference(text: string): string | null

export declare function noPlanReferences(
  files?: string[],
  ignores?: string[],
): Linter.Config & { files: string[]; ignores: string[]; rules: Linter.RulesRecord }
