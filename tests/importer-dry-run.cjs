'use strict';
// Execute the complete production inline script and its registered UI handlers.
const fs = require('node:fs'), path = require('node:path'), vm = require('node:vm');
const assert = require('node:assert/strict');
const root = process.argv[2] ? path.resolve(process.argv[2]) : path.resolve(__dirname, '..');
const php = fs.readFileSync(path.join(root, 'includes/class-mwseo-importer.php'), 'utf8');
const script = php.match(/return <<<'JS'\r?\n([\s\S]*?)\r?\nJS;/)[1];
// Only these two sources are offered. The server refuses every other source; the UI side of a refusal is tested below.
const sources = ['yoast', 'rankmath'];
const refused = ['aioseo', 'seopress', 'tsf', 'nosuchplugin'];
const refusal = 'Import currently supports Yoast SEO and Rank Math. Support for the other sources is switched off.';
let passed = 0, failed = 0;
function fixture(source, checked, overwrite, response) {
    const nodes = new Map(), requests = [], texts = {}, props = [];
    function $(selector) {
        if (!nodes.has(selector)) nodes.set(selector, {
            handlers: {}, on(event, fn) { this.handlers[event] = fn; return this; },
            val() { return selector === '#mw-import-source' ? source : 'nonce'; },
            is() { return selector.includes('dry_run') ? checked : overwrite; },
            map() { return { get: () => ['fixture'] }; },
            show() { return this; }, hide() { return this; }, prop(name, value) { props.push([selector, name, value]); return this; },
            css() { return this; }, text(value) { if (value !== undefined) texts[selector] = value; return this; }, html() { return ''; }
        });
        return nodes.get(selector);
    }
    $.post = (url, data) => { requests.push({ url, data }); return { done(fn) { if (response) fn(response); return this; }, fail() { return this; } }; };
    vm.runInNewContext(script, { jQuery: $, ajaxurl: '/wp-admin/admin-ajax.php', alert: message => { throw Error(message); },
        cfg: { nativeSources: ['yoast', 'rankmath'], i18n: {}, fieldMaps: {}, outcomes: {}, batchSize: 50 } });
    return { $, requests, texts, props };
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
// A source the server refuses: the page shows the message, stops after the one request and re-enables the buttons.
for (const source of refused) for (const action of ['submit', 'preview']) {
    const name = `${source} refused ${action}`;
    try {
        const f = fixture(source, false, true, { success: false, data: refusal });
        if (action === 'submit') f.$('#mw-seo-import-form').handlers.submit({ preventDefault() {} });
        else f.$('#mw-import-preview-btn').handlers.click();
        assert.equal(f.requests.length, 1);
        assert.equal(f.texts['#mw-import-progress-text'], 'Error: ' + refusal);
        assert.deepEqual(f.props[f.props.length - 1], ['#mw-import-preview-btn', 'disabled', false]);
        assert.equal(f.props.some(p => p[0] === '#mw-import-run-btn' && p[2] === false), false);
        passed++; console.log('PASS ' + name);
    } catch (error) { failed++; console.log('FAIL ' + name + ': ' + error.message); }
}

// 1.0.8: writing is possible only after a completed preview of the current choices.
const done = { success: true, data: { imported: 0, skipped: 0, unchanged: 0, errors: 0, processed: 1, total: 1, done: true, details: [], field_outcomes: {} } };
function gate(name, fn) { try { fn(); passed++; console.log('PASS ' + name); } catch (error) { failed++; console.log('FAIL ' + name + ': ' + error.message); } }
const runProps = f => f.props.filter(p => p[0] === '#mw-import-run-btn');
gate('Choosing a source does not enable writing', () => {
    const f = fixture('yoast', false, false);
    const source = f.$('#mw-import-source');
    source.handlers.change.call(source);
    assert.equal(runProps(f).some(p => p[2] === false), false);
});
gate('A completed preview enables writing', () => {
    const f = fixture('yoast', false, false, done);
    f.$('#mw-import-preview-btn').handlers.click();
    assert.deepEqual(runProps(f).pop(), ['#mw-import-run-btn', 'disabled', false]);
});
gate('A completed write leaves the Run button disabled until the next preview', () => {
    const f = fixture('yoast', false, false, done);
    f.$('#mw-seo-import-form').handlers.submit({ preventDefault() {} });
    assert.deepEqual(runProps(f).pop(), ['#mw-import-run-btn', 'disabled', true]);
});
gate('Changing a choice after the preview disables writing again', () => {
    const f = fixture('yoast', false, false, done);
    f.$('#mw-import-preview-btn').handlers.click();
    f.$('#mw-seo-import-form').handlers.change({ target: { name: 'overwrite' } });
    assert.deepEqual(runProps(f).pop(), ['#mw-import-run-btn', 'disabled', true]);
});
gate('Toggling Dry run does not discard a finished preview', () => {
    const f = fixture('yoast', false, false, done);
    f.$('#mw-import-preview-btn').handlers.click();
    f.$('#mw-seo-import-form').handlers.change({ target: { name: 'dry_run' } });
    assert.deepEqual(runProps(f).pop(), ['#mw-import-run-btn', 'disabled', false]);
});
console.log(`${passed} passed; ${failed} failed. Isolated UI routing, not WordPress runtime.`);
process.exitCode = failed ? 1 : 0;
