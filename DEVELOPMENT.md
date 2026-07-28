# Ontwikkelaarsgids — mk-cart-popup

## Opzet van de ontwikkelomgeving

**Ontwikkel nooit rechtstreeks in de `plugins/`-map van een klant-site.** Bewaar de plugin als zelfstandige repo en installeer hem in een aparte test-WordPress.

### Dev-modus inschakelen

Het **"Voor ontwikkelaars"**-tabje in de documentatie is op klant-sites verborgen. Voeg dit toe aan `wp-config.php` om het zichtbaar te maken op jouw eigen ontwikkelomgeving:

```php
define( 'MKCP_DEV', true );
```

Zonder deze constante ziet een klant-admin de documentatie zonder ontwikkelaarsinhoud.

Aanbevolen structuur in Laragon:
```
c:\laragon\www\
├── mk-cart-popup\          ← de plugin-repo (standalone)
└── test-wp\                ← lege WordPress voor plugin-development
    └── wp-content\plugins\
        └── mk-cart-popup\  ← symlink of kopie van bovenstaande repo
```

---

## Git-branches

| Branch | Doel |
|--------|------|
| `main`  | Stabiele releases — wat klanten ontvangen via de auto-updater |
| `dev`   | Lopende ontwikkeling — hier worden features gebouwd en getest |

**Werkwijze:** ontwikkel op `dev`, merge naar `main` pas bij een officiële release.

---

## Bestandsstructuur

```
mk-cart-popup/
├── .github/
│   └── workflows/
│       └── release.yml       ← GitHub Actions: bouwt zip + publiceert release automatisch
├── admin/
│   ├── assets/
│   │   ├── builder.js        ← gegenereerd door Vite (niet handmatig bewerken)
│   │   ├── builder.css       ← directe CSS, geen build-stap
│   │   ├── settings.js       ← directe JS voor de instellingenpagina, geen build-stap
│   │   ├── settings.css      ← directe CSS voor de instellingenpagina, geen build-stap
│   │   └── checkout.js       ← directe JS voor de Cart Checkout admin, geen build-stap
│   ├── views/
│   │   └── settings-page.php ← alle admin-tabs (één <form>, tabs zijn CSS-toggle, geen losse forms)
│   ├── settings.php          ← save-handlers (volledig formulier + builder quick-save AJAX) + asset-enqueue
│   ├── checkout-settings.php ← mkcp_checkout_config() — defaults + sanitizing voor checkout-instellingen
│   ├── scaffold.php          ← genereert/detecteert de child-theme scaffold-bestanden
│   └── docs.php              ← in-plugin documentatiepagina (klant + "Voor ontwikkelaars"-tabblad)
├── includes/
│   ├── checkout-frontend.php ← custom checkout-template, header/footer/stappen, BTW-switch, postcode-
│   │                            checker-koppeling, en de "Theme hooks/CSS uitschakelen"-sweep (zie hieronder)
│   ├── delivery-date.php     ← bezorgdatum-kiezer (premium): beschikbare data, AJAX-fragment, meta-opslag
│   ├── pickup.php            ← afhaallocaties/tijdvakken (premium): koppeling aan local_pickup rate-id's
│   ├── pickup-ready.php      ← afhaalmeldingen (premium): "klaar om op te halen"-knop op de bestelpagina, e-mail/sms
│   ├── shipping-choice.php   ← Ophalen/Bezorgen-keuzekaarten (premium): wc_get_template-override
│   ├── thankyou.php          ← next-level bedankpagina (premium): banner, cross-sell, factuur-downloadknop
│   └── abandoned-cart.php    ← herinneringsmail bij verlaten winkelmand (premium): cron + tracking-tabel
├── templates/
│   ├── cart-popup.php            ← HTML van de cart-drawer (ook WC-fragment)
│   ├── checkout-page.php         ← custom checkout-pagina-template (header/footer/stappen, zie checkout-frontend.php)
│   └── cart-shipping-choice.php  ← vervangt WooCommerce's cart/cart-shipping.php (zie shipping-choice.php)
├── config.php                 ← mkcp_config() / mkcp_checkout_config()-lezers, content-builder-renderers, USP-iconen
├── license.php                ← twee-tier licentievalidatie (remote endpoint + 24u cache)
├── src/
│   ├── admin/
│   │   └── builder/
│   │       ├── icons.js      ← alle SVG-iconen (hier aanpassen, nooit in index.js)
│   │       └── index.js      ← alle builder-logica (hoofd-entry voor Vite)
│   └── scss/
│       ├── cart-popup.scss   ← bronbestand popup-stylesheet → compileert naar assets/cart-popup.css
│       ├── checkout.scss     ← bronbestand checkout-stylesheet → compileert naar assets/checkout.css
│       ├── delivery-date.scss ← bronbestand bezorgdatum/afhalen-widget → compileert naar assets/delivery-date.css
│       └── shipping-choice.scss ← bronbestand Ophalen/Bezorgen-kaarten → compileert naar assets/shipping-choice.css
├── updater/
│   └── updater.php           ← GitHub-gebaseerde auto-updater
├── mk-cart-popup.php         ← hoofd-pluginbestand (versienummer in header + MKCP_VER)
├── mk-cart-popup-update.json ← update-manifest op main — wordt automatisch bijgewerkt door de Action
└── DEVELOPMENT.md
```

### Let op — de checkout dequeue-sweep (`includes/checkout-frontend.php`)

Met **"Theme hooks uitschakelen"** aan verwijdert de plugin via PHP Reflection élk hook-callback waarvan het bronbestand in het (child) thema staat — behalve de scaffold-map `mk-cart-popup/`. Dit gebeurt op de `wp`-actie, die **niet** vuurt tijdens `wc-ajax`-requests (WooCommerce's checkout-AJAX-endpoint sluit af via `exit` vóór `wp` ooit gestart wordt).

Praktisch gevolg: als een thema-hook op een volledige page-load verantwoordelijk is voor het renderen van een HTML-anker (bijv. de verzendmethode-container), en die hook wordt gesweept, dan bestaat dat anker nergens meer in de eerste HTML — en de latere AJAX-fragment-update (die wél gewoon blijft werken, want die slaat de sweep over) doet dan niets: WooCommerce's core-JS doet `$(selector).replaceWith(html)`, en een selector die niets matcht vervangt niets. Resultaat: content die op een test-omgeving prima werkt, verdwijnt stil zodra een klant deze instelling aanzet — zonder foutmelding.

**Regel:** elke plugin-feature die HTML op de checkout rendert via een hook die (mogelijk) in het thema leeft, moet een eigen fallback-render in de plugin hebben die detecteert of de content al elders is gerenderd (zie `includes/shipping-choice.php`'s gebruik van de `woocommerce_before_template_part`-hook als voorbeeldpatroon) — niet aannemen dat het thema het wel zal doen.

### Vuistregel: SCSS voor frontend, directe CSS voor admin

| Context | Aanpak | Reden |
|---------|--------|-------|
| **Frontend** (popup, checkout) | SCSS → gecompileerde CSS | Complexe stylesheets met veel componenten — SCSS nesting en variabelen houden het overzichtelijk |
| **Admin / backend** | Directe CSS | Kleinere, eenvoudigere bestanden die zelden veranderen — een build-stap is overkill |

### Welke bestanden hebben een build-stap?

| Bestand | Build-stap? | Aanpassen in |
|---------|-------------|--------------|
| `admin/assets/builder.js` | Ja — Vite/Terser | `src/admin/builder/index.js` |
| `admin/assets/builder.css` | Nee | Direct bewerken |
| `admin/assets/settings.js` | Nee | Direct bewerken |
| `admin/assets/settings.css` | Nee | Direct bewerken |
| `admin/assets/checkout.js` | Nee | Direct bewerken |
| `assets/cart-popup.css` | Ja — Sass | `src/scss/cart-popup.scss` |
| `assets/checkout.css` | Ja — Sass | `src/scss/checkout.scss` |

---

## CSS — Sass build-stap

De frontend-stylesheets (`cart-popup.css` en `checkout.css`) worden gegenereerd vanuit SCSS-bronbestanden in `src/scss/`. Bewerk nooit de `.css`-bestanden rechtstreeks — wijzigingen gaan verloren bij de volgende build.

### Vereisten

- [Node.js](https://nodejs.org) v18 of hoger (wordt ook gebruikt door Vite)
- npm (meegeleverd met Node.js)
- Dependencies eenmalig installeren: `npm install`

### Commando's

| Commando | Wanneer |
|---|---|
| `npm run build:css` | Eenmalige gecomprimeerde build van alle SCSS |
| `npm run dev:css` | Watch-modus: hercompileert direct bij elke opslag |
| `npm run build` | Volledige build: SCSS + Vite JS in één keer |

**Watch-modus starten:**
```bash
npm run dev:css
```
Sla een `.scss`-bestand op → de bijbehorende `.css` in `assets/` wordt direct bijgewerkt. Doe daarna een hard refresh (`Ctrl+Shift+R`) in de browser.

### Bronbestanden

**`src/scss/cart-popup.scss`**
Alle stijlen voor de winkelwagen-popup. Bevat:
- CSS custom properties (design tokens) in `:root`
- SCSS nesting met `&`-syntax (BEM-modifiers, hover-states, responsive breakpoints)
- Componenten gegroepeerd per sectie (drawer, items, footer, toasts, cross-sell, etc.)

**`src/scss/checkout.scss`**
Alle stijlen voor de distraction-free checkout. Bevat:
- SCSS-variabelen (`$accent`, `$text`, `$gray-mid`, etc.) voor gebruik binnen dit bestand
- `@mixin label-floated` — de floating-label animatie (gebruikt in `.has-value` en `.is-focused`)
- Geneste regels gegroepeerd onder `body.mkcp-distraction-free-checkout`
- Grid-posities voor formuliervelden (postcode, huisnummer, telefoon, e-mail, straat, stad)

### Hoe voeg je een nieuwe stijlregel toe?

1. Open het juiste `.scss`-bestand in `src/scss/`
2. Voeg de regel toe op de juiste plek (volg de bestaande sectie-indeling)
3. Sla op — bij `npm run dev:css` wordt de CSS direct hercompileert
4. Controleer in de browser met `Ctrl+Shift+R`
5. Vóór een release: `npm run build:css` voor de gecomprimeerde output

---

## Thema-overrides (scaffold bestanden)

Via het tabje **Theme Overrides** in de plugin-instellingen kan de plugin automatisch scaffold-bestanden aanmaken in de `mk-cart-popup/`-map van het actieve (child) thema. Deze bestanden worden door de plugin automatisch geladen — er zijn geen `functions.php`-aanpassingen nodig.

### Aangemaakt bestand

```
child-theme/
└── mk-cart-popup/
    ├── style.scss         ← SCSS met design tokens — klant past hier kleuren/fonts aan
    ├── style.css          ← gecompileerde output — auto-geladen na de plugin-CSS
    ├── checkout.scss      ← checkout SCSS bron — klant compileert naar checkout.css
    ├── checkout.css       ← gecompileerde checkout output — auto-geladen (premium)
    ├── cart-hooks.php          ← algemene popup hooks — auto-geladen bij plugins_loaded
    └── checkout-cart-hooks.php ← checkout-specifieke hooks — auto-geladen bij plugins_loaded
```

### Wat doet elk bestand?

| Bestand | Doel | Wie past aan? |
|---------|------|---------------|
| `style.scss` | CSS custom properties overschrijven (accentkleur, breedte, etc.) | Klant / developer |
| `style.css` | Gecompileerde output van `style.scss` — dit wordt geladen | Gegenereerd door Sass |
| `checkout.scss` | Checkout-specifieke stijlen overschrijven | Klant / developer |
| `checkout.css` | Gecompileerde output van `checkout.scss` | Gegenereerd door Sass (premium) |
| `cart-hooks.php` | PHP hooks voor de popup (bijv. extra knoppen, acties) | Developer |
| `checkout-cart-hooks.php` | PHP hooks voor de checkout (bijv. extra velden, filters) | Developer |

### Vuistregel voor thema-CSS

De thema-bestanden volgen dezelfde conventie als de plugin zelf: **SCSS is het bronbestand, CSS is de gecompileerde output**. De klant (of developer) is zelf verantwoordelijk voor het compileren van `style.scss` → `style.css` en `checkout.scss` → `checkout.css`. De plugin laadt altijd de `.css`-bestanden — nooit de `.scss`.

> **Aanbeveling:** gebruik een child-thema, niet het actieve thema zelf. Bij een thema-update worden thema-bestanden overschreven. De plugin waarschuwt hiervoor als er geen child-thema actief is.

### Hoe worden de bestanden geladen?

De plugin controleert bij elke paginalading of de bestanden bestaan en laadt ze in de juiste volgorde:

1. Plugin-CSS (`assets/cart-popup.css` / `assets/checkout.css`)
2. Thema-CSS (`mk-cart-popup/style.css` / `mk-cart-popup/checkout.css`) — overschrijft de plugin-CSS
3. PHP hooks (`cart-hooks.php` / `checkout-cart-hooks.php`) — via `plugins_loaded`

---

## Builder — Vite build-stap

De Content Builder gebruikt Vite om bronbestanden samen te voegen en te minificeren.

### Vereisten

- [Node.js](https://nodejs.org) v18 of hoger
- npm (meegeleverd met Node.js)

```bash
node --version
npm --version
```

### Installeer dependencies (eenmalig)

```bash
npm install
```

### Bouwen

**Eenmalige build** (gebruik dit vóór een release):
```bash
npm run build
```

**Watch-modus** (gebruik dit tijdens het ontwikkelen):
```bash
npm run dev
```

### Bronbestanden

**`src/admin/builder/icons.js`**
Alle SVG-icoonstrings als named exports, gegroepeerd in:
- `ICON` — popup UI-iconen (winkelwagen, pijl, sluiten, etc.)
- `ICON_CS_*` — cross-sell slider (plus, vorige, volgende)
- `ICON_SHARE`, `ICON_CHEVRON`, `ICON_LINK`, `ICON_SEND` — deel-sectie

Iconen hier toevoegen of aanpassen, nooit rechtstreeks in `index.js`.

**`src/admin/builder/index.js`**
Alle builder-logica. Functies zijn gegroepeerd:

| Groep | Functies |
|---|---|
| Bootstrap | `init()`, `bindEvents()` |
| Config | `readLiveConfig()` |
| Preview | `schedulePreview()`, `refreshPreview()`, `buildPopupHtml()` |
| Preview templates | `tplHeader()`, `tplBtwSwitch()`, `tplCrosssell()`, ... |
| Block zones | `renderZone()`, `initSortable()`, `initPreviewZoneDrop()` |
| Block editor | `openEditor()`, `saveEditor()`, `buildEditorFields()` |
| Inline editing | `initInlineEditing()` |
| Opslaan | `doSave()`, `markDirty()`, `showToast()` |
| Geschiedenis | `pushHistory()`, `undo()`, `redo()` |
| Hulpfuncties | `esc()`, `sel()`, `stripTags()` |

---

## Release — een nieuwe versie uitbrengen

De plugin heeft een ingebouwde auto-updater die `mk-cart-popup-update.json` van de `main`-branch op GitHub leest. WordPress-sites controleren dit bestand elke 6 uur.

### Stap voor stap

1. **Bump het versienummer** in `mk-cart-popup.php` — pas zowel de plugin-header (`Version:`) als de `MKCP_VER`-constante aan. Beide moeten identiek zijn.

2. **Ontwikkel en test op `dev`** — bouw alles met `npm run build` (compileert SCSS én Vite JS). Test op een eigen test-WordPress, nooit op een klantsite.

3. **Merge `dev` → `main`** en push naar GitHub.

4. **Publiceer een GitHub Release**:
   - Ga naar **github.com/mediakanjers/mk-cart-popup → Releases → Draft a new release**
   - Kies tag `v{versienummer}`, bijv. `v1.2.1`
   - Klik **Publish release**

5. **GitHub Action doet de rest automatisch** — de Action bouwt de plugin-zip, voegt hem toe aan de Release en werkt `mk-cart-popup-update.json` op `main` bij. Klanten ontvangen de update bij de volgende WordPress-updatecheck (max. 6 uur).

### Hoe werkt de updater?

`updater/updater.php` haalt `mk-cart-popup-update.json` op van:
```
https://raw.githubusercontent.com/mediakanjers/mk-cart-popup/main/mk-cart-popup-update.json
```
Als de versie in het JSON-bestand hoger is dan de geïnstalleerde versie, toont WordPress de bekende "Update beschikbaar"-melding in het pluginscherm.

---

## Licentiesysteem

De plugin gebruikt een eigen twee-tier licentiesysteem (`basic` / `premium`). Elke licentiesleutel wordt gevalideerd tegen een endpoint op `support.mediakanjers.nl`.

### Hoe werkt de validatie?

1. De site stuurt een POST naar het validatie-endpoint met de sleutel en het domein.
2. De licentiesleutel zelf is het enige credential — geen apart gedeeld geheim/HMAC in de plugin. De sleutel is al uniek per klant en willekeurig gegenereerd (`mk_gen_key()` in het license-dashboard), en op elk moment in te trekken/te vervangen.
3. De server (`validate.php`) checkt sleutel-status, vervaldatum en domein-match, en past rate limiting toe per IP/sleutel.
4. Een sleutel die op een ander domein wordt gebruikt dan waarvoor 'm is uitgegeven, wordt geweigerd én gelogd als "verdacht" (zichtbaar in het dashboard) — dat is het signaal dat een sleutel mogelijk gelekt is. De tegenmaatregel is intrekken + nieuwe sleutel uitgeven, niet een geheim opnieuw uitwisselen.
5. Het resultaat (`tier`, `valid`, `expires`) wordt 24 uur gecached in de WordPress-database.

### Endpoint

```
POST https://support.mediakanjers.nl/license/validate.php
```

Override voor een specifieke site (bv. lokaal testen tegen een andere licentieserver):

```php
// wp-config.php
define( 'MKCP_LICENSE_URL', 'https://…' );
```

### Sleutels beheren

Ga naar **support.mediakanjers.nl/wp-admin → Licentiebeheer**.

| Veld | Waarden | Toelichting |
|------|---------|-------------|
| `Tier` | `basic` of `premium` | Bepaalt welke features worden ontgrendeld |
| `Domein` | `domein.nl` of `*` | Zonder `https://` of `www.` — `*` voor elk domein |
| `Vervaldatum` | `YYYY-MM-DD` of leeg | Leeg = nooit verlopen |
| `Notitie` | vrije tekst | Klantnaam — alleen intern zichtbaar |

Het dashboard genereert automatisch een sleutel in het formaat `MK-BASIC-XXXX-XXXX-XXXX` of `MK-PREM-XXXX-XXXX-XXXX`. Geef deze door aan de klant — die vult hem in via **WooCommerce → Cart Popup → Licentie**.

### Sleutel intrekken of aanpassen

Ga naar **Licentiebeheer** op `support.mediakanjers.nl`. Deactiveer, pas de vervaldatum aan, of verwijder de sleutel. De plugin merkt dit bij de volgende cache-vervaldatum (standaard 24 uur). Direct doorvoeren: vraag de klant te klikken op **Licentie → Verifieer nu**.

### Tiers

| Tier | Gedrag |
|------|--------|
| `none` | Plugin geblokkeerd — rode banner, instellingen vergrendeld |
| `basic` | Basisinstellingen beschikbaar, premium-functies vergrendeld |
| `premium` | Alles ontgrendeld |

---

## Veelgestelde vragen

**Ik zie mijn wijzigingen in `settings.js` / `settings.css` niet.**
→ Deze bestanden hebben geen build-stap. Doe een hard refresh met `Ctrl+Shift+R`. Als het versienummer in `mk-cart-popup.php` niet veranderd is, kan de browser nog de oude versie cachen — bump dan tijdelijk `MKCP_VER`.

**Ik zie mijn SCSS-wijzigingen niet in de popup of checkout.**
→ Controleer of `npm run dev:css` draait (of draai `npm run build:css`). Daarna `Ctrl+Shift+R`. Let op: bewerk alleen de `.scss`-bestanden in `src/scss/` — de `.css`-bestanden in `assets/` worden overschreven bij elke build.

**Kan ik `assets/cart-popup.css` of `assets/checkout.css` direct bewerken?**
→ Technisch kan het, maar de wijziging gaat verloren bij de volgende `npm run build:css`. Pas altijd de bronbestanden in `src/scss/` aan.

**Ik zie mijn builder-wijzigingen niet.**
→ Controleer of `npm run dev:js` draait (of draai `npm run build:js`). Daarna `Ctrl+Shift+R`.

**Kan ik `admin/assets/builder.js` direct bewerken?**
→ Technisch kan het, maar de wijziging gaat verloren bij de volgende build. Pas altijd de bronbestanden in `src/` aan.

**Moet `node_modules/` meegestuurd worden bij een release?**
→ Nee, die staat in `.gitignore`. De gebouwde bestanden (`admin/assets/builder.js`, `assets/cart-popup.css`, `assets/checkout.css`) zitten wél in de release-zip.

**Hoe voeg ik een nieuwe builder-module toe?**
1. Maak `src/admin/builder/mijn-module.js` aan met `export function ...`
2. Importeer bovenaan `index.js`: `import { mijnFunctie } from './mijn-module.js'`
3. Gebruik de functie in de IIFE in `index.js`
4. Run `npm run build`

---

## Toekomstige splits (nog te doen)

`index.js` is nu ~1200 regels. Verdere splits zijn gepland:

- `src/admin/builder/templates.js` — alle `tpl*`-functies
- `src/admin/builder/block-editor.js` — `openEditor`, `saveEditor`, `buildEditorFields`
- `src/admin/builder/block-zones.js` — `renderZone`, `initSortable`, `initPreviewZoneDrop`
- `src/admin/builder/persistence.js` — `doSave`, `markDirty`, `showToast`
- `src/admin/builder/inline-editing.js` — `initInlineEditing`

Elke split volgt hetzelfde patroon: functies exporteren uit de nieuwe module, importeren in `index.js`.
