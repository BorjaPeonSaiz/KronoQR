import type { ESLint, Linter, Rule } from 'eslint'

export declare const noPlanReferencesPlugin: ESLint.Plugin & {
  rules: { 'no-plan-references': Rule.RuleModule }
}

export declare function noPlanReferences(
  files?: string[],
  ignores?: string[],
): Linter.Config & { files: string[]; ignores: string[]; rules: Linter.RulesRecord }
