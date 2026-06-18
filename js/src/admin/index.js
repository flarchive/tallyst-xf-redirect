import app from 'flarum/admin/app';
import m from 'mithril';

const LICENSE_STATES = {
  active: {
    cls: 'Alert Alert--success',
    text: 'License active — this domain is licensed and the extension is running.',
  },
  inactive: {
    cls: 'Alert Alert--error',
    text:
      'This domain is not licensed, so redirects are turned OFF. Make sure the ' +
      'domain you entered at purchase matches this forum’s address, then run ' +
      '`php flarum redirect:license-check`.',
  },
  unverified: {
    cls: 'Alert Alert--warning',
    text:
      'License not yet verified (the license server could not be reached). The ' +
      'extension keeps working in the meantime; it re-checks automatically.',
  },
};

app.initializers.add('tallyst-xf-redirect', () => {
  app.registry
    .for('tallyst-xf-redirect')
    // License status banner (read-only). Value is refreshed server-side by the
    // daily phone-home; here we only display the cached result.
    .registerSetting(() => {
      const status = app.data.settings['tallyst-xf-redirect.license.status'] || 'unverified';
      const s = LICENSE_STATES[status] || LICENSE_STATES.unverified;
      return m('div', { className: s.cls, style: 'margin-bottom:15px' }, s.text);
    })
    .registerSetting({
      setting: 'tallyst-xf-redirect.domains',
      type: 'textarea',
      label: 'Old domains (one per line)',
      help:
        'Only needed if your forum used a DIFFERENT web address in the past — for ' +
        'example before a rebrand, or when it ran on another platform/domain. List ' +
        'each old domain here (one per line) and the extension will redirect their ' +
        'old links to the matching new page. ' +
        'Your current domain (and its “www.” version) is already covered automatically, ' +
        'so leave this empty if the forum kept the same address. ' +
        'Important: an old domain must still point to THIS server (its DNS and the web ' +
        'server must route it here) for its links to actually arrive — listing it here ' +
        'alone does not redirect traffic by itself.',
    })
    .registerSetting({
      setting: 'tallyst-xf-redirect.fallback',
      type: 'select',
      label: 'When an old link points to content that no longer exists',
      help:
        'Some old links point to threads or users that were deleted and have no ' +
        'equivalent in Flarum. Choose what visitors see in that case. “410 Gone” is ' +
        'the cleanest signal for Google (it clearly says the page is gone); the ' +
        'redirect options are friendlier for visitors but less precise for SEO.',
      default: 'gone',
      options: {
        gone: '410 Gone — tells Google it no longer exists (recommended)',
        home: 'Send the visitor to the home page',
        search: 'Send the visitor to search',
      },
    })
    .registerSetting({
      setting: 'tallyst-xf-redirect.canonicalHost',
      type: 'switch',
      label: 'Send all old-domain traffic to the new domain (canonical host)',
      help:
        'Turn this ON only when you CHANGED domains during the migration. It ' +
        'permanently redirects every visit to an old domain to the same page on your ' +
        'new domain, so the old domain only ever redirects and never shows content. ' +
        'This prevents the same page from existing on two addresses at once — which ' +
        'splits and hurts your Google ranking — and consolidates all traffic and ' +
        'ranking onto the new domain. ' +
        'Leave it OFF if your forum kept the same domain (it has no effect then).',
    });
});
