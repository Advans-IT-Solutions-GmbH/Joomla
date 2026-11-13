const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { chromium, firefox } = require('../../shared/tests/browser/node_modules/playwright');

const root = path.resolve(__dirname, '..');
const css = fs.readFileSync(path.join(root, 'media/com_appointments/css/admin.css'), 'utf8');
const uid = `${'a'.repeat(64)}@example.test`;
const php = String.raw`<?php
namespace Joomla\CMS {
    class Factory {
        public static function getApplication() {
            return new class {
                public function getIdentity() {
                    return new class { public function authorise(...$args) { return true; } };
                }
            };
        }
    }
}
namespace Joomla\CMS\Language {
    class Text {
        public static array $strings = [];
        public static function _($key) { return self::$strings[$key] ?? $key; }
        public static function sprintf($key, ...$args) { return vsprintf(self::_($key), $args); }
    }
}
namespace Joomla\CMS\Router { class Route { public static function _($url) { return '#'; } } }
namespace Joomla\CMS\HTML { class HTMLHelper { public static function _($key) { return ''; } } }
namespace {
    define('_JEXEC', 1);
    \Joomla\CMS\Language\Text::$strings = parse_ini_file(
        getenv('QUEUE_ROOT') . '/administrator/language/' . getenv('QUEUE_LOCALE') . '/com_appointments.ini'
    ) + ['JGRID_HEADING_ID' => 'ID', 'JSTATUS' => 'Status'];
    $view = new class {
        public array $counts = ['eventtypes' => 1, 'bookings' => 1, 'today' => 1, 'upcoming' => 1, 'pending' => 1];
        public array $eventCounts = [];
        public bool $closeEnabled = false;
        public bool $closeConfigured = false;
        public int $closeStart = 0;
        public int $closeTotal = 1;
        public array $closeRows = [];
        public function escape($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
        public function render() { require getenv('QUEUE_ROOT') . '/administrator/components/com_appointments/tmpl/dashboard/default.php'; }
    };
    $view->closeRows = [['booking_id' => 900001, 'profile' => str_repeat('p', 32), 'state' => 'uncertain',
        'reason' => str_repeat('synthetic_reason_', 8), 'uid' => getenv('QUEUE_UID')]];
    $view->render();
}
`;

(async () => {
    let checks = 0;
    for (const [name, engine, options] of [
        ['edge', chromium, { channel: 'msedge' }],
        ['firefox', firefox, {}],
    ]) {
        const browser = await engine.launch({ headless: true, ...options });
        try {
            for (const locale of ['de-DE', 'en-GB', 'fr-FR']) {
                const rendered = spawnSync('php', [], {
                    input: php, encoding: 'utf8',
                    env: { ...process.env, QUEUE_ROOT: root, QUEUE_LOCALE: locale, QUEUE_UID: uid },
                });
                assert.equal(rendered.status, 0, rendered.stderr);
                const page = await browser.newPage();
                try {
                    for (const width of [375, 768, 1024, 1280, 1440, 1600, 1920]) {
                        for (const colorScheme of ['light', 'dark']) {
                            await page.setViewportSize({ width, height: 900 });
                            await page.emulateMedia({ colorScheme });
                            await page.setContent(`<style>
                                * { box-sizing: border-box; } body { margin: 0; font: 16px system-ui; }
                                main { padding: 16px; } .card-body { padding: 16px; }
                                table { border-collapse: collapse; } td, th { padding: 8px; }
                                .form-control { display: block; width: 100%; padding: 8px; }
                                .btn { padding: 8px 12px; font: inherit; }
                                code { font: 14px monospace; }
                                ${css}
                            </style><main>${rendered.stdout}</main>`);
                            const queue = page.locator('.com-appointments-close-queue');
                            assert.equal(await queue.locator('tbody td').count(), 4);
                            assert.equal(await queue.locator('code.text-break').textContent(), uid);
                            const geometry = await queue.evaluate(table => {
                                const nodes = [table, ...table.querySelectorAll('tbody, tbody tr, td, code, input, button')];
                                return nodes.map(node => {
                                    const box = node.getBoundingClientRect();
                                    return { tag: node.tagName, left: box.left, right: box.right,
                                        scroll: node.scrollWidth, client: node.clientWidth,
                                        height: box.height, viewport: innerWidth };
                                }).filter(box => box.height > 0);
                            });
                            for (const box of geometry) {
                                assert.ok(box.left >= -1 && box.right <= box.viewport + 1,
                                    `${name}/${locale}/${width}/${colorScheme}: ${JSON.stringify(box)}`);
                                assert.ok(box.client === 0 || box.scroll <= box.client + 1,
                                    `${name}/${locale}/${width}/${colorScheme}: overflow ${JSON.stringify(box)}`);
                            }
                            const glyphsFit = await queue.locator('code.text-break').evaluate(code => {
                                const cell = code.closest('td').getBoundingClientRect();
                                const range = document.createRange();
                                range.selectNodeContents(code);
                                return [...range.getClientRects()].every(box =>
                                    box.left >= cell.left - 1 && box.right <= cell.right + 1);
                            });
                            assert.equal(glyphsFit, true, `${name}/${locale}/${width}/${colorScheme}: UID glyph overflow`);
                            const input = queue.locator('input[name=remote_id]');
                            assert.equal(await input.getAttribute('required'), '');
                            await input.fill('acti_synthetic');
                            assert.equal(await input.inputValue(), 'acti_synthetic');
                            await page.evaluate(() => {
                                window.queueSubmitted = false;
                                document.querySelector('.com-appointments-close-queue form').addEventListener('submit', event => {
                                    event.preventDefault();
                                    window.queueSubmitted = true;
                                });
                            });
                            await queue.locator('button[type=submit]').click();
                            assert.equal(await page.evaluate(() => window.queueSubmitted), true);
                            checks++;
                        }
                    }
                } finally {
                    await page.close();
                }
            }
        } finally {
            await browser.close();
        }
    }
    console.log(`Close queue layout: ${checks} browser/locale/viewport/theme cases passed; no network or Joomla writes.`);
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
