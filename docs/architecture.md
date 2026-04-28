# Arquitectura actual

## Objetivo

Construir una base web real para `d-ml` con tres capas diferenciadas:

1. una capa publica para presentar la marca y sus soluciones
2. un portal autenticado por roles para operar las soluciones activas
3. una futura capa cloud para procesamiento pesado y automatizaciones

Dentro de esa estructura, `Audioprint` es hoy la solucion activa orientada al flujo de audio:

1. subir un audio
2. generar un analisis temporal y espectral
3. conservar imagenes, JSON y trazabilidad
4. consultar tendencias y resultados historicos

`Qvoice` queda definida como una segunda solucion de la marca, orientada al seguimiento de la voz humana en entornos laborales. Por ahora su espacio existe a nivel de acceso y producto, pero su capa analitica se incorporara mas adelante mediante otras APIs.

`Smart Tales` se incorpora como un nuevo producto digital orientado a familias: perfiles infantiles, clonacion de voz autorizada, generacion de cuentos personalizados y distribucion de audio narrado. A diferencia de `Audioprint`, su analitica no gira alrededor del espectrograma sino alrededor de orquestacion narrativa, voz sintetica, biblioteca personal y control de consentimiento.

## Estado de arquitectura

La arquitectura vigente no es la planteada originalmente con `Next.js + FastAPI` como despliegue principal.

Hoy el sistema se apoya en:

- `landing` y portal autenticado en `PHP`
- `MySQL` como base operativa actual
- archivos estaticos compartidos para estilo y comportamiento
- hosting tradicional como entorno activo
- integraciones cloud reservadas para una fase posterior

La arquitectura inicial con `frontend`, `api` y `workers` Python sigue existiendo como base de exploracion tecnica, pero no es la capa publicada actual.

## Modulos actuales

- Landing publica
- Portal autenticado
- MySQL
- Assets compartidos
- Documentacion y configuracion
- `audio_scalogram_api/` como servicio Python independiente para analisis de audio

## Stack activo

### Capa publica y portal

- `PHP` para autenticacion, sesiones y vistas protegidas
- `HTML` para la landing publica
- `CSS` compartido en `assets/css`
- `JavaScript` ligero en `assets/js`

### Base de datos

- `MySQL`
- tablas activas para usuarios, roles y relacion usuario-solucion
- posibilidad de ampliar a sesiones persistidas, jobs y auditoria

### Infraestructura real

- hosting actual con soporte `PHP + MySQL`
- despliegue por archivos publicados
- variables sensibles gestionadas en entorno y configuracion local

## Estructura principal

- `index.html`: landing publica de `d-ml`
- `login.php`, `signup.php`, `logout.php`: acceso y gestion de sesion
- `dashboard.php`: punto de entrada autenticado
- `portal/admin.php`: gestion y operaciones administrativas
- `portal/audioprint.php`: espacio operativo de la solucion activa
- `portal/qvoice.php`: espacio placeholder para la siguiente solucion de voz
- `portal/smart_tales.php`: espacio operativo inicial para el nuevo producto narrativo
- `includes/auth.php`: control de sesion y permisos
- `includes/layout.php`: layout compartido del portal
- `includes/audioprint.php`: integracion entre portal y API de analisis
- `config/`: bootstrap, entorno y acceso a base de datos
- `audio_scalogram_api/`: API independiente de analisis temporal y espectral

## Flujo actual

1. El usuario entra en la landing publica.
2. Desde `Acceso` o `Crear cuenta` llega al portal.
3. `PHP` valida credenciales contra `MySQL`.
4. Se crea una sesion nativa y se redirige segun membresia y permisos.
5. `Audioprint` registra audios, llama a la API y conserva los artefactos del analisis.

## Flujo futuro recomendado

Cuando se active una capa cloud mas amplia, el portal actual actuara como front-office de las soluciones activas:

1. el usuario autenticado subira un audio
2. el portal registrara la peticion
3. una API externa o servicio especializado ejecutara el analisis
4. el portal mostrara estado, artefactos, tendencias y resultados finales

## Decision tecnica

Se prioriza `PHP + MySQL` en esta fase porque es compatible con el hosting actual y permite publicar ya una presencia comercial y un portal funcional sin bloquearse por una infraestructura mas compleja.

El procesamiento de audio queda desacoplado en `audio_scalogram_api`, lo que permite evolucionar la parte analitica sin rehacer la capa publicada.

Para `Smart Tales`, la recomendacion es repetir ese principio: mantener el portal PHP como front-office compartido y desacoplar la logica de generacion en uno o varios servicios externos, por ejemplo:

1. API de producto para perfiles, solicitudes de cuento y biblioteca
2. servicio LLM para generar el texto infantil con guardrails
3. servicio TTS/voice cloning para narracion
4. almacenamiento de audio y muestras con trazabilidad y consentimiento

## Proxima evolucion recomendada

- consolidar el modelo de datos de analisis y tendencias
- mejorar el control global de usuarios y auditoria
- preparar el contrato entre portal y servicios de analisis externos
- definir que nuevas soluciones o APIs entran en la marca sin arrastrar legado innecesario
- poner `Smart Tales` sobre el mismo sistema comun de usuarios, roles, portal y administracion sin duplicar autenticacion
