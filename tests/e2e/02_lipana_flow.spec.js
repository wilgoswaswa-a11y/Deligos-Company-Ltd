const { test, expect } = require('@playwright/test');

test('lipana initiate -> verify -> complete sale (mocked)', async ({ page }) => {
  await page.setExtraHTTPHeaders({ 'X-LIPANA-MOCK': '1' });

  await page.goto('/');

  // Add first available product
  await page.fill('#productSearch', 'a');
  await page.click('#searchBtn');
  await page.waitForSelector('#searchResults .add-to-cart');
  await page.click('#searchResults .add-to-cart');

  // select Lipana payment
  await page.selectOption('#paymentMethodSelect', 'Lipana');
  await page.click('#initiateLipanaBtn');

  // open modal and confirm Lipana
  await page.waitForSelector('#lipanaModal', { state: 'visible' });
  await page.fill('#lipanaPhone', '254700000000');
  await page.click('#confirmLipanaBtn');

  // verify (mocked) and auto-complete
  await page.click('#verifyLipanaBtn');

  // wait for completion success toast, then assert cart cleared
  await page.waitForSelector('#cartBody tr', { state: 'detached', timeout: 20000 });
  const cartRows = await page.$$('#cartBody tr');
  expect(cartRows.length).toBe(0);
});
