    </div> <!-- /.container -->
    <?php $isEmbed = isset($_GET['embed']) && $_GET['embed'] == '1'; ?>
    <?php if (!$isEmbed): ?>
    <footer class="bg-light text-muted py-4 mt-4">
      <div class="container text-center">
        <small>© <?php echo date('Y'); ?> Kết nối Y tế — Một dự án ví dụ</small>
      </div>
    </footer>
    <?php endif; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
      // Ensure page shows server-rendered state when user navigates back/forward
      // Some browsers restore a cached DOM (bfcache) which can keep JS-modified markup.
      // Use the Navigation Timing API to detect back/forward navigation more reliably
      window.addEventListener('pageshow', function(event){
        var navEntries = (performance.getEntriesByType ? performance.getEntriesByType('navigation') : []) || [];
        var navType = null;
        if (navEntries && navEntries[0] && navEntries[0].type) navType = navEntries[0].type;
        // older browsers may expose performance.navigation.type === 2 for back_forward
        if (event.persisted || navType === 'back_forward' || (performance.navigation && performance.navigation.type === 2)) {
          try { window.location.reload(); } catch (e) { /* ignore */ }
        }
      });
    </script>
</body>
</html>
