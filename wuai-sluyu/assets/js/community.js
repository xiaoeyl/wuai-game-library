(function () {
  'use strict';
  var config = window.wuaiSluyu || {};
  function notify(message, form) {
    var box = form ? form.querySelector('.sluyu-form-status') : document.querySelector('.sluyu-toast');
    if (!box) return;
    box.hidden = false;
    box.textContent = message;
    if (!form) setTimeout(function () { box.hidden = true; }, 4500);
  }
  function request(operation, values, multipart) {
    var data = multipart && values instanceof FormData ? values : new URLSearchParams(values || {});
    data.set('action', 'wuai_sluyu_community'); data.set('nonce', config.nonce); data.set('operation', operation);
    return fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data }).then(function (response) {
      return response.json().catch(function () { throw new Error('服务返回异常，请刷新页面后重试。'); });
    }).then(function (result) {
      if (!result.success) throw new Error(result.data && result.data.message || '操作失败，请刷新后重试。');
      return result.data;
    });
  }
  document.addEventListener('submit', function (event) {
    var form = event.target.closest('.sluyu-community-form');
    if (!form) return;
    event.preventDefault();
    if (form.dataset.busy) return;
    form.dataset.busy = '1'; var button = form.querySelector('[type=submit]'); button.disabled = true;
    request(form.dataset.operation, new FormData(form), form.enctype === 'multipart/form-data').then(function (data) {
      notify(data.message, form); if (data.reset) form.reset(); if (data.reload) location.reload();
    }).catch(function (error) { notify(error.message, form); }).finally(function () { button.disabled = false; delete form.dataset.busy; });
  });
  document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-community-action]');
    if (!button || button.disabled) return;
    if (button.dataset.confirm && !window.confirm(button.dataset.confirm)) return;
    var values = {}; ['id','task','direction','decision','userId'].forEach(function (key) { if (button.dataset[key]) values[key === 'userId' ? 'user_id' : key] = button.dataset[key]; });
    button.disabled = true;
    request(button.dataset.communityAction, values).then(function (data) {
      notify(data.message);
      if (typeof data.selected === 'boolean') button.setAttribute('aria-pressed', String(data.selected));
      if (button.dataset.communityAction === 'checkin') {
        document.querySelectorAll('[data-community-action=checkin]').forEach(function (item) { item.textContent = '今日已签到'; item.disabled = true; });
        document.querySelectorAll('[data-checkin-days]').forEach(function (item) { item.textContent = data.days; });
      } else button.disabled = false;
      if (data.reload || (['follow','bookmark'].includes(button.dataset.communityAction) && document.querySelector('.sluyu-portal'))) location.reload();
    }).catch(function (error) { notify(error.message); button.disabled = false; });
  });
  document.querySelectorAll('[data-help-search]').forEach(function (input) {
    input.addEventListener('input', function () {
      var term = input.value.trim().toLowerCase();
      document.querySelectorAll('.sluyu-faq').forEach(function (item) { item.hidden = !item.textContent.toLowerCase().includes(term); });
      document.querySelectorAll('.sluyu-help-group').forEach(function (group) { group.hidden = !Array.from(group.querySelectorAll('.sluyu-faq')).some(function (item) { return !item.hidden; }); });
    });
  });
  document.querySelectorAll('[data-local-tool]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      event.preventDefault(); var output = form.querySelector('output'); var data = new FormData(form); var input = String(data.get('input') || '');
      try {
        if (form.dataset.localTool === 'qr') {
          var canvas = form.querySelector('canvas');
          if (!window.QRious) throw new Error('二维码组件未加载，请刷新重试。');
          if (new TextEncoder().encode(input).length > 700) throw new Error('内容过长，请缩短至 700 字节以内。');
          new window.QRious({ element: canvas, value: input, size: 320, level: 'M' }); canvas.hidden = false;
          var link = form.querySelector('[data-qr-download]'); link.href = canvas.toDataURL('image/png'); link.hidden = false; output.textContent = '已生成，可下载保存。';
        } else if (form.dataset.localTool === 'password') {
          if (!window.crypto || !window.crypto.getRandomValues) throw new Error('此浏览器不支持安全随机数。');
          var length = Math.max(12, Math.min(64, Number(data.get('length')) || 20)); var alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%&*+-=?'; var password = '';
          while (password.length < length) { var bytes = new Uint8Array(64); window.crypto.getRandomValues(bytes); bytes.forEach(function (byte) { if (byte < 256 - (256 % alphabet.length) && password.length < length) password += alphabet[byte % alphabet.length]; }); }
          output.textContent = password;
        } else {
          var mode = data.get('mode'); var result;
          if (mode === 'dedupe') result = Array.from(new Set(input.split(/\r?\n/).map(function (line) { return line.trim(); }).filter(Boolean))).join('\n');
          else if (mode === 'trim') result = input.replace(/[ \t]+/g, ' ').replace(/\n{3,}/g, '\n\n').trim();
          else if (mode === 'encode') result = encodeURIComponent(input);
          else if (mode === 'decode') result = decodeURIComponent(input);
          else if (mode === 'json') result = JSON.stringify(JSON.parse(input), null, 2);
          else result = '字符数：' + Array.from(input).length + ' · 非空白字符：' + Array.from(input.replace(/\s/g, '')).length + ' · 行数：' + (input ? input.split(/\r?\n/).length : 0);
          output.textContent = result;
        }
      } catch (error) { output.textContent = '处理失败：' + error.message; }
    });
  });
  document.querySelectorAll('.sluyu-community-form select[name=kind]').forEach(function (select) {
    function update() {
      var field = select.form.querySelector('[data-poll-field]'); if (field) { field.hidden = select.value !== 'poll'; field.disabled = select.value !== 'poll'; }
      var protectedFields = select.form.querySelector('.sluyu-protected-fields');
      if (protectedFields) { var enabled = ['post','topic'].includes(select.value); protectedFields.hidden = !enabled; protectedFields.querySelectorAll('input,textarea,select').forEach(function (input) { input.disabled = !enabled; }); }
    }
    select.addEventListener('change', update); update();
  });
  document.querySelectorAll('select[name=poll_mode]').forEach(function (select) {
    function update() { var field = select.form.querySelector('[data-poll-multiple]'); if (field) field.hidden = select.value !== 'multiple'; }
    select.addEventListener('change', update); update();
  });
})();
