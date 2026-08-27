# my-export

Descarga del registro propio en CSV (RF-ID-05, RL-05, art. 20 RGPD). Tarea 1.11.

- `MyExportView.vue` — filtro de rango y boton de descarga. El fichero se suelta en el acto (`downloadDocument` de `@kronoqr/web-kit/downloadDocument`, ADR-036): no queda vivo en el navegador.
- `export.api.ts` — `GET /api/v1/me/export?format=csv`, con `requestBlob` de `@kronoqr/web-kit/http`. Solo CSV en esta fase; el PDF llega en la tarea 2.9 como un valor mas del mismo parametro (ADR-012). Sin selector de formato en la interfaz: un desplegable con una unica opcion no aporta nada. El `WorkDateRange` del rango pedido es el mismo que declara `../my-records/workdays.api.ts`: las dos pantallas piden el mismo tipo de rango.

Carpeta por _feature_, no por tipo de fichero (doc 02 §3.5).
