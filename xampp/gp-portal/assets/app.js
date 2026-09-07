// Confirm destructive actions before submitting (small progressive enhancement).
document.addEventListener('submit', function (ev) {
  var f = ev.target;
  if (f.getAttribute('data-confirm')) {
    if (!window.confirm(f.getAttribute('data-confirm'))) {
      ev.preventDefault();
    }
  }
});
