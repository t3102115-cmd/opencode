(function () {
  document.querySelectorAll('[data-action]').forEach(function (button) {
    button.addEventListener('click', function () {
      const action = button.getAttribute('data-action');
      const id = button.getAttribute('data-id');
      if (action === 'delete' && !confirm('Delete this session and its workspace? This cannot be undone.')) {
        return;
      }
      fetch('index.php?page=api&action=' + action, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'id=' + encodeURIComponent(id),
      }).then(() => window.location.reload());
    });
  });
})();
