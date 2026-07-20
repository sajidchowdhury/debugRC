<?php
/** @var string $selectedNature */
require_once __DIR__ . '/../../models/LedgerModel.php';
$selectedNature = $selectedNature ?? '';
$groups = LedgerModel::getNatureOptionGroups();
foreach ($groups as $groupLabel => $options): ?>
<optgroup label="<?= htmlspecialchars($groupLabel, ENT_QUOTES) ?>">
    <?php foreach ($options as $value => $label): ?>
    <option value="<?= htmlspecialchars($value, ENT_QUOTES) ?>"<?= $selectedNature === $value ? ' selected' : '' ?>>
        <?= htmlspecialchars($label, ENT_QUOTES) ?>
    </option>
    <?php endforeach; ?>
</optgroup>
<?php endforeach; ?>
