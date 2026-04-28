<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';

require_role('medico');
render_app_header('d-ml | Portal medico');
?>
<section class="page-stack">
  <section class="hero">
    <div class="portal-hero">
      <div>
        <span class="role-badge">Medico</span>
        <h1>Un espacio pensado para revisar cada estudio con mas claridad.</h1>
        <p class="lead">Aqui se ira reuniendo el trabajo clinico asociado a los estudios, los resultados digitalizados y el seguimiento de cada caso.</p>
      </div>
      <aside class="route-card">
        <strong>Acceso confirmado</strong>
        <code>/portal/medico.php</code>
        <p class="route-note">Este entorno esta reservado para la revision clinica y el seguimiento de informacion medica.</p>
      </aside>
    </div>
  </section>

  <section class="portal-card-grid">
    <article class="card">
      <span class="tag">Revision</span>
      <h3>Estudios pendientes</h3>
      <p>Aqui apareceran los ECG listos para revision y validacion clinica.</p>
    </article>
    <article class="card">
      <span class="tag">Digitalizacion</span>
      <h3>Resultados procesados</h3>
      <p>Se mostrara la senal digitalizada y la informacion clave del caso.</p>
    </article>
    <article class="card">
      <span class="tag">Trazabilidad</span>
      <h3>Historial del caso</h3>
      <p>Una vista clara del recorrido del caso y sus momentos importantes.</p>
    </article>
    <article class="card">
      <span class="tag">Acciones</span>
      <h3>Validacion clinica</h3>
      <p>Espacio para anotar, validar y acompanar cada estudio con criterio clinico.</p>
    </article>
  </section>
</section>
<?php render_app_footer(); ?>
