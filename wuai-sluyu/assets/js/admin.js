/* CSF handles tabs, search, save, colors and media. Mobile behavior follows
 * the menu and sticky-footer handlers in the supplied Zibll settings script. */
(function ($) {
    'use strict';
    $(function () {
        var $panel = $('.wuai-csf-settings');
        if (!$panel.length) return;
        var $nav = $panel.find('.csf-nav-options');
        function closeMenu() {
            $nav.removeClass('show');
            $panel.find('.csf-menu').attr('aria-expanded', 'false');
        }
        function updateSticky() {
            $panel.find('.csf-container').toggleClass('sticky-sm', $(window).scrollTop() > 300);
        }
        $(window).on('scroll.wuaiCsf resize.wuaiCsf', updateSticky);
        updateSticky();
        $panel.find('.csf-menu').attr({ role: 'button', 'aria-expanded': 'false', 'aria-label': '切换设置菜单' });
        $panel.on('click', '.csf-menu', function (event) {
            event.preventDefault();
            $nav.toggleClass('show');
            $panel.find('.csf-menu').attr('aria-expanded', $nav.hasClass('show') ? 'true' : 'false');
        });
        $nav.on('click', 'a', function () {
            if (window.innerWidth < 783 && !$(this).hasClass('csf-arrow')) closeMenu();
        });
        $(document).on('keydown.wuaiCsf', function (event) {
            if (event.key === 'Escape') closeMenu();
        }).on('click.wuaiCsf', function (event) {
            if (!$(event.target).closest('.csf-nav-options, .csf-menu').length) closeMenu();
        });
        var touchStart = null;
        $nav.on('touchstart', function (event) {
            touchStart = event.originalEvent.touches[0].clientX;
        }).on('touchend', function (event) {
            if (touchStart !== null && event.originalEvent.changedTouches[0].clientX - touchStart > 70) closeMenu();
            touchStart = null;
        });
        $panel.on('click', '.wuai-csf-history-action', function () {
            var button = $(this), operation = button.data('operation');
            if (operation === 'restore' && !window.confirm('恢复这份配置？当前配置会先自动备份，未保存的表单修改将被放弃。')) return;
            if (window.CSF && CSF.vars && CSF.vars.form_modified && operation === 'backup' && !window.confirm('只备份已保存的配置，表单中尚未保存的修改不会进入备份。继续吗？')) return;
            if (window.CSF && CSF.vars) CSF.vars.form_modified = false;
            var form = $('<form>', { method: 'post', action: wuaiCsf.postUrl });
            var values = {
                action: 'wuai_sluyu_settings_history', operation: operation,
                _wpnonce: button.data('nonce'), backup_id: button.data('backup-id') || ''
            };
            $.each(values, function (name, value) { $('<input>', { type: 'hidden', name: name, value: value }).appendTo(form); });
            form.appendTo('body').trigger('submit');
        });
    });
})(jQuery);
