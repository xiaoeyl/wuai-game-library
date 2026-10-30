(function () {
  var button = document.querySelector('.sluyu-menu-button');
  var nav = document.querySelector('.sluyu-nav');
  if (!button || !nav) return;
  button.addEventListener('click', function () {
    var opened = nav.classList.toggle('is-open');
    button.setAttribute('aria-expanded', opened ? 'true' : 'false');
  });
})();
