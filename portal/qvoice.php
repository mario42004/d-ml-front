<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';

require_product_access('qvoice');
set_current_product('qvoice');

$membership = current_membership('qvoice');

render_app_header('Qvoice | Solución');
?>
<section class="page-stack">
  <section class="hero">
    <div class="portal-hero">
      <div>
        <span class="role-badge">Qvoice</span>
        <h1>Seguimiento de la voz humana en entornos laborales.</h1>
        <p class="lead">Qvoice será la solución de d-ml orientada a observar, registrar y contextualizar la voz humana en escenarios de trabajo. Este espacio queda preparado como base funcional para incorporar futuros análisis con APIs especializadas, sin mezclar todavía promesas técnicas que aún no están implementadas.</p>
      </div>
      <aside class="route-card">
        <strong>Rol actual</strong>
        <code><?= htmlspecialchars((string) ($membership['role_name'] ?? 'User'), ENT_QUOTES, 'UTF-8') ?></code>
        <p class="route-note">Tu acceso ya está habilitado, pero la capa analítica de Qvoice se incorporará en una siguiente fase.</p>
      </aside>
    </div>
  </section>

  <section class="panel-grid">
    <article class="card">
      <span class="section-tag">Enfoque</span>
      <h2>Una solución para seguimiento vocal con contexto operativo.</h2>
      <p>Qvoice se plantea para trabajar con señales de voz humana capturadas en entornos laborales, con foco en trazabilidad, seguimiento de evolución, contexto de captura y futura lectura automatizada sobre indicadores todavía por definir.</p>
    </article>

    <article class="card">
      <span class="section-tag">Estado actual</span>
      <h2>Espacio reservado para la siguiente capa analítica.</h2>
      <p>Por ahora esta vista no muestra análisis, gráficas ni métricas. La idea es dejar lista la estructura de acceso y solución para ir conectando próximamente nuevas APIs de voz conforme vayamos definiendo el alcance técnico y funcional.</p>
    </article>
  </section>

  <section class="panel-grid">
    <article class="card">
      <span class="section-tag">Lo que vendrá</span>
      <h2>Base preparada para nuevos módulos.</h2>
      <ul class="service-list">
        <li>Registro de muestras y contexto de captura</li>
        <li>Paneles de seguimiento por persona, puesto o entorno</li>
        <li>Análisis especializados integrados desde APIs externas</li>
      </ul>
    </article>

    <article class="card">
      <span class="section-tag">Importante</span>
      <h2>Sin análisis ficticios.</h2>
      <p>Preferimos dejar Qvoice limpio y honesto en esta etapa: la solución existe como espacio funcional, pero los resultados se irán llenando con integraciones reales cuando construyamos esa capa.</p>
    </article>
  </section>
</section>
<?php render_app_footer(); ?>
