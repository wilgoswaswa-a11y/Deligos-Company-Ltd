const { test, expect } = require('@playwright/test');

test('search and add-to-cart flow', async ({ page }) => {
  await page.goto('/');

  // perform a product search
  await page.fill('#productSearch', 'a');
  await page.click('#searchBtn');

  // wait for at least one add-to-cart button in results
  await page.waitForSelector('#searchResults .add-to-cart', { timeout: 10000 });
  const addButtons = await page.$$('#searchResults .add-to-cart');
  expect(addButtons.length).toBeGreaterThan(0);

  // click the first add-to-cart and assert cart updated
  await addButtons[0].click();
  await page.waitForSelector('#cartBody tr');
  const cartRows = await page.$$('#cartBody tr');
  expect(cartRows.length).toBeGreaterThan(0);
});
