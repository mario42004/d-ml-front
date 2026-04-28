# Documento para Codex — Extensión Evolutiva de API de Análisis de Audio

## 1. Contexto del sistema existente

El proyecto ya está iniciado y parcialmente funcional.

Características actuales:

- API existente desplegada en Linux.
- Entorno de ejecución en Docker.
- Servidor con aproximadamente 8GB de RAM.
- Ya existe al menos un endpoint funcional que recibe/procesa audio.
- La API ya devuelve un JSON.
- No se debe reconstruir el proyecto desde cero.
- No se debe cambiar la arquitectura principal existente.
- No se deben eliminar endpoints ni modificar contratos actuales de manera incompatible.

## 2. Objetivo de esta extensión

Extender la API actual con un módulo adicional de análisis acústico que enriquezca el JSON ya existente con resultados útiles para:

- dashboards actuales o futuros,
- análisis exploratorio,
- almacenamiento de features,
- análisis longitudinal,
- futura integración de machine learning,
- posible uso en contextos de health care no diagnóstico.

La extensión debe seguir un enfoque incremental en 4 etapas.

## 3. Principio arquitectónico obligatorio

Codex debe complementar la API existente, no reemplazarla.

La integración esperada es:

```python
existing_result = existing_audio_analysis(...)

from analysis_engine.orchestrator import run_analysis_engine

analysis_engine_result = run_analysis_engine(
    audio_input=...,
    sample_rate=...,
    config=...
)

existing_result["analysis_engine"] = analysis_engine_result

return existing_result
```

Si la API actual ya devuelve un JSON, se debe conservar ese JSON y añadir una clave nueva:

```json
{
  "existing_fields": "...",
  "analysis_engine": {
    "...": "..."
  }
}
```

## 4. Módulo nuevo a crear

Crear un módulo desacoplado:

```text
analysis_engine/
    ├── __init__.py
    ├── config.py
    ├── audio_io.py
    ├── framing.py
    ├── stage1_basic.py
    ├── stage2_dynamics.py
    ├── stage3_timefreq.py
    ├── stage4_ml_ready.py
    ├── orchestrator.py
    └── schemas.py
```

Este módulo debe poder llamarse desde la API actual sin cambiar la estructura base del proyecto.

## 5. Formatos de audio aceptados

La plataforma debe aceptar los siguientes formatos de entrada:

```text
.wav
.webm
.mp3
.m4a
.ogg
.flac
.aac
```

### Requisitos por formato

| Formato | Prioridad | Comentario |
|---|---:|---|
| `.wav` | Alta | Formato preferente para análisis científico. |
| `.webm` | Alta | Importante para grabaciones desde navegador. |
| `.mp3` | Media | Común, pero comprimido con pérdida. |
| `.m4a` | Media | Común en móviles. |
| `.ogg` | Media | Útil para navegador y Linux. |
| `.flac` | Media | Sin pérdida, útil para investigación. |
| `.aac` | Baja | Aceptar si el backend ya soporta ffmpeg. |

## 6. Normalización interna del audio

Independientemente del formato recibido, el sistema debe convertir el audio a una representación interna uniforme:

```text
sample_rate objetivo: 16000 Hz por defecto
canales: mono
dtype: float32
rango de amplitud: [-1.0, 1.0]
```

Configuración sugerida:

```python
AUDIO_CONFIG = {
    "target_sample_rate": 16000,
    "mono": True,
    "dtype": "float32",
    "normalize_amplitude": True,
    "max_audio_duration_seconds": 180,
    "accepted_extensions": [".wav", ".webm", ".mp3", ".m4a", ".ogg", ".flac", ".aac"]
}
```

## 7. Manejo de dependencias para formatos

El sistema debe usar preferiblemente:

- `soundfile` para `.wav` y `.flac`.
- `librosa` cuando ya esté disponible.
- `ffmpeg` o `pydub` únicamente si el proyecto actual ya lo permite o si Docker lo soporta.

Codex debe verificar el entorno antes de introducir nuevas dependencias pesadas.

Si se añade soporte para formatos comprimidos, documentar en Dockerfile o README la necesidad de `ffmpeg`.

Ejemplo:

```dockerfile
RUN apt-get update && apt-get install -y ffmpeg
```

No instalar dependencias innecesarias si el proyecto ya tiene una solución funcional.

## 8. Procesamiento por tramas

El análisis debe dividir el audio en tramas de duración configurable.

Esto es obligatorio.

El objetivo es:

- controlar memoria,
- mejorar rendimiento,
- permitir análisis temporal,
- preparar el sistema para machine learning,
- permitir dashboards con evolución por segmentos,
- aprovechar el hardware disponible sin cargar todo el procesamiento pesado de una vez.

## 9. Configuración de tramas

La duración de cada trama debe poder configurarse.

Valores recomendados:

```python
FRAMING_CONFIG = {
    "frame_duration_seconds": 2.0,
    "hop_duration_seconds": 1.0,
    "allow_overlap": True,
    "min_frame_duration_seconds": 0.5,
    "max_frame_duration_seconds": 10.0,
    "auto_frame_duration": True,
    "max_frames": 300
}
```

## 10. Política de selección automática de tramas

Si `auto_frame_duration=True`, el sistema debe escoger la duración de trama según la duración total del audio y los recursos disponibles.

Regla inicial sugerida:

| Duración del audio | Frame recomendado | Hop recomendado |
|---:|---:|---:|
| 0–15 s | 1 s | 0.5 s |
| 15–60 s | 2 s | 1 s |
| 60–180 s | 3 s | 1.5 s |
| >180 s | rechazar o procesar solo hasta límite configurado | — |

Para el servidor actual de 8GB RAM:

- usar 16 kHz,
- usar `float32`,
- evitar CWT de alta resolución en todas las tramas,
- limitar el número máximo de tramas,
- reducir vectores largos antes de devolver JSON.

## 11. Módulo de framing

Crear:

```text
analysis_engine/framing.py
```

Funciones esperadas:

```python
def choose_frame_params(
    duration_seconds: float,
    config: dict
) -> dict:
    """Return frame_duration_seconds and hop_duration_seconds."""

def split_audio_into_frames(
    audio: np.ndarray,
    sample_rate: int,
    frame_duration_seconds: float,
    hop_duration_seconds: float,
    max_frames: int
) -> list[dict]:
    """Return a list of frames with metadata."""
```

Cada frame debe tener:

```python
{
    "frame_index": 0,
    "start_time": 0.0,
    "end_time": 2.0,
    "audio": np.ndarray
}
```

El campo `audio` no debe aparecer en el JSON final.

## 12. JSON final orientado a tramas

El JSON enriquecido debe tener estructura compatible con dashboard y ML.

Estructura sugerida:

```json
{
  "analysis_engine": {
    "version": "0.1.0",
    "input_audio": {
      "original_format": "webm",
      "internal_sample_rate": 16000,
      "channels": 1,
      "duration_seconds": 34.7
    },
    "framing": {
      "enabled": true,
      "frame_duration_seconds": 2.0,
      "hop_duration_seconds": 1.0,
      "num_frames": 34,
      "max_frames": 300
    },
    "global_features": {},
    "frame_features": [],
    "temporal_summary": {},
    "time_frequency_summary": {},
    "ml_ready": {}
  }
}
```

## 13. Reglas para `frame_features`

Cada elemento de `frame_features` debe representar una trama.

Ejemplo:

```json
{
  "frame_index": 0,
  "start_time": 0.0,
  "end_time": 2.0,
  "basic_features": {
    "rms_mean": 0.031,
    "rms_std": 0.004,
    "zcr_mean": 0.08,
    "energy_bands": {
      "low": 0.22,
      "mid": 0.57,
      "high": 0.21
    }
  }
}
```

No devolver arrays enormes por frame.

## 14. Etapa 1 — Basic acoustic features

Archivo:

```text
analysis_engine/stage1_basic.py
```

Función:

```python
def compute_basic_features(audio: np.ndarray, sr: int) -> dict:
```

Features globales y por trama:

- duration_seconds
- rms_mean
- rms_std
- rms_max
- zero_crossing_rate_mean
- spectral_centroid_mean
- spectral_bandwidth_mean
- energy_low_band
- energy_mid_band
- energy_high_band
- snr_estimate

Salida JSON:

```json
"basic_features": {
  "duration_seconds": 2.0,
  "rms_mean": 0.034,
  "rms_std": 0.01,
  "rms_max": 0.12,
  "zcr_mean": 0.08,
  "spectral_centroid_mean": 1350.4,
  "spectral_bandwidth_mean": 850.2,
  "energy_bands": {
    "low": 0.2,
    "mid": 0.5,
    "high": 0.3
  },
  "snr_estimate": 18.2
}
```

## 15. Etapa 2 — Temporal dynamics

Archivo:

```text
analysis_engine/stage2_dynamics.py
```

Función:

```python
def compute_temporal_dynamics(frame_features: list[dict]) -> dict:
```

Esta etapa debe usar los resultados por trama para construir análisis temporal.

Features:

- rms_trend
- power_slope
- num_energy_peaks
- peak_frame_indices
- peak_times_seconds
- stability_index
- variability_index
- active_frame_ratio
- silence_frame_ratio

Salida:

```json
"temporal_summary": {
  "power_slope": 0.012,
  "num_energy_peaks": 5,
  "peak_times_seconds": [4.0, 8.0, 13.0],
  "stability_index": 0.83,
  "variability_index": 0.21,
  "active_frame_ratio": 0.78,
  "silence_frame_ratio": 0.22
}
```

## 16. Etapa 3 — Time-frequency features

Archivo:

```text
analysis_engine/stage3_timefreq.py
```

Función:

```python
def compute_time_frequency_features(
    audio: np.ndarray,
    sr: int,
    config: dict
) -> dict:
```

Esta etapa debe ser opcional y más costosa.

Debe estar desactivada por defecto si el servidor es limitado.

Features:

- wavelet_entropy
- dominant_scale
- spectral_concentration
- energy_distribution_reduced
- frequency_centroid_timefreq

Restricciones:

- no guardar imágenes por defecto,
- no devolver matrices CWT completas,
- usar máximo 64 escalas inicialmente,
- permitir downsampling para CWT,
- aplicar sobre audio global o sobre un subconjunto representativo de tramas.

Salida:

```json
"time_frequency_summary": {
  "enabled": true,
  "wavelet_entropy": 2.31,
  "dominant_scale": 45,
  "spectral_concentration": 0.67,
  "energy_distribution_reduced": [0.03, 0.06, 0.12, 0.20]
}
```

## 17. Etapa 4 — ML-ready representation

Archivo:

```text
analysis_engine/stage4_ml_ready.py
```

Función:

```python
def build_ml_ready_representation(
    global_features: dict,
    frame_features: list[dict],
    temporal_summary: dict,
    time_frequency_summary: dict | None = None
) -> dict:
```

Debe construir una representación lista para ML.

Salida:

```json
"ml_ready": {
  "enabled": true,
  "feature_vector": [0.034, 0.01, 0.08, 0.2, 0.5, 0.3],
  "feature_names": [
    "rms_mean",
    "rms_std",
    "zcr_mean",
    "energy_low",
    "energy_mid",
    "energy_high"
  ],
  "feature_vector_length": 6,
  "version": "v1"
}
```

Reglas:

- vector plano,
- valores numéricos,
- sin objetos anidados dentro del vector,
- sin modelos deep learning en esta fase,
- evitar embeddings pesados todavía.

## 18. Configuración global del engine

Archivo:

```text
analysis_engine/config.py
```

Configuración sugerida:

```python
ANALYSIS_ENGINE_CONFIG = {
    "version": "0.1.0",

    "audio": {
        "target_sample_rate": 16000,
        "mono": True,
        "normalize_amplitude": True,
        "max_audio_duration_seconds": 180,
        "accepted_extensions": [".wav", ".webm", ".mp3", ".m4a", ".ogg", ".flac", ".aac"]
    },

    "framing": {
        "enabled": True,
        "frame_duration_seconds": 2.0,
        "hop_duration_seconds": 1.0,
        "allow_overlap": True,
        "auto_frame_duration": True,
        "max_frames": 300
    },

    "stages": {
        "enable_stage1_basic": True,
        "enable_stage2_dynamics": True,
        "enable_stage3_timefreq": False,
        "enable_stage4_ml_ready": True
    },

    "performance": {
        "max_returned_trend_points": 300,
        "max_wavelet_scales": 64,
        "use_float32": True,
        "avoid_large_arrays_in_json": True
    }
}
```

## 19. Orquestador

Archivo:

```text
analysis_engine/orchestrator.py
```

Función:

```python
def run_analysis_engine(
    audio_input,
    sample_rate: int | None = None,
    original_format: str | None = None,
    config: dict | None = None
) -> dict:
```

Responsabilidades:

1. Validar formato.
2. Normalizar audio.
3. Escoger duración de trama.
4. Dividir en tramas.
5. Calcular features globales.
6. Calcular features por trama.
7. Calcular dinámica temporal.
8. Calcular time-frequency si está activado.
9. Construir representación ML-ready si está activada.
10. Devolver diccionario JSON-ready.

## 20. Requisitos de rendimiento

Dado el servidor actual:

- Linux.
- Docker.
- 8GB RAM.

Codex debe cumplir:

- no cargar matrices grandes innecesarias,
- no usar GPU,
- no usar deep learning inicialmente,
- trabajar con `float32`,
- limitar duración máxima del audio,
- limitar número máximo de tramas,
- no devolver arrays grandes en JSON,
- usar downsampling cuando sea necesario,
- permitir desactivar etapas pesadas.

## 21. Manejo de errores

La API no debe fallar completamente si una etapa falla.

El JSON debe incluir errores parciales:

```json
"analysis_engine": {
  "status": "partial_success",
  "errors": [
    {
      "stage": "stage3_timefreq",
      "message": "Time-frequency analysis skipped due to resource limits."
    }
  ]
}
```

Estados posibles:

```text
success
partial_success
failed
skipped
```

## 22. Compatibilidad con dashboards

El JSON debe permitir directamente:

- línea temporal de RMS por trama,
- línea temporal de energía,
- barras de energía por bandas,
- indicadores globales,
- tabla por tramas,
- comparación entre sesiones,
- exportación futura a CSV,
- entrenamiento futuro de modelos ML.

Campos útiles para dashboard:

```json
"dashboard_ready": {
  "rms_trend": [
    {"time": 0.0, "value": 0.031},
    {"time": 1.0, "value": 0.035}
  ],
  "energy_band_summary": {
    "low": 0.2,
    "mid": 0.5,
    "high": 0.3
  },
  "key_indicators": {
    "duration_seconds": 34.7,
    "stability_index": 0.83,
    "variability_index": 0.21,
    "snr_estimate": 18.2
  }
}
```

## 23. Tests requeridos

Crear tests mínimos:

```text
tests/test_analysis_engine_stage1.py
tests/test_analysis_engine_framing.py
tests/test_analysis_engine_orchestrator.py
tests/test_analysis_engine_json_schema.py
```

Validar:

- formatos aceptados,
- rechazo de formatos no permitidos,
- división correcta en tramas,
- límite máximo de tramas,
- salida JSON serializable,
- ausencia de arrays NumPy en JSON,
- ejecución de Stage 1,
- ejecución de Stage 2,
- Stage 3 desactivada por defecto,
- salida ML-ready con vector plano.

## 24. Prompt final para Codex

```text
You are extending an existing audio analysis API.

The project already exists, runs in Docker on Linux, and has approximately 8GB RAM available.

Do not recreate the project.
Do not change the existing architecture.
Do not remove existing endpoints.
Do not break the current JSON response.

Create a modular extension named analysis_engine that enriches the existing JSON response.

The engine must accept the following audio formats:
.wav, .webm, .mp3, .m4a, .ogg, .flac, .aac

All audio must be normalized internally to:
- mono
- 16 kHz by default
- float32
- amplitude range [-1.0, 1.0]

The engine must split the audio into frames.
Frame duration must be configurable, with 2 seconds as the default.
Hop duration must be configurable, with 1 second as the default.
The system should support automatic frame sizing according to the audio duration and hardware constraints.

Implement the engine in four incremental stages:

1. Basic acoustic features
2. Temporal dynamics based on frame-level features
3. Optional time-frequency features, disabled by default
4. ML-ready feature vector

Optimize for low memory usage.
Do not generate heavy artifacts.
Do not save scalogram images by default.
Do not return large arrays in JSON.
Do not use deep learning in this phase.

All outputs must be JSON-ready dictionaries.

The resulting JSON must be useful for dashboards and future machine learning workflows.

Implement:
- analysis_engine/config.py
- analysis_engine/audio_io.py
- analysis_engine/framing.py
- analysis_engine/stage1_basic.py
- analysis_engine/stage2_dynamics.py
- analysis_engine/stage3_timefreq.py
- analysis_engine/stage4_ml_ready.py
- analysis_engine/orchestrator.py
- minimal tests for framing, formats, orchestrator, and JSON serialization.

Integrate the new engine by adding a new key called analysis_engine to the existing API response.
```
