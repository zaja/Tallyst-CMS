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
// Non-text-align inline styles are still dropped (the `color:red` div above carries no
// text-align, so the whole style attribute is gone). text-align is the ONE style now
// preserved (TextAlign extension) — asserted in its own block below.
ok(!/style=/.test(out), 'non-text-align inline style (color) dropped');

// --- Image node survives its OWN round-trip (so PHP can convert it back) ---
const imgOnly = roundTrip('<img data-tallyst-image data-id="7" data-size="medium" src="/y.jpg" alt="A">');
ok(imgOnly.includes('data-id="7"') && imgOnly.includes('data-tallyst-image'), 'standalone image node round-trips');
ok(!imgOnly.includes('data-width'), 'an image without a width attribute stays width-less (default normal)');

// --- Per-image full width survives the round-trip ---
const fullImg = roundTrip('<img data-tallyst-image data-id="8" data-width="full" src="/z.jpg" alt="B">');
ok(fullImg.includes('data-width="full"') && fullImg.includes('data-id="8"'), 'full-width image round-trips (data-width preserved)');

// --- Link target/rel survive the round-trip (Group 3 "open in new tab") ---
// A link with target="_blank" keeps target + rel; a plain link stays clean (no forced _blank),
// so existing links aren't rewritten and the new-tab toggle can be turned off.
const newTabLink = roundTrip('<p><a href="/o-nama" target="_blank" rel="noopener noreferrer">novi</a></p>');
ok(/target="_blank"/.test(newTabLink), 'link target=_blank survives the round-trip');
ok(/rel="noopener noreferrer"/.test(newTabLink), 'link rel=noopener survives alongside target');
const plainLink = roundTrip('<p><a href="/x">isti</a></p>');
ok(!/target=/.test(plainLink), 'a link without target stays clean (no forced _blank)');

// --- Text alignment (TextAlign extension) survives on paragraphs + headings ---
// Group 1 adds text-align; the schema now PRESERVES the text-align inline style (other
// styles like color stay dropped). Default 'left' renders no style; center/right/justify do.
const aligned = roundTrip('<p style="text-align: center">Sredina</p><h2 style="text-align: right">Desno</h2>');
ok(/<p[^>]*text-align:\s*center/.test(aligned), 'paragraph text-align:center survives');
ok(/<h2[^>]*text-align:\s*right/.test(aligned), 'heading text-align:right survives');
// A block with NO alignment attribute stays clean (no style) — only an explicit choice carries one.
const plainPara = roundTrip('<p>Obicni odlomak</p>');
ok(!/style=/.test(plainPara), 'a paragraph with no alignment renders no style (clean content)');

// --- Display headings (landing typography): the optional display attribute round-trips as a
// FIXED class display-1/display-2 on an <h1>; a plain heading stays clean; an out-of-allowlist
// class is dropped (the schema's fixed display-1/2 allowlist — same injection-safe guarantee as
// the image size/align/width attributes). #5 in the implementation plan: the main schema-drop lock.
const display1 = roundTrip('<h1 class="display-1">Veliki naslov</h1>');
ok(/<h1[^>]*class="[^"]*\bdisplay-1\b/.test(display1), 'Display 1 (h1.display-1) survives save->load');
const display2 = roundTrip('<h1 class="display-2">Drugi</h1>');
ok(/<h1[^>]*class="[^"]*\bdisplay-2\b/.test(display2), 'Display 2 (h1.display-2) survives save->load');
const plainH1 = roundTrip('<h1>Obican naslov</h1>');
ok(/<h1[ >]/.test(plainH1) && !/class=/.test(plainH1), 'a plain h1 stays clean (no display class)');
// A centered Display 1: text-align (TextAlign) AND the display class survive together.
const display1Centered = roundTrip('<h1 class="display-1" style="text-align: center">Sredina</h1>');
ok(/class="[^"]*\bdisplay-1\b/.test(display1Centered) && /text-align:\s*center/.test(display1Centered),
    'a centered Display 1 round-trips (text-align + display class together)');
// Out-of-allowlist classes (display-9, arbitrary) are NOT preserved — only display-1/2 round-trip.
const badClass = roundTrip('<h1 class="display-9 evil">X</h1>');
ok(!/class=/.test(badClass), 'an out-of-allowlist heading class (display-9/evil) is dropped (fixed display-1/2 allowlist)');

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

// --- Inline icon node (WYSIWYG [icon]): the marker round-trips, stays INLINE, name preserved ---
// buildExtensions() runs with no iconSet here → the NodeView degrades but renderHTML (the marker)
// is unaffected, so serialization is deterministic and testable. renderHTML emits ONLY the marker
// (name), never the SVG — the WYSIWYG display is a NodeView concern, not serialized.
const icon = roundTrip('<span data-tallyst-icon data-name="github"></span>');
ok(/data-name="github"/.test(icon) && /data-tallyst-icon/.test(icon), 'inline icon marker + name round-trip');
ok(!icon.includes('<svg'), 'serialized icon is the clean marker, not the SVG (NodeView is display-only)');

// INLINE position mid-sentence: the node must stay INSIDE the paragraph (inline:true), not split it.
const inlineIcon = roundTrip('<p>Prati nas <span data-tallyst-icon data-name="github"></span> danas</p>');
ok((inlineIcon.match(/<p>/g) || []).length === 1, 'inline icon stays in ONE paragraph (not split into blocks)');
ok(/data-name="github"/.test(inlineIcon) && inlineIcon.includes('Prati nas') && inlineIcon.includes('danas'),
    'inline icon + both surrounding text runs preserved in place');

// Unknown name survives gracefully (name preserved, no throw) — the front/NodeView degrade to empty.
const unknownIcon = roundTrip('<p>x <span data-tallyst-icon data-name="nepostoji"></span> y</p>');
ok(/data-name="nepostoji"/.test(unknownIcon), 'an unknown icon name round-trips (preserved, graceful)');

console.log(`tiptap_roundtrip: ${passed} assertions passed`);
console.log('--- round-tripped HTML ---\n' + out);
