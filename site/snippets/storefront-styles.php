<style>
    :root {
        color-scheme: light dark;
        --background: #f7f7f2;
        --surface: #fff;
        --soft: #f0f1eb;
        --text: #242d27;
        --muted: #656e66;
        --border: #dce0d7;
        --accent: #28583b;
        --accent-text: #fff;
        --error: #9b322a;
        --radius: 1rem;
        font-family: ui-sans-serif, system-ui, -apple-system, sans-serif;
        line-height: 1.5;
        background: var(--background);
        color: var(--text);
    }
    * { box-sizing: border-box; }
    body { margin: 0; }
    a { color: inherit; text-underline-offset: .2em; }
    button, input, select { font: inherit; }
    button, summary, select { cursor: pointer; }
    button, .button {
        display: inline-flex; align-items: center; justify-content: center;
        border: 1px solid transparent; border-radius: .5rem;
        padding: .65rem 1rem; min-height: 2.75rem;
        background: var(--accent); color: var(--accent-text);
        font-weight: 600; text-decoration: none;
    }
    button:hover, .button:hover { filter: brightness(.92); }
    button:disabled { opacity: .45; cursor: not-allowed; }
    input, select { border: 1px solid var(--border); border-radius: .5rem; padding: .65rem; min-width: 0; background: var(--surface); color: var(--text); }
    input[type="number"] { width: 5rem; }
    :focus-visible { outline: 3px solid var(--accent); outline-offset: 4px; }
    [tabindex="-1"]:focus:not(:focus-visible) { outline: none; }
    h1, h2, h3, p { margin: 0; }
    h1 { font-size: clamp(2rem, 4vw, 3.5rem); line-height: 1.08; letter-spacing: -.045em; }
    h2 { font-size: 1.2rem; line-height: 1.25; letter-spacing: -.02em; }
    h3 { font-size: 1rem; line-height: 1.35; }
    img { display: block; max-width: 100%; object-fit: cover; }
    code, pre { overflow-wrap: anywhere; white-space: pre-wrap; }
    [hidden] { display: none !important; }
    .skip-link { position: absolute; left: 1rem; top: -5rem; z-index: 10; padding: 1rem; background: var(--surface); }
    .skip-link:focus { top: 1rem; }
    .site-header, .store-context, .store-layout, .site-footer { width: min(100% - 4rem, 88rem); margin-inline: auto; }
    .site-header { display: flex; align-items: center; flex-wrap: wrap; gap: 1.5rem; padding-block: 1.5rem; }
    .brand { font-size: 1.35rem; font-weight: 750; text-decoration: none; margin-right: auto; letter-spacing: -.04em; }
    .brand span { display: block; font-size: .7rem; letter-spacing: .04em; font-weight: 500; color: var(--muted); }
    nav { display: flex; align-items: center; gap: 1.25rem; font-size: .85rem; }
    nav a { text-decoration: none; padding-block: .5rem; }
    nav a:hover { text-decoration: underline; }
    .languages { gap: .2rem; }
    .languages a { padding: .4rem .55rem; border-radius: .4rem; font-size: .75rem; }
    .languages [aria-current] { background: var(--text); color: var(--background); }
    .test-menu { position: relative; }
    .test-menu > summary { padding-block: .5rem; }
    .test-menu > div { position: absolute; right: 0; top: 100%; width: 15rem; z-index: 5; display: grid; padding: .5rem 1rem; border: 1px solid var(--border); border-radius: .5rem; background: var(--surface); box-shadow: 0 .5rem 2rem #0001; }
    .store-context { border-block: 1px solid var(--border); padding-block: .75rem; display: flex; justify-content: space-between; flex-wrap: wrap; gap: .5rem; font-size: .75rem; color: var(--muted); }
    .status-dot { display: inline-block; width: .45rem; height: .45rem; border-radius: 50%; background: var(--accent); margin-right: .4rem; }
    .store-layout { display: grid; grid-template-columns: minmax(0, 1fr) 22rem; align-items: start; gap: 2.5rem; padding-block: 2.5rem 4rem; }
    .store-layout.without-cart { grid-template-columns: minmax(0, 1fr); }
    main { min-width: 0; }
    .page-heading { margin-bottom: 2.25rem; max-width: 40rem; }
    .page-heading h1 { margin-block: .75rem 1rem; }
    .page-heading > p:last-child { color: var(--muted); max-width: 34rem; }
    .eyebrow { color: var(--muted); font-size: .65rem; letter-spacing: .12em; text-transform: uppercase; font-weight: 650; }
    .product-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1.25rem; }
    .product-card { border: 1px solid var(--border); border-radius: var(--radius); background: var(--surface); overflow: hidden; }
    .product-card-link { display: block; height: 100%; text-decoration: none; }
    .product-card-link:hover h2 { text-decoration: underline; text-underline-offset: .2em; }
    .product-visual { aspect-ratio: 4 / 3; position: relative; display: grid; place-content: center; background: #e8eadd; color: #6d775b; overflow: hidden; }
    .product-visual.digital { background: #e3e9ed; color: #647684; }
    .product-visual img { width: 100%; height: 100%; position: absolute; inset: 0; }
    .product-monogram { font-family: Georgia, serif; font-size: clamp(5rem, 9vw, 8rem); line-height: 1; opacity: .5; }
    .image-caption { position: absolute; bottom: 1rem; left: 1rem; font-size: .65rem; opacity: .8; }
    .product-card-body { padding: 1.25rem; display: grid; gap: .65rem; }
    .product-price { font-size: 1rem; font-weight: 650; font-variant-numeric: tabular-nums; }
    .product-card-action { display: flex; justify-content: space-between; margin-top: .5rem; font-size: .8rem; color: var(--muted); }
    .back-link { display: inline-block; margin-bottom: 1.75rem; font-size: .8rem; color: var(--muted); }
    .product-detail { display: grid; gap: 1.75rem; }
    .product-media { min-width: 0; }
    .product-media > .product-visual { border-radius: var(--radius); width: 100%; }
    .product-detail h1 { font-size: clamp(2rem, 3vw, 2.8rem); margin-block: .6rem 1rem; }
    .product-description { color: var(--muted); margin-bottom: 1rem; }
    .product-detail .product-price { font-size: 1.6rem; }
    .product-form { margin-top: 1.5rem; }
    fieldset { border: 0; padding: 0; margin: 0; min-width: 0; }
    .product-form fieldset { display: grid; gap: 1rem; }
    label { display: grid; gap: .4rem; font-size: .8rem; font-weight: 600; }
    .option-fields { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(10rem, 100%), 1fr)); gap: 1rem; }
    .purchase-row { display: flex; align-items: end; gap: .75rem; }
    .purchase-row button { flex: 1; }
    .variant-status { font-size: .8rem; color: var(--muted); min-height: 1.5em; }
    .variant-status[data-unavailable="true"] { color: var(--error); }
    .technical-details { border-top: 1px solid var(--border); padding-top: 1rem; margin-top: 2rem; color: var(--muted); font-size: .75rem; }
    .technical-details p { margin-top: .75rem; }
    .gallery { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .5rem; margin-top: .5rem; }
    .gallery img { width: 100%; aspect-ratio: 1; border-radius: .5rem; }
    .cart-sidebar { position: sticky; top: 1.5rem; min-width: 0; }
    .cart-panel { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); }
    .cart-panel > summary { list-style: none; padding: 1.25rem; display: flex; align-items: center; gap: .65rem; }
    .cart-panel > summary::-webkit-details-marker { display: none; }
    .cart-title { font-size: 1.1rem; font-weight: 700; }
    .cart-count { font-size: .7rem; background: var(--soft); border-radius: 2rem; padding: .15rem .5rem; }
    .cart-chevron { margin-left: auto; font-size: .8rem; }
    .cart-panel[open] .cart-chevron { transform: rotate(180deg); }
    .cart-content { padding: 0 1.25rem 1.25rem; max-height: calc(100dvh - 12rem); overflow-y: auto; overscroll-behavior: contain; }
    .cart-empty { padding: 2rem .5rem 2.5rem; text-align: center; color: var(--muted); }
    .cart-empty strong { display: block; color: var(--text); margin-bottom: .5rem; }
    .cart-empty p { font-size: .85rem; }
    .cart-item { padding-block: 1.25rem; border-top: 1px solid var(--border); }
    .cart-item-heading { display: flex; align-items: start; gap: .75rem; }
    .cart-item-heading > div { flex: 1; min-width: 0; overflow-wrap: anywhere; }
    .cart-item img { flex: 0 0 3rem; width: 3rem; height: 3rem; border-radius: .4rem; }
    .cart-options, .cart-unit-price { color: var(--muted); font-size: .75rem; margin-top: .35rem; }
    .cart-item-total { font-size: .85rem; white-space: nowrap; font-weight: 600; }
    .cart-item-controls { display: flex; align-items: end; flex-wrap: wrap; gap: .5rem; margin-top: .8rem; }
    .quantity-controls { display: flex; align-items: end; gap: .4rem; }
    .quantity-controls label { font-size: .7rem; }
    .quantity-controls button { background: var(--soft); color: var(--text); font-size: .75rem; padding-inline: .65rem; }
    .text-button { padding-inline: .25rem; background: transparent; color: var(--muted); font-size: .75rem; font-weight: 500; text-decoration: underline; }
    .cart-item-controls > form:last-child { margin-left: auto; }
    .cart-total { display: flex; justify-content: space-between; border-top: 1px solid var(--border); padding-top: 1.25rem; font-weight: 700; font-variant-numeric: tabular-nums; }
    .cart-note { margin-block: .5rem 1rem; color: var(--muted); font-size: .7rem; }
    .cart-checkout { width: 100%; }
    .cart-clear { text-align: center; margin-top: .5rem; }
    .cart-feedback { padding: .65rem .5rem; display: flex; align-items: start; gap: .5rem; }
    .cart-feedback p { font-size: .75rem; flex: 1; min-height: 1.5em; }
    .cart-feedback button { padding: 0; min-height: 1.5rem; white-space: nowrap; }
    .notice { padding: 1rem; border: 1px solid var(--border); background: var(--soft); border-radius: .5rem; font-size: .85rem; margin-block: 1rem; }
    .notice p { margin-top: .5rem; }
    .notice.error { color: var(--error); }
    .site-footer { display: flex; flex-wrap: wrap; justify-content: space-between; gap: .5rem; border-top: 1px solid var(--border); padding-block: 1.5rem; color: var(--muted); font-size: .75rem; }
    @media (min-width: 80rem) {
        .product-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .product-detail { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); align-items: start; }
    }
    @media (max-width: 60rem) {
        .site-header, .store-context, .store-layout, .site-footer { width: calc(100% - 2rem); }
        .site-header { gap: 1rem; }
        .site-header > nav:not(.languages) { order: 3; width: 100%; justify-content: space-between; }
        .test-menu > div { right: auto; left: -3rem; }
        .store-layout { grid-template-columns: minmax(0, 1fr); gap: 1.5rem; padding-top: 1rem; }
        .cart-sidebar { grid-row: 1; position: static; }
        .cart-content { max-height: 55dvh; }
        .cart-feedback { padding-block: .4rem 0; }
        .page-heading { margin-block: 1rem 1.5rem; }
    }
    @media (max-width: 30rem) {
        .product-grid { gap: .75rem; }
        .product-card-body { padding: .8rem; }
        .product-card h2 { font-size: 1rem; }
        .product-card .product-price { font-size: .85rem; }
        .image-caption { font-size: .55rem; left: .75rem; bottom: .75rem; }
    }
    @media (prefers-color-scheme: dark) {
        :root { --background: #181e1a; --surface: #212a24; --soft: #2b362e; --text: #e4e9e1; --muted: #a5b0a6; --border: #3b473e; --accent: #b4d4ac; --accent-text: #18251c; --error: #ffafa5; }
        .product-visual { background: #343e30; color: #b9c7a5; }
        .product-visual.digital { background: #2e3b42; color: #abc3d3; }
    }
</style>
