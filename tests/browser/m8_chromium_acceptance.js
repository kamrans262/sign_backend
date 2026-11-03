'use strict';

const fs = require('fs');
const path = require('path');
const assert = require('node:assert/strict');
const {chromium} = require('playwright');

const htmlPath = path.resolve(__dirname, '../../build/m8-stage2/customer_signing.html');
if (!fs.existsSync(htmlPath)) {
  throw new Error('Synthetic Laravel customer signing fixture is missing.');
}
const html = fs.readFileSync(htmlPath, 'utf8');

async function inViewport(browser, width, height, method) {
  const page = await browser.newPage({viewport: {width, height}, deviceScaleFactor: 1});
  const clientErrors = [];
  page.on('pageerror', error => clientErrors.push(error.message));
  let posted = false;
  try {
    await page.route('**/sign/*/submit', async route => {
      const request = route.request();
      assert.equal(request.method(), 'POST');
      const values = new URLSearchParams(request.postData() || '');
      assert.equal(values.get('method'), method);
      assert.equal(values.get('consentAccepted'), '1');
      assert.ok(values.get('_token'), 'Laravel browser CSRF field must be present');
      assert.ok(values.get('signaturePngBase64').length > 200, 'PNG evidence required');
      assert.ok(values.get('documentId'));
      assert.ok(values.get('requestId'));
      assert.ok(values.get('signerId'));
      assert.ok(values.get('consentReference'));
      posted = true;
      await route.fulfill({
        status: 200, contentType: 'text/html',
        body: '<!doctype html><html><body><h1>Test signing receipt</h1></body></html>',
      });
    });

    await page.setContent(html, {waitUntil: 'domcontentloaded'});
    assert.equal(await page.locator('h1').first().textContent(), 'Quote Preview');
    assert.equal(await page.locator('.s2d-tabs button').count(), 3);
    const metrics = await page.evaluate(() => ({
      viewportWidth: innerWidth,
      scrollWidth: document.documentElement.scrollWidth,
      formWidth: document.querySelector('#sign2digital-signing').getBoundingClientRect().width,
      buttonWidth: document.querySelector('#s2d-submit').getBoundingClientRect().width,
    }));
    assert.ok(metrics.scrollWidth <= metrics.viewportWidth + 2,
      'Horizontal overflow on '+width+'x'+height+': '+JSON.stringify(metrics));
    assert.ok(metrics.formWidth > 100 && metrics.buttonWidth > 100);

    await page.locator('input[name="consentAccepted"]').check();
    if (method === 'draw') {
      await page.locator('#s2d-submit').click();
      assert.match(await page.locator('#s2d-warning').textContent(), /add a signature/i);
      const rect = await page.locator('#s2d-pad').boundingBox();
      assert.ok(rect && rect.width > 100);
      await page.mouse.move(rect.x + 25, rect.y + rect.height / 2);
      await page.mouse.down();
      for (let i = 1; i <= 12; i++) {
        await page.mouse.move(
          rect.x + 25 + i * Math.min(24, (rect.width - 60) / 13),
          rect.y + rect.height / 2 + Math.sin(i) * 22
        );
      }
      await page.mouse.up();
    } else if (method === 'type') {
      await page.locator('button[data-sign-method="type"]').click();
      await page.locator('#s2d-name').fill('Signing Customer');
      assert.equal(await page.locator('#s2d-typed').isVisible(), true);
    } else if (method === 'upload') {
      await page.locator('button[data-sign-method="draw"]').click();
      const img = await page.locator('#s2d-pad').screenshot();
      await page.locator('button[data-sign-method="upload"]').click();
      await page.locator('#s2d-upload-file').setInputFiles({
        name: 'synthetic_signature.png',
        mimeType: 'image/png',
        buffer: img,
      });
      await page.waitForFunction(() =>
        document.querySelector('#s2d-upload-file').files.length === 1 &&
        document.querySelector('#s2d-warning').hidden === true
      );
    } else {
      throw new Error('Unknown mode');
    }
    await Promise.all([
      page.waitForURL(/\/sign\/.*\/submit/),
      page.locator('#s2d-submit').click(),
    ]);
    assert.ok(posted, 'Client-side signature was not submitted');
    assert.equal(clientErrors.length, 0, clientErrors.join('; '));
    console.log('CHROMIUM_PASS '+width+'x'+height+' '+method+
      ' with matching IDs, explicit consent, PNG and no overflow');
  } finally {
    await page.close();
  }
}

(async () => {
  const browser = await chromium.launch({headless: true, args: ['--no-sandbox']});
  try {
    for (const viewport of [[390,844],[768,780],[1280,800]]) {
      for (const method of ['draw','type','upload']) {
        await inViewport(browser, viewport[0], viewport[1], method);
      }
    }
  } finally {
    await browser.close();
  }
})().catch(error => {
  console.error('CHROMIUM_FAIL', error);
  process.exitCode = 1;
});
