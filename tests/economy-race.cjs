'use strict';
// Two simultaneous PHP processes, not two requests to a single-threaded PHP server.
const assert = require('node:assert/strict');
const { spawn } = require('node:child_process');
const path = require('node:path');
const php = process.env.WP_QA_PHP || 'php';
const script = path.resolve(__dirname, 'economy-worker.php');
const children = new Set();
function worker(mode, args = []) {
  const child = spawn(php, [script, mode, ...args], { cwd: path.resolve(__dirname, '..'), env: process.env, windowsHide: true, stdio: ['pipe', 'pipe', 'pipe'] });
  children.add(child); let buffer = ''; let stderr = ''; let exited = null; const queue = []; const waiting = [];
  child.stderr.on('data', chunk => { stderr += chunk; });
  child.stdout.on('data', chunk => {
    buffer += chunk;
    for (;;) {
      const end = buffer.indexOf('\n'); if (end < 0) break;
      const line = buffer.slice(0, end).trim(); buffer = buffer.slice(end + 1); if (!line) continue;
      let value; try { value = JSON.parse(line); } catch { value = { parseError: line }; }
      if (waiting.length) waiting.shift().resolve(value); else queue.push(value);
    }
  });
  const ended = new Promise((resolve, reject) => {
    child.once('error', error => { children.delete(child); exited = { error }; for (const waiter of waiting.splice(0)) waiter.reject(error); reject(error); });
    child.once('exit', (code, signal) => { children.delete(child); exited = { code, signal, stderr }; for (const waiter of waiting.splice(0)) waiter.reject(new Error(mode + ' exited: ' + code + '/' + signal + ' ' + stderr)); resolve(exited); });
  }); ended.catch(() => {});
  return {
    child, ended, send: value => child.stdin.write(value + '\n'),
    next: () => queue.length ? Promise.resolve(queue.shift()) : exited ? Promise.reject(new Error(mode + ' already exited: ' + JSON.stringify(exited))) : new Promise((resolve, reject) => {
      const timer = setTimeout(() => reject(new Error(mode + ': no response within 30s\n' + stderr)), 30000);
      waiting.push({ resolve: value => { clearTimeout(timer); resolve(value); }, reject: error => { clearTimeout(timer); reject(error); } });
    }),
  };
}
async function run(mode, args) { const instance = worker(mode, args); const result = await instance.next(); const outcome = await instance.ended; assert.equal(outcome.code, 0, mode + ': ' + outcome.stderr); return result; }
(async () => {
  let prepared = false;
  try {
    assert.equal((await run('prepare')).prepared, true); prepared = true;
    const holder = worker('holder'); assert.equal((await holder.next()).ready, true);
    const borrower = worker('borrower'); const first = await borrower.next();
    assert.equal(first.first_error, 'economy_busy'); assert.equal(first.created, false, 'Another process cannot reserve while the spender holds the global mutex.');
    holder.send('charge'); assert.equal((await holder.next()).charged, true); assert.equal((await holder.ended).code, 0);
    borrower.send('retry'); const second = await borrower.next();
    assert.equal(second.second_error, 'bounty_points'); assert.equal(second.balance, 20); assert.equal((await borrower.ended).code, 0);
    const state = await run('state'); assert.equal(state.balance, 20); assert.equal(state.bounties, 0); assert.deepEqual(state.events.map(row => Number(row.points)), [100, -80]);
    const crashed = worker('crash_holder'); assert.equal((await crashed.next()).ready, true);
    crashed.child.kill(); await crashed.ended;
    const probe = await run('probe'); assert.equal(probe.error, 'economy_busy'); assert.equal(probe.balance, 20, 'A crash leaves the durable mutex: no lease takeover or double spend.');
    console.log('PASS: simultaneous PHP processes serialize reservation versus spending; exact balance and no negative overspend; process death retains the non-expiring mutex until explicit offline fixture cleanup.');
  } finally {
    const pending = [...children]; for (const child of pending) child.kill();
    await Promise.all(pending.map(child => child.exitCode !== null || child.signalCode !== null ? Promise.resolve() : new Promise(resolve => child.once('exit', resolve))));
    if (prepared) assert.equal((await run('cleanup', ['all-workers-stopped'])).cleaned, true);
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
