<?php
/** @var string $imagePrefix @var string $existingImage @var string $publicUrl */
$imagePrefix = $imagePrefix ?? 'Create';
$existingImage = $existingImage ?? '';
$publicUrl = $publicUrl ?? (defined('PUBLIC_URL') ? PUBLIC_URL : BASE_URL);
$inputId = 'imageInput' . $imagePrefix;
$previewId = 'imagePreview' . $imagePrefix;
$clearId = 'clearImage' . $imagePrefix;
?>
<div class="d-flex align-items-start gap-3 flex-wrap">
    <div class="product-img-preview-lg" id="<?= $previewId ?>">
        <?php if ($existingImage): ?>
            <img src="<?= htmlspecialchars($publicUrl . $existingImage, ENT_QUOTES) ?>" alt="Product">
        <?php else: ?>
            <i class="fas fa-image fa-2x text-secondary"></i>
        <?php endif; ?>
    </div>
    <div>
        <input type="file" id="<?= $inputId ?>" name="image" accept="image/jpeg,image/png,image/webp" class="d-none">
        <button type="button" class="btn btn-outline-primary btn-sm"
                onclick="document.getElementById('<?= $inputId ?>').click()">
            <i class="fas fa-upload me-1"></i> <?= $existingImage ? 'Replace image' : 'Choose image' ?>
        </button>
        <button type="button" id="<?= $clearId ?>" class="btn btn-outline-secondary btn-sm ms-1" style="display:none;">
            <i class="fas fa-times me-1"></i> Clear
        </button>
        <div class="small text-muted mt-2">JPG, PNG, WebP · max 2MB</div>
    </div>
</div>
<script>
(function() {
    const input = document.getElementById('<?= $inputId ?>');
    const preview = document.getElementById('<?= $previewId ?>');
    const clearBtn = document.getElementById('<?= $clearId ?>');
    if (!input || !preview) return;
    const originalHTML = preview.innerHTML;

    input.addEventListener('change', function() {
        if (!this.files || !this.files[0]) return;
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.innerHTML = '<img src="' + e.target.result + '" alt="Preview">';
            if (clearBtn) clearBtn.style.display = 'inline-block';
        };
        reader.readAsDataURL(this.files[0]);
    });

    if (clearBtn) {
        clearBtn.addEventListener('click', function() {
            input.value = '';
            preview.innerHTML = originalHTML;
            clearBtn.style.display = 'none';
        });
    }
})();
</script>