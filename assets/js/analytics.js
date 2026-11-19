/**
 * Google Analytics 4 with interaction tracking.
 * The GA script loads only on the live domains, so local testing never sends data.
 * No personal data (email addresses, form contents) is sent.
 */
(function () {
  'use strict';

  var GA_ID = 'G-2R3VBPY8ES';
  var LIVE_HOSTS = ['pratikbutani.com', 'www.pratikbutani.com', 'pratikbutani.github.io'];

  window.dataLayer = window.dataLayer || [];
  window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };

  var isLive = LIVE_HOSTS.indexOf(location.hostname) !== -1;

  if (isLive) {
    var s = document.createElement('script');
    s.async = true;
    s.src = 'https://www.googletagmanager.com/gtag/js?id=' + GA_ID;
    document.head.appendChild(s);
  }

  gtag('js', new Date());
  gtag('config', GA_ID);

  function track(name, params) {
    gtag('event', name, params || {});
    // Off the live site nothing is sent to Google, so show events in the console instead.
    if (!isLive && window.console) console.info('[GA event]', name, params || {});
  }

  // Other scripts on the page (such as the enquiry form) report events through this.
  window.pbTrack = track;

  function clean(text) {
    return (text || '').replace(/\s+/g, ' ').trim().slice(0, 100);
  }

  function sectionOf(el) {
    var sec = el.closest('section[id]');
    if (sec) return sec.id;
    if (el.closest('#header')) return 'navigation';
    if (el.closest('#footer, footer')) return 'footer';
    return 'page';
  }

  // Nearest card or block title (h3/h4) above the clicked element, inside its section.
  function titleOf(el) {
    var stop = el.closest('section') || document.body;
    var node = el.parentElement;
    while (node && node !== stop) {
      var h = node.querySelector('h4, h3');
      if (h) return clean(h.textContent);
      node = node.parentElement;
    }
    return '';
  }

  var SOCIAL = {
    'linkedin.com': 'linkedin',
    'twitter.com': 'twitter',
    'x.com': 'twitter',
    'github.com': 'github',
    'stackoverflow.com': 'stackoverflow',
    'medium.com': 'medium',
    'sessionize.com': 'sessionize',
    'topmate.io': 'topmate'
  };

  function socialNetwork(host) {
    for (var domain in SOCIAL) {
      if (host === domain || host.slice(-(domain.length + 1)) === '.' + domain) return SOCIAL[domain];
    }
    return null;
  }

  document.addEventListener('click', function (e) {
    var el = e.target.closest('a, button');
    if (!el) return;

    var section = sectionOf(el);
    var text = clean(el.textContent) || clean(el.getAttribute('title'));

    if (el.id === 'theme-toggle') {
      var current = document.documentElement.getAttribute('data-theme');
      track('theme_toggle', { theme: current === 'dark' ? 'light' : 'dark' });
      return;
    }

    // Pop-up triggers are tracked by the pop-up itself (enquiry_open).
    if (el.hasAttribute('data-bs-toggle')) return;

    if (el.tagName !== 'A') return;
    var href = el.getAttribute('href') || '';

    if (href.indexOf('mailto:') === 0) {
      track('contact_click', { method: 'email', section: section });
      return;
    }

    if (href.charAt(0) === '#') {
      if (href === '#') {
        if (el.classList.contains('back-to-top')) track('back_to_top', { section: section });
        return;
      }
      var target = href.slice(1);
      if (el.classList.contains('nav-link')) {
        track('nav_click', { target_section: target });
      } else {
        track('cta_click', { section: section, target_section: target, link_text: text });
      }
      return;
    }

    if (/\.pdf($|\?)/i.test(href)) {
      track('resume_download', { section: section });
      return;
    }

    if (el.classList.contains('glightbox') || el.hasAttribute('data-gallery')) {
      track('photo_open', { section: section, photo: clean(el.getAttribute('data-title') || el.getAttribute('title')) });
      return;
    }

    var url;
    try { url = new URL(href, location.href); } catch (err) { return; }
    if (url.host === location.host) return;

    var host = url.hostname.replace(/^www\./, '');
    var network = socialNetwork(host);

    if (network && (section === 'contact' || section === 'hero' || network === 'topmate')) {
      track('contact_click', { method: network, section: section });
    } else if (network && !titleOf(el)) {
      track('social_click', { network: network, section: section });
    } else if (host === 'play.google.com') {
      track('app_store_click', { project: titleOf(el), section: section });
    } else {
      track('project_link_click', {
        project: titleOf(el),
        link_domain: host,
        link_type: network === 'github' ? 'source' : 'website',
        section: section
      });
    }
  }, true);

  // Section views: fire once per section when it reaches the middle of the screen.
  if ('IntersectionObserver' in window) {
    var seen = {};
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        var id = entry.target.id;
        if (entry.isIntersecting && !seen[id]) {
          seen[id] = true;
          track('section_view', { section: id });
          io.unobserve(entry.target);
        }
      });
    }, { rootMargin: '0px 0px -50% 0px', threshold: 0 });

    document.addEventListener('DOMContentLoaded', function () {
      document.querySelectorAll('section[id]').forEach(function (sec) {
        if (sec.id !== 'hero') io.observe(sec);
      });
    });
  }
})();
