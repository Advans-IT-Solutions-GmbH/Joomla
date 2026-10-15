const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const path = require('node:path');
const {test} = require('node:test');
const vm = require('node:vm');

const template = path.join(__dirname, '..', 'administrator', 'components', 'com_j2commerce_importexport', 'tmpl', 'dashboard', 'default.php');
const rendered = execFileSync('php', ['-r', `
namespace Joomla\\CMS\\Language { class Text { public static function _($key) { return $key; } } }
namespace Joomla\\CMS\\Router { class Route { public static function _($url) { return $url; } } }
namespace Joomla\\CMS\\HTML { class HTMLHelper { public static function _($key) { return ''; } } }
namespace {
    define('_JEXEC', 1);
    $view = new class {
        public $menutypes = [];
        public $viewlevels = [];
        public $categories = [];
        public function render($file) { include $file; }
    };
    $view->render($argv[1]);
}
`, template], {encoding: 'utf8'});
const script = rendered.match(/<script>([\s\S]*?)<\/script>/)[1];

function dashboard(responses) {
    const elements = {};
    const alerts = [];
    const calls = [];
    for (const id of ['export-type', 'export-full-info', 'opt-menu', 'menu-options',
        'import-form', 'import-preview', 'preview-content', 'process-import', 'import-file']) {
        elements[id] = {
            style: {}, handlers: {}, disabled: false,
            addEventListener(event, handler) { this.handlers[event] = handler; },
        };
    }
    const upload = {disabled: false};
    elements['import-form'].querySelector = () => upload;
    elements['import-form'].action = 'index.php?task=import.upload';
    elements['import-file'].files = [{}];
    const values = [['type', 'products_full'], ['options[quantity_mode]', 'add'],
        ['options[update_existing]', '1'], ['csrf-token', '1'], ['import_file', 'file']];
    class FormData {
        constructor() { this.values = new Map(values); }
        delete(key) { this.values.delete(key); }
    }
    vm.runInNewContext(script, {
        document: {
            getElementById: id => elements[id],
            addEventListener: (_event, callback) => callback(),
        },
        FormData,
        alert: message => alerts.push(message),
        confirm: () => true,
        fetch: async (url, init) => {
            calls.push({url, values: init.body.values});
            const response = responses.shift();
            if (response instanceof Error) throw response;
            assert.ok(response, 'No unexpected HTTP request');
            return {ok: true, json: async () => response};
        },
    });
    return {
        elements, alerts, calls, upload,
        submit: () => elements['import-form'].handlers.submit.call(elements['import-form'], {preventDefault() {}}),
        process: () => elements['process-import'].handlers.click(),
    };
}

const preview = {
    success: true,
    preview: {headers: ['title', 'quantity'], rows: [{title: '<b>Product</b>', quantity: 0}], total: 1},
};

test('upload -> preview -> process uses flat responses and preserves form options', async () => {
    const ui = dashboard([{success: true, file: 'fixture.json'}, preview,
        {success: true, imported: 1, failed: 0, errors: []}]);
    await ui.process();
    assert.equal(ui.calls.length, 0, 'Processing is gated on a preview');
    await ui.submit();
    assert.match(ui.calls[0].url, /import.upload/);
    assert.match(ui.calls[1].url, /import.preview/);
    assert.equal(ui.elements['import-preview'].style.display, 'block');
    assert.equal(ui.elements['process-import'].disabled, false);
    assert.match(ui.elements['preview-content'].innerHTML, /&lt;b&gt;Product&lt;\/b&gt;/);
    assert.match(ui.elements['preview-content'].innerHTML, /<td>0<\/td>/);
    await ui.process();
    assert.match(ui.calls[2].url, /import.process/);
    assert.equal(ui.calls[2].values.get('options[quantity_mode]'), 'add');
    assert.equal(ui.calls[2].values.get('options[update_existing]'), '1');
    assert.equal(ui.calls[2].values.get('csrf-token'), '1');
    assert.equal(ui.calls[2].values.has('import_file'), false);
    assert.equal(ui.elements['import-preview'].style.display, 'none');
    await ui.process();
    assert.equal(ui.calls.length, 3, 'Completed import cannot be submitted twice');
});

test('upload and preview errors block processing and display server messages', async () => {
    for (const responses of [
        [{success: false, message: 'Upload failed'}],
        [{success: true, file: 'fixture.json'}, {success: false, message: 'Preview failed'}],
        [{success: true, file: 'fixture.json'}, {success: true, data: preview.preview}],
        [new Error('Network unavailable')],
    ]) {
        const ui = dashboard(responses);
        await ui.submit();
        const count = ui.calls.length;
        await ui.process();
        assert.equal(ui.calls.length, count);
        assert.equal(ui.elements['import-preview'].style.display, 'none');
        assert.equal(ui.elements['process-import'].disabled, true);
        assert.equal(ui.upload.disabled, false);
        assert.equal(ui.alerts.length, 1);
    }
});

test('partial and failed imports show row errors rather than silently reload', async () => {
    for (const success of [true, false]) {
        const ui = dashboard([{success: true, file: 'fixture.json'}, preview,
            {success, imported: success ? 1 : 0, failed: 1, message: 'Import error', errors: ['Row 2: Missing title']}]);
        await ui.submit();
        await ui.process();
        assert.match(ui.alerts[0], /Row 2: Missing title/);
        assert.equal(ui.elements['process-import'].disabled, true);
    }
});

test('changing the file invalidates the preview', async () => {
    const ui = dashboard([{success: true, file: 'fixture.json'}, preview]);
    await ui.submit();
    ui.elements['import-file'].handlers.change();
    await ui.process();
    assert.equal(ui.calls.length, 2);
    assert.equal(ui.elements['import-preview'].style.display, 'none');
});

test('unsupported import types and XML are not advertised; exports remain unchanged', () => {
    const importSelect = rendered.match(/<select name="type" id="import-type"[\s\S]*?<\/select>/)[0];
    assert.deepEqual([...importSelect.matchAll(/value="([^"]+)"/g)].map(match => match[1]), ['products_full']);
    assert.match(rendered, /accept="\.csv,\.json"/);
    assert.match(rendered, /<option value="xml">XML<\/option>/);
});
