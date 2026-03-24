(function () {
  'use strict';

  function getCurrentScript() {
    if (document.currentScript) return document.currentScript;
    var scripts = document.getElementsByTagName('script');
    return scripts[scripts.length - 1] || null;
  }

  function getScriptUrl() {
    var script = getCurrentScript();
    if (script && script.src) {
      try {
        return new URL(script.src, window.location.href);
      } catch (e) {
        return null;
      }
    }
    return null;
  }

  function getParam(name) {
    var url = getScriptUrl();
    if (url) {
      var v = url.searchParams.get(name);
      if (v) return v;
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
    try {
      setCookie(ATTR_COOKIE_KEY, JSON.stringify(data || {}), ATTR_COOKIE_DAYS);
    } catch (e) {}
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
    '.wrap { font-family: Arial, sans-serif; }',
    '.wrap.floating { position: fixed; right: 20px; bottom: 20px; z-index: 2147483000; }',
    '.wrap.inline { width: 100%; max-width: 420px; margin: 0; }',

    '.toggle { width: 58px; height: 58px; border-radius: 50%; border: none; cursor: pointer; background: linear-gradient(135deg,#2563eb,#1d4ed8); color: #fff; box-shadow: 0 10px 24px rgba(37,99,235,.35); font-size: 13px; font-weight: 700; transition: transform .22s ease, box-shadow .22s ease; animation: pulse 2s infinite; }',
    '.toggle:hover { transform: translateY(-2px) scale(1.04); box-shadow: 0 14px 28px rgba(37,99,235,.42); }',
    '.toggle:active { transform: scale(0.96); }',

    '@keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(37,99,235,.35), 0 10px 24px rgba(37,99,235,.35); } 70% { box-shadow: 0 0 0 12px rgba(37,99,235,0), 0 10px 24px rgba(37,99,235,.35); } 100% { box-shadow: 0 0 0 0 rgba(37,99,235,0), 0 10px 24px rgba(37,99,235,.35); } }',

    '.panel { width: 360px; max-height: 75vh; overflow: auto; background: #fff; border-radius: 12px; box-shadow: 0 12px 26px rgba(0,0,0,.18); margin-bottom: 10px; display: none; border: 1px solid #e5e7eb; }',
    '.wrap.inline .panel { display:block; width:100%; margin-bottom:0; box-shadow:none; }',
    '.panel.open { display: block; animation: fadeIn .18s ease; }',
    '@keyframes fadeIn { from { opacity:0; transform: translateY(8px);} to { opacity:1; transform: translateY(0);} }',

    '.head { padding: 12px 14px; border-bottom: 1px solid #e5e7eb; font-weight: bold; color: #111827; background:#f8fafc; }',
    '.body { padding: 12px 14px; }',
    '.field { margin-bottom: 10px; }',
    '.field input, .field textarea { width: 100%; box-sizing: border-box; border: 1px solid #d1d5db; border-radius: 8px; padding: 9px; font-size: 14px; font-family: inherit; }',
    '.field textarea { min-height: 88px; resize: vertical; }',
    '.required { color: #dc2626; margin-left: 4px; }',
    '.submit { width: 100%; border: none; border-radius: 8px; padding: 10px; cursor: pointer; background: #2563eb; color: #fff; font-size: 14px; }',
    '.submit[disabled] { background: #9ca3af; cursor: not-allowed; }',
    '.msg { margin-top: 10px; padding: 8px 10px; border-radius: 8px; font-size: 13px; display: none; }',
    '.msg.ok { background: #dcfce7; color: #166534; display: block; }',
    '.msg.err { background: #fee2e2; color: #991b1b; display: block; }',
    '.loading { color: #6b7280; font-size: 13px; }'
  ].join('');

  var wrap = document.createElement('div');
  wrap.className = 'wrap ' + (displayMode === 'inline' ? 'inline' : 'floating');

  var panel = document.createElement('div');
  panel.className = 'panel';
  panel.innerHTML = '<div class="head">Online Inquiry</div><div class="body"></div>';

  var toggle = document.createElement('button');
  toggle.className = 'toggle';
  toggle.type = 'button';
  toggle.textContent = 'Inquiry';

  if (displayMode !== 'inline') {
    toggle.addEventListener('click', function () {
      panel.classList.toggle('open');

      if (panel.classList.contains('open')) {
        var hasRenderedForm = !!panel.querySelector('form');
        if (hasRenderedForm) {
          return;
        }

        if (loadedFormConfig) {
          renderForm(loadedFormConfig);
          return;
        }

        loadFormAndRender();
      }
    });
  }

  wrap.appendChild(panel);
  if (displayMode !== 'inline') {
    wrap.appendChild(toggle);
  }
  shadow.appendChild(style);
  shadow.appendChild(wrap);

  function createInputByType(type) {
    if (type === 'textarea') return document.createElement('textarea');
    var input = document.createElement('input');
    if (type === 'email') input.type = 'email';
    else if (type === 'phone') input.type = 'tel';
    else input.type = 'text';
    return input;
  }

  function setLoading(message) {
    var body = panel.querySelector('.body');
    body.innerHTML = '<div class="loading">' + message + '</div>';
  }

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
    } catch (e) {
      // ignore storage quota or privacy errors
    }
  }

  function fetchFormConfig() {
    if (loadedFormConfig) {
      return Promise.resolve(loadedFormConfig);
    }

    var cached = readCachedFormConfig();
    if (cached) {
      loadedFormConfig = cached;
      return Promise.resolve(cached);
    }

    if (loadingFormPromise) {
      return loadingFormPromise;
    }

    loadingFormPromise = fetch(GET_FORM_API, { method: 'GET' })
      .then(function (res) { return res.json(); })
      .then(function (json) {
        if (!json || !json.success || !json.data) {
          throw new Error((json && json.message) || 'Failed to load form config');
        }

        var data = {
          site_id: Number(json.data.site_id || 0),
          form_id: Number(json.data.form_id || json.data.id || 0),
          fields: Array.isArray(json.data.fields) ? json.data.fields : []
        };

        loadedFormConfig = data;
        writeCachedFormConfig(data);
        return data;
      })
      .finally(function () {
        loadingFormPromise = null;
      });

    return loadingFormPromise;
  }

  function mapPayload(siteId, formId, values, fields) {
    function pickFirstValue(candidates) {
      for (var i = 0; i < candidates.length; i++) {
        var key = candidates[i];
        if (values[key]) return values[key];
      }
      return '';
    }

    function pickByFieldType(type) {
      if (!Array.isArray(fields)) return '';
      for (var i = 0; i < fields.length; i++) {
        var f = fields[i] || {};
        var fieldType = String(f.type || '').toLowerCase();
        var fieldName = String(f.name || '');
        if (fieldType === type && fieldName && values[fieldName]) {
          return values[fieldName];
        }
      }
      return '';
    }

    function pickByLabelKeyword(keywords) {
      if (!Array.isArray(fields)) return '';
      for (var i = 0; i < fields.length; i++) {
        var f = fields[i] || {};
        var fieldName = String(f.name || '');
        if (!fieldName || !values[fieldName]) continue;
        var label = String(f.label || fieldName).toLowerCase();
        for (var j = 0; j < keywords.length; j++) {
          if (label.indexOf(keywords[j]) >= 0) {
            return values[fieldName];
          }
        }
      }
      return '';
    }

    var normalizedEmail = pickFirstValue(['email', 'contact_email']) || pickByFieldType('email') || pickByLabelKeyword(['email', 'e-mail', 'mail']);
    var normalizedName = pickFirstValue(['name', 'full_name', 'contact_name']) || pickByLabelKeyword(['name', 'fullname', 'full name', '联系人', '姓名']);
    var normalizedPhone = pickFirstValue(['phone', 'tel', 'mobile']) || pickByFieldType('phone') || pickByLabelKeyword(['phone', 'tel', 'mobile', 'whatsapp', '电话', '手机']);
    var normalizedMessage = pickFirstValue(['message', 'content', 'inquiry']) || pickByFieldType('textarea') || pickByLabelKeyword(['message', 'inquiry', 'requirement', 'details', '留言', '需求']);
    function readQueryParams() {
      var defaults = {
        utm_source: '', utm_medium: '', utm_campaign: '', utm_term: '', utm_content: '',
        gad_source: '', gad_campaignid: '',
        gclid: '', fbclid: '', wbraid: '', gbraid: '',
        msclkid: '', ttclid: ''
      };
      try {
        var u = new URL(window.location.href);
        Object.keys(defaults).forEach(function (k) {
          defaults[k] = u.searchParams.get(k) || '';
        });
      } catch (e) {}
      return defaults;
    }

    function normalizeUtm(query, detected) {
      var utm = {
        source: query.utm_source || '',
        medium: query.utm_medium || '',
        campaign: query.utm_campaign || '',
        term: query.utm_term || '',
        content: query.utm_content || ''
      };

      var hasGoogleAdClick = !!(query.gclid || query.wbraid || query.gbraid);
      if (!utm.source && hasGoogleAdClick) {
        utm.source = 'google';
      }
      if (!utm.medium && hasGoogleAdClick) {
        utm.medium = 'cpc';
      }
      if (!utm.campaign && query.gad_campaignid) {
        utm.campaign = 'google_ads_' + query.gad_campaignid;
      }
      if (!utm.source && query.gad_source === '1') {
        utm.source = 'google';
      }

      if (!utm.source && detected.source_platform && detected.source_platform !== 'direct') {
        utm.source = detected.source_platform;
      }
      if (!utm.medium && detected.source_medium && detected.source_medium !== 'none') {
        utm.medium = detected.source_medium;
      }

      return utm;
    }

    function detectSource(query) {
      var referrer = document.referrer || '';
      var source = 'direct';
      var medium = 'none';
      var channel = 'direct';

      if (query.gclid || query.wbraid || query.gbraid) {
        source = query.utm_source || 'google';
        medium = query.utm_medium || 'cpc';
        channel = (query.utm_source || '').toLowerCase().indexOf('youtube') >= 0 ? 'paid_video' : 'paid_search';
      } else if (query.msclkid) {
        source = query.utm_source || 'bing';
        medium = query.utm_medium || 'cpc';
        channel = 'paid_search';
      } else if (query.fbclid || query.ttclid) {
        source = query.utm_source || (query.ttclid ? 'tiktok' : 'facebook');
        medium = query.utm_medium || 'paid_social';
        channel = 'paid_social';
      } else if (query.utm_source) {
        source = query.utm_source;
        medium = query.utm_medium || 'utm';
        if (/youtube|yt/.test(source.toLowerCase())) {
          channel = /cpc|paid|video/.test(medium.toLowerCase()) ? 'paid_video' : 'organic_video';
        } else if (/cpc|ppc|paid|display|social/.test(medium.toLowerCase())) {
          channel = 'paid';
        } else if (/seo|organic/.test(medium.toLowerCase())) {
          channel = 'organic_search';
        } else {
          channel = 'campaign';
        }
      } else if (referrer) {
        try {
          var refHost = new URL(referrer).hostname.toLowerCase();
          source = refHost;
          medium = 'referral';
          if (/google\.|bing\.|yahoo\.|baidu\.|yandex\.|duckduckgo\./.test(refHost)) {
            channel = 'organic_search';
          } else if (/youtube\.|youtu\.be/.test(refHost)) {
            channel = 'organic_video';
          } else if (/facebook\.|instagram\.|t\.co|twitter\.|linkedin\.|pinterest\./.test(refHost)) {
            channel = 'organic_social';
          } else {
            channel = 'referral';
          }
        } catch (e) {
          source = 'referral';
          medium = 'referral';
          channel = 'referral';
        }
      }

      return {
        source_channel: channel,
        source_platform: source,
        source_medium: medium,
        referrer_url: referrer || null,
        landing_page: window.location.href || null
      };
    }

    var query = readQueryParams();
    var persisted = readPersistedAttribution();
    var merged = {};
    Object.keys(query).forEach(function (k) {
      merged[k] = query[k] || persisted[k] || '';
    });
    if (query.gclid || query.wbraid || query.gbraid || query.fbclid || query.utm_source || query.utm_medium || query.utm_campaign) {
      persistAttribution(merged);
    }

    var detected = detectSource(merged);
    var normalizedUtm = normalizeUtm(merged, detected);

    var payload = {
      api_key: apiKey,
      site_id: siteId,
      form_id: formId,
      name: normalizedName || values.field_1 || '',
      email: normalizedEmail,
      phone: normalizedPhone,
      message: normalizedMessage,
      source_channel: detected.source_channel,
      source_platform: detected.source_platform,
      source_medium: detected.source_medium,
      referrer_url: detected.referrer_url,
      landing_page: detected.landing_page,
      utm_source: normalizedUtm.source || null,
      utm_medium: normalizedUtm.medium || null,
      utm_campaign: normalizedUtm.campaign || null,
      utm_term: normalizedUtm.term || null,
      utm_content: normalizedUtm.content || null,
      fbclid: merged.fbclid || null,
      gbraid: merged.gbraid || null,
      wbraid: merged.wbraid || null,
      client_id: getGaClientIdFromCookie() || null
    };

    payload.gclid = values.gclid || merged.gclid || null;

    Object.keys(values).forEach(function (k) {
      if (!(k in payload)) payload[k] = values[k];
    });

    return payload;
  }

  function renderForm(formConfig) {
    var body = panel.querySelector('.body');
    body.innerHTML = '';

    var form = document.createElement('form');
    form.noValidate = true;

    var fields = Array.isArray(formConfig.fields) ? formConfig.fields.slice() : [];
    fields.sort(function (a, b) {
      return (Number(a.sort) || 0) - (Number(b.sort) || 0);
    });

    fields.forEach(function (field, index) {
      var name = field.name || ('field_' + (index + 1));
      var type = field.type || 'text';
      var labelText = field.label || name;
      var required = !!field.required;

      var box = document.createElement('div');
      box.className = 'field';

      var normalizedLabel = String(labelText || '').trim();
      if (/^massage$/i.test(normalizedLabel)) {
        normalizedLabel = 'Message';
      }

      var control = createInputByType(type);
      control.name = name;
      control.setAttribute('aria-label', normalizedLabel || name);
      control.placeholder = 'Please enter ' + (normalizedLabel || name) + (required ? ' *' : '');
      if (required) control.required = true;

      box.appendChild(control);
      form.appendChild(box);
    });

    var submitBtn = document.createElement('button');
    submitBtn.type = 'submit';
    submitBtn.className = 'submit';
    submitBtn.textContent = 'Submit Inquiry';

    var msg = document.createElement('div');
    msg.className = 'msg';

    form.appendChild(submitBtn);
    form.appendChild(msg);
    body.appendChild(form);

    form.addEventListener('submit', function (e) {
      e.preventDefault();

      var fd = new FormData(form);
      var values = {};
      fd.forEach(function (v, k) {
        values[k] = String(v).trim();
      });

      for (var i = 0; i < fields.length; i++) {
        if (fields[i].required) {
          var k = fields[i].name || ('field_' + (i + 1));
          if (!values[k]) {
            msg.className = 'msg err';
            msg.textContent = 'Please fill required field: ' + (fields[i].label || k);
            return;
          }
        }
      }

      submitBtn.disabled = true;
      submitBtn.textContent = 'Submitting...';
      msg.className = 'msg';
      msg.textContent = '';

      var payload = mapPayload(formConfig.site_id, formConfig.form_id, values, fields);

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
            msg.textContent = 'Submitted successfully';
            form.reset();
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
          submitBtn.textContent = 'Submit Inquiry';
        });
    });
  }

  function loadFormAndRender() {
    setLoading('Loading form...');
    fetchFormConfig()
      .then(function (cfg) {
        renderForm(cfg);
      })
      .catch(function (err) {
        var body = panel.querySelector('.body');
        body.innerHTML = '<div class="msg err" style="display:block">' + (err.message || 'Failed to load form') + '</div>';
      });
  }

  if (displayMode === 'inline') {
    panel.classList.add('open');
    loadFormAndRender();
  } else {
    setLoading('Click button to open form');
    // Warm up after page settled to improve first-open latency.
    setTimeout(function () {
      fetchFormConfig().catch(function () { /* noop */ });
    }, 1200);
  }
})();
