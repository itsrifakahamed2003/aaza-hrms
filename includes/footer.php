        </main>

        <!-- App Footer -->
        <footer class="app-footer">
            <div class="d-flex align-items-center gap-2">
                <span class="fw-semibold text-primary"><?= e(APP_NAME) ?></span>
                <span>&bull;</span>
                <span>Enterprise HR Management Platform</span>
            </div>
            <div>
                &copy; <?= date('Y') ?> AaZa Group. All rights reserved.
            </div>
        </footer>
    </div>
</div>

<!-- Global Confirmation Modal (replaces browser confirm()) -->
<div class="modal fade" id="globalConfirmModal" tabindex="-1" aria-labelledby="globalConfirmTitle" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 440px;">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden global-confirm-card">
            <div class="modal-body p-4 text-center position-relative">
                <button type="button" class="btn-close position-absolute top-0 end-0 m-3" data-bs-dismiss="modal" aria-label="Close"></button>
                
                <!-- Icon badge -->
                <div class="confirm-icon-wrapper mb-3 mx-auto variant-danger" id="globalConfirmIconWrapper">
                    <i class="bi bi-exclamation-triangle-fill fs-2" id="globalConfirmIcon"></i>
                </div>
                
                <!-- Title -->
                <h5 class="fw-bold mb-2 text-body fs-5" id="globalConfirmTitle">
                    Confirm Action
                </h5>
                
                <!-- Message -->
                <p class="text-body-secondary mb-4 px-2" id="globalConfirmMessage" style="font-size: 0.935rem; line-height: 1.55;">
                    Are you sure you want to proceed with this action?
                </p>
                
                <!-- Action Buttons -->
                <div class="d-flex align-items-center justify-content-center gap-2 pt-1">
                    <button type="button" class="btn btn-outline-secondary px-4 py-2 fw-medium rounded-3 flex-fill" data-bs-dismiss="modal" id="globalConfirmCancelBtn">
                        Cancel
                    </button>
                    <button type="button" class="btn btn-danger px-4 py-2 fw-semibold rounded-3 flex-fill d-inline-flex align-items-center justify-content-center gap-2" id="globalConfirmBtn">
                        <span id="globalConfirmBtnText">Confirm</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="<?= e(url('assets/vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<?php
$appJsFile = ROOT_PATH . '/assets/js/app.js';
$appJsVer = file_exists($appJsFile) ? (string)filemtime($appJsFile) : '1';
?>
<script src="<?= e(url('assets/js/app.js')) ?>?v=<?= urlencode($appJsVer) ?>"></script>
</body>
</html>
