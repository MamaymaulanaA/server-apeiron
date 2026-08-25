    </div> <!-- end container -->
    
    <footer class="footer">
        <div class="footer-content">
            <p style="display: flex; align-items: center; justify-content: center; gap: 8px;">
                <i class="fas fa-bolt" style="color: var(--accent);"></i>
                <span><?= APP_NAME ?></span>
                <span style="opacity: 0.4;">•</span>
                <span style="opacity: 0.6;">v<?= APP_VERSION ?></span>
                <span style="opacity: 0.4;">•</span>
                <span>&copy; <?= date('Y') ?></span>
            </p>
        </div>
    </footer>
    
    <?php
    $is_admin = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') !== false;
    $base_path = $is_admin ? '../' : '';
    ?>
    <script src="<?= $base_path ?>assets/js/main.js"></script>
</body>
</html>