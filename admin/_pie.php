</main>

<div class="toast-container" id="toastContainer" aria-live="polite"></div>
<script src="<?= e(url_recurso('assets/js/admin.js')) ?>" defer></script>
<?php foreach ((array)($jsPanel ?? []) as $scriptPanel): ?>
<script src="<?= e(url_recurso($scriptPanel)) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
