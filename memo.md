# Memo de continuidad

Fecha: 2026-04-29

## Contexto

Estamos trabajando principalmente en el front de `d-ml`:

`/home/mariojojoaacosta/Documents/d-ml-front`

El despliegue del front se hace por SFTP al servidor `d-ml.eu`, web root remoto:

`/html`

Las credenciales están guardadas en FileZilla local:

`/home/mariojojoaacosta/.config/filezilla/recentservers.xml`

No imprimir la contraseña. Se ha usado `paramiko` desde Python para subir archivos.

## Último estado: títulos dentro del PNG

El front ya envía la descripción corta del audio a la API como `audio_description`.

Archivo desplegado por SFTP:

- `includes/audioprint.php`

Cambio de API preparado en:

- Repo: `/home/mariojojoaacosta/Documents/d-ml`
- Rama: `clean-audio-metrics-contract`
- Commit: `b758440 Include audio descriptions in plot titles`

Qué hace:

- `/audioanalisys` acepta `audio_description` como `Form`.
- La API normaliza espacios, descarta vacío y corta a 50 caracteres.
- El dashboard PNG usa título `Audio Analysis Dashboard: {descripcion}`.
- La autocorrelación PNG usa título `Autocorrelation: {descripcion}`.
- Los títulos en `plots` del JSON quedan iguales a los títulos pintados.

Pruebas ejecutadas:

- `../.venv/bin/python -m pytest tests/test_plot_audio_description.py tests/test_audioanalisys_metrics_schema.py`
- `php -l /home/mariojojoaacosta/Documents/d-ml-front/includes/audioprint.php`

Pendiente:

- Abrir PR contra `main` desde `clean-audio-metrics-contract`.
- La integración de GitHub devolvió `403 Resource not accessible by integration` al intentar crear la PR.
- `gh` no está instalado en la máquina.

## Cambios ya desplegados

### Landing

Archivo principal:

`index.html`

Cambios aplicados:

- Se eliminó el footer redundante final que decía:
  - `d-ml desarrolla soluciones para transformar audio...`
  - botones `Entrar` / `Crear cuenta`
- La landing ahora cierra con la franja:
  - `Empieza con Audioprint.`
  - `Carga audios, conserva sus análisis y construye un historial trazable desde una interfaz operativa.`
- Se agregó sección `Nosotros`.
- Se agregó contacto:
  - Teléfono: `+34 602 454 625`
  - Correo: `soporte@d-ml.eu`
- Se agregó mensaje empresarial:
  - `Si estás interesado en una solución empresarial, escríbenos...`
- En la landing se unificó el texto de alta: todos los CTA dicen `Crear cuenta`.
- Se quitó `Registrarse`.
- Se corrigieron tildes y textos visibles.
- Se corrigieron falsos positivos de tildes en anclas y palabras:
  - `#proposito` debe quedarse sin tilde.
  - `Soluciones`, no `Soluciónes`.
  - `reutilizable`, no `reútilizable`.
  - `utilizable`, no `útilizable`.

### Estilo global

Archivos:

- `assets/css/core.css`
- `assets/css/landing.css`
- `assets/css/portal.css`

Cambios aplicados:

- Se eliminaron óvalos/cápsulas flotantes en etiquetas.
- Las etiquetas tipo `Nosotros`, `Operación`, `Audioprint`, etc. ahora son rótulos discretos con línea inferior.
- Se removieron adornos circulares y gradientes decorativos.
- Se hizo la plantilla más sobria:
  - botones rectangulares
  - cards menos redondeadas
  - menos ruido visual
  - mejor `line-height`
  - sin `letter-spacing` negativo en headings
- Se ajustó la card final de CTA para que no sea tan pesada.

### Audioprint: persistencia y export

Archivos importantes:

- `includes/audioprint.php`
- `portal/audioprint.php`
- `db/schema.sql`
- `scripts/migrate_audioprint_schema.py`
- `db/migrations/003_audio_job_feature_snapshots.sql`

Decisión funcional:

- El usuario aclaró que la persistencia/export debe ser una fila por audio/análisis.
- `user_id` sirve para permisos y filtro, pero no como identificador principal porque se repite.
- El CSV debe empezar por `analysis_id` / `audio_job_id`.

Tabla principal nueva:

`audio_job_feature_snapshots`

Características:

- Una fila por audio.
- `UNIQUE audio_job_id`.
- Guarda features en JSON:
  - `features_json`
  - `numeric_features_json`
  - `feature_labels_json`
  - `feature_units_json`
- Mantiene trazabilidad:
  - `audio_job_id`
  - `user_id`
  - `product_id`
  - `captured_at`

Tabla secundaria:

`audio_job_metrics`

Quedó como apoyo interno en formato largo, una métrica por fila, útil para detalles/tendencias, pero no como export principal.

CSV de features:

Endpoint:

`/portal/audioprint.php?download=metrics_table_csv`

Comportamiento:

- Usuario normal: exporta solo sus audios.
- Admin/super: exporta toda la tabla.
- Formato: una fila por audio; cada feature como columna.
- Encabezado inicial:
  - `analysis_id`
  - `audio_job_id`
  - `archivo_audio`
  - `audio_creado_en`
  - `audio_procesado_en`
  - `captured_at`
  - `estado_audio`
  - `mime_type`
  - `audio_size_bytes`
  - `user_id`
  - `user_email`
  - `usuario`
  - columnas dinámicas de features

Se hizo backfill en producción:

- `jobs_seen: 2`
- `snapshots_written: 2`

Después se verificó:

- Tabla `audio_job_feature_snapshots`
- `rows: 2`

## API

Repo API:

`/home/mariojojoaacosta/Documents/d-ml`

Rama creada:

`clean-audio-metrics-contract`

Commit:

`19ea1a8 Clean audio metrics response contract`

Estado:

- API probada en `https://api.d-ml.eu/audioanalisys`.
- Devuelve JSON limpio.
- Ya no devuelve bloques legacy contradictorios como:
  - `analysis_engine`
  - `audio_metadata`
  - `temporal_analysis`
  - `spectral_analysis`
  - `autocorrelation_analysis`
  - `scalogram_config`
  - `metrics`
- Usa `metricas.grupos`.
- La visual principal y las imágenes actuales fueron consideradas OK.

Pendiente:

- PR de API no se pudo abrir desde aquí:
  - GitHub connector dio 403.
  - `gh` no estaba disponible en ese momento.

## Verificaciones recientes

Antes de desplegar se corrieron lints PHP en archivos principales:

- `portal/audioprint.php`
- `includes/audioprint.php`
- `includes/auth.php`
- `portal/medico.php`
- `portal/paciente.php`
- `portal/soporte.php`
- `portal/smart_tales.php`
- `portal/qvoice.php`
- `portal/admin.php`
- `signup.php`
- `login.php`
- `dashboard.php`

Todos sin errores de sintaxis.

También se verificó por SFTP que el `index.html` publicado:

- contiene la sección `Nosotros`
- contiene `soporte@d-ml.eu`
- contiene `+34 602 454 625`
- no contiene `Registrarse`
- no contiene la card vieja de `Acceso / Entra en Audioprint...`
- no contiene el footer redundante final

## Último estado visual

El usuario dijo:

> esta mucho mejor

Después pidió:

- Quitar footer redundante final.
- Unificar CTA de alta como `Crear cuenta`.

Ambas cosas ya quedaron hechas y desplegadas.

## Para retomar

Si se continúa desde aquí:

1. Revisar landing visualmente en navegador.
2. Revisar que no haya caché del navegador mostrando CSS viejo.
3. Seguir afinando microcopy/diseño si el usuario señala nuevas partes feas.
4. No cambiar la lógica de persistencia de Audioprint salvo que el usuario lo pida.
5. Mantener el criterio: front sobrio, profesional, sin óvalos flotantes ni bloques redundantes.

## Mejoras y hallazgos futuros

### Plataforma genérica de análisis

Estado: implementado y desplegado el 2026-04-29.

Motivo:

- El equipo tendrá al menos dos APIs nuevas:
  - una API de machine learning
  - una API de descomposición wavelets
- No conviene duplicar un panel por cada API.

Archivos nuevos/modificados:

- `includes/analysis.php`
- `includes/audioprint.php`
- `includes/layout.php`
- `portal/analisis.php`
- `db/migrations/005_analysis_platform.sql`
- `db/schema.sql`
- `scripts/migrate_audioprint_schema.py`

Tablas nuevas:

- `analysis_engines`
- `analysis_jobs`
- `analysis_metrics`
- `analysis_feature_snapshots`
- `analysis_artifacts`

Decisión:

- Mantener por ahora las tablas actuales de Audioprint para no romper producción:
  - `audio_jobs`
  - `audio_job_metrics`
  - `audio_job_feature_snapshots`
- Pero cada análisis nuevo de Audioprint también se registra en las tablas genéricas `analysis_*`.
- Audioprint queda como primer motor registrado:
  - `audioprint_wavelet`

Verificación en producción:

- Se subieron los archivos por SFTP.
- Se ejecutó un verificador temporal remoto.
- Confirmó `ok=true` y existencia de:
  - `analysis_engines`
  - `analysis_jobs`
  - `analysis_metrics`
  - `analysis_feature_snapshots`
  - `analysis_artifacts`
- El verificador temporal fue eliminado.
- `/portal/analisis.php` responde en producción y redirige correctamente a login si no hay sesión.

Cómo debe entrar la próxima API:

1. Crear o reutilizar un producto en `products`.
2. Registrar un motor en `analysis_engines`, por ejemplo:
   - `ml_audio`
   - `wavelet_decomposition`
3. Crear un `analysis_jobs` con usuario, producto, engine, descripción y archivo/entrada.
4. Guardar métricas normalizadas en `analysis_metrics`.
5. Guardar una fila por análisis en `analysis_feature_snapshots`.
6. Guardar imágenes, JSON, audios o reportes en `analysis_artifacts`.

Pendiente recomendado:

- Fortalecer el panel común `/portal/analisis.php` con filtros por producto, engine, fecha y export CSV.
- Luego Audioprint, machine learning y wavelets podrán compartir historial, artifacts y permisos desde el mismo lugar.

### Modelo multi-servicio

Hallazgo:

- El modelo actual sí permite que un mismo usuario tenga varios servicios asociados.
- La relación principal es `user_product_roles`.
- `UNIQUE (user_id, product_id)` permite muchos productos por usuario, pero un solo rol por producto.
- Ejemplo válido:
  - Usuario X -> Audioprint -> user
  - Usuario X -> Qvoice -> admin
  - Usuario X -> Nuevo servicio -> user

Lo que ya está bien:

- `products` funciona como catálogo de servicios.
- `roles` define roles por producto.
- `user_product_roles` asigna acceso por usuario/producto.
- El dashboard de accesos muestra varias membresías.
- El panel admin ya recorre productos activos para actualizar membresías.

Mejora pendiente:

- El alta de usuarios en `portal/admin.php` sigue demasiado orientada a Audioprint:
  - título `Crear usuario para Audioprint`
  - creación inicial llama `admin_create_user(..., 'audioprint', ...)`
- Para soportar nuevos servicios con más elegancia, conviene convertir esa sección en un alta genérica:
  - selector de producto inicial
  - selector de rol dependiente del producto
  - copy neutral: `Crear usuario`
  - mensaje de éxito neutral

Limitación de extensibilidad:

- Para que un nuevo servicio aparezca en navegación no basta con insertarlo en `products`.
- También hay que crear su ruta/pantalla y registrar el código en `product_dashboard_path()` dentro de `includes/auth.php`.
- Esto está bien para servicios con UI propia, pero debe recordarse al agregar productos nuevos.
