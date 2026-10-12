// Minimal dependency-free Chrome DevTools Protocol driver.
//
// Same file as in the Cash On Pickup suite next door - both drive a WooCommerce checkout in
// headless Chrome and need nothing more than this.
const { spawn } = require('child_process');
const fs = require('fs');

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

class Chrome {
  constructor(opts = {}) {
    this.port = opts.port || 9333;
    this.profile = opts.profile;
    this.proc = null;
    this.ws = null;
    this.id = 0;
    this.pending = new Map();
    this.consoleErrors = [];
    this.pageErrors = [];
  }

  async launch() {
    this.proc = spawn('google-chrome', [
      '--headless=new', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage',
      '--ignore-certificate-errors', '--no-first-run', '--no-default-browser-check',
      '--disable-background-timer-throttling', '--disable-renderer-backgrounding',
      `--remote-debugging-port=${this.port}`, `--user-data-dir=${this.profile}`,
      'about:blank',
    ], { stdio: ['ignore', 'ignore', 'pipe'] });
    this.proc.stderr.on('data', () => {});

    let version = null;
    for (let i = 0; i < 60; i++) {
      try {
        const res = await fetch(`http://127.0.0.1:${this.port}/json/version`);
        version = await res.json();
        break;
      } catch (e) { await sleep(250); }
    }
    if (!version) throw new Error('Chrome did not expose the debugging port');

    // Grab the existing about:blank tab.
    const list = await (await fetch(`http://127.0.0.1:${this.port}/json/list`)).json();
    const page = list.find((t) => t.type === 'page');
    if (!page) throw new Error('no page target');
    await this._connect(page.webSocketDebuggerUrl);

    await this.send('Page.enable');
    await this.send('Runtime.enable');
    await this.send('Network.enable');
    return this;
  }

  _connect(url) {
    return new Promise((resolve, reject) => {
      this.ws = new WebSocket(url);
      this.ws.onopen = () => resolve();
      this.ws.onerror = (e) => reject(new Error('ws error'));
      this.ws.onmessage = (ev) => {
        const msg = JSON.parse(ev.data);
        if (msg.id && this.pending.has(msg.id)) {
          const { resolve: res, reject: rej } = this.pending.get(msg.id);
          this.pending.delete(msg.id);
          msg.error ? rej(new Error(JSON.stringify(msg.error))) : res(msg.result);
          return;
        }
        if (msg.method === 'Page.loadEventFired') this._loaded = true;
        if (msg.method === 'Runtime.consoleAPICalled' && msg.params.type === 'error') {
          this.consoleErrors.push(msg.params.args.map((a) => a.value ?? a.description ?? a.type).join(' '));
        }
        if (msg.method === 'Runtime.exceptionThrown') {
          const d = msg.params.exceptionDetails;
          this.pageErrors.push(d.exception?.description || d.text);
        }
      };
    });
  }

  send(method, params = {}) {
    const id = ++this.id;
    return new Promise((resolve, reject) => {
      this.pending.set(id, { resolve, reject });
      this.ws.send(JSON.stringify({ id, method, params }));
      setTimeout(() => {
        if (this.pending.has(id)) { this.pending.delete(id); reject(new Error('CDP timeout: ' + method)); }
      }, 60000);
    });
  }

  clearErrors() { this.consoleErrors = []; this.pageErrors = []; }

  async goto(url, { waitFor = null, timeout = 30000 } = {}) {
    this._loaded = false;
    await this.send('Page.navigate', { url });
    const start = Date.now();
    while (!this._loaded && Date.now() - start < timeout) await sleep(50);
    if (waitFor) await this.waitFor(waitFor, timeout - (Date.now() - start));
    return this;
  }

  // Evaluates an expression in the page; expression must return a JSON-serialisable value.
  async eval(expression, { awaitPromise = true } = {}) {
    const res = await this.send('Runtime.evaluate', {
      expression: `(async () => { ${expression} })()`,
      awaitPromise, returnByValue: true,
    });
    if (res.exceptionDetails) {
      throw new Error('page eval failed: ' + (res.exceptionDetails.exception?.description || res.exceptionDetails.text));
    }
    return res.result.value;
  }

  async waitFor(expression, timeout = 20000) {
    const start = Date.now();
    while (Date.now() - start < timeout) {
      try {
        const ok = await this.eval(`return !!(${expression});`);
        if (ok) return true;
      } catch (e) { /* page may be mid-navigation */ }
      await sleep(150);
    }
    throw new Error('waitFor timed out: ' + expression);
  }

  async close() {
    try { this.ws && this.ws.close(); } catch (e) {}
    if (this.proc) { this.proc.kill('SIGTERM'); await sleep(300); try { this.proc.kill('SIGKILL'); } catch (e) {} }
  }
}

module.exports = { Chrome, sleep };
