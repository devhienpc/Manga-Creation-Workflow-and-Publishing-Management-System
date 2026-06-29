<?php
/**
 * includes/footer.php
 * Đóng các div: .page-content, .main-area, .app-shell
 * Chèn main.js với BASE_URL
 */
?>
        </div><!-- /.page-content -->
    </div><!-- /.main-area -->
</div><!-- /.app-shell -->

<!-- JSZip Library for Bulk Download -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>

<!-- Inject BASE_URL for JS -->
<script>
    const BASE_URL = <?= json_encode(BASE_URL) ?>;
</script>

<!-- Global JS -->
<script src="<?= BASE_URL ?>assets/js/main.js?v=<?= filemtime(__DIR__ . '/../assets/js/main.js') ?>"></script>

</body>
</html>

