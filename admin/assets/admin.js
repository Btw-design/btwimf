/* BTW IMF blog admin — editor client */
(function () {
  'use strict';
  var main = document.querySelector('.admin-main');
  if (!main) return;
  var CSRF = main.getAttribute('data-csrf');
  var SITE = main.getAttribute('data-site') || '';

  function api(action, data, isForm) {
    var body;
    if (isForm) { body = data; body.append('action', action); body.append('_csrf', CSRF); }
    else {
      body = new FormData();
      body.append('action', action); body.append('_csrf', CSRF);
      Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });
    }
    return fetch('api.php', { method: 'POST', body: body })
      .then(function (r) { return r.text().then(function (t) {
        var j; try { j = JSON.parse(t); } catch (e) { j = { ok: false, error: 'Server error (' + r.status + ').' }; }
        return j;
      }); });
  }

  /* ---- Rebuild button (present on every authed page) ---- */
  var rebuild = document.getElementById('rebuildBtn');
  if (rebuild) {
    rebuild.addEventListener('click', function () {
      if (!confirm('Regenerate every published post page, the blog listing and the sitemap?')) return;
      rebuild.disabled = true; rebuild.textContent = 'Rebuilding…';
      api('rebuild', {}).then(function (j) {
        rebuild.disabled = false; rebuild.textContent = 'Rebuild site';
        alert(j.ok ? ('Done — ' + j.count + ' post(s) regenerated.') : (j.error || 'Failed.'));
      });
    });
  }

  /* ---- Editor ---- */
  var editor = document.querySelector('.editor');
  if (!editor) return;

  var F = {};
  ['title','slug','category','date','excerpt','body','hero','heroAlt','seo_title','seo_desc','author']
    .forEach(function (k) { F[k] = document.getElementById('f-' + k); });
  var msg = document.getElementById('edMsg');
  var statusLine = document.getElementById('statusLine');
  var viewLink = document.getElementById('viewLink');
  var unpubBtn = document.getElementById('unpublishBtn');

  function say(text, ok) {
    msg.textContent = text; msg.hidden = false;
    msg.className = 'ed-msg ' + (ok ? 'is-ok' : 'is-err');
  }
  function collect() {
    return {
      title: F.title.value, slug: F.slug.value, category: F.category.value,
      date: F.date.value, excerpt: F.excerpt.value, body_md: F.body.value,
      hero: F.hero.value, heroAlt: F.heroAlt.value,
      seo_title: F.seo_title.value, seo_desc: F.seo_desc.value, author: F.author.value
    };
  }
  function validate() {
    if (!F.title.value.trim()) { say('Add a title first.', false); F.title.focus(); return false; }
    if (!F.body.value.trim()) { say('The body is empty.', false); F.body.focus(); return false; }
    return true;
  }
  function lock(on) {
    editor.querySelectorAll('button, input, select, textarea, a.btn').forEach(function (el) {
      if (on) el.setAttribute('data-was', el.disabled ? '1' : '0'), el.disabled = true;
      else if (el.getAttribute('data-was') === '0') el.disabled = false;
    });
  }

  // auto-slug from title until the user edits the slug
  var slugTouched = !!F.slug.value;
  F.slug.addEventListener('input', function () { slugTouched = true; });
  F.title.addEventListener('input', function () {
    if (slugTouched) return;
    F.slug.value = F.title.value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 80);
  });

  function send(action) {
    if (!validate()) return;
    say('Working…', true);
    lock(true);
    api(action, collect()).then(function (j) {
      lock(false);
      if (!j.ok) { say(j.error || 'Something went wrong.', false); return; }
      if (action === 'publish') {
        say('Published. ', true);
        if (j.url) {
          var a = document.createElement('a'); a.href = j.url; a.target = '_blank'; a.rel = 'noopener'; a.textContent = 'View live ↗';
          msg.appendChild(a);
          if (viewLink) { viewLink.href = j.url; viewLink.hidden = false; }
        }
        if (unpubBtn) unpubBtn.hidden = false;
        if (statusLine) statusLine.innerHTML = 'Status: <b>published</b>';
        if (j.slug && !/slug=/.test(location.search)) {
          history.replaceState(null, '', 'index.php?view=edit&slug=' + j.slug);
        }
      } else {
        say('Draft saved.', true);
        if (statusLine) statusLine.innerHTML = 'Status: <b>' + (j.status || 'draft') + '</b>';
      }
    });
  }

  document.getElementById('publishBtn').addEventListener('click', function () { send('publish'); });
  document.getElementById('draftBtn').addEventListener('click', function () { send('save'); });

  if (unpubBtn) unpubBtn.addEventListener('click', function () {
    if (!confirm('Take this post offline? The page and its sitemap entry will be removed.')) return;
    lock(true);
    api('unpublish', { slug: F.slug.value }).then(function (j) {
      lock(false);
      if (!j.ok) return say(j.error || 'Failed.', false);
      say('Unpublished — now a draft.', true);
      unpubBtn.hidden = true;
      if (viewLink) viewLink.hidden = true;
      if (statusLine) statusLine.innerHTML = 'Status: <b>draft</b>';
    });
  });

  var delBtn = document.getElementById('deleteBtn');
  if (delBtn) delBtn.addEventListener('click', function () {
    if (!confirm('Delete this post permanently? This cannot be undone.')) return;
    lock(true);
    api('delete', { slug: F.slug.value }).then(function (j) {
      if (j.ok) { location.href = 'index.php'; }
      else { lock(false); say(j.error || 'Failed.', false); }
    });
  });

  /* preview */
  var pv = document.getElementById('preview');
  var pvToggle = document.getElementById('previewToggle');
  pvToggle.addEventListener('click', function () {
    if (!pv.hidden) { pv.hidden = true; F.body.hidden = false; pvToggle.textContent = 'Preview'; return; }
    pvToggle.textContent = 'Loading…';
    api('preview', { body_md: F.body.value }).then(function (j) {
      pvToggle.textContent = 'Edit';
      pv.innerHTML = j.ok ? j.html : '<p>Preview failed.</p>';
      pv.hidden = false; F.body.hidden = true;
    });
  });

  /* hero upload */
  var heroFile = document.getElementById('f-heroFile');
  var heroPrev = document.getElementById('heroPreview');
  heroFile.addEventListener('change', function () {
    var file = heroFile.files && heroFile.files[0];
    if (!file) return;
    var fd = new FormData();
    fd.append('image', file);
    say('Uploading image…', true);
    api('upload', fd, true).then(function (j) {
      if (!j.ok) return say(j.error || 'Upload failed.', false);
      F.hero.value = j.path;
      heroPrev.classList.remove('empty');
      heroPrev.innerHTML = '<img src="../' + j.path + '" alt="">';
      say('Image attached. Publish or save to keep it.', true);
    });
  });
})();
