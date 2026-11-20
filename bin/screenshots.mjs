// Drives a local WordPress with the plugin active: saves the sender settings,
// sends test emails, requests a password reset and takes the readme screenshots.
// Usage: WP_URL=http://127.0.0.1:8089 WP_USER=admin WP_PASS=... OUT=./shots node bin/screenshots.mjs
import { chromium } from 'playwright';
import fs from 'node:fs';

const base = process.env.WP_URL;
const out = process.env.OUT || './shots';
const fromEmail = process.env.FROM_EMAIL || '';
const fromName = process.env.FROM_NAME || '';
const tests = (process.env.TEST_TO || '').split(',').filter(Boolean);
const resetUser = process.env.RESET_USER || '';
fs.mkdirSync(out, { recursive: true });

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
const settings = `${base}/wp-admin/options-general.php?page=smtpfast`;
const notice = async () => (await page.locator('.wrap .notice').first().innerText().catch(() => '')).trim();

await page.goto(`${base}/wp-login.php`);
await page.fill('#user_login', process.env.WP_USER);
await page.fill('#user_pass', process.env.WP_PASS);
await page.click('#wp-submit');
await page.waitForURL(/wp-admin/);

if (fromEmail) {
  await page.goto(settings);
  await page.fill('#smtpfast-from-email', fromEmail);
  await page.fill('#smtpfast-from-name', fromName);
  await page.click('form:has(input[value="smtpfast_save_settings"]) #submit');
  await page.waitForLoadState('load');
  console.log('SAVE', await notice());
}

for (const to of tests) {
  await page.goto(settings);
  await page.fill('#smtpfast-test-to', to);
  await page.click('form:has(input[value="smtpfast_send_test"]) [type=submit]');
  await page.waitForLoadState('load');
  console.log('TEST', to, await notice());
}

if (resetUser) {
  const guest = await browser.newPage();
  await guest.goto(`${base}/wp-login.php?action=lostpassword`);
  await guest.fill('#user_login', resetUser);
  await guest.click('#wp-submit');
  await guest.waitForLoadState('load');
  console.log('RESET', guest.url(), (await guest.locator('#login .message, #login_error').first().innerText().catch(() => '')).trim());
  await guest.close();
}

if (process.env.WAIT_MS) await page.waitForTimeout(Number(process.env.WAIT_MS));

await page.goto(settings);
await page.screenshot({ path: `${out}/screenshot-1.png`, fullPage: true });
await page.goto(`${settings}&tab=domains`);
await page.screenshot({ path: `${out}/screenshot-2.png`, fullPage: true });
await page.goto(`${settings}&tab=log`);
await page.screenshot({ path: `${out}/screenshot-3.png`, fullPage: true });
console.log('LOG', (await page.locator('.smtpfast-table tbody').innerText().catch(() => '')).replace(/\s+\n/g, '\n'));

await browser.close();
