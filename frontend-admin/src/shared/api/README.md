# Cliente de la API

- `schema.d.ts` — **generado** desde `docs/api/openapi.yaml` con `npm run api:generate`. No se
  escribe a mano y no se edita: el contrato es la fuente de verdad de la API (CLAUDE.md, orden de
  autoridad 2; ADR-013). Está excluido de Prettier: no tiene sentido discutir el formato de un
  fichero que nadie edita.
- `types.ts` — alias cortos de los tipos generados. **No declara ninguna forma de la API**, solo
  les pone nombre.
- `http.ts` — la única puerta de salida hacia la API: token de sesión en un solo sitio, errores
  traducidos a un tipo cerrado (`ApiErrorKind`) y descarga de documentos sin dejar rastro.
- `queryClient.ts` — configuración de la caché de consultas.
- `organisation.api.ts` — centros y departamentos, que usan varias _features_ a la vez.

Si una pantalla necesita algo que el contrato no da, se pide el cambio en `openapi.yaml`; no se
improvisa aquí.
