const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch();
  const base = 'http://127.0.0.1:8000';
  const out = [];
  async function visit(ctx, path, label) {
    const p = await ctx.newPage(); const errs = [];
    p.on('console', m => { if (m.type() === 'error') errs.push(m.text().slice(0, 140)); });
    p.on('pageerror', e => errs.push('pageerror: ' + e.message.slice(0, 140)));
    const t = Date.now(); const r = await p.goto(base + path, { waitUntil: 'networkidle' }).catch(e => null);
    const overflow = await p.evaluate(() => document.documentElement.scrollWidth - window.innerWidth).catch(() => -1);
    out.push(`${label} ${path} -> ${r ? r.status() : 'ERR'} ${Date.now() - t}ms hscroll=${overflow}px console_errors=${errs.length}${errs.length ? ' :: ' + errs.join(' | ') : ''}`);
    return p;
  }
  for (const [vw, name] of [[{ width: 375, height: 800 }, 'mobile'], [{ width: 1280, height: 900 }, 'desktop']]) {
    const ctx = await b.newContext({ viewport: vw });
    for (const path of ['/', '/book', '/book/find', '/staff/login', '/magic-link', '/track']) await visit(ctx, path, name);
    const lp = await ctx.newPage(); await lp.goto(base + '/staff/login');
    await lp.fill('input[name=email]', 'qa_system_admin@audit.test'); await lp.fill('input[name=password]', 'Audit-Role-Pass-12345');
    await Promise.all([lp.waitForNavigation(), lp.click('button[type=submit]')]);
    for (const path of ['/staff', '/rentals', '/rentals/new', '/customers', '/customers/new', '/fleet/vehicles', '/fleet/drivers', '/fleet/locations', '/payments', '/admin/users', '/staff/notifications']) await visit(ctx, path, name);
    // double submit: does the submit button disable after the first click?
    const p = await ctx.newPage(); await p.goto(base + '/customers/new');
    await p.fill('input[name=full_name]', 'Double Submit Probe').catch(()=>{});
    await p.selectOption('select[name=customer_type]', 'walk_in').catch(()=>{});
    const btn = p.locator('form button[type=submit]').last();
    await p.evaluate(() => { document.querySelector('form').addEventListener('submit', e => e.preventDefault(), { once: true }); });
    await btn.click(); const disabled = await btn.isDisabled().catch(() => 'n/a');
    out.push(`${name} double-submit guard on /customers/new after first click: disabled=${disabled}`);
    // validation error preserves input
    const v = await ctx.newPage(); await v.goto(base + '/customers/new');
    await v.selectOption('select[name=customer_type]', 'corporate').catch(()=>{});
    await v.fill('input[name=full_name]', 'Preserve Me Inc Contact');
    await v.evaluate(() => { document.querySelectorAll('[required]').forEach(e => e.removeAttribute('required')); });
    await Promise.all([v.waitForLoadState('networkidle'), v.locator('form button[type=submit]').last().click()]);
    await v.waitForTimeout(500);
    const kept = await v.inputValue('input[name=full_name]').catch(() => '(field missing)');
    const msg = (await v.locator('.alert, .notice, [role=alert], .error, .form-error').allTextContents().catch(() => [])).join(' ').slice(0, 120);
    out.push(`${name} invalid corporate customer (no company): url=${v.url().replace(base,'')} name field after error='${kept}' message='${msg}'`);
    await ctx.close();
  }
  console.log(out.join('\n')); await b.close();
})();
