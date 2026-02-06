<?php
$user = currentUser();
?>
<?php if ($user): ?>
        </div><!-- .content-body -->
    </main><!-- .main-content -->
</div><!-- .app-wrapper -->
<?php else: ?>
</div><!-- .guest-wrapper -->
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= url('/assets/js/app.js') ?>"></script>
<script>
function toggleSidebar() {
    document.querySelector('.sidebar').classList.toggle('show');
}
// モバイルでサイドバー外クリックで閉じる
document.addEventListener('click', function(e) {
    const sidebar = document.querySelector('.sidebar');
    const toggle = document.querySelector('.sidebar-toggle');
    if (sidebar && sidebar.classList.contains('show') &&
        !sidebar.contains(e.target) && !toggle.contains(e.target)) {
        sidebar.classList.remove('show');
    }
});
</script>
</body>
</html>
