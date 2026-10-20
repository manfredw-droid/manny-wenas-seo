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
const done = { success: true, data: { imported: 0, skipped: 0, unchanged: 0, errors: 0, processed: 1, total: 1, done: true, details: [], field_outcomes: {} } };
function fixture(source, checked, overwrite, response, defer) {
    // The choices live in `state` so a test can change them while a request is still running.
    const state = { source, checked, overwrite, types: ['fixture'], setup: false };
    const nodes = new Map(), requests = [], texts = {}, props = [], pending = [], htmls = {};
    function $(selector) {
        if (!nodes.has(selector)) nodes.set(selector, {
            handlers: {}, on(event, fn) { this.handlers[event] = fn; return this; },
            val() { return selector === '#mw-import-source' ? state.source : 'nonce'; },
            is() { return selector.includes('dry_run') ? state.checked : state.overwrite; },
            map() { return { get: () => state.types.slice() }; },
            show() { return this; }, hide() { return this; }, prop(name, value) { props.push([selector, name, value]); return this; },
            css() { return this; }, text(value) { if (value !== undefined) texts[selector] = value; return this; },
            html(value) { if (value !== undefined) { htmls[selector] = value; return this; } return ''; }, hasClass(name) { return name === 'mwseo-import--setup' && state.setup; }
        });
        return nodes.get(selector);
    }
    $.post = (url, data) => { requests.push({ url, data }); return { done(fn) { if (response) { if (defer) pending.push(() => fn(response)); else fn(response); } return this; }, fail() { return this; } }; };
    vm.runInNewContext(script, { jQuery: $, ajaxurl: '/wp-admin/admin-ajax.php', alert: message => { throw Error(message); },
        cfg: { nativeSources: ['yoast', 'rankmath'], i18n: { uncheck_dry_run_and_click_run_import_to_: 'UNCHECK-DRY-RUN-HINT', click_run_import_to_apply_these_changes: 'CLICK-RUN-HINT' }, fieldMaps: {}, outcomes: {}, batchSize: 50 } });
    return { $, requests, texts, props, htmls, state, finish() { const run = pending.shift(); if (run) run(); } };
}
for (const source of sources) for (const overwrite of [false, true]) for (const checked of [false, true]) for (const action of ['submit', 'preview']) {
    const name = `${source} ${action} checked=${checked} overwrite=${overwrite}`;
    try {
        const f = fixture(source, checked, overwrite, done);
        let prevented = false;
        const needsPreview = action === 'submit' && !checked; // a real write is only sent after a preview of these choices
        if (needsPreview) f.$('#mw-import-preview-btn').handlers.click();
        if (action === 'submit') f.$('#mw-seo-import-form').handlers.submit({ preventDefault() { prevented = true; } });
        else f.$('#mw-import-preview-btn').handlers.click();
        const sent = f.requests[f.requests.length - 1];
        assert.equal(f.requests.length, needsPreview ? 2 : 1);
        assert.equal(sent.data.source, source);
        assert.equal(sent.data.dry_run, action === 'preview' || checked ? 1 : 0);
        assert.equal(sent.data.overwrite, overwrite ? 1 : 0);
        assert.equal(sent.data.action, 'mw_seo_import_batch');
        if (action === 'submit') assert.equal(prevented, true);
        passed++; console.log('PASS ' + name);
    } catch (error) { failed++; console.log('FAIL ' + name + ': ' + error.message); }
}
// A source the server refuses: the page shows the message, stops after the one request and re-enables the buttons.
for (const source of refused) for (const action of ['submit', 'preview']) {
    const name = `${source} refused ${action}`;
    try {
        const f = fixture(source, action === 'submit', true, { success: false, data: refusal }); // submit with Dry run ticked: a write is never sent without a preview
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
    f.$('#mw-import-preview-btn').handlers.click();
    f.$('#mw-seo-import-form').handlers.submit({ preventDefault() {} });
    assert.equal(f.requests.length, 2);
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

// 1.0.8 review fix: writing only after a completed preview of exactly the choices in the form right now.
const lastRun = f => runProps(f).pop();
gate('A change made during a running preview keeps writing disabled (source)', () => {
    const f = fixture('yoast', false, false, done, true);
    f.$('#mw-import-preview-btn').handlers.click();
    f.state.source = 'rankmath';
    f.$('#mw-seo-import-form').handlers.change({ target: { name: 'source' } });
    f.finish();
    assert.deepEqual(lastRun(f), ['#mw-import-run-btn', 'disabled', true]);
});
gate('A post type changed during a running preview keeps writing disabled (even without a change event)', () => {
    const f = fixture('yoast', false, false, done, true);
    f.$('#mw-import-preview-btn').handlers.click();
    f.state.types = ['page'];
    f.finish();
    assert.deepEqual(lastRun(f), ['#mw-import-run-btn', 'disabled', true]);
});
gate('The overwrite choice changed during a running preview keeps writing disabled', () => {
    const f = fixture('yoast', false, false, done, true);
    f.$('#mw-import-preview-btn').handlers.click();
    f.state.overwrite = true;
    f.$('#mw-seo-import-form').handlers.change({ target: { name: 'overwrite' } });
    f.finish();
    assert.deepEqual(lastRun(f), ['#mw-import-run-btn', 'disabled', true]);
});
gate('Choices changed and restored during the preview count as the previewed choices', () => {
    const f = fixture('yoast', false, false, done, true);
    f.$('#mw-import-preview-btn').handlers.click();
    f.state.overwrite = true; f.$('#mw-seo-import-form').handlers.change({ target: { name: 'overwrite' } });
    f.state.overwrite = false; f.$('#mw-seo-import-form').handlers.change({ target: { name: 'overwrite' } });
    f.finish();
    assert.deepEqual(lastRun(f), ['#mw-import-run-btn', 'disabled', false]);
});
gate('A write submit without any preview sends nothing', () => {
    const f = fixture('yoast', false, false, done);
    f.$('#mw-seo-import-form').handlers.submit({ preventDefault() {} });
    assert.equal(f.requests.length, 0);
});
gate('A write submit after a preview of different choices sends nothing', () => {
    const f = fixture('yoast', false, false, done);
    f.$('#mw-import-preview-btn').handlers.click();
    f.state.source = 'rankmath';
    f.$('#mw-seo-import-form').handlers.submit({ preventDefault() {} });
    assert.equal(f.requests.length, 1);
});
gate('A write submit after a preview of exactly these choices is sent', () => {
    const f = fixture('yoast', false, true, done);
    f.$('#mw-import-preview-btn').handlers.click();
    f.$('#mw-seo-import-form').handlers.submit({ preventDefault() {} });
    assert.equal(f.requests.length, 2);
    assert.equal(f.requests[1].data.dry_run, 0);
    assert.equal(f.requests[1].data.overwrite, 1);
});
gate('A second write needs a new preview', () => {
    const f = fixture('yoast', false, false, done);
    f.$('#mw-import-preview-btn').handlers.click();
    f.$('#mw-seo-import-form').handlers.submit({ preventDefault() {} });
    f.$('#mw-seo-import-form').handlers.submit({ preventDefault() {} });
    assert.equal(f.requests.length, 2);
});

// 1.0.8 review fix: after a preview the setup screen (no Dry run option) gives its own next step.
gate('Setup screen: the preview result tells the writer to click Run Import and never mentions Dry run', () => {
    const f = fixture('yoast', false, false, done);
    f.state.setup = true;
    f.$('#mw-import-preview-btn').handlers.click();
    const html = f.htmls['#mw-import-results-content'];
    assert.match(html, /CLICK-RUN-HINT/);
    assert.doesNotMatch(html, /UNCHECK-DRY-RUN-HINT/);
});
gate('Import page: the preview result keeps the "uncheck Dry run" instruction', () => {
    const f = fixture('yoast', true, false, done);
    f.$('#mw-import-preview-btn').handlers.click();
    const html = f.htmls['#mw-import-results-content'];
    assert.match(html, /UNCHECK-DRY-RUN-HINT/);
    assert.doesNotMatch(html, /CLICK-RUN-HINT/);
});
gate('Setup screen: a finished real import shows no preview hint at all', () => {
    const f = fixture('yoast', false, false, done);
    f.state.setup = true;
    f.$('#mw-import-preview-btn').handlers.click();
    f.$('#mw-seo-import-form').handlers.submit({ preventDefault() {} });
    const html = f.htmls['#mw-import-results-content'];
    assert.doesNotMatch(html, /CLICK-RUN-HINT|UNCHECK-DRY-RUN-HINT/);
});
console.log(`${passed} passed; ${failed} failed. Isolated UI routing, not WordPress runtime.`);
process.exitCode = failed ? 1 : 0;
