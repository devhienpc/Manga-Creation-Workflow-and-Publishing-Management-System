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

<!-- Inject BASE_URL for JS -->
<script>
    const BASE_URL = <?= json_encode(BASE_URL) ?>;
</script>

<!-- Global JS -->
<script src="<?= BASE_URL ?>assets/js/main.js"></script>

</body>
</html>
