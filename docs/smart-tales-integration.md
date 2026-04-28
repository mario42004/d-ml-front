# Smart Tales Integration

## Fuente funcional

La base de este producto sale de `smart_Tales/Doc/approach_description/ai_story_app_spec.docx`.

El documento define un producto movil premium para generar cuentos infantiles personalizados, narrados con voces familiares clonadas y pensados para una rutina de noche repetible.

## Objetivo de integracion

Construir `Smart Tales` como un producto nuevo dentro del ecosistema `d-ml`, reutilizando el sistema comun ya existente:

- autenticacion y sesiones compartidas
- modelo de `products`, `roles` y `user_product_roles`
- dashboard comun y navegacion por solucion
- administracion global de usuarios y accesos

La idea no es abrir otro sistema aislado, sino enchufar el producto nuevo al front-office actual y desacoplar solo la parte intensiva de generacion.

## Propuesta de arquitectura

### Capa comun ya reutilizable

- `PHP` sigue siendo la puerta de entrada web y el panel autenticado
- `MySQL` sigue siendo el registro comun de usuarios, membresias y metadatos operativos
- el panel de administracion global sigue asignando accesos por producto

### Capa especifica de Smart Tales

- `portal/smart_tales.php` como entrada operativa del producto dentro del portal
- tablas de dominio para menores, voces y solicitudes de cuento
- futuro backend especializado para orquestar LLM, TTS y storage

### Servicios externos recomendados

1. `smart_tales_api`
   Responsable de perfiles infantiles, consentimientos, solicitudes de cuento, biblioteca y estados.
2. `story_generation_service`
   Genera texto seguro y estructurado segun tema, tono, duracion y contexto del menor.
3. `voice_service`
   Registra muestra, clona voz, guarda `provider_voice_id` y sintetiza el audio final.
4. `storage layer`
   Guarda muestras, audios narrados, portadas y artefactos de auditoria.

## Integracion con el sistema de informacion actual

### Identidad y acceso

- `products` incorpora `smart_tales`
- `roles` incorpora `admin` y `user` para ese producto
- `includes/auth.php` resuelve la nueva ruta del dashboard sin duplicar login

### Datos maestros

- `users` sigue siendo la identidad comun
- `smart_tales_child_profiles` modela los perfiles infantiles por cuenta
- `smart_tales_voice_profiles` guarda voces autorizadas y sus ids de proveedor
- `smart_tales_story_requests` concentra cada generacion de cuento y su salida

### Administracion

- el admin global puede asignar o retirar acceso a `Smart Tales`
- el producto puede tener admins propios para supervision funcional
- el consentimiento de voz debe quedar trazado a nivel de perfil de voz y proveedor

## Modelo de datos inicial

### `smart_tales_child_profiles`

- un menor pertenece a un usuario del sistema
- guarda nombre visible, edad, idioma y preferencias narrativas
- permite mantener contexto de rutina nocturna

### `smart_tales_voice_profiles`

- una voz pertenece al usuario titular que aporta la muestra
- puede asociarse opcionalmente a un menor si se usa para un perfil concreto
- guarda proveedor, `provider_voice_id`, estado y consentimiento

### `smart_tales_story_requests`

- registra tema, tono, duracion objetivo e idioma
- guarda el texto generado, el audio final y el estado del proceso
- deja preparado el historial para biblioteca, reintentos y auditoria

## Contrato funcional recomendado

### Onboarding

1. el usuario entra con su cuenta del ecosistema `d-ml`
2. crea uno o varios perfiles infantiles
3. sube una muestra de voz y acepta consentimiento explicito
4. el backend genera y guarda el `provider_voice_id`

### Uso diario

1. el usuario elige menor, tema, tono y duracion
2. el backend genera el cuento con guardrails infantiles
3. el servicio TTS produce la narracion usando la voz autorizada
4. el resultado queda guardado en biblioteca y listo para reproducir

## Guardrails obligatorios

- consentimiento explicito para clonacion de voz
- prompts infantiles con lenguaje seguro y final relajante
- trazabilidad de proveedor LLM y TTS por solicitud
- control de costes por peticion y por suscripcion
- opcion de desactivar o revocar una voz ya registrada

## Roadmap de implementacion

### Fase 1

- producto dado de alta en el sistema comun
- tablas base y portal inicial creados
- API propia para CRUD de menores, voces y solicitudes
- generacion de cuento + narracion en flujo simple

### Fase 2

- biblioteca de historias, reuso de voces y multiples menores
- moderacion mas fuerte y continuidad narrativa
- panel admin con supervision operativa del producto

### Fase 3

- app movil dedicada
- suscripciones y limites de uso
- continuidad entre cuentos, musica y personalizacion avanzada

## Decision de producto

`Smart Tales` debe integrarse con el sistema de informacion actual en la capa de identidad, permisos, navegacion y administracion.

La generacion de contenido, clonacion de voz y almacenamiento multimedia deben vivir fuera del portal como servicios especializados. Asi evitamos duplicar autenticacion y tambien evitamos meter logica pesada o sensible dentro del monolito PHP.
