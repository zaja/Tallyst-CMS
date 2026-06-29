# Dijagnoza: Display tipovi — EDITOR strana (mehanika)

> v1.3.0 "Landing & tipografija". Ovo je DIJAGNOZA editor-mehanike. Vizual (skala/dramatika)
> = Tema v2 (uz mockup), NE zakucavati sad. Bez implementacije, migracije, commita.

## A) Kako je heading trenutno strukturiran u Editoru v2

**Schema / extension setup** — `modules/Media/assets/tiptap_extensions.js:42-50`:

```js
StarterKit.configure({
    document: false,
    heading: { levels: [1, 2, 3, 4] },
    link: { ... },
}),
TextAlign.configure({ types: ['heading', 'paragraph'] }),
```

Heading dolazi iz **StarterKit-ovog ugrađenog Heading nodea**. Konfiguriran je SAMO s
`levels: [1,2,3,4]`. StarterKit Heading node po defaultu nosi **jedan jedini atribut: `level`**
— nema `class`, nema `data-*`. Sve izvan sheme ProseMirror tiho odbacuje na load (komentar
`tiptap_extensions.js:16-17`, dokazano round-trip testom).

**Dropdown UI** — `tiptap_widget.html.twig:24-35`: jedan `.tiptap__dropdown` s 5 stavki
(Paragraph + H1–H4), svaka nosi `data-level="0..4"` i `data-action="media--tiptap#setHeading"`.

**Akcija** — `tiptap_controller.js:181-186`:
```js
setHeading(event) {
    const level = Number(event.currentTarget.dataset.level);
    const chain = this.editor.chain().focus();
    (0 === level ? chain.setParagraph() : chain.setHeading({ level })).run();
    this.closeDropdowns();
}
```
Čista razina — `setHeading({ level })`. Nema atributa.

**markActive** (sinkronizacija aktivne stavke pri otvaranju) — `tiptap_controller.js:150-154`:
za svaki `[data-level]` provjerava `editor.isActive('heading', { level })`. Samo razina.

**Serijalizacija u bazu** — `tiptap_controller.js:109-111`: `sync()` piše `editor.getHTML()` u
skriveni textarea. Pohrana je sirovi HTML (`<h1>…</h1>`), isti `content` stupac, bez sheme/migracije.

**Render na frontu** — `themes/default/templates/page.html.twig:54`: `{{ page.content|render_content }}`.
`render_content` (`src/Content/ContentRenderer.php:18-50`) pokreće SAMO regex shortcode registra preko
`[tag …]` uzoraka — headinge ne dira. Twig filter je `is_safe: ['html']` (`src/Twig/ContentExtension.php:22`)
→ ispis je **`|raw`, bez ikakve server-side HTML sanitizacije / allowliste**.

### Ključan nalaz za A
> **Trenutni heading mehanizam podržava SAMO razinu (h1–h4), NE atribut/klasu.** StarterKit Heading
> nema `class` atribut, a ProseMirror schema tiho briše svaki neprepoznati atribut na load-u. **Jedina
> "allowlista" koja postoji u cijelom lancu jest sama ProseMirror schema** (editor strana) — na serveru
> NEMA HTML allowliste (front ispisuje `|raw`). Injection-safe garancija iz CLAUDE.md za image resize
> vrijedi za **shortcode atribute** (`[image size align width]` — whitelistani u PHP-u), a heading
> **nije shortcode** → ta garancija se NE odnosi na njega. Za displaye, kontrola se seli na schemu.

---

## B) Dva mehanizma — procjena

### (A) H1 + atribut/klasa — implementiran kao **proširenje Heading nodea jednim atributom**

Ne "H1 + slobodna klasa", nego: proširi StarterKit Heading da nosi opcionalni `display` atribut koji se
serijalizira **isključivo** kao fiksni `class="display-1"` / `display-2`.

```js
// zamijeni heading:false u StarterKit + dodaj prošireni Heading,
// ili Heading.extend({ addAttributes }) povrh StarterKitovog
addAttributes() {
  return {
    ...this.parent?.(),          // zadrži `level`
    display: {
      default: null,
      // parseHTML čita SAMO display-1 / display-2 (fiksni skup) → broj
      parseHTML: el => { const m = el.className.match(/\bdisplay-([12])\b/); return m ? Number(m[1]) : null; },
      // renderHTML emitira SAMO display-N, ništa drugo
      renderHTML: a => a.display ? { class: `display-${a.display}` } : {},
    },
  };
}
```

- **Serijalizacija:** `<h1 class="display-1">…</h1>`. Preživljava save/reload jer smo schemu naučili
  čuvati taj atribut (isti princip kao `count` na columns nodeu, `tiptap_columns_node.js:60-68`, ili
  `size/align/width` na image nodeu).
- **render_content:** ne dira ga (nije shortcode) → prolazi netaknut.
- **Front sanitizacija/allowlist:** nema je na serveru (`|raw`), pa klasa stiže do `<h1>`.
  **Injection-safe je očuvan jer parseHTML/renderHTML čine fiksni allowlist** (`display-1`/`display-2`
  ↔ broj 1/2) — proizvoljna klasa se ne može autorirati ni perzistirati; samo fiksni skup round-trippa.
  Ista garancija kao kod shortcode size/align, samo provedena na razini sheme umjesto PHP whitelista.
- **Opseg:** ~1 atribut na Heading + 2 dropdown stavke + grana u `setHeading`/`markActive` + CSS klase
  u temi. Nema novog nodea, nema novog konvertera, nema migracije.

### (B) Zaseban Tiptap node (`displayHeading`)

Vlastiti node tip s `name: 'displayHeading'`, vlastiti `parseHTML`/`renderHTML`, vlastiti group/content,
vlastite naredbe (`setDisplayHeading`), zaseban unos u schemu (`tiptap_extensions.js`), zaseban tretman u
`markActive`, i — pošto round-trip test vozi pravu schemu — novi asserti.

- Funkcionalno ekvivalentno (`<h1 class="display-1">` se može emitirati i ovako), ali traži:
  - definiciju nodea + group/content pravila (da ne završi pogrešno unutar columna, slično razmatranju za
    `columns` group),
  - odluku kako se ponaša prema TextAlign-u (`types` lista bi trebala uključiti i njega),
  - više round-trip pokrivenosti.
- **Strogo veći** od (A) bez funkcionalne dobiti — display JE semantički heading (h1 s naglašenom
  tipografijom), ne novi blok-tip.

### Preporuka: **(A) — proširi Heading jednim `display` atributom**

1. **"Simple is the product."** Display je vizualno naglašen H1, ne nova vrsta sadržaja. Atribut na
   postojećem nodeu izražava točno to.
2. **Najmanji round-trip rizik** — koristi isti, već dokazani obrazac kao `columns.count` i
   `image.size/align/width` (parseHTML/renderHTML par povrh postojećeg nodea).
3. **Nula dodira u konverter lanac i front** — render_content i tema su agnostični; samo se pojavljuje
   nova CSS klasa.
4. **Semantika SEO ostaje čista** — `<h1>` ostaje `<h1>` (bitno uz `hideTitle` landing scenarij, gdje je
   sadržajni H1 jedini h1 na stranici).
5. **Bez migracije** — pohrana je sirovi HTML, kao i sad.

---

## C) Dropdown integracija

Postojeći heading dropdown (`tiptap_widget.html.twig:28-34`) dobiva 2 nove stavke uz H1–H4. Pošto
`setHeading` čita samo `data-level`, proširi ga da pročita i opcionalni `data-display`:

```html
<button … data-level="1" data-display="1" data-action="media--tiptap#setHeading">Display 1</button>
<button … data-level="1" data-display="2" data-action="media--tiptap#setHeading">Display 2</button>
```

```js
setHeading(event) {
  const level = Number(event.currentTarget.dataset.level);
  const display = event.currentTarget.dataset.display ? Number(event.currentTarget.dataset.display) : null;
  const chain = this.editor.chain().focus();
  (0 === level ? chain.setParagraph() : chain.setHeading({ level, display })).run();
  this.closeDropdowns();
}
```

**Ne razbija postojeće:** H1–H4 stavke nemaju `data-display` → `display=null` → identično dosadašnjem
(renderHTML ne emitira klasu kad je null). `markActive` (`:150-154`) dopuniti: Display 1/2 aktivne tek
kad je `heading level=1` **i** `display === N` (inače bi i plain H1 i Display 1 bili "aktivni" jer su oba
level 1). Trivijalna dopuna iste petlje.

**i18n:** nove labele u `modules/Media/translations/admin.{en,hr}.yaml` pod `tiptap:` (linija 31), npr.
`admin.media.tiptap.display1` / `display2` (uz postojeće `h1`–`h4` na :37-40). Dropdown je čisti Twig
`|trans({}, 'admin')`, JS ne sastavlja string → **nema JS-i18n shima** (ista situacija kao H1–H4 danas).

---

## D) Round-trip rizik

**Kako round-trip radi danas:** editor učita `content` (HTML) → ProseMirror parsira u doc po schemi (sve
izvan sheme se BRIŠE) → `getHTML()` natrag u textarea na svaku promjenu i jednom na connect
(`tiptap_controller.js:39,48,109`). Front: `render_content` (samo shortcodi) → `|raw`. Reload: isti HTML
natrag u editor.

**Gdje display može puknuti:** točno na jednom mjestu — **ako schema ne čuva `class` na headingu,
ProseMirror je obriše na load** i `display-1` nestane nakon prvog save→reload ciklusa (failure mode iz
CLAUDE.md: "ProseMirror SILENTLY DROPS anything outside the schema"). Rješenje je upravo
`addAttributes({ display })` s parseHTML/renderHTML — onda klasa preživi jer je u schemi.

**Sekundarni rizik:** `clearFormatting` (`:118`) radi `clearNodes()` → vraća na paragraph, gubi i display
— to je ISPRAVNO ponašanje (clear briše display), ne bug.

**Veza s 34 JS round-trip testa** (`modules/Media/tests/js/tiptap_roundtrip.test.mjs`): test vozi PRAVU
`buildExtensions()` schemu kroz `generateJSON→generateHTML` (`:31-33`). Trenutno provjerava
`heading preserved` samo regexom `/<h2[ >]/` (:55) i `text-align` na headingu (:100) — **display klasa
NIJE pokrivena**. Pri implementaciji obavezno dodati assert: `<h1 class="display-1">` → save→load → klasa
preživi (i da plain `<h1>` ostaje bez klase). Glavni "lock" protiv drop-rizika; mora ići u istu datoteku.

---

## E) Što tema MORA izložiti (🚩 flag: Tema v2)

Editor-mehanika emitira samo marker; izgled je 100% u temi. Tema mora pružiti CSS kuke:

- `.display-1` i `.display-2` (i `.display-3` ako se ide na 3) u `themes/default/public/css/theme.css`.
- Te klase su **jedini ugovor** između editora i teme (kao `.tallyst-columns`/`.tallyst-column` theme
  contract iz CLAUDE.md). Bez njih display degradira u običan `<h1>` (graciozno — nije lom).

> 🚩 **VIZUAL = TEMA v2.** NE definirati skalu (font-size, weight, letter-spacing, line-height, dramatiku)
> sad u staru `theme.css` — isti razlog kao hero CSS: Tema v2 ionako prerađuje theme.css, pa bi svaka
> skala upisana sad bila prepravljena. Editor-mehanika (atribut + dropdown + round-trip + i18n) može i
> treba ići sad i biti potpuno funkcionalna; klase samo postoje, a njihov *izgled* se dizajnira uz mockup
> Teme v2. Editor preview u `tiptap.css` (da WYSIWYG odgovara frontu) također ide uz Temu v2.

---

## F) Koliko Display razina?

**Ne odlučujem.** Ali: broj (2 vs 3) **NE dotiče arhitekturu**. Posljedice su čisto aditivne:
- +1 dropdown stavka (`data-display="3"`),
- parseHTML regex `display-([12])` → `([123])`,
- +1 CSS klasa `.display-3` (Tema v2).

Nema utjecaja na node model, konverter, migraciju ni round-trip mehaniku. Odluka o broju može pasti kasno,
čak uz Temu v2 kad se vidi vizualna skala.

---

## Plan editor-mehanike po koracima (rizik)

1. 🟢 **Proširi Heading `display` atributom** (parseHTML `display-N` ↔ broj, renderHTML samo
   `class="display-N"`, fiksni allowlist 1–2). U `tiptap_extensions.js` — ili `heading:false` + zaseban
   `Heading.extend(...)`, ili `.extend` povrh StarterKitovog. Bez migracije.
2. 🟢 **Dropdown stavke + `setHeading` grana** (`data-display`), `markActive` dopuna (level=1 ∧ display===N).
3. 🟢 **i18n** `admin.media.tiptap.display1/2` (en+hr).
4. 🟢 **Round-trip assert** u `tiptap_roundtrip.test.mjs` (display klasa preživi; plain H1 ostaje čist).
5. 🟢 **Prazne CSS kuke `.display-1/2`** u temi (placeholder, bez dizajna) — ili potpuno odgoditi izgled
   za Temu v2.
6. 🟢 **`asset-map:compile`** (obavezno nakon JS izmjena) + vizualni smoke (na korisniku).

Cijeli posao je 🟢 — nizak rizik, slijedi već dokazane obrasce (columns.count / image atributi), ne dira
ni jedan PHP/konverter/render put.

---

## Otvorena pitanja

1. **Marker: `class="display-N"` vs `data-display="N"`?** Preporuka `class` — standardni CSS-kuka idiom
   (Bootstrap `.display-1`), tema ga koristi izravno bez `[data]` selektora. (Round-trip isti u oba slučaja.)
2. **Implementacija atributa: `heading:false` + zaseban `Heading.extend` vs `.extend` povrh StarterKit
   konfiguriranog Headinga?** Sitan tehnički izbor; oba rade — odlučiti pri implementaciji (provjeriti
   čuva li StarterKit pristup `this.parent()` za `level`).
3. **Ponaša li se Display kao zaseban "blok" u align dropdownu?** Pošto je i dalje heading, TextAlign već
   radi na njemu (`types: ['heading',…]`) — vjerojatno OK, ali smoke da centriran Display 1 round-trippa
   (text-align + display klasa zajedno).
4. **Broj razina (2/3)** — F: ne dotiče kod, može odlučiti uz Temu v2.

**OUT OF SCOPE (potvrđeno):** implementacija, vizualna skala (Tema v2), font-size/color (odbačeno), ostali
editor featurei. Vizualni smoke je na korisniku.
