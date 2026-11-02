<?php
// Shared "services offered" checkboxes (registration + provider profile).
// Expects $selectedCats (array of category keys) and $errors.
$selectedCats = $selectedCats ?? [];
$hasTutor = (bool)array_intersect($selectedCats, ['tutor_high_school', 'tutor_tertiary']);
?>
<fieldset>
  <legend>Services you offer</legend>
  <?php foreach (['gardener' => 'Gardener', 'plumber' => 'Plumber', 'cleaner' => 'Cleaner'] as $k => $label): ?>
    <label class="check"><input type="checkbox" name="services[]" value="<?= $k ?>" <?= in_array($k, $selectedCats, true) ? 'checked' : '' ?>> <?= $label ?></label>
  <?php endforeach; ?>
  <label class="check"><input type="checkbox" name="services[]" value="tutor" id="tutor-box" <?= $hasTutor ? 'checked' : '' ?>> Tutor</label>
  <div id="tutor-levels" class="indent" <?= $hasTutor ? '' : 'hidden' ?>>
    <p class="hint">I tutor:</p>
    <label class="check"><input type="checkbox" name="tutor_levels[]" value="tutor_high_school" <?= in_array('tutor_high_school', $selectedCats, true) ? 'checked' : '' ?>> High school subjects</label>
    <label class="check"><input type="checkbox" name="tutor_levels[]" value="tutor_tertiary" <?= in_array('tutor_tertiary', $selectedCats, true) ? 'checked' : '' ?>> Tertiary (university / college) subjects</label>
  </div>
  <?= field_error($errors, 'services') ?>
</fieldset>