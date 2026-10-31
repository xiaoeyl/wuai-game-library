(function () {
  'use strict';

  // Runs next to the loader markup, before videos, images or footer scripts load.
  var loader = document.querySelector('.sluyu-page-loader');
  if (!loader) return;
  var closed = false;
  var deadline;
  var dismiss = function () {
    if (closed) return;
    closed = true;
    window.clearTimeout(deadline);
    document.removeEventListener('DOMContentLoaded', dismiss);
    window.removeEventListener('load', dismiss);
    window.removeEventListener('pageshow', dismiss);
    window.removeEventListener('pagehide', dismiss);
    loader.classList.add('is-hidden');
    window.setTimeout(function () {
      if (loader.parentNode) loader.parentNode.removeChild(loader);
    }, 220);
  };

  // A blocked third-party script must not leave a full-screen loader behind.
  deadline = window.setTimeout(dismiss, 2000);
  if (document.body.getAttribute('data-sluyu-loader') !== '1' || document.readyState !== 'loading') {
    dismiss();
  } else {
    document.addEventListener('DOMContentLoaded', dismiss);
    window.addEventListener('load', dismiss);
    window.addEventListener('pageshow', dismiss);
    window.addEventListener('pagehide', dismiss);
  }
})();
