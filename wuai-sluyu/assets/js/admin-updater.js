(function ($) {
    'use strict';
    $(function () {
        var panel = $('.wuai-update');
        if (!panel.length) return;
        var latest = null, running = false, checking = false, jobId = '', pollTimer = null, pollFailures = 0;
        var message = panel.find('.wuai-update-message');
        var checkButton = panel.find('.wuai-update-check');
        var installButton = panel.find('.wuai-update-install');
        function request(action, data, timeout) {
            return $.ajax({ url: wuaiUpdater.ajaxUrl, method: 'POST', dataType: 'json', timeout: timeout || 30000,
                data: $.extend({ action: 'wuai_sluyu_' + action, nonce: wuaiUpdater.nonce }, data || {}) });
        }
        function failure(xhr) {
            return xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message || '连接服务器失败，请检查网络后重试。';
        }
        function state(value, title, text) {
            panel.attr('data-state', value);
            panel.find('.wuai-update-title').text(title);
            message.text(text || '');
            var icons = { current: 'fa-thumbs-o-up', available: 'fa-cloud-upload', error: 'fa-exclamation-circle', complete: 'fa-check-circle' };
            panel.find('.wuai-update-icon').attr('class', 'wuai-update-icon fa ' + (icons[value] || 'fa-cloud-download'));
        }
        function busy(value) {
            running = value;
            if (value) recovery(false);
            panel.attr('aria-busy', value ? 'true' : 'false');
            checkButton.prop('disabled', value);
            installButton.prop('disabled', value);
            // Do not let a save/reset reload the theme while its files are being replaced.
            $('.wuai-csf-settings .csf-save, .wuai-csf-settings .csf-reset-section, .wuai-csf-settings .csf-reset-all').each(function () {
                if (value && !this.disabled) $(this).attr('data-wuai-update-disabled', '1').prop('disabled', true);
                if (!value && this.hasAttribute('data-wuai-update-disabled')) $(this).removeAttr('data-wuai-update-disabled').prop('disabled', false);
            });
        }
        function remember(id) {
            try { if (id) sessionStorage.setItem('wuai-update-job', id); else sessionStorage.removeItem('wuai-update-job'); } catch (e) { /* Storage can be disabled. */ }
        }
        function remembered() {
            try { return sessionStorage.getItem('wuai-update-job'); } catch (e) { return ''; }
        }
        function progress(percent) {
            percent = Math.min(100, Math.max(0, Number(percent) || 0));
            panel.find('.wuai-update-progress-wrap').prop('hidden', false);
            panel.find('.wuai-update-progress').attr('aria-valuenow', percent);
            panel.find('.wuai-update-bar').css('width', percent + '%');
            panel.find('.wuai-update-percent').text(percent + '%');
        }
        function recovery(show) {
            panel.find('.wuai-update-download, .wuai-update-manual').prop('hidden', !show);
            panel.find('.wuai-update-help').prop('hidden', !show || panel.attr('data-state') !== 'error');
        }
        function error(text) {
            state('error', '暂时无法完成更新', text);
            latest = null;
            installButton.prop('hidden', true);
            recovery(!running);
        }
        function releaseNotes(text) {
            var box = panel.find('.wuai-update-notes').empty(), list = null;
            // Render common release-note Markdown using text nodes, never remote HTML.
            String(text || '此版本暂无更新说明。').split(/\r?\n/).forEach(function (line) {
                var heading = line.match(/^#{1,6}\s+(.+)/), bullet = line.match(/^[-*]\s+(.+)/);
                if (bullet) {
                    if (!list) list = $('<ul>').appendTo(box);
                    $('<li>').text(bullet[1]).appendTo(list);
                } else {
                    list = null;
                    if (line.trim()) $(heading ? '<h5>' : '<p>').text(heading ? heading[1] : line).appendTo(box);
                }
            });
        }
        function showRelease(data) {
            latest = data;
            panel.find('.wuai-update-current').text('V' + data.current);
            panel.find('.wuai-update-latest').text('V' + data.latest);
            panel.find('.wuai-update-latest-wrap').prop('hidden', !data.available);
            panel.find('.wuai-update-meta').text(data.channel + ' · 最近检测：' + data.checked_at);
            releaseNotes(data.notes);
            panel.find('.wuai-update-release').attr('href', data.url);
            if (data.package_url) panel.find('.wuai-update-download').attr('href', data.package_url);
            panel.find('.wuai-update-log').prop('hidden', false);
            panel.find('.wuai-update-progress-wrap, .wuai-update-reload').prop('hidden', true);
            state(data.available ? 'available' : 'current', data.available ? '发现新版本，可以更新啦' : '当前主题已经是最新版啦',
                data.available ? (data.package_error || (data.installable ? '新版已准备就绪，点击在线更新即可安装。' : 'WordPress 已禁止在线修改文件，请手动上传主题。')) : '检测完成，暂时没有需要安装的新版本。');
            installButton.prop('hidden', !data.available || !data.installable);
            recovery(data.available);
        }
        function check(force) {
            if (running || checking) return;
            if (force) remember('');
            jobId = '';
            checking = true;
            checkButton.prop('disabled', true);
            installButton.prop('hidden', true);
            panel.attr('aria-busy', 'true');
            state('checking', '正在检查主题更新…', '正在连接 GitHub，请稍候。');
            recovery(false);
            request('check_update', { force: force ? 1 : 0 }).done(function (result) {
                if (result.success && result.data.active) {
                    showActive(result.data);
                    schedulePoll();
                } else if (result.success) showRelease(result.data);
                else error(result.data && result.data.message || '未能获取版本信息，请稍后重试。');
            }).fail(function (xhr) { error(failure(xhr)); }).always(function () {
                checking = false;
                checkButton.prop('disabled', running);
                panel.attr('aria-busy', running ? 'true' : 'false');
            });
        }
        function showJob(data) {
            var job = data.job;
            if (!job || !job.id || jobId && job.id !== jobId && !data.active) return false;
            jobId = job.id;
            if (data.current) panel.find('.wuai-update-current').text('V' + data.current);
            progress(job.percent);
            if (data.active && (job.phase === 'complete' || job.phase === 'error')) {
                busy(true);
                progress(Math.min(99, Number(job.percent) || 0));
                state('installing', '正在完成更新收尾…', '服务器更新进程尚未释放，请勿重复安装或关闭文件写入权限。');
            } else if (job.phase === 'complete' || job.phase === 'error') {
                clearTimeout(pollTimer);
                busy(false);
                // A terminal job is only a result for this page view. Do not persist
                // its id, otherwise a later tab can resurrect an old progress card.
                remember('');
                installButton.prop('hidden', true);
                panel.find('.wuai-update-reload').prop('hidden', job.phase !== 'complete');
                state(job.phase, job.phase === 'complete' ? '主题更新成功' : '本次更新未完成', job.message);
                recovery(job.phase === 'error');
                latest = null;
            } else {
                busy(true);
                remember(job.id);
                state('installing', '正在更新主题，请稍候…', job.message);
            }
            return true;
        }
        function showActive(data) {
            if (showJob(data)) return;
            busy(true);
            installButton.prop('hidden', true);
            state('installing', '正在确认服务器更新进度…', data.message || '已有更新进程正在运行，正在等待服务器报告进度，请勿重复安装。');
        }
        function schedulePoll() {
            clearTimeout(pollTimer);
            if (running) pollTimer = setTimeout(poll, 1800);
        }
        function poll() {
            request('update_status', {}, 15000).done(function (result) {
                if (!result.success) {
                    pollFailures++;
                    message.text(result.data && result.data.message || '暂时无法读取进度，正在重试…');
                    return;
                }
                if (result.data.active) { showActive(result.data); pollFailures = 0; }
                else if (showJob(result.data)) pollFailures = 0;
                else pollFailures++;
            }).fail(function () {
                pollFailures++;
                message.text('服务器正在处理或连接暂时中断，正在重新获取进度，请勿重复更新。');
            }).always(function () {
                if (pollFailures >= 12) {
                    clearTimeout(pollTimer);
                    busy(true);
                    error('暂时无法确认安装结果。请刷新页面恢复检测；刷新前不要重复提交更新。');
                    panel.find('.wuai-update-reload').prop('hidden', false);
                    checkButton.prop('disabled', true);
                } else schedulePoll();
            });
        }
        checkButton.on('click', function () { check(true); });
        panel.find('.wuai-update-reload').on('click', function () { remember(''); window.location.reload(); });
        installButton.on('click', function () {
            if (running || checking || !latest || !latest.available || !latest.installable) return;
            if (window.CSF && CSF.vars && CSF.vars.form_modified) {
                message.text('有尚未保存的主题设置，请先保存，再点击在线更新。');
                return;
            }
            busy(true);
            jobId = 'update-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2);
            remember(jobId);
            pollFailures = 0;
            progress(0);
            state('installing', '正在更新主题，请稍候…', '正在提交更新请求…');
            request('install_update', { version: latest.latest, job: jobId }, 240000).done(function (result) {
                if (result.success && showJob(result.data)) return;
                if (result.data && result.data.busy) { jobId = ''; poll(); return; }
                busy(false);
                clearTimeout(pollTimer);
                remember('');
                error(result.data && result.data.message || '更新请求失败，请重新检查更新。');
            }).fail(function (xhr) {
                if (xhr.responseJSON && xhr.responseJSON.data) {
                    if (xhr.responseJSON.data.busy) { jobId = ''; poll(); return; }
                    busy(false);
                    clearTimeout(pollTimer);
                    remember('');
                    error(failure(xhr));
                } else if (running) {
                    message.text('连接暂时中断，正在确认服务器端的更新结果…');
                    schedulePoll();
                }
            });
            schedulePoll();
        });
        // Restore an interrupted browser session without starting a second installation.
        request('update_status').done(function (result) {
            var job = result.success && result.data.job;
            if (!result.success) {
                showActive({ message: result.data && result.data.message });
                schedulePoll();
            } else if (result.data.active) {
                showActive(result.data);
                schedulePoll();
            } else if (job && job.id && remembered() === job.id) {
                showJob(result.data);
            } else {
                remember('');
                check(false);
            }
        }).fail(function (xhr) {
            if (xhr.status === 403) {
                busy(false);
                error(failure(xhr));
            } else {
                showActive({ message: '暂时无法确认服务器端更新状态，正在重试，请勿重复安装。' });
                schedulePoll();
            }
        });
    });
})(jQuery);
