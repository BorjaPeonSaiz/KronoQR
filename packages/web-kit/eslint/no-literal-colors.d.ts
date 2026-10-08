import type { ESLint, Linter, Rule } from 'eslint'

export declare const noLiteralColorsPlugin: ESLint.Plugin & {
  rules: { 'no-literal-colors': Rule.RuleModule }
}

export declare function noLiteralColors(
  files?: string[],
  ignores?: string[],
): Linter.Config & { files: string[]; ignores: string[]; rules: Linter.RulesRecord }
