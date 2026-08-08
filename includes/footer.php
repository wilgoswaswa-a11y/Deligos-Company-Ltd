</div> <!-- app-shell -->
    </div> <!-- main-content -->
</div> <!-- app-layout -->
<script src="<?= asset_url('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= asset_url('assets/app.js') ?>"></script>
<script>
// Keep every Bootstrap modal above fixed navigation, off-canvas menus, and
// page content. A modal placed inside a grid can otherwise inherit a stacking
// context and appear behind its own backdrop or the navigation.
document.addEventListener('show.bs.modal', function (event) {
    const modal = event.target;
    if (modal instanceof HTMLElement && modal.parentElement !== document.body) {
        document.body.appendChild(modal);
    }
});
</script>
<footer id="site-footer" class="text-start text-white py-2 px-3" style="font-size:0.9rem; position:fixed; left:0; bottom:0; width:100%; background:linear-gradient(90deg, #2c3e50, #34495e); z-index:1030; border-top:3px solid #f39c12; box-shadow:0 -2px 8px rgba(0,0,0,0.12);">
    &copy; <?php echo date('Y'); ?> Deligos. All rights reserved.
</footer>
<script>
// Ensure the page content isn't hidden behind the fixed footer on small screens
(function(){
    function updateFooterPadding(){
        var f = document.getElementById('site-footer');
        if(!f) return;
        document.body.style.paddingBottom = f.offsetHeight + 'px';
    }
    window.addEventListener('resize', updateFooterPadding);
    window.addEventListener('orientationchange', updateFooterPadding);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', updateFooterPadding);
    } else {
        updateFooterPadding();
    }
})();
</script>
</body>
</html>
