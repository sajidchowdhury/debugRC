<?php
/** @var array $employee @var bool $isEdit */
$isEdit = !empty($isEdit);
$employee = $employee ?? [];
$publicUrl = defined('PUBLIC_URL') ? rtrim(PUBLIC_URL, '/') : rtrim(BASE_URL, '/');
$photoPath = $employee['photo'] ?? '';
$photoUrl = $photoPath ? $publicUrl . '/' . ltrim($photoPath, '/') : '';
$inputId = $isEdit ? 'photoInputEdit' : 'photoInputCreate';
$previewId = $isEdit ? 'photoPreviewEdit' : 'photoPreviewCreate';
$clearId = $isEdit ? 'clearPhotoEdit' : 'clearPhotoCreate';
?>
<div class="branch-form-section">
    <div class="branch-form-section-head">
        <span class="icon-wrap slate"><i class="fas fa-camera"></i></span>
        Photo (optional)
    </div>
    <div class="d-flex align-items-start gap-3 flex-wrap">
        <div id="<?= $previewId ?>" class="employee-photo-upload-preview">
            <?php if ($isEdit && $photoUrl): ?>
                <img src="<?= htmlspecialchars($photoUrl, ENT_QUOTES) ?>" alt="Photo" style="width:100%;height:100%;object-fit:cover;">
            <?php else: ?>
                <i class="fas fa-user fa-3x text-secondary"></i>
            <?php endif; ?>
        </div>
        <div>
            <input type="file" id="<?= $inputId ?>" name="photo" accept="image/jpeg,image/png,image/gif,image/webp" class="d-none">
            <button type="button" class="btn btn-outline-primary btn-sm" onclick="document.getElementById('<?= $inputId ?>').click()">
                <i class="fas fa-upload me-1"></i> <?= ($isEdit && $photoUrl) ? 'Replace photo' : 'Choose photo' ?>
            </button>
            <button type="button" id="<?= $clearId ?>" class="btn btn-outline-secondary btn-sm ms-1" style="display:none;">
                <i class="fas fa-times me-1"></i> Clear
            </button>
            <?php if ($isEdit && $photoUrl): ?>
            <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" name="remove_photo" id="removePhoto" value="1">
                <label class="form-check-label small text-danger" for="removePhoto">Remove current photo</label>
            </div>
            <?php endif; ?>
            <div class="small text-muted mt-1">JPG, PNG, GIF, WebP · max 2MB</div>
        </div>
    </div>
</div>
<script>
(function() {
    const input = document.getElementById('<?= $inputId ?>');
    const preview = document.getElementById('<?= $previewId ?>');
    const clearBtn = document.getElementById('<?= $clearId ?>');
    const removeChk = document.getElementById('removePhoto');
    if (!input || !preview) return;
    const originalHTML = preview.innerHTML;

    input.addEventListener('change', function() {
        if (!this.files || !this.files[0]) return;
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.innerHTML = '<img src="' + e.target.result + '" style="width:100%;height:100%;object-fit:cover;" alt="Preview">';
            if (clearBtn) clearBtn.style.display = 'inline-block';
            if (removeChk) removeChk.checked = false;
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

    if (removeChk) {
        removeChk.addEventListener('change', function() {
            if (this.checked) {
                input.value = '';
                preview.style.opacity = '0.4';
                if (clearBtn) clearBtn.style.display = 'none';
            } else {
                preview.style.opacity = '1';
            }
        });
    }
})();
</script>