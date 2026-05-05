<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/audiometer.php';

require_product_access('audiometer');
set_current_product('audiometer');

$user = current_user();
$membership = current_membership('audiometer');
$currentOrganizationId = current_organization_id();
$audiometerCoins = product_coin_balance((int) $user['id'], 'audiometer');
$message = null;
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;

    if (!verify_csrf(is_string($token) ? $token : null)) {
        $message = 'La sesión del formulario no es válida. Recarga la página e inténtalo de nuevo.';
        $messageType = 'error';
    } else {
        $action = (string) ($_POST['action'] ?? 'create_test');

        if ($action === 'delete_test') {
            if (!can_administer_product('audiometer')) {
                $message = 'No tienes permisos para eliminar screenings.';
                $messageType = 'error';
            } else {
                $testId = (int) ($_POST['test_id'] ?? 0);
                $result = delete_audiometer_test_record($testId);
                if (($result['ok'] ?? false) === true) {
                    $message = 'Screening eliminado correctamente.';
                    $messageType = 'success';
                } else {
                    $message = (string) ($result['message'] ?? 'No fue posible eliminar el screening.');
                    $messageType = 'error';
                }
            }
        } else {
            $result = create_audiometer_test((int) $user['id'], $_POST);
            if (($result['ok'] ?? false) === true) {
                $message = 'Screening guardado correctamente en el historial.';
                $messageType = 'success';
            } else {
                $message = (string) ($result['message'] ?? 'No fue posible guardar el screening.');
                $messageType = 'error';
            }
        }
    }
}

$tests = list_audiometer_tests_for_user((int) $user['id'], $currentOrganizationId);
$audiometerCoins = product_coin_balance((int) $user['id'], 'audiometer');
$adminTests = can_administer_product('audiometer') ? list_recent_audiometer_tests(50, $currentOrganizationId) : [];
$selectedTest = null;
$selectedPayload = [];
$selectedTestId = (int) ($_GET['test_id'] ?? 0);
if ($selectedTestId > 0) {
    $candidateTest = get_audiometer_test_by_id($selectedTestId);
    if ($candidateTest === null) {
        $message = 'El screening solicitado no existe.';
        $messageType = 'error';
    } elseif (!is_system_admin() && (int) ($candidateTest['organization_id'] ?? 0) !== $currentOrganizationId) {
        $message = 'No tienes permisos para consultar este screening.';
        $messageType = 'error';
    } elseif (!can_administer_product('audiometer') && (int) $candidateTest['user_id'] !== (int) $user['id']) {
        $message = 'No tienes permisos para consultar este screening.';
        $messageType = 'error';
    } else {
        $selectedTest = $candidateTest;
        $selectedPayload = audiometer_payload_from_row($candidateTest);
    }
}
$csrfToken = csrf_token();

render_app_header('Audiometer | Screening auditivo');
?>
<section class="page-stack">
  <section class="hero">
    <div class="audiometer-hero">
      <div>
        <div class="audiometer-hero-meta">
          <span class="role-badge">Audiometer</span>
          <span class="audiometer-role-chip">d-ml · Digital Machine Listening</span>
          <span class="audiometer-role-chip">www.d-ml.eu</span>
          <span class="audiometer-role-chip">
            Rol <?= htmlspecialchars((string) ($membership['role_name'] ?? 'User'), ENT_QUOTES, 'UTF-8') ?>
          </span>
        </div>
        <h1>Screening auditivo orientativo con tonos puros.</h1>
        <p class="lead">Audiometer es un módulo de d-ml para escucha computacional clínica: genera tonos por frecuencia y oído, registra respuestas con mouse y conserva un audiograma relativo para seguimiento. No sustituye una audiometría clínica con equipo calibrado.</p>
      </div>
    </div>
  </section>

  <?php if ($message !== null): ?>
    <div class="message <?= $messageType === 'error' ? 'is-error' : 'is-success' ?>">
      <strong><?= $messageType === 'error' ? 'Revisión necesaria' : 'Operación completada' ?></strong>
      <span><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></span>
    </div>
  <?php endif; ?>

  <?php if ($selectedTest !== null): ?>
    <?php
      $selectedThresholds = is_array($selectedPayload['thresholds'] ?? null) ? $selectedPayload['thresholds'] : [];
      $selectedFrequencies = is_array($selectedPayload['frequencies'] ?? null) ? $selectedPayload['frequencies'] : [];
    ?>
    <section class="card" id="screening-report">
      <span class="section-tag">Reporte histórico</span>
      <h2><?= htmlspecialchars((string) ($selectedTest['test_title'] ?: 'Screening auditivo'), ENT_QUOTES, 'UTF-8') ?></h2>
      <p class="muted">Realizado el <?= htmlspecialchars((string) $selectedTest['created_at'], ENT_QUOTES, 'UTF-8') ?>. Resultado orientativo no calibrado.</p>

      <div class="audiometer-report-grid">
        <article class="feature-card">
          <strong>Usuario</strong>
          <p><?= htmlspecialchars(trim((string) $selectedTest['first_name'] . ' ' . (string) $selectedTest['last_name']) . ' · ' . (string) $selectedTest['email'], ENT_QUOTES, 'UTF-8') ?></p>
        </article>
        <article class="feature-card">
          <strong>Equipo</strong>
          <p><?= htmlspecialchars(trim((string) $selectedTest['device_label'] . ' · ' . (string) $selectedTest['headphone_label']) ?: 'Sin detalle', ENT_QUOTES, 'UTF-8') ?></p>
        </article>
        <article class="feature-card">
          <strong>Volumen</strong>
          <p><?= htmlspecialchars((string) ($selectedTest['volume_label'] ?: 'Sin detalle'), ENT_QUOTES, 'UTF-8') ?></p>
        </article>
        <article class="feature-card">
          <strong>Ambiente</strong>
          <p><?= htmlspecialchars((string) ($selectedTest['environment_label'] ?: 'Sin detalle'), ENT_QUOTES, 'UTF-8') ?></p>
        </article>
      </div>

      <?php if ((string) ($selectedTest['calibration_note'] ?? '') !== ''): ?>
        <div class="helper">
          <strong>Nota</strong>
          <span><?= htmlspecialchars((string) $selectedTest['calibration_note'], ENT_QUOTES, 'UTF-8') ?></span>
        </div>
      <?php endif; ?>

      <div class="audiometer-chart-wrap">
        <svg id="am-report-chart" class="audiometer-chart" viewBox="0 0 720 360" role="img" aria-label="Audiograma histórico"></svg>
      </div>
      <div class="audiometer-legend">
        <span><i class="left"></i>Izquierdo</span>
        <span><i class="right"></i>Derecho</span>
      </div>

      <div class="table-wrap audiometer-report-table">
        <table class="users-table">
          <thead>
            <tr>
              <th>Frecuencia</th>
              <th>Izquierdo</th>
              <th>Derecho</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($selectedFrequencies as $frequency): ?>
              <?php
                $frequencyKey = (string) $frequency;
                $leftValue = $selectedThresholds['left'][$frequencyKey] ?? $selectedThresholds['left'][(int) $frequency] ?? null;
                $rightValue = $selectedThresholds['right'][$frequencyKey] ?? $selectedThresholds['right'][(int) $frequency] ?? null;
              ?>
              <tr>
                <td><?= htmlspecialchars((string) $frequency, ENT_QUOTES, 'UTF-8') ?> Hz</td>
                <td><?= $leftValue === null ? 'No detectado' : htmlspecialchars((string) $leftValue, ENT_QUOTES, 'UTF-8') . ' dB rel.' ?></td>
                <td><?= $rightValue === null ? 'No detectado' : htmlspecialchars((string) $rightValue, ENT_QUOTES, 'UTF-8') . ' dB rel.' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="table-actions">
        <a class="button-secondary" href="/portal/audiometer.php">Cerrar reporte</a>
      </div>
    </section>
    <script type="application/json" id="am-report-payload"><?= htmlspecialchars(json_encode($selectedPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_NOQUOTES, 'UTF-8') ?></script>
  <?php endif; ?>

  <?php if ($selectedTest === null): ?>
  <section class="audiometer-layout">
    <article class="card audiometer-console">
      <span class="section-tag">Prueba activa</span>
      <h2>Captura de umbral relativo</h2>
      <p>Empieza con volumen moderado del sistema, usa auriculares en buen estado y detén la prueba si aparece molestia. El nivel se guarda como dB relativos de salida del navegador.</p>
      <div class="coin-balance-strip">
        <strong><?= (int) $audiometerCoins ?></strong>
        <span>coins disponibles para Audiometer</span>
      </div>

      <div class="audiometer-stage">
        <div class="audiometer-readout">
          <span id="am-ear">Oído izquierdo</span>
          <strong id="am-frequency">250 Hz</strong>
          <small id="am-progress">1 de 12 puntos</small>
        </div>
        <div class="audiometer-level">
          <label for="am-level">Nivel relativo</label>
          <input id="am-level" type="range" min="5" max="75" step="5" value="35">
          <output id="am-level-output" for="am-level">35 dB</output>
        </div>
      </div>

      <div class="audiometer-actions">
        <div class="audiometer-action-row">
          <button class="button audiometer-start-button" id="am-start" type="button" <?= $audiometerCoins > 0 ? '' : 'disabled' ?>>Iniciar prueba</button>
          <button class="button-secondary" id="am-softer" type="button">Más bajo</button>
          <button class="button-secondary" id="am-louder" type="button">Más alto</button>
        </div>
        <div class="audiometer-action-row">
          <button class="button-secondary" id="am-play" type="button">Reproducir tono</button>
          <button class="button" id="am-heard" type="button">Lo escucho</button>
          <button class="button-secondary" id="am-skip" type="button">No lo escucho</button>
          <button class="button-danger" id="am-stop" type="button">Parar y guardar</button>
        </div>
      </div>
      <p class="audiometer-status" id="am-audio-status"><?= $audiometerCoins > 0 ? 'Audio listo. Pulsa reproducir para activar el motor del navegador.' : 'No tienes coins disponibles para Audiometer. Solicita una recarga al superadmin.' ?></p>

      <div class="audiometer-grid" id="am-grid" aria-live="polite"></div>
    </article>

    <aside class="card audiometer-results">
      <span class="section-tag">Audiograma relativo</span>
      <h2>Curva capturada</h2>
      <div class="audiometer-chart-wrap">
        <svg id="am-chart" class="audiometer-chart" viewBox="0 0 720 360" role="img" aria-label="Audiograma relativo"></svg>
      </div>
      <div class="audiometer-legend">
        <span><i class="left"></i>Izquierdo</span>
        <span><i class="right"></i>Derecho</span>
      </div>
    </aside>
  </section>

  <section class="panel-grid">
    <article class="card">
      <span class="section-tag">Guardar</span>
      <h2>Contexto del screening</h2>
      <form method="post" action="/portal/audiometer.php" class="form-block" id="am-save-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="create_test">
        <input type="hidden" name="result_payload_json" id="am-payload">
        <div class="form-grid two">
          <div>
            <label for="test_title">Título</label>
            <input id="test_title" name="test_title" type="text" maxlength="190" placeholder="Control mensual">
          </div>
          <div>
            <label for="volume_label">Volumen usado</label>
            <input id="volume_label" name="volume_label" type="text" maxlength="120" placeholder="Sistema al 45%, navegador al 100%">
          </div>
        </div>
        <div class="form-grid two">
          <div>
            <label for="device_label">Dispositivo</label>
            <input id="device_label" name="device_label" type="text" maxlength="190" placeholder="Laptop / navegador">
          </div>
          <div>
            <label for="headphone_label">Auriculares</label>
            <input id="headphone_label" name="headphone_label" type="text" maxlength="190" placeholder="Marca y modelo si se conoce">
          </div>
        </div>
        <div>
          <label for="environment_label">Ambiente</label>
          <input id="environment_label" name="environment_label" type="text" maxlength="190" placeholder="Habitación silenciosa, sin conversación cercana">
        </div>
        <div>
          <label for="calibration_note">Nota de calibración</label>
          <textarea id="calibration_note" name="calibration_note" rows="3" placeholder="Ejemplo: prueba no calibrada, auriculares estándar, resultado orientativo."></textarea>
        </div>
        <button class="button" id="am-save" type="submit" disabled>Guardar screening</button>
      </form>
      <div class="helper">
        <strong>Consumo</strong>
        <span>Cada screening guardado consume 1 coin de Audiometer.</span>
      </div>
    </article>

    <article class="card">
      <span class="section-tag">Criterio</span>
      <h2>Lectura responsable</h2>
      <ul class="service-list">
        <li>Los niveles son relativos al navegador, no dB HL clínicos.</li>
        <li>Repite siempre con el mismo equipo si quieres comparar evolución.</li>
        <li>Ante pérdida súbita, dolor, vértigo o acúfenos intensos, deriva a evaluación profesional.</li>
      </ul>
    </article>
  </section>

  <section class="card">
    <span class="section-tag">Historial</span>
    <h2>Tus screenings guardados</h2>
    <?php if ($tests === []): ?>
      <p class="muted">Todavía no hay registros guardados para tu cuenta.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="users-table">
          <thead>
            <tr>
              <th>Fecha</th>
              <th>Título</th>
              <th>Equipo</th>
              <th>Resumen</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($tests as $test): ?>
              <?php $payload = audiometer_payload_from_row($test); ?>
	              <tr>
	                <td><?= htmlspecialchars((string) $test['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
	                <td><?= htmlspecialchars((string) ($test['test_title'] ?: 'Screening auditivo'), ENT_QUOTES, 'UTF-8') ?></td>
	                <td><?= htmlspecialchars(trim((string) $test['headphone_label'] . ' ' . (string) $test['volume_label']) ?: 'Sin detalle', ENT_QUOTES, 'UTF-8') ?></td>
	                <td>
                    <?= htmlspecialchars(audiometer_threshold_summary($payload), ENT_QUOTES, 'UTF-8') ?>
                    <div class="table-actions">
                      <a class="button-secondary" href="/portal/audiometer.php?test_id=<?= (int) $test['id'] ?>#screening-report">Ver reporte</a>
                    </div>
                  </td>
	              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <?php if ($adminTests !== []): ?>
    <section class="card">
      <span class="section-tag">Administración</span>
      <h2>Últimos screenings del producto</h2>
      <div class="table-wrap">
        <table class="users-table">
          <thead>
            <tr>
              <th>Fecha</th>
              <th>Usuario</th>
              <th>Título</th>
              <th>Resumen</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($adminTests as $test): ?>
              <?php $payload = audiometer_payload_from_row($test); ?>
	              <tr>
	                <td><?= htmlspecialchars((string) $test['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
	                <td><?= htmlspecialchars(trim((string) $test['first_name'] . ' ' . (string) $test['last_name']) . ' · ' . (string) $test['email'], ENT_QUOTES, 'UTF-8') ?></td>
	                <td><?= htmlspecialchars((string) ($test['test_title'] ?: 'Screening auditivo'), ENT_QUOTES, 'UTF-8') ?></td>
	                <td>
                    <?= htmlspecialchars(audiometer_threshold_summary($payload), ENT_QUOTES, 'UTF-8') ?>
                    <div class="table-actions">
                      <a class="button-secondary" href="/portal/audiometer.php?test_id=<?= (int) $test['id'] ?>#screening-report">Ver reporte</a>
                      <form method="post" action="/portal/audiometer.php" class="inline-form" onsubmit="return confirm('¿Eliminar este screening?');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="delete_test">
                        <input type="hidden" name="test_id" value="<?= (int) $test['id'] ?>">
                        <button class="button-danger" type="submit">Eliminar</button>
                      </form>
                    </div>
                  </td>
	              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  <?php endif; ?>
  <?php endif; ?>
</section>

<script>
const hasAudiometerCoins = <?= $audiometerCoins > 0 ? 'true' : 'false' ?>;
const frequencies = [125, 250, 500, 750, 1000, 1500, 2000, 3000, 4000, 6000, 8000, 10000, 12000];
const ears = ["left", "right"];
const earLabels = { left: "Oído izquierdo", right: "Oído derecho" };
const thresholds = { left: {}, right: {} };
let position = 0;
let audioContext = null;
let testStarted = false;
let testStatus = "completed";
let activeOscillator = null;

const levelInput = document.getElementById("am-level");
const levelOutput = document.getElementById("am-level-output");
const earNode = document.getElementById("am-ear");
const frequencyNode = document.getElementById("am-frequency");
const progressNode = document.getElementById("am-progress");
const gridNode = document.getElementById("am-grid");
const chartNode = document.getElementById("am-chart");
const payloadNode = document.getElementById("am-payload");
const saveButton = document.getElementById("am-save");
const audioStatusNode = document.getElementById("am-audio-status");
const startButton = document.getElementById("am-start");
const playButton = document.getElementById("am-play");
const heardButton = document.getElementById("am-heard");
const skipButton = document.getElementById("am-skip");
const softerButton = document.getElementById("am-softer");
const louderButton = document.getElementById("am-louder");
const stopButton = document.getElementById("am-stop");

function currentPoint() {
  const ear = ears[Math.floor(position / frequencies.length)] || "right";
  const frequency = frequencies[position % frequencies.length] || frequencies[frequencies.length - 1];
  return { ear, frequency };
}

function relativeGain(level) {
  const normalized = Math.max(0, Math.min(1, Number(level) / 75));
  return 0.015 + normalized * 0.28;
}

function ensureAudioContext() {
  const AudioContextClass = window.AudioContext || window.webkitAudioContext;
  if (!AudioContextClass) {
    throw new Error("Este navegador no expone Web Audio API.");
  }

  if (!audioContext) {
    audioContext = new AudioContextClass();
  }
  return audioContext;
}

function setAudioStatus(text, isError = false) {
  if (!audioStatusNode) {
    return;
  }

  audioStatusNode.textContent = text;
  audioStatusNode.classList.toggle("is-error", isError);
}

async function playTone() {
  if (!audioStatusNode) {
    return;
  }

  if (!testStarted) {
    setAudioStatus("Pulsa iniciar prueba para activar el audio antes de reproducir tonos.", true);
    return;
  }

  try {
    const context = ensureAudioContext();
    if (context.state === "suspended") {
      await context.resume();
    }

    const { ear, frequency } = currentPoint();
    const oscillator = context.createOscillator();
    const gain = context.createGain();
    const panner = context.createStereoPanner ? context.createStereoPanner() : null;
    const now = context.currentTime + 0.02;
    const level = Number(levelInput.value);
    const targetGain = relativeGain(level);

    oscillator.type = "sine";
    oscillator.frequency.setValueAtTime(frequency, now);
    gain.gain.setValueAtTime(0.0001, now);
    gain.gain.linearRampToValueAtTime(targetGain, now + 0.06);
    gain.gain.setValueAtTime(targetGain, now + 0.6);
    gain.gain.linearRampToValueAtTime(0.0001, now + 0.78);

    oscillator.connect(gain);
    if (panner) {
      panner.pan.setValueAtTime(ear === "left" ? -1 : 1, now);
      gain.connect(panner);
      panner.connect(context.destination);
    } else {
      gain.connect(context.destination);
    }

    oscillator.start(now);
    oscillator.stop(now + 0.82);
    activeOscillator = oscillator;
    oscillator.addEventListener("ended", () => {
      if (activeOscillator === oscillator) {
        activeOscillator = null;
      }
    });
    setAudioStatus(`Reproduciendo ${frequency} Hz en ${earLabels[ear].toLowerCase()} a ${level} dB relativos.`);
  } catch (error) {
    setAudioStatus(error.message || "El navegador bloqueó la reproducción de audio.", true);
  }
}

async function startTest() {
  if (!startButton) {
    return;
  }

  if (!hasAudiometerCoins) {
    setAudioStatus("No tienes coins disponibles para iniciar una prueba.", true);
    return;
  }

  try {
    const context = ensureAudioContext();
    if (context.state === "suspended") {
      await context.resume();
    }
    testStarted = true;
    testStatus = "completed";
    startButton.disabled = true;
    startButton.textContent = "Prueba iniciada";
    setControlsEnabled(true);
    setAudioStatus("Bloque activo. Reproduce el tono y registra si lo escuchas.");
    await playTone();
  } catch (error) {
    setAudioStatus(error.message || "No fue posible iniciar el audio del navegador.", true);
  }
}

function setControlsEnabled(enabled) {
  if (!playButton) {
    return;
  }

  playButton.disabled = !enabled;
  heardButton.disabled = !enabled;
  skipButton.disabled = !enabled;
  softerButton.disabled = !enabled;
  louderButton.disabled = !enabled;
  stopButton.disabled = !enabled;
  levelInput.disabled = !enabled;
}

function setLevel(value) {
  levelInput.value = String(Math.max(5, Math.min(75, value)));
  levelOutput.textContent = `${levelInput.value} dB`;
}

function advance() {
  position = Math.min(position + 1, ears.length * frequencies.length - 1);
  updateView();
}

function markHeard() {
  const { ear, frequency } = currentPoint();
  thresholds[ear][frequency] = Number(levelInput.value);
  const nextPosition = position + 1;
  if (position < ears.length * frequencies.length - 1) {
    position = nextPosition;
  }
  updateView();
}

function markUnheard() {
  const { ear, frequency } = currentPoint();
  thresholds[ear][frequency] = null;
  const nextPosition = position + 1;
  if (position < ears.length * frequencies.length - 1) {
    position = nextPosition;
  }
  updateView();
}

function isComplete() {
  return ears.every((ear) => frequencies.every((frequency) => Object.prototype.hasOwnProperty.call(thresholds[ear], frequency)));
}

function answeredCount() {
  return ears.reduce((total, ear) => total + Object.keys(thresholds[ear]).length, 0);
}

function payload() {
  return {
    version: "audiometer_relative_v1",
    captured_at: new Date().toISOString(),
    status: testStatus,
    disclaimer: "Screening orientativo no calibrado. Los niveles son relativos al dispositivo y no equivalen a dB HL clinicos.",
    frequencies,
    ears,
    thresholds,
  };
}

function updatePayload() {
  if (!payloadNode) {
    return;
  }

  payloadNode.value = JSON.stringify(payload());
  saveButton.disabled = !hasAudiometerCoins || answeredCount() === 0 || (testStatus !== "stopped" && !isComplete());
}

function stopAndEnablePartialSave() {
  if (activeOscillator) {
    try {
      activeOscillator.stop();
    } catch (_error) {
      // The oscillator may already have ended.
    }
    activeOscillator = null;
  }

  if (answeredCount() === 0) {
    setAudioStatus("Registra al menos una respuesta antes de parar y guardar.", true);
    return;
  }

  testStarted = false;
  testStatus = "stopped";
  setControlsEnabled(false);
  startButton.disabled = true;
  saveButton.disabled = !hasAudiometerCoins;
  setAudioStatus("Prueba detenida. Puedes guardar el avance registrado hasta este punto.");
  updatePayload();
}

function renderGrid() {
  if (!gridNode) {
    return;
  }

  gridNode.replaceChildren();
  ears.forEach((ear) => {
    frequencies.forEach((frequency) => {
      const cell = document.createElement("button");
      const value = thresholds[ear][frequency];
      const index = ears.indexOf(ear) * frequencies.length + frequencies.indexOf(frequency);
      cell.type = "button";
      cell.className = `audiometer-cell${index === position ? " is-active" : ""}${Object.prototype.hasOwnProperty.call(thresholds[ear], frequency) ? " is-done" : ""}`;
      cell.innerHTML = `<strong>${frequency} Hz</strong><span>${ear === "left" ? "Izq." : "Der."}</span><small>${value === undefined ? "Pendiente" : value === null ? "No detectado" : `${value} dB`}</small>`;
      cell.addEventListener("click", () => {
        position = index;
        if (typeof value === "number") {
          setLevel(value);
        }
        updateView();
      });
      gridNode.append(cell);
    });
  });
}

function chartPoint(index, value, chartFrequencies = frequencies) {
  const left = 62;
  const top = 34;
  const width = 610;
  const height = 260;
  const denominator = Math.max(1, chartFrequencies.length - 1);
  const x = left + (width / denominator) * index;
  const y = top + (height * (Math.max(0, Math.min(80, value))) / 80);
  return [x, y];
}

function thresholdValue(rows, frequency) {
  if (!rows || typeof rows !== "object") {
    return undefined;
  }

  return rows[frequency] ?? rows[String(frequency)];
}

function renderAudiometerChart(targetNode, chartFrequencies, chartThresholds) {
  const lines = [];
  const grid = [0, 20, 40, 60, 80].map((level) => {
    const y = chartPoint(0, level, chartFrequencies)[1];
    return `<line x1="62" y1="${y}" x2="672" y2="${y}" /><text x="16" y="${y + 5}">${level}</text>`;
  }).join("");
  const labels = chartFrequencies.map((frequency, index) => {
    const x = chartPoint(index, 80, chartFrequencies)[0];
    return `<text class="freq-label" x="${x}" y="334">${frequency}</text>`;
  }).join("");

  ears.forEach((ear) => {
    const points = chartFrequencies
      .map((frequency, index) => {
        const value = thresholdValue(chartThresholds[ear], frequency);
        return typeof value === "number" ? chartPoint(index, value, chartFrequencies) : null;
      })
      .filter(Boolean);
    if (points.length > 1) {
      lines.push(`<polyline class="${ear}" points="${points.map((point) => point.join(",")).join(" ")}" />`);
    }
    points.forEach((point) => {
      lines.push(`<circle class="${ear}" cx="${point[0]}" cy="${point[1]}" r="6" />`);
    });
  });

  targetNode.innerHTML = `
    <g class="chart-grid">${grid}</g>
    <line class="chart-axis" x1="62" y1="34" x2="62" y2="294" />
    <line class="chart-axis" x1="62" y1="294" x2="672" y2="294" />
    <text class="axis-label" x="16" y="24">dB rel.</text>
    <text class="axis-label" x="620" y="354">Hz</text>
    ${labels}
    ${lines.join("")}
  `;
}

function renderChart() {
  if (!chartNode) {
    return;
  }

  renderAudiometerChart(chartNode, frequencies, thresholds);
}

function renderSavedReport() {
  const reportPayloadNode = document.getElementById("am-report-payload");
  const reportChartNode = document.getElementById("am-report-chart");
  if (!reportPayloadNode || !reportChartNode) {
    return;
  }

  try {
    const reportPayload = JSON.parse(reportPayloadNode.textContent || "{}");
    const reportFrequencies = Array.isArray(reportPayload.frequencies) ? reportPayload.frequencies : [];
    const reportThresholds = reportPayload.thresholds && typeof reportPayload.thresholds === "object"
      ? reportPayload.thresholds
      : { left: {}, right: {} };
    renderAudiometerChart(reportChartNode, reportFrequencies, reportThresholds);
  } catch (_error) {
    reportChartNode.innerHTML = "";
  }
}

function updateView() {
  if (!earNode) {
    return;
  }

  const { ear, frequency } = currentPoint();
  earNode.textContent = earLabels[ear];
  frequencyNode.textContent = `${frequency} Hz`;
  progressNode.textContent = `${position + 1} de ${ears.length * frequencies.length} puntos`;
  levelOutput.textContent = `${levelInput.value} dB`;
  renderGrid();
  renderChart();
  updatePayload();
}

if (startButton) {
  startButton.addEventListener("click", startTest);
  playButton.addEventListener("click", playTone);
  heardButton.addEventListener("click", markHeard);
  skipButton.addEventListener("click", markUnheard);
  softerButton.addEventListener("click", () => setLevel(Number(levelInput.value) - 5));
  louderButton.addEventListener("click", () => setLevel(Number(levelInput.value) + 5));
  stopButton.addEventListener("click", stopAndEnablePartialSave);
  levelInput.addEventListener("input", updateView);
  setControlsEnabled(false);
  if (!hasAudiometerCoins) {
    startButton.disabled = true;
  }
  updateView();
}
renderSavedReport();
</script>
<?php render_app_footer(); ?>
