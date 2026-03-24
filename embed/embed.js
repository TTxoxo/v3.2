(function () {
  'use strict';

  function getCurrentScript() {
    if (document.currentScript) return document.currentScript;
    var scripts = document.getElementsByTagName('script');
    return scripts[scripts.length - 1] || null;
  }

  function getScriptUrl() {
    var script = getCurrentScript();
    if (!script || !script.src) return null;
    try { return new URL(script.src, window.location.href); } catch (e) { return null; }
  }

  function getParam(name) {
    var url = getScriptUrl();
    if (url) {
      var v = url.searchParams.get(name);
      if (v !== null && v !== '') return v;
    }

    try {
      var pageUrl = new URL(window.location.href);
      return pageUrl.searchParams.get(name);
    } catch (e) {
      return null;
    }
  }

  var apiKey = getParam('key');
  if (!apiKey) {
    console.error('[Inquiry Embed] Missing key parameter.');
    return;
  }

  var displayMode = (getParam('display') || 'floating').toLowerCase(); // floating | inline
  var targetSelector = getParam('target') || '#inquiry-embed-inline';

  var UI_OVERRIDES = {
    title: getParam('title') || '',
    subtitle: getParam('subtitle') || '',
    button_text: getParam('button_text') || '',
    success_message: getParam('success_message') || '',
    helper_text: getParam('whatsapp_text') || getParam('helper_text') || '',
    theme_color: getParam('theme_color') || '',
    floating_position: getParam('floating_position') || ''
  };

  var API_BASE = (function () {
    var u = getScriptUrl();
    if (u) return u.origin;
    return window.location.origin;
  })();

  var GET_FORM_API = API_BASE + '/api/get_form.php?key=' + encodeURIComponent(apiKey);
  var SUBMIT_API = API_BASE + '/api/submit.php';
  var FORM_CACHE_KEY = 'inquiry_form_cache_' + apiKey;
  var FORM_CACHE_TTL_MS = 5 * 60 * 1000;
  var loadedFormConfig = null;
  var loadingFormPromise = null;

  var ATTR_COOKIE_KEY = 'inquiry_attr_' + apiKey;
  var ATTR_COOKIE_DAYS = 30;

  function getCookie(name) {
    var match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/[.$?*|{}()\[\]\\/+^]/g, '\\$&') + '=([^;]*)'));
    return match ? decodeURIComponent(match[1]) : '';
  }

  function setCookie(name, value, days) {
    var maxAge = Math.max(1, Number(days || 1)) * 24 * 60 * 60;
    var secure = window.location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = name + '=' + encodeURIComponent(value) + '; path=/; max-age=' + String(maxAge) + '; SameSite=Lax' + secure;
  }

  function persistAttribution(data) {
    try { setCookie(ATTR_COOKIE_KEY, JSON.stringify(data || {}), ATTR_COOKIE_DAYS); } catch (e) {}
  }

  function readPersistedAttribution() {
    try {
      var raw = getCookie(ATTR_COOKIE_KEY);
      if (!raw) return {};
      var parsed = JSON.parse(raw);
      return parsed && typeof parsed === 'object' ? parsed : {};
    } catch (e) {
      return {};
    }
  }

  function getGaClientIdFromCookie() {
    var ga = getCookie('_ga');
    if (!ga) return '';
    var m = ga.match(/^GA\d+\.\d+\.(\d+\.\d+)$/);
    return m ? m[1] : '';
  }

  var host = document.createElement('div');
  host.id = 'inquiry-embed-host';

  var mountInline = null;
  if (displayMode === 'inline') {
    mountInline = document.querySelector(targetSelector);
    if (!mountInline) {
      mountInline = document.createElement('div');
      mountInline.id = targetSelector.replace(/^#/, '') || 'inquiry-embed-inline';
      document.body.appendChild(mountInline);
    }
    mountInline.appendChild(host);
  } else {
    document.body.appendChild(host);
  }

  var shadow = host.attachShadow({ mode: 'open' });

  var style = document.createElement('style');
  style.textContent = [
    ':host { all: initial; }',
    '.wrap { --theme:#1f6feb; --theme-dark:#1755af; --text:#0f172a; --muted:#64748b; --line:#e2e8f0; --bg:#ffffff; --radius:14px; font-family:Inter,Segoe UI,Arial,sans-serif; color:var(--text); }',
    '.hidden { display:none !important; }',

    '.wrap.inline { width:100%; max-width:620px; }',
    '.card { background:var(--bg); border:1px solid var(--line); border-radius:var(--radius); box-shadow:0 8px 28px rgba(2,6,23,.08); overflow:hidden; }',
    '.card-head { padding:16px 18px 8px; }',
    '.title { margin:0; font-size:19px; line-height:1.25; font-weight:700; color:var(--text); }',
    '.subtitle { margin:6px 0 0; font-size:13px; line-height:1.5; color:var(--muted); }',
    '.card-body { padding:14px 18px 18px; }',

    '.grid { display:grid; grid-template-columns:1fr 1fr; gap:10px 12px; }',
    '.field { display:flex; flex-direction:column; gap:6px; min-width:0; }',
    '.field.full { grid-column:1 / -1; }',
    '.label { font-size:12px; color:#334155; font-weight:600; line-height:1.25; }',
    '.required { color:#dc2626; margin-left:3px; }',
    '.input, .textarea, .select { width:100%; box-sizing:border-box; border:1px solid #cbd5e1; border-radius:10px; background:#fff; padding:10px 11px; font-size:14px; color:#0f172a; transition:border-color .15s ease, box-shadow .15s ease; outline:none; }',
    '.textarea { min-height:110px; resize:vertical; }',
    '.input:focus, .textarea:focus, .select:focus { border-color:var(--theme); box-shadow:0 0 0 3px color-mix(in srgb, var(--theme) 20%, white); }',

    '.footer { margin-top:12px; display:flex; flex-direction:column; gap:10px; }',
    '.submit { border:none; border-radius:10px; padding:11px 12px; font-size:14px; font-weight:600; color:#fff; cursor:pointer; background:linear-gradient(135deg,var(--theme),var(--theme-dark)); transition:transform .15s ease, box-shadow .15s ease, opacity .2s ease; box-shadow:0 8px 18px color-mix(in srgb, var(--theme) 35%, transparent); }',
    '.submit:hover { transform:translateY(-1px); }',
    '.submit:focus-visible { outline:2px solid color-mix(in srgb, var(--theme) 45%, white); outline-offset:2px; }',
    '.submit[disabled] { opacity:.65; cursor:not-allowed; transform:none; }',
    '.helper { font-size:12px; color:var(--muted); line-height:1.5; }',
    '.msg { border-radius:10px; padding:9px 10px; font-size:13px; line-height:1.4; display:none; }',
    '.msg.ok { display:block; background:#eafaf0; color:#166534; border:1px solid #bbf7d0; }',
    '.msg.err { display:block; background:#fff1f2; color:#9f1239; border:1px solid #fecdd3; }',

    '.floating-wrap { position:fixed; z-index:2147483000; right:20px; bottom:20px; display:flex; flex-direction:column; align-items:flex-end; gap:10px; }',
    '.floating-wrap.left { left:20px; right:auto; align-items:flex-start; }',
    '.overlay { position:fixed; inset:0; background:rgba(15,23,42,.38); backdrop-filter:blur(1px); opacity:0; pointer-events:none; transition:opacity .2s ease; }',
    '.overlay.open { opacity:1; pointer-events:auto; }',
    '.floating-panel { width:min(392px, calc(100vw - 26px)); max-height:min(78vh, 720px); overflow:auto; transform:translateY(8px) scale(.98); opacity:0; pointer-events:none; transition:transform .22s ease, opacity .22s ease; }',
    '.floating-panel.open { transform:translateY(0) scale(1); opacity:1; pointer-events:auto; }',
    '.floating-head { display:flex; justify-content:space-between; align-items:center; gap:8px; padding-right:10px; }',
    '.close { border:none; border-radius:9px; width:32px; height:32px; cursor:pointer; background:#eef2ff; color:#1e3a8a; font-size:18px; line-height:1; }',

    '.toggle { border:none; width:58px; height:58px; border-radius:999px; cursor:pointer; background:linear-gradient(135deg,var(--theme),var(--theme-dark)); color:#fff; font-size:12px; font-weight:700; letter-spacing:.2px; box-shadow:0 10px 24px color-mix(in srgb, var(--theme) 35%, transparent); transition:transform .2s ease, box-shadow .2s ease; animation:breath 2.2s ease-in-out infinite; }',
    '.toggle:hover { transform:translateY(-2px); box-shadow:0 14px 28px color-mix(in srgb, var(--theme) 45%, transparent); }',
    '.toggle:focus-visible { outline:2px solid color-mix(in srgb, var(--theme) 40%, white); outline-offset:2px; }',
    '@keyframes breath { 0%{ box-shadow:0 10px 24px color-mix(in srgb,var(--theme) 35%, transparent);} 50%{ box-shadow:0 14px 30px color-mix(in srgb,var(--theme) 48%, transparent);} 100%{ box-shadow:0 10px 24px color-mix(in srgb,var(--theme) 35%, transparent);} }',

    '.loading { color:var(--muted); font-size:13px; }',

    '@media (max-width: 860px) { .wrap.inline { max-width:100%; } .grid { grid-template-columns:1fr; } }',
    '@media (max-width: 640px) {',
    ' .floating-wrap, .floating-wrap.left { right:0; left:0; bottom:0; align-items:stretch; padding:0 0 0; }',
    ' .toggle { position:fixed; right:14px; bottom:14px; z-index:2147483002; width:56px; height:56px; }',
    ' .floating-wrap.left .toggle { left:14px; right:auto; }',
    ' .floating-panel { width:100vw; max-height:86vh; border-radius:14px 14px 0 0; }',
    ' .floating-panel.card { border-radius:14px 14px 0 0; border-bottom:0; }',
    ' .card-head { padding:14px 14px 8px; }',
    ' .card-body { padding:10px 14px 16px; }',
    ' .submit { padding:12px; font-size:15px; }',
    '}'
  ].join('\n');

  var wrap = document.createElement('div');
  wrap.className = 'wrap ' + (displayMode === 'inline' ? 'inline' : 'floating');

  var overlay = document.createElement('div');
  overlay.className = 'overlay';

  var panel = document.createElement('div');
  panel.className = (displayMode === 'inline' ? 'card' : 'floating-panel card');

  var toggle = document.createElement('button');
  toggle.className = 'toggle';
  toggle.type = 'button';
  toggle.textContent = 'Inquiry';
  toggle.setAttribute('aria-label', 'Open inquiry form');

  if (displayMode === 'inline') {
    wrap.appendChild(panel);
  } else {
    var floatingWrap = document.createElement('div');
    floatingWrap.className = 'floating-wrap';
    floatingWrap.appendChild(panel);
    floatingWrap.appendChild(toggle);
    wrap.appendChild(overlay);
    wrap.appendChild(floatingWrap);

    overlay.addEventListener('click', closePanel);

    toggle.addEventListener('click', function () {
      if (panel.classList.contains('open')) {
        closePanel();
        return;
      }
      openPanel();
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && panel.classList.contains('open')) {
        closePanel();
      }
    });
  }

  shadow.appendChild(style);
  shadow.appendChild(wrap);

  function readCachedFormConfig() {
    try {
      var raw = window.sessionStorage.getItem(FORM_CACHE_KEY);
      if (!raw) return null;
      var parsed = JSON.parse(raw);
      if (!parsed || !parsed.data || !parsed.ts) return null;
      if ((Date.now() - Number(parsed.ts)) > FORM_CACHE_TTL_MS) return null;
      return parsed.data;
    } catch (e) {
      return null;
    }
  }

  function writeCachedFormConfig(data) {
    try {
      window.sessionStorage.setItem(FORM_CACHE_KEY, JSON.stringify({ ts: Date.now(), data: data }));
    } catch (e) {}
  }

  function mergeUiConfig(formName, serverUi) {
    serverUi = serverUi && typeof serverUi === 'object' ? serverUi : {};
    return {
      title: UI_OVERRIDES.title || serverUi.title || formName || 'Online Inquiry',
      subtitle: UI_OVERRIDES.subtitle || serverUi.subtitle || 'Leave your requirements and we will contact you shortly.',
      button_text: UI_OVERRIDES.button_text || serverUi.button_text || 'Submit Inquiry',
      success_message: UI_OVERRIDES.success_message || serverUi.success_message || 'Submitted successfully, we will contact you soon.',
      helper_text: UI_OVERRIDES.helper_text || serverUi.helper_text || '',
      theme_color: UI_OVERRIDES.theme_color || serverUi.theme_color || '#1f6feb',
      floating_position: (UI_OVERRIDES.floating_position || serverUi.floating_position || 'right').toLowerCase()
    };
  }

  function fetchFormConfig() {
    if (loadedFormConfig) return Promise.resolve(loadedFormConfig);

    var cached = readCachedFormConfig();
    if (cached) {
      loadedFormConfig = cached;
      return Promise.resolve(cached);
    }

    if (loadingFormPromise) return loadingFormPromise;

    loadingFormPromise = fetch(GET_FORM_API, { method: 'GET' })
      .then(function (res) { return res.json(); })
      .then(function (json) {
        if (!json || !json.success || !json.data) {
          throw new Error((json && json.message) || 'Failed to load form config');
        }

        var ui = mergeUiConfig(String(json.data.form_name || ''), json.data.ui || {});
        var fields = Array.isArray(json.data.fields) ? json.data.fields.slice() : [];
        fields = fields.map(function (f, index) {
          return {
            key: String(f.name || f.field_key || ('custom_' + (index + 1))),
            label: String(f.label || f.field_label || f.name || ('Field ' + (index + 1))),
            type: String(f.type || f.field_type || 'text').toLowerCase(),
            required: !!f.required,
            enabled: f.enabled === undefined ? true : !!f.enabled,
            sort: Number(f.sort || f.sort_order || (index + 1)),
            placeholder: String(f.placeholder || ''),
            options: String(f.options || ''),
            display_width: String(f.display_width || 'full')
          };
        }).filter(function (f) { return f.enabled !== false; });

        ['name', 'tel', 'email', 'message'].forEach(function (k) {
          if (!fields.some(function (f) { return f.key === k; })) {
            fields.push({
              key: k,
              label: k === 'tel' ? 'Tel' : (k.charAt(0).toUpperCase() + k.slice(1)),
              type: k === 'email' ? 'email' : (k === 'message' ? 'textarea' : (k === 'tel' ? 'phone' : 'text')),
              required: k === 'name' || k === 'email',
              enabled: true,
              sort: k === 'name' ? 10 : (k === 'tel' ? 20 : (k === 'email' ? 30 : 40)),
              placeholder: '',
              options: '',
              display_width: 'full'
            });
          }
        });

        fields.sort(function (a, b) { return a.sort - b.sort; });

        var data = {
          site_id: Number(json.data.site_id || 0),
          form_id: Number(json.data.form_id || json.data.id || 0),
          fields: fields,
          ui: ui
        };

        loadedFormConfig = data;
        writeCachedFormConfig(data);
        return data;
      })
      .finally(function () { loadingFormPromise = null; });

    return loadingFormPromise;
  }

  function setLoading(message) {
    panel.innerHTML = '<div class="card-body"><div class="loading">' + message + '</div></div>';
  }

  function getAttributionPayload(values) {
    function readQueryParams() {
      var defaults = { utm_source: '', utm_medium: '', utm_campaign: '', utm_term: '', utm_content: '', gclid: '', fbclid: '', wbraid: '', gbraid: '', msclkid: '', ttclid: '' };
      try {
        var u = new URL(window.location.href);
        Object.keys(defaults).forEach(function (k) { defaults[k] = u.searchParams.get(k) || ''; });
      } catch (e) {}
      return defaults;
    }

    function detectSource(query) {
      var referrer = document.referrer || '';
      var source = 'direct', medium = 'none', channel = 'direct';
      if (query.gclid || query.wbraid || query.gbraid) {
        source = query.utm_source || 'google'; medium = query.utm_medium || 'cpc'; channel = 'paid_search';
      } else if (query.fbclid || query.ttclid) {
        source = query.utm_source || (query.ttclid ? 'tiktok' : 'facebook'); medium = query.utm_medium || 'paid_social'; channel = 'paid_social';
      } else if (query.utm_source) {
        source = query.utm_source; medium = query.utm_medium || 'utm'; channel = 'campaign';
      } else if (referrer) {
        try {
          var refHost = new URL(referrer).hostname.toLowerCase();
          source = refHost; medium = 'referral'; channel = 'referral';
        } catch (e) {
          source = 'referral'; medium = 'referral'; channel = 'referral';
        }
      }
      return { source_channel: channel, source_platform: source, source_medium: medium, referrer_url: referrer || null, landing_page: window.location.href || null };
    }

    var query = readQueryParams();
    var persisted = readPersistedAttribution();
    var merged = {};
    Object.keys(query).forEach(function (k) { merged[k] = query[k] || persisted[k] || ''; });
    if (query.gclid || query.wbraid || query.gbraid || query.fbclid || query.utm_source || query.utm_medium || query.utm_campaign) {
      persistAttribution(merged);
    }

    var detected = detectSource(merged);

    return {
      source_channel: detected.source_channel,
      source_platform: detected.source_platform,
      source_medium: detected.source_medium,
      referrer_url: detected.referrer_url,
      landing_page: detected.landing_page,
      utm_source: merged.utm_source || null,
      utm_medium: merged.utm_medium || null,
      utm_campaign: merged.utm_campaign || null,
      utm_term: merged.utm_term || null,
      utm_content: merged.utm_content || null,
      fbclid: merged.fbclid || null,
      gclid: values.gclid || merged.gclid || null,
      wbraid: merged.wbraid || null,
      gbraid: merged.gbraid || null,
      client_id: getGaClientIdFromCookie() || null
    };
  }

  function openPanel() {
    panel.classList.add('open');
    overlay.classList.add('open');
    toggle.setAttribute('aria-expanded', 'true');

    if (!panel.querySelector('form')) {
      if (loadedFormConfig) {
        renderForm(loadedFormConfig);
      } else {
        loadFormAndRender();
      }
    }
  }

  function closePanel() {
    panel.classList.remove('open');
    overlay.classList.remove('open');
    toggle.setAttribute('aria-expanded', 'false');
  }

  function createControl(field) {
    var type = field.type;
    var el;

    if (type === 'textarea') {
      el = document.createElement('textarea');
      el.className = 'textarea';
    } else if (type === 'select') {
      el = document.createElement('select');
      el.className = 'select';
      var opts = String(field.options || '').split(',').map(function (x) { return x.trim(); }).filter(Boolean);
      if (opts.length === 0) opts = ['Please select'];
      opts.forEach(function (text, idx) {
        var o = document.createElement('option');
        o.value = idx === 0 && text === 'Please select' ? '' : text;
        o.textContent = text;
        el.appendChild(o);
      });
    } else {
      el = document.createElement('input');
      el.className = 'input';
      el.type = type === 'email' ? 'email' : (type === 'phone' ? 'tel' : 'text');
      if (field.key === 'tel') el.autocomplete = 'tel';
      if (field.key === 'email') el.autocomplete = 'email';
      if (field.key === 'name') el.autocomplete = 'name';
    }

    el.name = field.key;
    el.id = 'inq_' + field.key;
    el.setAttribute('aria-label', field.label || field.key);
    el.placeholder = field.placeholder || ('Please enter ' + (field.label || field.key));
    if (field.required) el.required = true;

    return el;
  }

  function renderForm(cfg) {
    var ui = cfg.ui || {};
    var fields = Array.isArray(cfg.fields) ? cfg.fields : [];

    wrap.style.setProperty('--theme', ui.theme_color || '#1f6feb');
    wrap.style.setProperty('--theme-dark', '#1755af');

    if (displayMode !== 'inline') {
      var floatingWrap = wrap.querySelector('.floating-wrap');
      if (floatingWrap) {
        if ((ui.floating_position || 'right') === 'left') {
          floatingWrap.classList.add('left');
        } else {
          floatingWrap.classList.remove('left');
        }
      }
      toggle.textContent = (ui.button_text || 'Inquiry').length > 10 ? 'Inquiry' : (ui.button_text || 'Inquiry');
    }

    panel.innerHTML = '';

    var head = document.createElement('div');
    head.className = 'card-head';

    if (displayMode === 'floating') {
      var headRow = document.createElement('div');
      headRow.className = 'floating-head';

      var textWrap = document.createElement('div');
      var titleEl = document.createElement('h3');
      titleEl.className = 'title';
      titleEl.textContent = ui.title || 'Online Inquiry';
      textWrap.appendChild(titleEl);

      if (ui.subtitle) {
        var subtitleEl = document.createElement('p');
        subtitleEl.className = 'subtitle';
        subtitleEl.textContent = ui.subtitle;
        textWrap.appendChild(subtitleEl);
      }

      var closeBtn = document.createElement('button');
      closeBtn.className = 'close';
      closeBtn.type = 'button';
      closeBtn.textContent = '×';
      closeBtn.setAttribute('aria-label', 'Close inquiry form');
      closeBtn.addEventListener('click', closePanel);

      headRow.appendChild(textWrap);
      headRow.appendChild(closeBtn);
      head.appendChild(headRow);
    } else {
      var title = document.createElement('h3');
      title.className = 'title';
      title.textContent = ui.title || 'Online Inquiry';
      head.appendChild(title);

      if (ui.subtitle) {
        var subtitle = document.createElement('p');
        subtitle.className = 'subtitle';
        subtitle.textContent = ui.subtitle;
        head.appendChild(subtitle);
      }
    }

    var body = document.createElement('div');
    body.className = 'card-body';

    var form = document.createElement('form');
    form.noValidate = true;

    var grid = document.createElement('div');
    grid.className = 'grid';

    fields.forEach(function (field) {
      var box = document.createElement('div');
      box.className = 'field ' + (field.display_width === 'half' ? '' : 'full');

      var label = document.createElement('label');
      label.className = 'label';
      label.setAttribute('for', 'inq_' + field.key);
      label.textContent = field.label || field.key;
      if (field.required) {
        var req = document.createElement('span');
        req.className = 'required';
        req.textContent = '*';
        label.appendChild(req);
      }

      var control = createControl(field);
      box.appendChild(label);
      box.appendChild(control);
      grid.appendChild(box);
    });

    var honeypot = document.createElement('input');
    honeypot.type = 'text';
    honeypot.name = 'website';
    honeypot.autocomplete = 'off';
    honeypot.tabIndex = -1;
    honeypot.className = 'hidden';

    var submitBtn = document.createElement('button');
    submitBtn.type = 'submit';
    submitBtn.className = 'submit';
    submitBtn.textContent = ui.button_text || 'Submit Inquiry';

    var helper = document.createElement('div');
    helper.className = 'helper' + (ui.helper_text ? '' : ' hidden');
    helper.textContent = ui.helper_text || '';

    var msg = document.createElement('div');
    msg.className = 'msg';

    var footer = document.createElement('div');
    footer.className = 'footer';
    footer.appendChild(submitBtn);
    footer.appendChild(helper);
    footer.appendChild(msg);

    form.appendChild(grid);
    form.appendChild(honeypot);
    form.appendChild(footer);

    body.appendChild(form);
    panel.appendChild(head);
    panel.appendChild(body);

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var fd = new FormData(form);
      var values = {};
      fd.forEach(function (v, k) { values[k] = String(v).trim(); });

      if (values.website) {
        msg.className = 'msg err';
        msg.textContent = 'Submission failed, please refresh and retry.';
        return;
      }

      for (var i = 0; i < fields.length; i++) {
        var field = fields[i];
        if (field.required && !values[field.key]) {
          msg.className = 'msg err';
          msg.textContent = 'Please fill required field: ' + (field.label || field.key);
          var invalid = form.querySelector('[name="' + field.key + '"]');
          if (invalid) invalid.focus();
          return;
        }
      }

      msg.className = 'msg';
      msg.textContent = '';
      submitBtn.disabled = true;
      submitBtn.textContent = 'Submitting...';

      var payload = {
        api_key: apiKey,
        site_id: cfg.site_id,
        form_id: cfg.form_id
      };

      // Explicit builtin mapping by field_key first.
      payload.name = values.name || '';
      payload.tel = values.tel || values.phone || '';
      payload.phone = payload.tel;
      payload.email = values.email || '';
      payload.message = values.message || '';

      fields.forEach(function (field) {
        var key = field.key;
        if (values[key] !== undefined) {
          payload[key] = values[key];
        }
      });

      var tracking = getAttributionPayload(values);
      Object.keys(tracking).forEach(function (k) { payload[k] = tracking[k]; });

      fetch(SUBMIT_API, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-API-KEY': apiKey
        },
        body: JSON.stringify(payload)
      })
        .then(function (res) { return res.json(); })
        .then(function (json) {
          if (json && (json.success || json.status === 'success')) {
            msg.className = 'msg ok';
            msg.textContent = ui.success_message || 'Submitted successfully';
            form.reset();
            if (displayMode === 'floating') {
              setTimeout(closePanel, 1200);
            }
          } else {
            msg.className = 'msg err';
            msg.textContent = (json && json.message) ? json.message : 'Submission failed, please try again later';
          }
        })
        .catch(function () {
          msg.className = 'msg err';
          msg.textContent = 'Network error, please try again later';
        })
        .finally(function () {
          submitBtn.disabled = false;
          submitBtn.textContent = ui.button_text || 'Submit Inquiry';
        });
    });
  }

  function loadFormAndRender() {
    setLoading('Loading form...');
    fetchFormConfig().then(renderForm).catch(function (err) {
      panel.innerHTML = '<div class="card-body"><div class="msg err" style="display:block">' + (err.message || 'Failed to load form') + '</div></div>';
    });
  }

  if (displayMode === 'inline') {
    loadFormAndRender();
  } else {
    setLoading('Click button to open form');
    setTimeout(function () { fetchFormConfig().catch(function () {}); }, 1200);
  }
})();
