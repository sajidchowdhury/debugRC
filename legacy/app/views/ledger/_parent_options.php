<?php
/** @var array<int, array<string, mixed>> $parentOptions */
/** @var int $selectedParentId */
$parentOptions = $parentOptions ?? [];
$selectedParentId = (int)($selectedParentId ?? 0);
?>
<option value="0">— None (Top Level) —</option>
<?php foreach ($parentOptions as $opt): ?>
<option value="<?= (int)$opt['id'] ?>"<?= (int)$opt['id'] === $selectedParentId ? ' selected' : '' ?>>
    <?= htmlspecialchars($opt['picker_label'] ?? ($opt['ledger_name'] ?? ''), ENT_QUOTES) ?>
</option>
<?php endforeach; ?>
