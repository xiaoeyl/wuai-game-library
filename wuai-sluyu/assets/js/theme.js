(function () {
  'use strict';

  var body = document.body;
  var button = document.querySelector('.sluyu-menu-button');
  var nav = document.querySelector('.sluyu-nav');
  if (button && nav) {
    button.addEventListener('click', function () {
      var opened = nav.classList.toggle('is-open');
      button.setAttribute('aria-expanded', opened ? 'true' : 'false');
    });
  }

  var particles = document.querySelector('.sluyu-particles');
  if (particles && body.getAttribute('data-sluyu-particles') === '1') {
    for (var i = 0; i < 18; i += 1) {
      var dot = document.createElement('i');
      dot.style.left = (Math.random() * 100).toFixed(2) + '%';
      dot.style.top = (Math.random() * 100).toFixed(2) + '%';
      dot.style.animationDelay = (-Math.random() * 12).toFixed(2) + 's';
      dot.style.animationDuration = (8 + Math.random() * 10).toFixed(2) + 's';
      dot.style.transform = 'scale(' + (0.5 + Math.random()).toFixed(2) + ')';
      particles.appendChild(dot);
    }
  }

  var loader = document.querySelector('.sluyu-page-loader');
  var hideLoader = function () { if (loader) loader.classList.add('is-hidden'); };
  if (loader && body.getAttribute('data-sluyu-loader') === '1') {
    window.addEventListener('load', hideLoader);
    window.setTimeout(hideLoader, 1800);
  } else {
    hideLoader();
  }

  var topButton = document.querySelector('.sluyu-tool-top');
  if (topButton) {
    var tools = document.querySelector('.sluyu-floating-tools');
    var updateTopButton = function () { if (tools) tools.classList.toggle('is-visible', window.scrollY > 280); };
    window.addEventListener('scroll', updateTopButton, { passive: true });
    updateTopButton();
    topButton.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: 'smooth' }); });
  }

  var themeButton = document.querySelector('.sluyu-tool-theme');
  if (themeButton) {
    var storageKey = 'wuai-sluyu-soft-dark';
    if (window.localStorage && localStorage.getItem(storageKey) === '1') body.classList.add('wuai-soft-dark');
    themeButton.addEventListener('click', function () {
      var enabled = body.classList.toggle('wuai-soft-dark');
      if (window.localStorage) localStorage.setItem(storageKey, enabled ? '1' : '0');
    });
  }

  var copyButtons = document.querySelectorAll('[data-sluyu-copy]');
  Array.prototype.forEach.call(copyButtons, function (copyButton) {
    copyButton.addEventListener('click', function () {
      var value = copyButton.getAttribute('data-sluyu-copy') || '';
      var success = copyButton.getAttribute('data-copy-success') || '已复制';
      var done = function () {
        var oldText = copyButton.textContent;
        copyButton.textContent = success;
        window.setTimeout(function () { copyButton.textContent = oldText; }, 1400);
      };
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(value).then(done);
      } else {
        var input = document.createElement('textarea');
        input.value = value;
        input.style.position = 'fixed'; input.style.opacity = '0';
        document.body.appendChild(input); input.select();
        try { document.execCommand('copy'); done(); } catch (e) { /* old browsers */ }
        document.body.removeChild(input);
      }
    });
  });

  if (body.getAttribute('data-sluyu-lightbox') === '1') {
    var images = document.querySelectorAll('[data-sluyu-lightbox="1"]');
    var overlay = document.createElement('div');
    overlay.className = 'sluyu-lightbox';
    overlay.innerHTML = '<button type="button" aria-label="关闭预览">×</button><img alt="">';
    document.body.appendChild(overlay);
    var preview = overlay.querySelector('img');
    var close = function () { overlay.classList.remove('is-open'); overlay.classList.remove('is-visible'); };
    overlay.addEventListener('click', function (event) { if (event.target === overlay || event.target.tagName === 'BUTTON') close(); });
    Array.prototype.forEach.call(images, function (image) {
      image.style.cursor = 'zoom-in';
      image.addEventListener('click', function () {
        preview.src = image.currentSrc || image.src;
        preview.alt = image.alt || '';
        overlay.classList.add('is-open');
        overlay.classList.add('is-visible');
      });
    });
  }
})();
