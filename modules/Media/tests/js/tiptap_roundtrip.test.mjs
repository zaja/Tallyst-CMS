/*
 * Node round-trip test for the Tiptap content editor. Drives the EXACT extension set the
 * editor uses (buildExtensions) through @tiptap/html's generateJSON -> generateHTML,
 * which is what ProseMirror does on load + save. Proves on RICH content that:
 *   - formatting survives the Trix div-soup -> semantic <p> normalisation,
 *   - [form id=N] survives as text, our [image] node survives (data-id intact),
 *   - and what Tiptap SILENTLY DROPS (tables, iframes, inline styles) is gone — so the
 *     loss is known and asserted, not discovered in production.
 *
 * Run:  node modules/Media/tests/js/tiptap_roundtrip.test.mjs   (exits non-zero on fail)
 */
import assert from 'node:assert/strict';
import { generateJSON, generateHTML } from '@tiptap/html';
import { buildExtensions, registerEditorExtension, editorToolbarExtensions } from '../../assets/tiptap_extensions.js';
import { FormEmbed } from '../../../FormBuilder/assets/tiptap_form_node.js';

// Register FormBuilder's form node + toolbar like the app does (app-level), so the test
// exercises the real production schema (image + form embeds) and toolbar gating.
registerEditorExtension({
    key: 'form_builder',
    node: FormEmbed,
    toolbar: { label: '📋 Forma', action: () => {} },
});

const extensions = buildExtensions();

/** Simulate the editor load (HTML->doc) + save (doc->HTML) round-trip. */
function roundTrip(html) {
    return generateHTML(generateJSON(html, extensions), extensions);
}

// Rich, Trix-style content: <div> blocks + <br>, plus things outside the schema.
const input = [
    '<div>Uvod s <strong>podebljanim</strong>, <em>kurzivom</em> i ',
    '<a href="https://example.com">poveznicom</a>.<br><br></div>',
    '<h2>Podnaslov</h2>',
    '<ul><li>Prva stavka</li><li>Druga stavka</li></ul>',
    '<blockquote>Citat teksta.</blockquote>',
    '<div>[form id=2] ostaje kao tekst.</div>',
    '<div data-tallyst-form data-id="2" data-label="Kontakt">📋 Forma: Kontakt</div>',
    '<img data-tallyst-image data-id="5" data-size="thumb" src="/x.jpg" alt="Slika">',
    '<table><tbody><tr><td>tablica</td></tr></tbody></table>',
    '<iframe src="https://evil.example"></iframe>',
    '<div style="color:red">obojani div</div>',
].join('');

const out = roundTrip(input);
let passed = 0;
const ok = (cond, msg) => { assert.ok(cond, msg); passed++; };

// --- Formatting PRESERVED through normalisation ---
ok(out.includes('<strong>podebljanim</strong>'), 'bold preserved');
ok(out.includes('<em>kurzivom</em>'), 'italic preserved');
ok(out.includes('href="https://example.com"'), 'link href preserved');
ok(/<h2[ >]/.test(out), 'heading preserved');
ok(/<ul>/.test(out) && out.includes('Prva stavka') && out.includes('Druga stavka'), 'list + items preserved');
ok(out.includes('<blockquote>'), 'blockquote preserved');

// --- Shortcodes / image node survive ---
ok(out.includes('[form id=2]'), 'literal [form id=2] survives as text');
ok(out.includes('data-tallyst-image') && out.includes('data-id="5"'), 'image node + data-id preserved');
ok(out.includes('data-tallyst-form') && /data-tallyst-form[^>]*data-id="2"|data-id="2"[^>]*data-tallyst-form/.test(out), 'form embed node + data-id preserved');

// --- Trix <div> soup normalised to semantic <p> ---
ok(out.includes('<p>'), 'div normalised to paragraph');
// Trix content <div>s become <p>; the only divs allowed are form-embed nodes.
ok(!/<div(?![^>]*data-tallyst-form)/.test(out), 'plain Trix <div>s normalised to <p> (only form-embed divs remain)');

// --- SILENT DROPS (documented + asserted) ---
ok(!out.includes('<table'), 'table dropped (outside schema)');
ok(!out.includes('<iframe'), 'iframe dropped (outside schema)');
ok(!/style=/.test(out), 'inline style attribute dropped');

// --- Image node survives its OWN round-trip (so PHP can convert it back) ---
const imgOnly = roundTrip('<img data-tallyst-image data-id="7" data-size="medium" src="/y.jpg" alt="A">');
ok(imgOnly.includes('data-id="7"') && imgOnly.includes('data-tallyst-image'), 'standalone image node round-trips');

// --- Toolbar gating: the form button shows only when FormBuilder is enabled ---
ok(editorToolbarExtensions(['media', 'form_builder']).some((t) => t.label.includes('Forma')),
    'form toolbar button present when form_builder enabled');
ok(!editorToolbarExtensions(['media']).some((t) => t.label.includes('Forma')),
    'form toolbar button hidden when form_builder disabled');

// --- Multi-column layout (Prolaz C): pure HTML node, round-trips with nested embeds ---
// 2 columns, equal, survive load->save with the wrapper + data-columns intact.
const twoCol = roundTrip(
    '<div class="tallyst-columns" data-columns="2">'
    + '<div class="tallyst-column"><p>Lijevo</p></div>'
    + '<div class="tallyst-column"><p>Desno</p></div></div>'
);
ok(/class="tallyst-columns"/.test(twoCol) && /data-columns="2"/.test(twoCol), '2-column wrapper + count round-trips');
ok((twoCol.match(/class="tallyst-column"/g) || []).length === 2, 'exactly two columns preserved');
ok(twoCol.includes('Lijevo') && twoCol.includes('Desno'), 'column text preserved');

// 3 columns.
const threeCol = roundTrip(
    '<div class="tallyst-columns" data-columns="3">'
    + '<div class="tallyst-column"><p>A</p></div>'
    + '<div class="tallyst-column"><p>B</p></div>'
    + '<div class="tallyst-column"><p>C</p></div></div>'
);
ok(/data-columns="3"/.test(threeCol) && (threeCol.match(/class="tallyst-column"/g) || []).length === 3,
    '3-column layout round-trips with three columns');

// NESTING: an [image] node in one column and a [form] embed in another both survive in
// place (the converters run over the whole HTML, so nested embeds still convert in PHP).
const colEmbeds = roundTrip(
    '<div class="tallyst-columns" data-columns="2">'
    + '<div class="tallyst-column"><img data-tallyst-image data-id="5" data-size="medium" src="/x.jpg" alt="S"></div>'
    + '<div class="tallyst-column"><div data-tallyst-form data-id="2" data-label="Kontakt">📋 Forma: Kontakt</div></div>'
    + '</div>'
);
ok(/class="tallyst-columns"/.test(colEmbeds), 'columns wrapper survives with nested embeds');
ok(colEmbeds.includes('data-tallyst-image') && colEmbeds.includes('data-id="5"'), 'image node inside a column preserved');
ok(colEmbeds.includes('data-tallyst-form') && colEmbeds.includes('data-id="2"'), 'form embed inside a column preserved');

// MALFORMED: nested columns are forbidden in v1 — ProseMirror lifts the inner columns out,
// so no columns ever ends up inside a column.
const nested = roundTrip(
    '<div class="tallyst-columns" data-columns="2">'
    + '<div class="tallyst-column">'
    + '<div class="tallyst-columns" data-columns="2"><div class="tallyst-column"><p>X</p></div><div class="tallyst-column"><p>Y</p></div></div>'
    + '</div>'
    + '<div class="tallyst-column"><p>B</p></div></div>'
);
ok(!/tallyst-column"[^>]*>\s*<div class="tallyst-columns"/.test(nested), 'no columns nested inside a column (inner lifted out)');
ok(nested.includes('X') && nested.includes('Y') && nested.includes('B'), 'content from a malformed nested layout is kept (not dropped)');

console.log(`tiptap_roundtrip: ${passed} assertions passed`);
console.log('--- round-tripped HTML ---\n' + out);
