(() => {
  'use strict';

  const app = document.getElementById('app');
  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  const initial = JSON.parse(document.getElementById('initial-data').textContent);
  let content = initial.content;
  let email = initial.email;
  const ui = { sectionDraft: null, sectionDirty: false };

  /* ---------- helpers ---------- */

  // Build DOM nodes without innerHTML so product text can never inject markup.
  function h(tag, attrs, ...children) {
    const el = tag === 'svg' || tag === 'use' ? document.createElementNS('http://www.w3.org/2000/svg', tag) : document.createElement(tag);
    for (const [key, val] of Object.entries(attrs || {})) {
      if (val == null || val === false) continue;
      if (key === 'class') el.setAttribute('class', val);
      else if (key.startsWith('on')) el.addEventListener(key.slice(2), val);
      else if (['value', 'checked', 'selected', 'disabled', 'hidden'].includes(key)) el[key] = val;
      else el.setAttribute(key, val === true ? '' : val);
    }
    for (const child of children.flat(Infinity)) {
      if (child == null || child === false) continue;
      el.append(child instanceof Node ? child : String(child));
    }
    return el;
  }
  const icon = (id) => h('svg', { 'aria-hidden': 'true' }, h('use', { href: '#' + id }));
  const clone = (v) => JSON.parse(JSON.stringify(v));
  const uid = (prefix) => prefix + Array.from(crypto.getRandomValues(new Uint8Array(5)), (b) => b.toString(16).padStart(2, '0')).join('');
  const imgSrc = (path) => (path ? '../' + path : '');
  const fullTitle = (p) => (p.title + ' ' + (p.titleAccent || '')).trim();
  const slugify = (text) => String(text || '').normalize('NFKD').replace(/[̀-ͯ]/g, '').toLowerCase()
    .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 80);
  function moveItem(list, from, to) {
    if (to < 0 || to >= list.length) return;
    const [item] = list.splice(from, 1);
    list.splice(to, 0, item);
  }

  let toastTimer;
  function toast(message, type) {
    const el = document.getElementById('toast');
    el.textContent = message;
    el.className = 'toast show' + (type === 'error' ? ' error' : '');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => (el.className = 'toast'), type === 'error' ? 5000 : 2200);
  }
  function toastLink(message, href, label) {
    const el = document.getElementById('toast');
    el.replaceChildren(message + ' · ', h('a', { href, target: '_blank', rel: 'noopener' }, label || 'Open it ↗'));
    el.className = 'toast show';
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => (el.className = 'toast'), 6000);
  }

  async function api(action, { json, form } = {}) {
    const opts = { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf } };
    if (json) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(json); }
    if (form) opts.body = form;
    let res;
    try { res = await fetch('api.php?action=' + action, opts); } catch (err) { throw new Error('Network error. Check that the admin is still running.'); }
    let data = {};
    const text = await res.text().catch(() => '');
    try { data = JSON.parse(text); } catch (err) {
      const m = text.match(/\{"[\s\S]*\}\s*$/);
      try { data = m ? JSON.parse(m[0]) : {}; } catch (e) { data = {}; }
      if (res.ok && !m) throw new Error('The server sent an unexpected reply. Please try again.');
    }
    if (res.status === 401) {
      alert(data.error || 'Your session has ended. Please log in again.');
      location.reload();
      throw new Error('Session ended');
    }
    if (!res.ok) throw new Error(data.error || 'Request failed (' + res.status + ').');
    return data;
  }

  // Saves the whole content document. Saves run one after another.
  let queue = Promise.resolve();
  function persist(message = 'Saved') {
    const snapshot = clone(content);
    const run = queue.then(async () => {
      try {
        const data = await api('save', { json: { content: snapshot } });
        content = data.content;
        toast(message + ' · click Publish to put it on the website');
        markUnpublished();
        return true;
      } catch (err) {
        if (err.message !== 'Session ended') toast(err.message, 'error');
        try { content = (await api('load')).content; } catch (e) { /* keep local copy */ }
        render();
        return false;
      }
    });
    queue = run.catch(() => {});
    return run;
  }

  function uploadImage(file) {
    if (!file) return Promise.reject(new Error('No file selected.'));
    if (!/^image\/(jpeg|png|webp|gif)$/.test(file.type)) return Promise.reject(new Error('Please choose a JPG, PNG, WEBP or GIF image.'));
    if (file.size > initial.uploadLimit) return Promise.reject(new Error('Image is too large. Maximum size is ' + initial.uploadLimitText + '.'));
    const form = new FormData();
    form.append('image', file);
    return api('upload', { form }).then((d) => d.path);
  }

  /* ---------- reusable editors ---------- */

  function imagePicker(getPath, setPath, label, opts = {}) {
    const fileInput = h('input', { type: 'file', accept: 'image/jpeg,image/png,image/webp,image/gif', hidden: true });
    const drop = h('div', { class: 'image-drop', tabindex: '0', role: 'button', 'aria-label': 'Upload ' + label });
    const actions = h('div', { class: 'image-actions' });
    function paint() {
      drop.replaceChildren(getPath() ? h('img', { src: imgSrc(getPath()), alt: '' }) : (opts.compact ? '＋ Photo' : 'Click or drop an image here'));
      actions.replaceChildren(...[
        h('button', { type: 'button', class: 'btn btn-secondary btn-sm', onclick: () => fileInput.click() }, getPath() ? 'Replace' : 'Upload'),
        getPath() ? h('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: () => { setPath(''); paint(); } }, 'Remove') : null,
      ].filter(Boolean));
    }
    async function handle(file) {
      drop.classList.add('busy');
      try { setPath(await uploadImage(file)); paint(); }
      catch (err) { if (err.message !== 'Session ended') toast(err.message, 'error'); }
      finally { drop.classList.remove('busy'); fileInput.value = ''; }
    }
    fileInput.addEventListener('change', () => fileInput.files[0] && handle(fileInput.files[0]));
    drop.addEventListener('click', () => fileInput.click());
    drop.addEventListener('keydown', (ev) => { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); fileInput.click(); } });
    drop.addEventListener('dragover', (ev) => { ev.preventDefault(); drop.classList.add('over'); });
    drop.addEventListener('dragleave', () => drop.classList.remove('over'));
    drop.addEventListener('drop', (ev) => { ev.preventDefault(); drop.classList.remove('over'); if (ev.dataTransfer.files[0]) handle(ev.dataTransfer.files[0]); });
    paint();
    const picker = opts.compact
      ? h('div', { class: 'mini-picker' }, drop, actions, fileInput)
      : h('div', { class: 'image-picker' }, drop, h('div', {},
          h('div', { class: 'label' }, label),
          h('p', { class: 'hint' }, opts.hint || ('JPG, PNG, WEBP or GIF, up to ' + initial.uploadLimitText + '. Photos on a white or transparent background look best.')),
          actions, fileInput));
    picker.repaint = paint;
    return picker;
  }

  const field = (label, input, hint, required) =>
    h('label', { class: 'field' }, h('span', {}, label, required && h('span', { class: 'req' }, ' *')), input, hint && h('small', {}, hint));
  const textInput = (obj, key, attrs = {}) => h('input', Object.assign({ type: 'text', value: obj[key] || '', oninput: (ev) => (obj[key] = ev.target.value) }, attrs));
  const textArea = (obj, key, attrs = {}) => h('textarea', Object.assign({ rows: '3', value: obj[key] || '', oninput: (ev) => (obj[key] = ev.target.value) }, attrs));

  /**
   * Editable list of rows (features, highlights, specs, applications).
   * make() returns a new blank row; renderRow(row, i) returns the row's input fields.
   */
  function listEditor({ list, make, renderRow, addLabel, emptyText, max = 40, rowClass = '' }) {
    const box = h('div', { class: 'rowlist' });
    function paint() {
      box.replaceChildren(...list.map((row, i) => h('div', { class: 'lrow lrow-wrap ' + rowClass },
        renderRow(row, i),
        h('div', { class: 'row-actions' },
          h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'Move up', disabled: i === 0, onclick: () => { moveItem(list, i, i - 1); paint(); } }, '↑'),
          h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'Move down', disabled: i === list.length - 1, onclick: () => { moveItem(list, i, i + 1); paint(); } }, '↓'),
          h('button', { type: 'button', class: 'icon-btn danger', 'aria-label': 'Remove', onclick: () => { list.splice(i, 1); paint(); } }, '✕')))));
      if (!list.length) box.append(h('p', { class: 'hint empty-hint' }, emptyText));
    }
    paint();
    return h('div', {}, box, h('div', { class: 'pane-actions' }, h('button', { type: 'button', class: 'btn btn-secondary btn-sm', onclick: () => {
      if (list.length >= max) return toast('Up to ' + max + ' rows.', 'error');
      list.push(make()); paint();
      const last = box.lastElementChild && box.lastElementChild.querySelector('input,textarea');
      if (last) last.focus();
    } }, '＋ ' + addLabel)));
  }

  function dialogShell(title, tabs, onSubmit, opts = {}) {
    const errorBox = h('div', { class: 'alert alert-error form-error', role: 'alert', hidden: true });
    const keys = Object.keys(tabs);
    const buttons = keys.map((k) => h('button', { type: 'button', role: 'tab', class: 'dtab', 'data-key': k, onclick: () => select(k) }, tabs[k].label));
    function select(k) {
      keys.forEach((key) => (tabs[key].pane.hidden = key !== k));
      buttons.forEach((b) => b.setAttribute('aria-selected', String(b.dataset.key === k)));
    }
    keys.forEach((k) => tabs[k].pane.classList.add('tab-pane'));
    select(keys[0]);
    const submit = h('button', { type: 'submit', class: 'btn btn-primary' }, opts.submitLabel || 'Save');
    const form = h('form', { method: 'dialog', novalidate: true },
      h('div', { class: 'dialog-head' }, h('h2', {}, title), h('div', { class: 'dialog-head-actions' }, opts.headExtra,
        h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'Close', onclick: () => dialog.close() }, '✕'))),
      keys.length > 1 ? h('div', { class: 'dtabs', role: 'tablist' }, buttons) : null,
      h('div', { class: 'dialog-body' }, errorBox, keys.map((k) => tabs[k].pane)),
      h('div', { class: 'dialog-foot' }, opts.footExtra,
        h('button', { type: 'button', class: 'btn btn-secondary', onclick: () => dialog.close() }, 'Cancel'), submit));
    const dialog = h('dialog', { class: 'dialog-wide', 'aria-label': title }, form);
    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      errorBox.hidden = true;
      submit.disabled = true;
      try {
        const res = await onSubmit({ showError: (msg, tab) => { errorBox.textContent = msg; errorBox.hidden = false; if (tab) select(tab); } });
        if (res) dialog.close();
      } finally { submit.disabled = false; }
    });
    dialog.addEventListener('close', () => dialog.remove());
    document.body.append(dialog);
    dialog.showModal();
    return dialog;
  }

  /* ---------- Products ---------- */

  function productThumb(p) {
    if (p.image) return h('img', { class: 'thumb', src: imgSrc(p.image), alt: '', loading: 'lazy' });
    return h('div', { class: 'thumb ' + (p.customMedia ? 'thumb-drawing' : 'thumb-empty') }, p.customMedia ? 'Drawing' : 'No photo');
  }

  function renderProducts() {
    const rows = h('ul', { class: 'rows' });
    const list = content.products;
    let dragFrom = null;
    rows.replaceChildren(...(list.length ? list.map((p, index) => {
      const row = h('li', { class: 'row' + (p.visible ? '' : ' is-hidden'), draggable: 'true' },
        h('span', { class: 'handle', title: 'Drag to reorder', 'aria-hidden': 'true' }, '⋮⋮'),
        productThumb(p),
        h('div', { class: 'row-main' },
          h('div', { class: 'row-title' }, p.title, p.titleAccent ? h('span', { class: 'title-accent' }, ' ' + p.titleAccent) : null),
          h('div', { class: 'row-meta' }, h('span', { class: 'pill' + (p.kicker ? '' : ' muted') }, p.kicker || 'No type'), h('span', { class: 'desc' }, p.subtitle))),
        h('label', { class: 'switch', title: 'Show on website' },
          h('input', { type: 'checkbox', checked: p.visible, 'aria-label': 'Show ' + fullTitle(p) + ' on website',
            onchange: (ev) => { p.visible = ev.target.checked; row.classList.toggle('is-hidden', !p.visible); persist(p.visible ? 'Product shown' : 'Product hidden'); } }),
          h('i'), h('span', {}, 'Visible')),
        h('div', { class: 'row-actions' },
          h('button', { class: 'icon-btn', 'aria-label': 'Move up', disabled: index === 0, onclick: () => { moveItem(list, index, index - 1); render(); persist('Order saved'); } }, '↑'),
          h('button', { class: 'icon-btn', 'aria-label': 'Move down', disabled: index === list.length - 1, onclick: () => { moveItem(list, index, index + 1); render(); persist('Order saved'); } }, '↓'),
          h('button', { class: 'btn btn-secondary btn-sm', onclick: () => openProductEditor(p) }, 'Edit'),
          h('a', { class: 'btn btn-ghost btn-sm', href: '../#' + p.slug, target: '_blank', rel: 'noopener', title: 'See it in the preview' }, 'Preview ↗'),
          h('button', { class: 'btn btn-ghost btn-sm', onclick: () => {
            const copy = Object.assign(clone(p), { id: uid('p'), slug: '', title: p.title + ' (copy)', quoteName: '', visible: false });
            list.splice(index + 1, 0, copy); render(); persist('Copy created (hidden until you switch it on)');
          } }, 'Duplicate'),
          h('button', { class: 'icon-btn danger', 'aria-label': 'Delete ' + fullTitle(p), onclick: () => {
            if (!confirm(`Delete “${fullTitle(p)}”? This cannot be undone.`)) return;
            content.products = list.filter((x) => x !== p);
            content.range.items.forEach((it) => { if (it.link === p.id) it.link = ''; });
            render(); persist('Product deleted');
          } }, '✕')));
      row.addEventListener('dragstart', (ev) => { dragFrom = index; row.classList.add('dragging'); ev.dataTransfer.effectAllowed = 'move'; ev.dataTransfer.setData('text/plain', String(index)); });
      row.addEventListener('dragend', () => { dragFrom = null; row.classList.remove('dragging'); });
      row.addEventListener('dragover', (ev) => { if (dragFrom === null) return; ev.preventDefault(); const after = ev.clientY > row.getBoundingClientRect().top + row.offsetHeight / 2; row.classList.toggle('drop-after', after); row.classList.toggle('drop-before', !after); });
      row.addEventListener('dragleave', () => row.classList.remove('drop-before', 'drop-after'));
      row.addEventListener('drop', (ev) => {
        ev.preventDefault();
        const after = row.classList.contains('drop-after');
        row.classList.remove('drop-before', 'drop-after');
        if (dragFrom === null) return;
        let to = index + (after ? 1 : 0);
        if (dragFrom < to) to -= 1;
        if (to !== dragFrom) { moveItem(list, dragFrom, to); render(); persist('Order saved'); }
      });
      return row;
    }) : [h('li', { class: 'empty' }, h('b', {}, 'No products yet'), 'Click “Add product” to create the first one.')]));

    return [
      h('div', { class: 'page-head' },
        h('div', {}, h('h1', {}, 'Products'), h('p', {}, 'The detailed products in the “Our products” section, in this order. Products alternate picture-left / picture-right automatically.')),
        h('button', { class: 'btn btn-primary', onclick: () => openProductEditor(null) }, '＋ Add product')),
      h('section', { class: 'card' }, rows),
    ];
  }

  function openProductEditor(existing) {
    const blank = { id: uid('p'), slug: '', visible: true, kicker: '', title: '', titleAccent: '', quoteName: '', subtitle: '', description: '',
      mediaLabel: '', image: '', alt: '', customMedia: '', cardImage: '', taglineBold: 'Clean Air', taglineText: 'Healthy Life',
      features: [], highlights: [], specsTitle: 'Specifications', specs: [], applicationsTitle: 'Typical Applications', applications: [],
      secondaryLabel: '', secondaryLink: '' };
    const d = Object.assign(blank, existing ? clone(existing) : {});
    const featureRows = d.features.map((t) => ({ text: t }));   // edit strings as rows

    // Details
    const titleInput = textInput(d, 'title', { maxlength: '120' });
    const details = h('div', {},
      h('div', { class: 'grid-2' },
        field('Title', titleInput, null, true),
        field('Title accent (in green)', textInput(d, 'titleAccent', { maxlength: '60', placeholder: 'e.g. SENSE' }), 'Optional second word shown in green, like AERIS SENSE.')),
      h('div', { class: 'grid-2' },
        field('Product type', textInput(d, 'kicker', { maxlength: '80', placeholder: 'e.g. Air Quality Monitor' }), 'Small green label above the title.'),
        field('Name in quote form', textInput(d, 'quoteName', { maxlength: '160', placeholder: 'Automatic: title - product type' }), 'Shown in the quote form’s “Product of interest” list.')),
      field('Short line', textArea(d, 'subtitle', { maxlength: '300', rows: '2' }), 'One sentence under the title.'),
      field('Description', textArea(d, 'description', { maxlength: '2000', rows: '4' }), 'Optional paragraph.'),
      h('label', { class: 'check' }, h('input', { type: 'checkbox', checked: d.visible, onchange: (ev) => (d.visible = ev.target.checked) }),
        h('span', {}, h('b', {}, 'Show on website'), h('small', {}, 'Untick to keep it as a hidden draft.'))),
      h('details', { class: 'advanced' }, h('summary', {}, 'Advanced: page link name'),
        field('Link name (anchor)', textInput(d, 'slug', { maxlength: '80', placeholder: 'Automatic from the title' }), 'Used for links like aerisclean.in/#aeris-sense. Changing it breaks old links.')));

    // Picture
    const picture = h('div', {},
      field('Label above the picture (optional)', textInput(d, 'mediaLabel', { maxlength: '60', placeholder: 'e.g. Dimensions' })),
      imagePicker(() => d.image, (v) => (d.image = v), 'Product photo'),
      field('Photo description (alt text)', textInput(d, 'alt', { maxlength: '200', placeholder: 'Describe the photo for screen readers and Google' })),
      imagePicker(() => d.cardImage, (v) => (d.cardImage = v), 'Card picture (optional)',
        { hint: 'Shown on this product’s card at the top of the section. Leave empty to use the product photo. Useful when the product uses a drawing instead of a photo.' }),
      h('div', { class: 'grid-2' },
        field('Tagline (bold part)', textInput(d, 'taglineBold', { maxlength: '60', placeholder: 'Clean Air' })),
        field('Tagline (rest)', textInput(d, 'taglineText', { maxlength: '80', placeholder: 'Healthy Life' }), 'Shown under the picture as “CLEAN AIR · HEALTHY LIFE”.')),
      h('details', { class: 'advanced', open: d.customMedia ? true : null }, h('summary', {}, 'Advanced: custom drawing (SVG) instead of a photo'),
        field('Custom drawing code', textArea(d, 'customMedia', { class: 'code', rows: '8', spellcheck: 'false' }),
          'If filled in, this drawing is shown instead of the photo (used for the Aeris Sense dimensions drawing). Leave empty to use the photo.')));

    // Features & highlights
    const features = h('div', {},
      h('div', { class: 'label' }, 'Key features (green tick list)'),
      listEditor({ list: featureRows, make: () => ({ text: '' }), addLabel: 'Add feature', emptyText: 'No tick-list features.', max: 20,
        renderRow: (r) => h('div', { class: 'lrow-fields' }, textInput(r, 'text', { maxlength: '200', placeholder: 'e.g. Accurate PM1, PM2.5, PM10 monitoring', 'aria-label': 'Feature' })) }),
      h('hr', { class: 'sep' }),
      h('div', { class: 'label' }, 'Highlight boxes (bold title + short text)'),
      listEditor({ list: d.highlights, make: () => ({ title: '', text: '' }), addLabel: 'Add highlight box', emptyText: 'No highlight boxes.', max: 12,
        renderRow: (r) => h('div', { class: 'lrow-fields cols-2' },
          textInput(r, 'title', { maxlength: '80', placeholder: 'e.g. Low Noise Operation', 'aria-label': 'Highlight title' }),
          textInput(r, 'text', { maxlength: '200', placeholder: 'e.g. < 65 dB(A) @ 1 m distance', 'aria-label': 'Highlight text' })) }));

    // Specifications
    const specs = h('div', {},
      field('Table heading', textInput(d, 'specsTitle', { maxlength: '80', placeholder: 'Specifications' })),
      listEditor({ list: d.specs, make: () => ({ label: '', value: '' }), addLabel: 'Add specification', emptyText: 'No specifications. The table is hidden until you add a row.', max: 40,
        renderRow: (r) => h('div', { class: 'lrow-fields cols-2' },
          textInput(r, 'label', { maxlength: '80', placeholder: 'e.g. Airflow Capacity', 'aria-label': 'Specification name' }),
          textInput(r, 'value', { maxlength: '300', placeholder: 'e.g. Approx. 500 CFM', 'aria-label': 'Specification value' })) }));

    // Applications
    const applications = h('div', {},
      field('Heading', textInput(d, 'applicationsTitle', { maxlength: '80', placeholder: 'Typical Applications' })),
      listEditor({ list: d.applications, make: () => ({ image: '', alt: '', title: '', text: '' }), addLabel: 'Add application', max: 12, rowClass: 'app-row-wrap',
        emptyText: 'No applications. This part is hidden until you add one (4 per row on the website).',
        renderRow: (r) => h('div', { class: 'lrow-fields app-row', style: 'display:grid;gap:10px' },
          imagePicker(() => r.image, (v) => (r.image = v), 'Application photo', { compact: true }),
          h('div', { class: 'lrow-fields' },
            textInput(r, 'title', { maxlength: '80', placeholder: 'e.g. Soldering Stations', 'aria-label': 'Application title' }),
            textInput(r, 'text', { maxlength: '200', placeholder: 'e.g. Manual / Automated Soldering', 'aria-label': 'Application text' }),
            textInput(r, 'alt', { maxlength: '200', placeholder: 'Photo description (alt text)', 'aria-label': 'Photo description' }))) }));

    // Buttons
    const sectionOptions = h('datalist', { id: 'section-links' },
      ['#demo', '#how', '#health', '#applications', '#contact', '#soldering-risks'].concat(content.products.map((p) => '#' + p.slug)).map((v) => h('option', { value: v })));
    const buttons = h('div', {},
      h('p', { class: 'hint', style: 'margin-bottom:14px' }, 'Every product gets a “Request a quote” button that jumps to the quote form with this product selected.'),
      h('div', { class: 'grid-2' },
        field('Second button text (optional)', textInput(d, 'secondaryLabel', { maxlength: '60', placeholder: 'e.g. Try the live demo' })),
        field('Second button link', textInput(d, 'secondaryLink', { maxlength: '300', placeholder: '#demo or https://…', list: 'section-links' }), 'A section of the page (#demo, #contact…) or a web address (e.g. a PDF brochure).')),
      sectionOptions);

    dialogShell(existing ? 'Edit product' : 'Add product', {
      details: { label: 'Details', pane: details },
      picture: { label: 'Picture', pane: picture },
      features: { label: 'Features', pane: features },
      specs: { label: 'Specifications', pane: specs },
      applications: { label: 'Applications', pane: applications },
      buttons: { label: 'Buttons', pane: buttons },
    }, async ({ showError }) => {
      d.title = d.title.trim();
      d.features = featureRows.map((r) => r.text.trim()).filter(Boolean);
      d.specs = d.specs.filter((s) => s.label.trim() || s.value.trim());
      d.highlights = d.highlights.filter((x) => x.title.trim() || x.text.trim());
      d.applications = d.applications.filter((a) => a.image || a.title.trim() || a.text.trim());
      if (!d.title) { showError('Please enter a title.', 'details'); titleInput.focus(); return false; }
      if (/<\s*script|\son[a-z]+\s*=|javascript:/i.test(d.customMedia)) { showError('The custom drawing may not contain scripts.', 'picture'); return false; }
      const before = clone(content.products);
      const i = content.products.findIndex((x) => x.id === d.id);
      if (i >= 0) content.products[i] = d; else content.products.push(d);
      const ok = await persist(existing ? 'Product updated' : 'Product added');
      if (!ok) { content.products = before; showError('Could not save. See the message at the bottom of the screen.'); return false; }
      render();
      return true;
    }, {
      submitLabel: existing ? 'Save changes' : 'Add product',
      headExtra: existing ? h('a', { class: 'btn btn-ghost btn-sm', href: '../#' + existing.slug, target: '_blank', rel: 'noopener' }, 'Preview ↗') : null,
    });
    if (!existing) titleInput.focus();
  }

  /* ---------- "We specialize in" cards ---------- */

  function rangeThumb(it) {
    return h('div', { class: 'range-thumb' }, it.image ? h('img', { src: imgSrc(it.image), alt: '' }) : icon(it.icon || 'i-fan'));
  }

  function renderRange() {
    const r = content.range;
    const head = { eyebrow: r.eyebrow, title: r.title, lede: r.lede };
    const items = r.items;
    const rows = h('ul', { class: 'rows' }, items.length ? items.map((it, i) => {
      const linked = content.products.find((p) => p.id === it.link);
      return h('li', { class: 'row' + (it.visible ? '' : ' is-hidden') },
        h('span', { class: 'handle', 'aria-hidden': 'true' }, ''),
        rangeThumb(it),
        h('div', { class: 'row-main' }, h('div', { class: 'row-title' }, it.title),
          h('div', { class: 'row-meta' }, h('span', { class: 'pill muted' }, linked ? 'Links to ' + fullTitle(linked) : 'Links to Products'),
            it.inQuoteForm ? h('span', { class: 'pill' }, 'In quote form') : null, h('span', { class: 'desc' }, it.text))),
        h('label', { class: 'switch', title: 'Show on website' },
          h('input', { type: 'checkbox', checked: it.visible, 'aria-label': 'Show ' + it.title, onchange: (ev) => { it.visible = ev.target.checked; render(); persist(it.visible ? 'Card shown' : 'Card hidden'); } }),
          h('i'), h('span', {}, 'Visible')),
        h('div', { class: 'row-actions' },
          h('button', { class: 'icon-btn', 'aria-label': 'Move up', disabled: i === 0, onclick: () => { moveItem(items, i, i - 1); render(); persist('Order saved'); } }, '↑'),
          h('button', { class: 'icon-btn', 'aria-label': 'Move down', disabled: i === items.length - 1, onclick: () => { moveItem(items, i, i + 1); render(); persist('Order saved'); } }, '↓'),
          h('button', { class: 'btn btn-secondary btn-sm', onclick: () => openRangeEditor(it) }, 'Edit'),
          h('button', { class: 'icon-btn danger', 'aria-label': 'Delete ' + it.title, onclick: () => {
            if (!confirm(`Delete the card “${it.title}”?`)) return;
            r.items = items.filter((x) => x !== it); render(); persist('Card deleted');
          } }, '✕')));
    }) : h('li', { class: 'empty' }, h('b', {}, 'No cards yet'), 'Add one with the button above.'));

    const headForm = h('form', { class: 'card card-pad', style: 'margin-bottom:20px', onsubmit: (ev) => {
      ev.preventDefault();
      Object.assign(r, head); persist('Heading saved');
    } },
      h('h2', { class: 'section-title' }, 'Heading'),
      h('p', { class: 'section-sub' }, 'Text above the cards.'),
      h('div', { class: 'grid-2' }, field('Small label', textInput(head, 'eyebrow', { maxlength: '80' })), field('Heading', textInput(head, 'title', { maxlength: '200' }))),
      field('Introduction', textArea(head, 'lede', { maxlength: '600', rows: '2' })),
      h('button', { class: 'btn btn-primary', type: 'submit' }, 'Save heading'));

    return [
      h('div', { class: 'page-head' },
        h('div', {}, h('h1', {}, 'We specialize in'), h('p', {}, 'The category cards under the products. They also fill the footer’s product list and the quote form.')),
        h('button', { class: 'btn btn-primary', onclick: () => openRangeEditor(null) }, '＋ Add card')),
      headForm,
      h('section', { class: 'card' }, rows),
    ];
  }

  function openRangeEditor(existing) {
    const d = Object.assign({ id: uid('r'), visible: true, title: '', text: '', icon: 'i-fan', image: '', link: '', inQuoteForm: true }, existing ? clone(existing) : {});
    const titleInput = textInput(d, 'title', { maxlength: '80' });
    const grid = h('div', { class: 'icon-grid', role: 'group', 'aria-label': 'Icon' });
    function paintIcons() {
      grid.replaceChildren(...initial.icons.map((id) => h('button', { type: 'button', class: 'icon-opt', 'aria-pressed': String(!d.image && d.icon === id), title: id.replace('i-', ''),
        onclick: () => { d.icon = id; d.image = ''; picker.repaint(); paintIcons(); } }, icon(id))));
    }
    const picker = imagePicker(() => d.image, (v) => { d.image = v; paintIcons(); }, 'Or use a picture', { hint: 'A small product picture replaces the icon. Transparent PNG/WEBP works best.' });
    paintIcons();
    const linkSelect = h('select', { onchange: (ev) => (d.link = ev.target.value) },
      h('option', { value: '' }, 'The Products section'),
      content.products.map((p) => h('option', { value: p.id, selected: d.link === p.id }, fullTitle(p))));
    linkSelect.value = d.link;
    const pane = h('div', {},
      field('Title', titleInput, null, true),
      field('Short text', textInput(d, 'text', { maxlength: '200' })),
      h('div', { class: 'label' }, 'Icon'), grid, picker,
      field('Footer link goes to', linkSelect, 'The footer’s product list links each card here.'),
      h('label', { class: 'check' }, h('input', { type: 'checkbox', checked: d.inQuoteForm, onchange: (ev) => (d.inQuoteForm = ev.target.checked) }),
        h('span', {}, h('b', {}, 'Show in the quote form'), h('small', {}, 'Adds this title to the “Product of interest” list.'))),
      h('label', { class: 'check' }, h('input', { type: 'checkbox', checked: d.visible, onchange: (ev) => (d.visible = ev.target.checked) }),
        h('span', {}, h('b', {}, 'Show on website'))));
    dialogShell(existing ? 'Edit card' : 'Add card', { card: { label: 'Card', pane } }, async ({ showError }) => {
      d.title = d.title.trim();
      if (!d.title) { showError('Please enter a title.'); titleInput.focus(); return false; }
      const items = content.range.items;
      const before = clone(items);
      const i = items.findIndex((x) => x.id === d.id);
      if (i >= 0) items[i] = d; else items.push(d);
      const ok = await persist(existing ? 'Card updated' : 'Card added');
      if (!ok) { content.range.items = before; showError('Could not save.'); return false; }
      render();
      return true;
    }, { submitLabel: existing ? 'Save changes' : 'Add card' });
    if (!existing) titleInput.focus();
  }

  /* ---------- Section text ---------- */

  function renderSection() {
    if (!ui.sectionDraft) ui.sectionDraft = clone(content.section);
    const d = ui.sectionDraft;
    const dirty = () => (ui.sectionDirty = true);
    const form = h('form', { class: 'card card-pad', style: 'max-width:760px', onsubmit: async (ev) => {
      ev.preventDefault();
      content.section = clone(d);
      if (await persist('Section text saved')) { ui.sectionDraft = null; ui.sectionDirty = false; render(); }
    } },
      h('h2', { class: 'section-title' }, '“Our products” heading'),
      h('p', { class: 'section-sub' }, 'The text at the top of the products section.'),
      field('Small label', textInput(d, 'eyebrow', { maxlength: '80', oninput: (ev) => { d.eyebrow = ev.target.value; dirty(); } })),
      field('Heading', textInput(d, 'title', { maxlength: '200', oninput: (ev) => { d.title = ev.target.value; dirty(); } })),
      field('Introduction', textArea(d, 'lede', { maxlength: '600', rows: '3', oninput: (ev) => { d.lede = ev.target.value; dirty(); } })),
      h('label', { class: 'check' }, h('input', { type: 'checkbox', checked: d.showCards !== false, onchange: (ev) => { d.showCards = ev.target.checked; dirty(); } }),
        h('span', {}, h('b', {}, 'Show product cards'), h('small', {}, 'A row of cards under this heading, one per visible product, each linking to the product’s full details.'))),
      h('button', { class: 'btn btn-primary', type: 'submit' }, 'Save'));
    return [h('div', { class: 'page-head' }, h('div', {}, h('h1', {}, 'Section text'), h('p', {}, 'Headings for the products part of the page.'))), form];
  }

  /* ---------- Account ---------- */

  function renderAccount() {
    const msg = h('div', { class: 'alert', hidden: true });
    const emailInput = h('input', { type: 'email', value: email, required: true, autocomplete: 'username' });
    const current = h('input', { type: 'password', required: true, autocomplete: 'current-password' });
    const next = h('input', { type: 'password', minlength: '10', autocomplete: 'new-password' });
    const confirmInput = h('input', { type: 'password', autocomplete: 'new-password' });
    const show = (text, ok) => { msg.textContent = text; msg.className = 'alert ' + (ok ? 'alert-ok' : 'alert-error'); msg.hidden = false; };
    const form = h('form', { class: 'card card-pad', style: 'max-width:520px', onsubmit: async (ev) => {
      ev.preventDefault();
      if (next.value && next.value.length < 10) return show('New password must be at least 10 characters.');
      if (next.value !== confirmInput.value) return show('The new passwords do not match.');
      const btn = form.querySelector('button');
      btn.disabled = true;
      try {
        const data = await api('account', { json: { email: emailInput.value.trim(), currentPassword: current.value, newPassword: next.value } });
        email = data.email; current.value = next.value = confirmInput.value = '';
        show('Account updated.', true);
      } catch (err) { if (err.message !== 'Session ended') show(err.message); } finally { btn.disabled = false; }
    } },
      h('h2', { class: 'section-title' }, 'Login details'),
      h('p', { class: 'section-sub' }, 'Change the email or password used to log in to this admin.'),
      msg,
      h('label', { class: 'field' }, h('span', {}, 'Email'), emailInput),
      h('label', { class: 'field' }, h('span', {}, 'New password'), next, h('small', {}, 'Leave empty to keep your current password. At least 10 characters.')),
      h('label', { class: 'field' }, h('span', {}, 'Confirm new password'), confirmInput),
      h('label', { class: 'field' }, h('span', {}, 'Current password', h('span', { class: 'req' }, ' *')), current, h('small', {}, 'Required to confirm any change.')),
      h('button', { class: 'btn btn-primary', type: 'submit' }, 'Update account'));
    return [h('div', { class: 'page-head' }, h('div', {}, h('h1', {}, 'Account'), h('p', {}, 'Logged in as ' + email))), form];
  }

  /* ---------- Publish ---------- */

  let publishBtn = null;
  let publishInfo = null;
  let publishState = initial.publish || { lastPublish: '', unpublished: false };
  function paintPublish() {
    if (!publishBtn) return;
    publishBtn.classList.toggle('has-changes', !!publishState.unpublished);
    publishBtn.title = publishState.unpublished ? 'You have changes that are not on the live website yet' : 'The live website is up to date';
    publishInfo.textContent = publishState.lastPublish ? 'Last published ' + publishState.lastPublish : 'Not published yet';
  }
  function markUnpublished() { publishState.unpublished = true; paintPublish(); }
  async function publish() {
    if (!confirm('Publish your changes to the live website (www.aerisclean.in)?')) return;
    publishBtn.disabled = true;
    publishBtn.classList.add('busy');
    publishBtn.lastChild.textContent = 'Publishing…';
    try {
      const data = await api('publish');
      publishState = { lastPublish: data.lastPublish, unpublished: false };
      toastLink(data.changed ? 'Published. The website updates in about 1 minute' : 'The website is already up to date', initial.siteUrl);
    } catch (err) {
      if (err.message !== 'Session ended') toast(err.message, 'error');
    } finally {
      publishBtn.disabled = false;
      publishBtn.classList.remove('busy');
      publishBtn.lastChild.textContent = 'Publish to website';
      paintPublish();
    }
  }
  if (initial.canPublish) {
    publishInfo = h('span', { class: 'publish-info' });
    publishBtn = h('button', { type: 'button', class: 'btn btn-primary btn-publish', onclick: publish },
      h('span', { class: 'publish-dot', 'aria-hidden': 'true' }), h('span', {}, 'Publish to website'));
    document.querySelector('.topbar-actions').prepend(h('div', { class: 'publish-wrap' }, publishBtn, publishInfo));
    paintPublish();
  }

  /* ---------- router ---------- */

  const views = { products: renderProducts, range: renderRange, section: renderSection, account: renderAccount };
  const currentTab = () => (views[location.hash.slice(1)] ? location.hash.slice(1) : 'products');
  function render() {
    const tab = currentTab();
    document.querySelectorAll('#tabs a').forEach((a) => (a.dataset.tab === tab ? a.setAttribute('aria-current', 'page') : a.removeAttribute('aria-current')));
    const y = window.scrollY;
    app.replaceChildren(...views[tab]());
    window.scrollTo(0, y);
  }
  let lastTab = currentTab();
  window.addEventListener('hashchange', () => {
    if (lastTab === 'section' && ui.sectionDirty && currentTab() !== 'section') {
      if (!confirm('You have unsaved changes to the section text. Leave without saving?')) { history.replaceState(null, '', '#section'); return; }
      ui.sectionDraft = null; ui.sectionDirty = false;
    }
    lastTab = currentTab();
    render();
    window.scrollTo(0, 0);
  });
  window.addEventListener('beforeunload', (ev) => { if (ui.sectionDirty) { ev.preventDefault(); ev.returnValue = ''; } });
  render();
})();
