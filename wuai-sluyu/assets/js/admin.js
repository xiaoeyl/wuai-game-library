(function () {
    'use strict';

    var root = document.querySelector('[data-wuai-admin="1"]');
    if (!root) {
        return;
    }

    var links = root.querySelectorAll('[data-wuai-tab]');
    var panes = root.querySelectorAll('[data-wuai-pane]');
    var saveButton = root.querySelector('.wuai-save-button');

    function activate(tab, updateHash) {
        var target = root.querySelector('[data-wuai-pane="' + tab + '"]');
        if (!target) {
            return;
        }
        Array.prototype.forEach.call(panes, function (pane) {
            pane.classList.toggle('is-active', pane === target);
        });
        Array.prototype.forEach.call(links, function (link) {
            var active = link.getAttribute('data-wuai-tab') === tab;
            link.classList.toggle('is-active', active);
            var marker = link.querySelector('.dashicons-arrow-right-alt2');
            if (active && !marker) {
                var icon = document.createElement('i');
                icon.className = 'dashicons dashicons-arrow-right-alt2';
                link.appendChild(icon);
            } else if (!active && marker) {
                marker.remove();
            }
        });
        var title = root.querySelector('.wuai-admin-savebar strong');
        var description = root.querySelector('.wuai-admin-savebar span');
        var activeLink = root.querySelector('.wuai-admin-nav[data-wuai-tab="' + tab + '"]');
        if (activeLink) {
            var text = activeLink.querySelector('strong');
            var sub = activeLink.querySelector('small');
            if (title && text) title.textContent = text.textContent;
            if (description && sub) description.textContent = sub.textContent;
        }
        if (updateHash && window.history && window.history.replaceState) {
            window.history.replaceState(null, '', '#' + tab);
        }
    }

    Array.prototype.forEach.call(links, function (link) {
        link.addEventListener('click', function (event) {
            var tab = link.getAttribute('data-wuai-tab');
            if (!tab) return;
            event.preventDefault();
            activate(tab, true);
        });
    });

    var initial = window.location.hash ? window.location.hash.substring(1) : '';
    if (initial && root.querySelector('[data-wuai-pane="' + initial + '"]')) {
        activate(initial, false);
    }

    Array.prototype.forEach.call(root.querySelectorAll('input[type="color"]'), function (input) {
        var code = input.parentNode.querySelector('code');
        input.addEventListener('input', function () {
            if (code) code.textContent = input.value;
        });
    });

    Array.prototype.forEach.call(root.querySelectorAll('.wuai-media-button'), function (button) {
        button.addEventListener('click', function () {
            if (!window.wp || !wp.media) return;
            var target = document.getElementById(button.getAttribute('data-wuai-target'));
            var frame = wp.media({ title: '选择主题媒体', button: { text: '使用此地址' }, multiple: false });
            frame.on('select', function () {
                var selection = frame.state().get('selection').first().toJSON();
                if (target && selection && selection.url) target.value = selection.url;
            });
            frame.open();
        });
    });

    if (saveButton) {
        saveButton.addEventListener('click', function () {
            saveButton.classList.add('is-saving');
            window.setTimeout(function () { saveButton.classList.remove('is-saving'); }, 900);
        });
    }
}());
