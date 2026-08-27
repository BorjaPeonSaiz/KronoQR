# Cliente de la API

- `schema.d.ts` — **generado** desde `docs/api/openapi.yaml` con `npm run api:generate`. No se
  escribe a mano y no se edita: el contrato es la fuente de verdad de la API (CLAUDE.md, orden de
  autoridad 2; ADR-013). Está excluido de Prettier: no tiene sentido discutir el formato de un
  fichero que nadie edita.
- `types.ts` — alias cortos de los tipos generados. **No declara ninguna forma de la API**, solo
  les pone nombre.
- `queryClient.ts` — configuración de la caché de consultas.
- `organisation.api.ts` — centros y departamentos, que usan varias _features_ a la vez.

**`http.ts` ya no vive aquí.** La puerta de salida base hacia la API —token de sesión en un solo
sitio, errores traducidos a un tipo cerrado (`ApiErrorKind`), descarga de documentos sin dejar
rastro— se movió a `@kronoqr/web-kit/http` (ADR-036): es idéntica para cualquier SPA de KronoQR y
`frontend-portal` la necesitaba tanto como este panel. Los endpoints concretos (`employees.api.ts`,
`credentials.api.ts`, etc.) siguen siendo de este panel y consumen `requestJson`/`requestBlob`
importándolos de `@kronoqr/web-kit/http`, no de un fichero local.

Si una pantalla necesita algo que el contrato no da, se pide el cambio en `openapi.yaml`; no se
improvisa aquí.
