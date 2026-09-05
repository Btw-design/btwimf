/* ════════════════════════════════════════════════
   BTW IMF — interactions
   ════════════════════════════════════════════════ */
(function () {
  'use strict';

  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ── Graceful fallback for missing images ─────── */
  Array.prototype.slice.call(document.images).forEach(function (img) {
    function flag() { img.classList.add('img-missing'); }
    if (img.complete && img.naturalWidth === 0) flag();
    img.addEventListener('error', flag);
  });

  /* ── Dynamic year ─────────────────────────────── */
  var yr = document.getElementById('yr');
  if (yr) yr.textContent = new Date().getFullYear();

  /* ── Sticky header shadow ─────────────────────── */
  var header = document.getElementById('header');
  function onScroll() {
    if (header) header.classList.toggle('is-scrolled', window.scrollY > 10);
  }
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  /* ── Desktop dropdowns (click/touch + keyboard) ─ */
  var dropBtns = Array.prototype.slice.call(document.querySelectorAll('.drop-btn'));
  function closeDrops(except) {
    dropBtns.forEach(function (b) {
      if (b !== except) {
        b.setAttribute('aria-expanded', 'false');
        var li = b.closest('.has-drop');
        if (li) li.classList.remove('open');
      }
    });
  }
  dropBtns.forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      var li = btn.closest('.has-drop');
      var isOpen = li.classList.toggle('open');
      btn.setAttribute('aria-expanded', String(isOpen));
      closeDrops(btn);
    });
  });
  document.addEventListener('click', function () { closeDrops(null); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeDrops(null);
  });

  /* ── Mobile drawer ────────────────────────────── */
  var burger = document.getElementById('burger');
  var drawer = document.getElementById('drawer');
  var scrim = document.getElementById('scrim');

  function openDrawer() {
    drawer.classList.add('open');
    drawer.setAttribute('aria-hidden', 'false');
    burger.setAttribute('aria-expanded', 'true');
    burger.setAttribute('aria-label', 'Close menu');
    if (scrim) { scrim.hidden = false; requestAnimationFrame(function () { scrim.classList.add('show'); }); }
    document.body.classList.add('lock');
  }
  function closeDrawer() {
    drawer.classList.remove('open');
    drawer.setAttribute('aria-hidden', 'true');
    burger.setAttribute('aria-expanded', 'false');
    burger.setAttribute('aria-label', 'Open menu');
    if (scrim) { scrim.classList.remove('show'); setTimeout(function () { scrim.hidden = true; }, 300); }
    document.body.classList.remove('lock');
  }
  if (burger && drawer) {
    burger.addEventListener('click', function () {
      drawer.classList.contains('open') ? closeDrawer() : openDrawer();
    });
    if (scrim) scrim.addEventListener('click', closeDrawer);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && drawer.classList.contains('open')) closeDrawer();
    });
    // anchor links inside the drawer close it
    drawer.querySelectorAll('a[href^="#"]').forEach(function (a) {
      a.addEventListener('click', closeDrawer);
    });
  }

  /* ── Drawer accordions ────────────────────────── */
  Array.prototype.slice.call(document.querySelectorAll('.d-acc')).forEach(function (acc) {
    acc.addEventListener('click', function () {
      var panel = document.getElementById(acc.getAttribute('aria-controls'));
      var open = acc.getAttribute('aria-expanded') === 'true';
      acc.setAttribute('aria-expanded', String(!open));
      panel.style.maxHeight = open ? '0px' : panel.scrollHeight + 'px';
    });
  });

  /* ── Quote bar: pills + submit ────────────────── */
  var pills = Array.prototype.slice.call(document.querySelectorAll('.qb-pills .pill'));
  pills.forEach(function (pill) {
    pill.addEventListener('click', function () {
      pills.forEach(function (p) { p.classList.remove('is-active'); });
      pill.classList.add('is-active');
    });
  });

  /* ── Shared form submission helper ────────────────
     Every form POSTs to the PHP handler, which stores the lead and emails
     the office. Success UI is shown ONLY after {ok:true}; failures show an
     inline error and leave the form editable. */
  window.__formTs = Date.now();
  var FORM_ENDPOINT = '/form-handler.php';

  /* Honeypot: injected (not in HTML) so it never reaches real users but
     naive bots that fill every field get caught server-side. */
  Array.prototype.forEach.call(
    document.querySelectorAll('.contact-form,.careers-form,.pcf,.claim-form,.renew-form,.qb-form'),
    function (fm) {
      if (fm.querySelector('input[name="_hp"]')) return;
      var hp = document.createElement('input');
      hp.type = 'text'; hp.name = '_hp'; hp.tabIndex = -1;
      hp.setAttribute('autocomplete', 'off'); hp.setAttribute('aria-hidden', 'true');
      hp.style.cssText = 'position:absolute!important;left:-9999px!important;width:1px;height:1px;opacity:0;pointer-events:none';
      fm.appendChild(hp);
    }
  );

  function setLoading(btn, on) {
    if (!btn) return;
    if (on) { btn.setAttribute('data-loading', '1'); btn.setAttribute('aria-busy', 'true'); btn.disabled = true; }
    else { btn.removeAttribute('data-loading'); btn.removeAttribute('aria-busy'); btn.disabled = false; }
  }
  function showFormError(form, msg) {
    var e = form.querySelector(':scope > .form-error') || form.querySelector('.form-error');
    if (!e) {
      e = document.createElement('div');
      e.className = 'form-error';
      e.setAttribute('role', 'alert');
      e.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 9v4M12 17h.01M10.3 4.3 3 17a2 2 0 0 0 1.7 3h14.6a2 2 0 0 0 1.7-3L13.7 4.3a2 2 0 0 0-3.4 0Z"/></svg><span></span>';
      var sb = form.querySelector('[type="submit"]');
      if (sb && sb.parentNode === form) form.insertBefore(e, sb.nextSibling);
      else form.appendChild(e);
    }
    e.querySelector('span').textContent = msg;
  }
  function sendForm(form, btn, type, extra, onSuccess) {
    var fd;
    try { fd = new FormData(form); } catch (err) { fd = new FormData(); }
    fd.append('_form', type);
    fd.append('_ts', String(window.__formTs));
    fd.append('_page', location.href);
    if (extra) Object.keys(extra).forEach(function (k) { fd.set(k, extra[k]); });
    var old = form.querySelector('.form-error'); if (old) old.parentNode.removeChild(old);
    form.classList.add('is-submitting');
    setLoading(btn, true);
    fetch(FORM_ENDPOINT, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) {
        return r.text().then(function (t) {
          var j = {}; try { j = JSON.parse(t); } catch (e) {}
          return { ok: r.ok, j: j };
        });
      })
      .then(function (res) {
        form.classList.remove('is-submitting');
        setLoading(btn, false);
        if (res.j && res.j.ok === true) { onSuccess(); }
        else { showFormError(form, (res.j && res.j.error) || 'We could not send your request just now. Please try again, or call / WhatsApp us on 90043 83987.'); }
      })
      .catch(function () {
        form.classList.remove('is-submitting');
        setLoading(btn, false);
        showFormError(form, 'Network error — please check your connection and try again, or call us on 022 4526 0380.');
      });
  }

  /* ── Quote bar: phone form ────────────────────── */
  var qbForm = document.querySelector('.qb-form');
  if (qbForm) {
    qbForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var input = qbForm.querySelector('input[name="phone"]');
      var digits = (input.value || '').replace(/\D/g, '');
      if (digits.length < 10) {
        input.style.borderColor = '#E06B6B';
        input.focus();
        return;
      }
      var need = 'your needs';
      var active = document.querySelector('.qb-pills .pill.is-active');
      if (active) need = active.textContent.trim().toLowerCase();
      sendForm(qbForm, qbForm.querySelector('[type="submit"]'), 'quick-quote', { interest: need }, function () {
        var ok = document.createElement('div');
        ok.className = 'qb-success';
        ok.innerHTML =
          '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4.5 12.5l5 5L19.5 7"/></svg>' +
          '<span>Thank you! An advisor will call you about <strong>&nbsp;' + need + '&nbsp;</strong> within working hours.</span>';
        qbForm.parentNode.replaceChild(ok, qbForm);
      });
    });
  }

  /* ── Vehicle registration lookup (mock) ───────── */
  var vehForm = document.getElementById('vehForm');
  if (vehForm) {
    var vehInput = document.getElementById('vehreg');
    var vehMsg = document.getElementById('qbvMsg');
    var rx = /^[A-Z]{2}[ -]?\d{1,2}[ -]?[A-Z]{0,3}[ -]?\d{1,4}$/;
    vehInput.addEventListener('input', function () {
      vehInput.value = vehInput.value.toUpperCase();
      vehInput.classList.remove('is-invalid');
      if (vehMsg) vehMsg.className = 'qbv-msg';
    });
    vehForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var v = vehInput.value.trim();
      if (!rx.test(v)) {
        vehInput.classList.add('is-invalid');
        if (vehMsg) { vehMsg.textContent = 'Please enter a valid registration, e.g. MH 12 AB 1234.'; vehMsg.className = 'qbv-msg show err'; }
        vehInput.focus();
        return;
      }
      var btn = vehForm.querySelector('[type="submit"]');
      setLoading(btn, true);
      if (vehMsg) { vehMsg.textContent = 'Checking…'; vehMsg.className = 'qbv-msg show'; }
      var fd = new FormData();
      fd.append('_form', 'vehicle-lookup');
      fd.append('_ts', String(window.__formTs));
      fd.append('_page', location.href);
      fd.append('vehreg', v);
      fetch(FORM_ENDPOINT, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' } })
        .then(function (r) { return r.text().then(function (t) { var j = {}; try { j = JSON.parse(t); } catch (e) {} return j; }); })
        .then(function (j) {
          setLoading(btn, false);
          if (j && j.ok) {
            if (vehMsg) {
              vehMsg.innerHTML = 'Noted <strong>' + v + '</strong>. Share your mobile number in the form above or ' +
                '<a href="https://wa.me/919004383987?text=Renewal%20quote%20for%20' + encodeURIComponent(v) + '" target="_blank" rel="noopener">message us on WhatsApp</a> and an advisor will send renewal quotes.';
              vehMsg.className = 'qbv-msg show ok';
            }
          } else {
            if (vehMsg) { vehMsg.textContent = (j && j.error) || 'Could not submit just now — please WhatsApp us on 90043 83987.'; vehMsg.className = 'qbv-msg show err'; }
          }
        })
        .catch(function () {
          setLoading(btn, false);
          if (vehMsg) { vehMsg.textContent = 'Network error — please try again or WhatsApp us on 90043 83987.'; vehMsg.className = 'qbv-msg show err'; }
        });
    });
  }

  /* ── Animated counters ────────────────────────── */
  function animateNum(el) {
    var target = parseInt(el.getAttribute('data-n'), 10);
    var suffix = el.getAttribute('data-suf') || '';
    if (reduceMotion) { el.textContent = target.toLocaleString('en-IN') + suffix; return; }
    var t0 = performance.now(), dur = 1500;
    function tick(t) {
      var p = Math.min((t - t0) / dur, 1);
      var eased = 1 - Math.pow(1 - p, 3);
      el.textContent = Math.round(target * eased).toLocaleString('en-IN') + suffix;
      if (p < 1) requestAnimationFrame(tick);
    }
    requestAnimationFrame(tick);
  }
  var numIO = new IntersectionObserver(function (entries) {
    entries.forEach(function (en) {
      if (!en.isIntersecting) return;
      numIO.unobserve(en.target);
      animateNum(en.target);
    });
  }, { threshold: 0.5 });
  Array.prototype.slice.call(document.querySelectorAll('.num')).forEach(function (n) { numIO.observe(n); });

  /* ── Scroll reveals (with stagger inside grids) ─ */
  var revealEls = Array.prototype.slice.call(document.querySelectorAll('.rv'));
  revealEls.forEach(function (el) {
    var parent = el.parentElement;
    if (!parent) return;
    var sibs = Array.prototype.filter.call(parent.children, function (c) {
      return c.classList && c.classList.contains('rv');
    });
    var idx = sibs.indexOf(el);
    if (idx > 0) el.style.transitionDelay = Math.min(idx * 70, 420) + 'ms';
  });
  var rvIO = new IntersectionObserver(function (entries) {
    entries.forEach(function (en) {
      if (!en.isIntersecting) return;
      en.target.classList.add('in');
      rvIO.unobserve(en.target);
    });
  }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
  revealEls.forEach(function (el) { rvIO.observe(el); });

  /* ── Testimonial carousel ─────────────────────── */
  var track = document.getElementById('ttrack');
  var prevBtn = document.getElementById('tprev');
  var nextBtn = document.getElementById('tnext');
  var dotsWrap = document.getElementById('tdots');

  if (track && prevBtn && nextBtn && dotsWrap) {
    var cards = Array.prototype.slice.call(track.children);
    function step() {
      if (cards.length < 2) return track.clientWidth;
      return cards[1].offsetLeft - cards[0].offsetLeft;
    }
    function pageCount() {
      var s = step();
      return Math.max(1, Math.round((track.scrollWidth - track.clientWidth) / s) + 1);
    }
    function currentPage() {
      return Math.min(pageCount() - 1, Math.round(track.scrollLeft / step()));
    }
    function buildDots() {
      dotsWrap.innerHTML = '';
      for (var i = 0; i < pageCount(); i++) {
        var d = document.createElement('button');
        d.type = 'button';
        d.className = 't-dot' + (i === currentPage() ? ' is-active' : '');
        d.setAttribute('aria-label', 'Go to testimonials page ' + (i + 1));
        (function (idx) {
          d.addEventListener('click', function () {
            track.scrollTo({ left: idx * step(), behavior: reduceMotion ? 'auto' : 'smooth' });
          });
        })(i);
        dotsWrap.appendChild(d);
      }
    }
    function syncDots() {
      var cur = currentPage();
      Array.prototype.slice.call(dotsWrap.children).forEach(function (d, i) {
        d.classList.toggle('is-active', i === cur);
      });
    }
    prevBtn.addEventListener('click', function () {
      track.scrollTo({ left: Math.max(0, track.scrollLeft - step()), behavior: reduceMotion ? 'auto' : 'smooth' });
    });
    nextBtn.addEventListener('click', function () {
      track.scrollTo({
        left: Math.min(track.scrollWidth - track.clientWidth, track.scrollLeft + step()),
        behavior: reduceMotion ? 'auto' : 'smooth'
      });
    });
    var rT;
    track.addEventListener('scroll', function () {
      clearTimeout(rT); rT = setTimeout(syncDots, 60);
    }, { passive: true });
    window.addEventListener('resize', function () { clearTimeout(rT); rT = setTimeout(buildDots, 120); });
    buildDots();
  }

  /* ── SIP calculator ───────────────────────────── */
  var amt = document.getElementById('amt'),
      yrs = document.getElementById('yrs'),
      ret = document.getElementById('ret');
  if (amt && yrs && ret) {
    var amtO = document.getElementById('amtO'),
        yrsO = document.getElementById('yrsO'),
        retO = document.getElementById('retO'),
        corpusEl = document.getElementById('corpus'),
        investedEl = document.getElementById('invested'),
        returnsEl = document.getElementById('returns'),
        multEl = document.getElementById('mult'),
        scInv = document.getElementById('scInv'),
        scRet = document.getElementById('scRet'),
        scTot = document.getElementById('scTot'),
        dInv = document.getElementById('dInv'),
        dRet = document.getElementById('dRet'),
        bars = document.getElementById('sipBars'),
        barsX = document.getElementById('barsX');

    var SVGNS = 'http://www.w3.org/2000/svg';

    function inr(n) { return '₹' + Math.round(n).toLocaleString('en-IN'); }
    function shortMoney(n) {
      if (n >= 1e7) return '₹' + (n / 1e7).toFixed(n >= 1e9 ? 1 : 2).replace(/\.?0+$/, '') + ' Cr';
      if (n >= 1e5) return '₹' + (n / 1e5).toFixed(1).replace(/\.0$/, '') + ' L';
      return inr(n);
    }
    function paintFill(el) {
      var min = +el.min, max = +el.max;
      var pct = ((+el.value - min) / (max - min)) * 100;
      el.style.setProperty('--fill', pct + '%');
    }
    function fv(P, ratePa, months) {
      var r = ratePa / 100 / 12;
      return P * ((Math.pow(1 + r, months) - 1) / r) * (1 + r);
    }
    function drawDonut(investedPart, gainPart) {
      if (!dInv || !dRet) return;
      var total = investedPart + gainPart || 1;
      var invPct = investedPart / total * 100;
      var retPct = gainPart / total * 100;
      var gap = 1.2;
      dInv.setAttribute('stroke-dasharray', Math.max(0, invPct - gap) + ' ' + (100 - Math.max(0, invPct - gap)));
      dInv.setAttribute('stroke-dashoffset', '0');
      dRet.setAttribute('stroke-dasharray', Math.max(0, retPct - gap) + ' ' + (100 - Math.max(0, retPct - gap)));
      dRet.setAttribute('stroke-dashoffset', String(-invPct));
    }
    function drawBars(P, ratePa, years) {
      if (!bars) return;
      while (bars.firstChild) bars.removeChild(bars.firstChild);
      var W = 260, H = 118, n = Math.min(years, 12);
      var pts = [], maxV = 0;
      for (var i = 1; i <= n; i++) {
        var y = Math.round(years * i / n);
        var v = fv(P, ratePa, y * 12);
        pts.push({ y: y, v: v, inv: P * y * 12 });
        if (v > maxV) maxV = v;
      }
      var bw = W / n * 0.56, gapw = W / n;
      pts.forEach(function (p, idx) {
        var x = idx * gapw + (gapw - bw) / 2;
        var hTot = (p.v / maxV) * (H - 6);
        var hInv = (p.inv / maxV) * (H - 6);
        var r1 = document.createElementNS(SVGNS, 'rect');
        r1.setAttribute('x', x); r1.setAttribute('width', bw);
        r1.setAttribute('y', H - hTot); r1.setAttribute('height', hTot);
        r1.setAttribute('rx', 3); r1.setAttribute('fill', 'rgba(123,191,167,.9)');
        bars.appendChild(r1);
        var r2 = document.createElementNS(SVGNS, 'rect');
        r2.setAttribute('x', x); r2.setAttribute('width', bw);
        r2.setAttribute('y', H - hInv); r2.setAttribute('height', hInv);
        r2.setAttribute('rx', 3); r2.setAttribute('fill', 'rgba(95,121,196,.85)');
        bars.appendChild(r2);
      });
      if (barsX) {
        var mid = pts[Math.floor((n - 1) / 2)];
        barsX.innerHTML = '<span>Year ' + pts[0].y + '</span><span>Year ' + (mid ? mid.y : '') +
          '</span><span>Year ' + pts[n - 1].y + '</span>';
      }
    }
    function sip() {
      var P = +amt.value, months = +yrs.value * 12;
      var corpus = fv(P, +ret.value, months);
      var invested = P * months;
      var gain = Math.max(0, corpus - invested);

      amtO.textContent = inr(P);
      yrsO.textContent = yrs.value + (yrs.value === '1' ? ' year' : ' years');
      retO.textContent = ret.value + '%';

      if (corpusEl) corpusEl.textContent = shortMoney(corpus);
      if (investedEl) investedEl.textContent = shortMoney(invested);
      if (returnsEl) returnsEl.textContent = shortMoney(gain);
      if (scInv) scInv.textContent = shortMoney(invested);
      if (scRet) scRet.textContent = shortMoney(gain);
      if (scTot) scTot.textContent = shortMoney(corpus);
      if (multEl) multEl.textContent = (corpus / invested).toFixed(1) + 'x your money';

      drawDonut(invested, gain);
      drawBars(P, +ret.value, +yrs.value);
      [amt, yrs, ret].forEach(paintFill);
    }
    [amt, yrs, ret].forEach(function (el) { el.addEventListener('input', sip); });
    window.addEventListener('resize', function () { clearTimeout(sip._t); sip._t = setTimeout(sip, 150); });
    sip();
  }

  /* ── Contact form (contact page) ───────────────── */
  var cForm = document.querySelector('.contact-form');
  if (cForm) {
    var emailRx = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    function clearErr(el) { el.classList.remove('is-invalid'); }
    cForm.querySelectorAll('input,select,textarea').forEach(function (el) {
      el.addEventListener('input', function () { clearErr(el); });
      el.addEventListener('change', function () { clearErr(el); });
    });
    cForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var name = cForm.querySelector('#cf-name');
      var phone = cForm.querySelector('#cf-phone');
      var email = cForm.querySelector('#cf-email');
      var topic = cForm.querySelector('#cf-topic');
      var bad = null;
      if (!name.value.trim()) { name.classList.add('is-invalid'); bad = bad || name; }
      if ((phone.value || '').replace(/\D/g, '').length < 10) { phone.classList.add('is-invalid'); bad = bad || phone; }
      if (!emailRx.test(email.value.trim())) { email.classList.add('is-invalid'); bad = bad || email; }
      if (!topic.value) { topic.classList.add('is-invalid'); bad = bad || topic; }
      if (bad) { bad.focus(); return; }

      sendForm(cForm, cForm.querySelector('[type="submit"]'), 'contact', null, function () {
        var card = cForm.closest('.contact-card');
        var first = (name.value.trim().split(/\s+/)[0]) || 'there';
        var done = document.createElement('div');
        done.className = 'form-done';
        done.setAttribute('role', 'status');
        done.innerHTML =
          '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4.5 12.5l5 5L19.5 7"/></svg>' +
          '<div><b>Thanks, ' + first + ' — message received.</b>' +
          '<p>One of our advisors will get back to you on ' + email.value.trim() +
          ' or ' + phone.value.trim() + ' within working hours (Mon&ndash;Sat, 10&nbsp;am&nbsp;&ndash;&nbsp;7&nbsp;pm).</p></div>';
        cForm.replaceWith(done);
        if (card) { var h = card.querySelector('h2'); if (h) h.textContent = 'Message sent'; }
      });
    });
  }

  /* ── Careers application form (careers page) ───── */
  var jForm = document.querySelector('.careers-form');
  if (jForm) {
    var jEmailRx = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    jForm.querySelectorAll('input,select,textarea').forEach(function (el) {
      var drop = function () { el.classList.remove('is-invalid'); };
      el.addEventListener('input', drop);
      el.addEventListener('change', drop);
    });
    jForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var f = {
        name: jForm.querySelector('#jf-name'),
        phone: jForm.querySelector('#jf-phone'),
        email: jForm.querySelector('#jf-email'),
        city: jForm.querySelector('#jf-city'),
        role: jForm.querySelector('#jf-role')
      };
      var bad = null;
      if (!f.name.value.trim()) { f.name.classList.add('is-invalid'); bad = bad || f.name; }
      if ((f.phone.value || '').replace(/\D/g, '').length < 10) { f.phone.classList.add('is-invalid'); bad = bad || f.phone; }
      if (!jEmailRx.test(f.email.value.trim())) { f.email.classList.add('is-invalid'); bad = bad || f.email; }
      if (!f.city.value.trim()) { f.city.classList.add('is-invalid'); bad = bad || f.city; }
      if (!f.role.value) { f.role.classList.add('is-invalid'); bad = bad || f.role; }
      if (bad) { bad.focus(); return; }

      sendForm(jForm, jForm.querySelector('[type="submit"]'), 'careers', null, function () {
        var resume = jForm.querySelector('#jf-resume');
        var fileName = resume && resume.files && resume.files[0] ? resume.files[0].name : '';
        var card = jForm.closest('.contact-card');
        var first = (f.name.value.trim().split(/\s+/)[0]) || 'there';
        var done = document.createElement('div');
        done.className = 'form-done';
        done.setAttribute('role', 'status');
        done.innerHTML =
          '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4.5 12.5l5 5L19.5 7"/></svg>' +
          '<div><b>Thanks, ' + first + ' — application received.</b>' +
          '<p>We&rsquo;ve logged your interest in the <strong>' + f.role.value + '</strong> role' +
          (fileName ? ' along with <strong>' + fileName + '</strong>' : '') +
          '. If there&rsquo;s a fit, our team will contact you on ' + f.email.value.trim() +
          ' within 5&ndash;7 working days.</p></div>';
        jForm.replaceWith(done);
        if (card) { var h = card.querySelector('h2'); if (h) h.textContent = 'Application received'; }
      });
    });
  }

  /* ── Product-page quote form (.pcf) ───────────── */
  var pForm = document.querySelector('.pcf');
  if (pForm) {
    var pEmailRx = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    pForm.querySelectorAll('input,textarea').forEach(function (el) {
      el.addEventListener('input', function () { el.classList.remove('is-invalid'); });
    });
    pForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var n = pForm.querySelector('#pcf-name'),
          ph = pForm.querySelector('#pcf-phone'),
          em = pForm.querySelector('#pcf-email');
      var bad = null;
      if (!n.value.trim()) { n.classList.add('is-invalid'); bad = bad || n; }
      if ((ph.value || '').replace(/\D/g, '').length < 10) { ph.classList.add('is-invalid'); bad = bad || ph; }
      if (!pEmailRx.test(em.value.trim())) { em.classList.add('is-invalid'); bad = bad || em; }
      if (bad) { bad.focus(); return; }

      var product = pForm.getAttribute('data-product') || 'your cover';
      sendForm(pForm, pForm.querySelector('[type="submit"]'), 'product-quote', { product: product }, function () {
        var card = pForm.closest('.contact-card');
        var first = (n.value.trim().split(/\s+/)[0]) || 'there';
        var done = document.createElement('div');
        done.className = 'form-done';
        done.setAttribute('role', 'status');
        done.innerHTML =
          '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4.5 12.5l5 5L19.5 7"/></svg>' +
          '<div><b>Thanks, ' + first + ' — request received.</b>' +
          '<p>A licensed BTW IMF advisor will call you about <strong>' + product +
          '</strong> on ' + ph.value.trim() + ' within working hours (Mon&ndash;Sat, 10&nbsp;am&ndash;7&nbsp;pm).</p></div>';
        pForm.replaceWith(done);
        if (card) { var h = card.querySelector('h2'); if (h) h.textContent = 'Request received'; }
      });
    });
  }

  /* ── Claims assistance form (.claim-form) ─────── */
  var clForm = document.querySelector('.claim-form');
  if (clForm) {
    var clEmailRx = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    clForm.querySelectorAll('input,select,textarea').forEach(function (el) {
      var drop = function () { el.classList.remove('is-invalid'); };
      el.addEventListener('input', drop); el.addEventListener('change', drop);
    });
    clForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var n = clForm.querySelector('#clf-name'),
          ph = clForm.querySelector('#clf-phone'),
          em = clForm.querySelector('#clf-email'),
          ty = clForm.querySelector('#clf-type');
      var bad = null;
      if (!n.value.trim()) { n.classList.add('is-invalid'); bad = bad || n; }
      if ((ph.value || '').replace(/\D/g, '').length < 10) { ph.classList.add('is-invalid'); bad = bad || ph; }
      if (!clEmailRx.test(em.value.trim())) { em.classList.add('is-invalid'); bad = bad || em; }
      if (!ty.value) { ty.classList.add('is-invalid'); bad = bad || ty; }
      if (bad) { bad.focus(); return; }

      sendForm(clForm, clForm.querySelector('[type="submit"]'), 'claim', null, function () {
        var card = clForm.closest('.contact-card');
        var first = (n.value.trim().split(/\s+/)[0]) || 'there';
        var done = document.createElement('div');
        done.className = 'form-done';
        done.setAttribute('role', 'status');
        done.innerHTML =
          '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4.5 12.5l5 5L19.5 7"/></svg>' +
          '<div><b>Thanks, ' + first + ' — we’ve got it.</b>' +
          '<p>A BTW IMF claim manager will call you on ' + ph.value.trim() +
          ' about your <strong>' + ty.value + '</strong> claim within working hours (Mon&ndash;Sat, 10&nbsp;am&ndash;7&nbsp;pm). ' +
          'For an emergency, call 022 45260 380 now.</p></div>';
        clForm.replaceWith(done);
        if (card) { var h = card.querySelector('h2'); if (h) h.textContent = 'Request received'; }
      });
    });
  }

  /* ── Renewal request form (.renew-form) ───────── */
  var rnForm = document.querySelector('.renew-form');
  if (rnForm) {
    var rnEmailRx = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    rnForm.querySelectorAll('input,select,textarea').forEach(function (el) {
      var drop = function () { el.classList.remove('is-invalid'); };
      el.addEventListener('input', drop); el.addEventListener('change', drop);
    });
    rnForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var n = rnForm.querySelector('#rnf-name'),
          ph = rnForm.querySelector('#rnf-phone'),
          em = rnForm.querySelector('#rnf-email'),
          ty = rnForm.querySelector('#rnf-type');
      var bad = null;
      if (!n.value.trim()) { n.classList.add('is-invalid'); bad = bad || n; }
      if ((ph.value || '').replace(/\D/g, '').length < 10) { ph.classList.add('is-invalid'); bad = bad || ph; }
      if (!rnEmailRx.test(em.value.trim())) { em.classList.add('is-invalid'); bad = bad || em; }
      if (!ty.value) { ty.classList.add('is-invalid'); bad = bad || ty; }
      if (bad) { bad.focus(); return; }

      sendForm(rnForm, rnForm.querySelector('[type="submit"]'), 'renewal', null, function () {
        var card = rnForm.closest('.contact-card');
        var first = (n.value.trim().split(/\s+/)[0]) || 'there';
        var done = document.createElement('div');
        done.className = 'form-done';
        done.setAttribute('role', 'status');
        done.innerHTML =
          '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4.5 12.5l5 5L19.5 7"/></svg>' +
          '<div><b>Thanks, ' + first + ' — noted.</b>' +
          '<p>A BTW IMF advisor will call you on ' + ph.value.trim() +
          ' about renewing your <strong>' + ty.value + '</strong> policy, with the options, before it expires ' +
          '(we work Mon&ndash;Sat, 10&nbsp;am&ndash;7&nbsp;pm).</p></div>';
        rnForm.replaceWith(done);
        if (card) { var h = card.querySelector('h2'); if (h) h.textContent = 'Request received'; }
      });
    });
  }

  /* ── Blog category filter (.blog-filter) ──────── */
  var bFilter = document.querySelector('.blog-filter');
  if (bFilter) {
    var bCards = Array.prototype.slice.call(document.querySelectorAll('.blog-card'));
    bFilter.addEventListener('click', function (e) {
      var btn = e.target.closest('button');
      if (!btn) return;
      Array.prototype.slice.call(bFilter.querySelectorAll('button')).forEach(function (b) {
        b.classList.toggle('is-active', b === btn);
        b.setAttribute('aria-pressed', String(b === btn));
      });
      var f = btn.getAttribute('data-filter');
      bCards.forEach(function (c) {
        c.classList.toggle('is-hidden', f !== 'all' && c.getAttribute('data-cat') !== f);
      });
    });
  }

  /* ── On-page TOC scroll-spy (.toc) ────────────── */
  var toc = document.querySelector('.toc');
  if (toc) {
    var links = Array.prototype.slice.call(toc.querySelectorAll('a[href^="#"]'));
    var targets = links.map(function (a) { return document.getElementById(a.getAttribute('href').slice(1)); });
    var spy = function () {
      var y = window.scrollY + 170;
      var idx = 0;
      targets.forEach(function (t, i) { if (t && t.offsetTop <= y) idx = i; });
      links.forEach(function (a, i) { a.classList.toggle('is-active', i === idx); });
    };
    window.addEventListener('scroll', spy, { passive: true });
    spy();
    links.forEach(function (a) {
      a.addEventListener('click', function () {
        setTimeout(function () { links.forEach(function (x) { x.classList.remove('is-active'); }); a.classList.add('is-active'); }, 10);
      });
    });
  }
})();
