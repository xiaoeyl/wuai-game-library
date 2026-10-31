(function () {
  'use strict';
  var dialog = document.querySelector('.sluyu-dialog');
  var previousFocus = null;
  var previousOverflow = '';
  var toastTimer;
  var titles = {search:'搜索', account:'个人中心', vip:'会员中心', cart:'购物车', coupon:'优惠券', checkin:'今日签到', message:'私信列表', qrcode:'二维码', support:'联系客服', about:'关于本站', terms:'用户说明', disclaimer:'免责声明', circle:'次元圈子', verify:'认证服务', help:'帮助文档', share:'分享文章'};
  function toast(message) {
    var box = document.querySelector('.sluyu-toast');
    if (!box) return;
    box.textContent = message;
    box.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { box.hidden = true; }, 2600);
  }
  function closeDialog() {
    if (!dialog || dialog.hidden) return;
    dialog.hidden = true;
    document.body.style.overflow = previousOverflow;
    if (previousFocus) previousFocus.focus();
  }
  function openDialog(name) {
    var template = document.getElementById('sluyu-panel-' + name);
    if (!dialog || !template) return;
    if (dialog.hidden) {
      previousFocus = document.activeElement;
      previousOverflow = document.body.style.overflow;
    }
    dialog.querySelector('h2').textContent = titles[name] || '提示';
    var content = dialog.querySelector('.sluyu-dialog-body');
    content.replaceChildren(template.content.cloneNode(true));
    dialog.hidden = false;
    document.body.style.overflow = 'hidden';
    content.querySelectorAll('[data-sluyu-qr]').forEach(function (canvas) {
      if (window.QRious) new QRious({element:canvas, size:320, value:location.href.split('#')[0], level:'M'});
    });
    var focus = content.querySelector('input,button,a') || dialog.querySelector('[data-sluyu-close]');
    focus.focus();
  }
  function api(operation, extras) {
    var fields = new URLSearchParams({action:'wuai_sluyu_frontend', nonce:window.wuaiSluyu.nonce, operation:operation});
    Object.keys(extras || {}).forEach(function (key) { fields.append(key, extras[key]); });
    return fetch(window.wuaiSluyu.ajaxUrl, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:fields.toString()})
      .then(function (response) { return response.json(); })
      .then(function (result) {
        if (!result.success) {
          if (result.data && result.data.login) openDialog('account');
          throw new Error(result.data && result.data.message || '操作失败，请稍后重试。');
        }
        return result.data;
      });
  }
  document.addEventListener('click', function (event) {
    var panel = event.target.closest('[data-sluyu-panel]');
    if (panel) { event.preventDefault(); openDialog(panel.dataset.sluyuPanel); return; }
    if (event.target.closest('[data-sluyu-close]') || event.target === dialog) { closeDialog(); return; }
    if (event.target.closest('[data-sluyu-top]')) {
      window.scrollTo({top:0, behavior:window.matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth'});
    }
    var checkin = event.target.closest('[data-sluyu-checkin]');
    if (checkin && !checkin.disabled) {
      checkin.disabled = true;
      api('checkin').then(function (data) {
        checkin.textContent = '今日已签到';
        document.querySelectorAll('[data-checkin-days]').forEach(function (element) { element.textContent = data.days; });
        toast(data.message);
      }).catch(function (error) { toast(error.message); }).finally(function () { checkin.disabled = false; });
    }
    var like = event.target.closest('[data-sluyu-like]');
    if (like && !like.disabled) {
      like.disabled = true;
      api('like', {post_id:like.dataset.sluyuLike}).then(function (data) {
        document.querySelectorAll('[data-sluyu-like]').forEach(function (button) { button.setAttribute('aria-pressed', data.liked?'true':'false'); });
        document.querySelectorAll('[data-like-count]').forEach(function (element) { element.textContent = data.count; });
        toast(data.message);
      }).catch(function (error) { toast(error.message); }).finally(function () { like.disabled = false; });
    }
    var bookmark = event.target.closest('[data-sluyu-bookmark]');
    if (bookmark) {
      if (window.wuaiSluyu && window.wuaiSluyu.loggedIn) {
        if (bookmark.disabled) return;
        bookmark.disabled = true;
        var fields = new URLSearchParams({action:'wuai_sluyu_community', nonce:window.wuaiSluyu.nonce, operation:'bookmark', id:bookmark.dataset.sluyuBookmark});
        fetch(window.wuaiSluyu.ajaxUrl, {method:'POST', credentials:'same-origin', body:fields}).then(function (response) {return response.json();}).then(function (result) {
          if (!result.success) throw new Error(result.data && result.data.message || '收藏失败');
          document.querySelectorAll('[data-sluyu-bookmark="'+bookmark.dataset.sluyuBookmark+'"]').forEach(function (button) {button.setAttribute('aria-pressed', String(result.data.selected));});
          toast(result.data.selected?'已收藏到你的账号':'已取消收藏');
        }).catch(function (error) {toast(error.message);}).finally(function () {bookmark.disabled=false;});
        return;
      }
      try {
        var key = 'sluyu-bookmark-' + bookmark.dataset.sluyuBookmark;
        var selected = localStorage.getItem(key) !== '1';
        if (selected) localStorage.setItem(key,'1'); else localStorage.removeItem(key);
        document.querySelectorAll('[data-sluyu-bookmark]').forEach(function (button) {button.setAttribute('aria-pressed',selected?'true':'false');});
        toast(selected?'已收藏到当前浏览器':'已取消收藏');
      } catch (error) { toast('浏览器无法保存收藏，请使用浏览器书签。'); }
    }
    if (event.target.closest('[data-sluyu-copy-page]')) {
      if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(location.href.split('#')[0]).then(function () {toast('文章链接已复制');}).catch(function () {toast('复制失败，请复制浏览器地址栏中的链接。');});
      else toast('请复制浏览器地址栏中的链接。');
    }
  });
  document.addEventListener('keydown', function (event) {
    if (!dialog || dialog.hidden) return;
    if (event.key === 'Escape') closeDialog();
    if (event.key === 'Tab') {
      var focusable = Array.from(dialog.querySelectorAll('a[href],button:not([disabled]),input,textarea,select')).filter(function (el) {return el.offsetParent!==null;});
      if (!focusable.length) return;
      var first = focusable[0], last = focusable[focusable.length-1];
      if (event.shiftKey && document.activeElement===first) {event.preventDefault(); last.focus();}
      else if (!event.shiftKey && document.activeElement===last) {event.preventDefault(); first.focus();}
    }
  });
  document.querySelectorAll('[data-sluyu-bookmark]').forEach(function (button) {
    if (window.wuaiSluyu && window.wuaiSluyu.loggedIn) return;
    try {button.setAttribute('aria-pressed',localStorage.getItem('sluyu-bookmark-'+button.dataset.sluyuBookmark)==='1'?'true':'false');} catch (error) { /* Storage may be blocked. */ }
  });
  document.querySelectorAll('.sluyu-toc').forEach(function (toc) {
    var headings = document.querySelectorAll('.sluyu-article .sluyu-entry h2,.sluyu-article .sluyu-entry h3,.sluyu-article .sluyu-entry h4');
    headings.forEach(function (heading,index) {
      if (!heading.textContent.trim()) return;
      if (!heading.id) {
        var id = 'article-heading-' + (index+1);
        while (document.getElementById(id)) id += '-section';
        heading.id = id;
      }
      var link = document.createElement('a');
      link.href = '#' + encodeURIComponent(heading.id);
      link.textContent = heading.textContent;
      link.dataset.level = heading.tagName.slice(1);
      toc.appendChild(link);
    });
    var container = toc.closest('[data-sluyu-toc-container]') || toc.closest('section');
    container.hidden = !toc.children.length;
    if (container.nextElementSibling && container.nextElementSibling.classList.contains('sluyu-toc-fallback')) container.nextElementSibling.hidden = !!toc.children.length;
    if ('IntersectionObserver' in window) {
      var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {if(entry.isIntersecting) toc.querySelectorAll('a').forEach(function (a) {a.classList.toggle('is-current', decodeURIComponent(a.hash.slice(1))===entry.target.id);});});
      }, {rootMargin:'-15% 0px -65% 0px'});
      headings.forEach(function (heading) {observer.observe(heading);});
    }
  });
})();
