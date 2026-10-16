import type { Linter } from 'eslint'

export declare const STORAGE_MESSAGE: string

export interface StorageException {
  file: string
  allow: Array<'localStorage' | 'sessionStorage'>
  reason: string
}

export declare function noBrowserStorage(options?: {
  files?: string[]
  exceptions?: StorageException[]
}): Linter.Config[]
