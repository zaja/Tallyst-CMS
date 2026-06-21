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
import { buildExtensions } from '../../assets/tiptap_extensions.js';

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
ok(out.includes('[form id=2]'), '[form id=2] survives as text');
ok(out.includes('data-tallyst-image') && out.includes('data-id="5"'), 'image node + data-id preserved');

// --- Trix <div> soup normalised to semantic <p> ---
ok(out.includes('<p>'), 'div normalised to paragraph');
ok(!out.includes('<div'), 'no <div> remains after normalisation');

// --- SILENT DROPS (documented + asserted) ---
ok(!out.includes('<table'), 'table dropped (outside schema)');
ok(!out.includes('<iframe'), 'iframe dropped (outside schema)');
ok(!/style=/.test(out), 'inline style attribute dropped');

// --- Image node survives its OWN round-trip (so PHP can convert it back) ---
const imgOnly = roundTrip('<img data-tallyst-image data-id="7" data-size="medium" src="/y.jpg" alt="A">');
ok(imgOnly.includes('data-id="7"') && imgOnly.includes('data-tallyst-image'), 'standalone image node round-trips');

console.log(`tiptap_roundtrip: ${passed} assertions passed`);
console.log('--- round-tripped HTML ---\n' + out);
