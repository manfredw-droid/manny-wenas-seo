'use strict';
// Execute the complete production inline script and its registered UI handlers.
const fs = require('node:fs'), path = require('node:path'), vm = require('node:vm');
const assert = require('node:assert/strict');
const root = process.argv[2] ? path.resolve(process.argv[2]) : path.resolve(__dirname, '..');
const php = fs.readFileSync(path.join(root, 'includes/class-mwseo-importer.php'), 'utf8');
const script = php.match(/return <<<'JS'\r?\n([\s\S]*?)\r?\nJS;/)[1];
const sources = ['yoast', 'rankmath', 'aioseo', 'seopress', 'tsf'];
let passed = 0, failed = 0;
function fixture(source, checked, overwrite) {
    const nodes = new Map(), requests = [];
    function $(selector) {
        if (!nodes.has(selector)) nodes.set(selector, {
            handlers: {}, on(event, fn) { this.handlers[event] = fn; return this; },
            val() { return selector === '#mw-import-source' ? source : 'nonce'; },
            is() { return selector.includes('dry_run') ? checked : overwrite; },
            map() { return { get: () => ['fixture'] }; },
            show() { return this; }, hide() { return this; }, prop() { return this; },
            css() { return this; }, text() { return this; }, html() { return ''; }
        });
        return nodes.get(selector);
    }
    $.post = (url, data) => { requests.push({ url, data }); return { done() { return this; }, fail() { return this; } }; };
    vm.runInNewContext(script, { jQuery: $, ajaxurl: '/wp-admin/admin-ajax.php', alert: message => { throw Error(message); },
        cfg: { nativeSources: ['yoast', 'rankmath'], i18n: {}, fieldMaps: {}, outcomes: {}, batchSize: 50 } });
    return { $, requests };
}
for (const source of sources) for (const overwrite of [false, true]) for (const checked of [false, true]) for (const action of ['submit', 'preview']) {
    const name = `${source} ${action} checked=${checked} overwrite=${overwrite}`;
    try {
        const f = fixture(source, checked, overwrite);
        let prevented = false;
        if (action === 'submit') f.$('#mw-seo-import-form').handlers.submit({ preventDefault() { prevented = true; } });
        else f.$('#mw-import-preview-btn').handlers.click();
        assert.equal(f.requests.length, 1);
        assert.equal(f.requests[0].data.source, source);
        assert.equal(f.requests[0].data.dry_run, action === 'preview' || checked ? 1 : 0);
        assert.equal(f.requests[0].data.overwrite, overwrite ? 1 : 0);
        assert.equal(f.requests[0].data.action, 'mw_seo_import_batch');
        if (action === 'submit') assert.equal(prevented, true);
        passed++; console.log('PASS ' + name);
    } catch (error) { failed++; console.log('FAIL ' + name + ': ' + error.message); }
}
console.log(`${passed} passed; ${failed} failed. Isolated UI routing, not WordPress runtime.`);
process.exitCode = failed ? 1 : 0;
