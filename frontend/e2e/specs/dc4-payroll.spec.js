import { test, expect } from '@playwright/test';
import path from 'node:path';
async function request(page, route, body, method = 'POST') {
  const r = await page.evaluate(async ({
    route,
    body,
    method
  }) => {
    const r = await fetch('/api' + route, {
      method,
      headers: {
        Authorization: 'Bearer ' + localStorage.getItem('api_token'),
        Accept: 'application/json',
        'Content-Type': 'application/json'
      },
      ...(body === undefined ? {} : {
        body: JSON.stringify(body)
      })
    });
    return {
      status: r.status,
      data: await r.json()
    };
  }, {
    route,
    body,
    method
  });
  expect(r.status, JSON.stringify(r.data)).toBeLessThan(300);
  return r.data;
}
test('DC4 approved bonus + advance → calculate → review → post → pay → EN/SQ payslip → close; retries preserve one posting', async ({
  page
}) => {
  test.setTimeout(150000);
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  await page.goto('/login');
  await page.getByLabel('Email address').fill('dc4-payroll@e2e.example');
  await page.getByLabel('Password', {
    exact: true
  }).fill('dc4-local-test-secret');
  await page.getByRole('button', {
    name: 'Enter workspace'
  }).click();
  await expect(page).toHaveURL(/dashboard$/);
  await page.goto('/payroll?tab=configuration');
  await page.getByRole('button', {
    name: 'Initialize reviewed payroll defaults',
    exact: true
  }).click();
  await expect(page.getByText('XK-08L142-2024-08-v1', {
    exact: true
  })).toBeVisible();
  await page.goto('/workforce');
  await page.getByRole('button', {
    name: 'Add employee',
    exact: true
  }).click();
  const employeeForm = page.getByRole('dialog');
  await employeeForm.getByLabel('Employee number', {
    exact: true
  }).fill('E-DC4');
  await employeeForm.getByLabel('Full name', {
    exact: true
  }).fill('Arta Payroll Journey');
  await employeeForm.getByLabel('Start date', {
    exact: true
  }).fill('2026-01-01');
  await employeeForm.getByLabel('Status', {
    exact: true
  }).selectOption('active');
  await employeeForm.getByRole('button', {
    name: 'Save employee',
    exact: true
  }).click();
  await expect(page.getByRole('heading', {
    name: 'Arta Payroll Journey · E-DC4'
  })).toBeVisible();
  const employeeId = Number(new URL(page.url()).searchParams.get('employee'));
  await page.getByRole('button', {
    name: 'Employment',
    exact: true
  }).click();
  await page.getByRole('button', {
    name: 'Add effective terms revision',
    exact: true
  }).click();
  const terms = page.getByRole('dialog');
  await terms.getByLabel('Position', {
    exact: true
  }).fill('Finance Coordinator');
  await terms.getByLabel('Department', {
    exact: true
  }).fill('Administration');
  await terms.getByLabel('Gross salary / rate EUR', {
    exact: true
  }).fill('900');
  await terms.getByLabel('Approval reason', {
    exact: true
  }).fill('Synthetic controlled monthly salary');
  await terms.getByRole('button', {
    name: 'Approve new terms version',
    exact: true
  }).click();
  await expect(page.getByRole('dialog')).toHaveCount(0);
  const bank = await request(page, '/finance/accounts', {
    name: 'DC4 Payroll Bank',
    type: 'bank',
    currency: 'EUR',
    opening_balance: 10000,
    opening_date: '2026-01-01'
  });
  await request(page, '/workforce/employees/' + employeeId + '/advances', {
    financial_account_id: bank.id,
    amount: 300,
    paid_on: '2026-10-01',
    reason: 'Approved synthetic employee advance',
    idempotency_key: 'dc4-browser-advance'
  });
  for (const [kind, amount] of [['bonus', 100], ['advance_repayment', 150]]) {
    const r = await request(page, '/workforce/records', {
      employee_id: employeeId,
      kind,
      starts_on: '2026-10-15',
      ends_on: '2026-10-15',
      units: 1,
      amount,
      reason: 'Reviewed synthetic payroll instruction'
    });
    await request(page, '/workforce/records/' + r.id + '/decision', {
      status: 'approved',
      reason: 'Reviewed by authorized payroll accountant'
    });
  }
  await page.goto('/payroll');
  await page.getByRole('button', {
    name: 'New payroll period',
    exact: true
  }).click();
  const period = page.getByRole('dialog');
  await period.getByLabel('Month', {
    exact: true
  }).fill('2026-10');
  await period.getByLabel('Period name', {
    exact: true
  }).fill('October 2026');
  await period.getByLabel('Scheduled pay date', {
    exact: true
  }).fill('2026-10-31');
  await period.getByRole('button', {
    name: 'Create payroll period',
    exact: true
  }).click();
  await expect(page.getByRole('dialog')).toHaveCount(0);
  await page.getByRole('button', {
    name: 'Calculate payroll',
    exact: true
  }).click();
  await expect(page.getByRole('table', {
    name: 'Payroll employees'
  })).toContainText('€734.00');
  await page.getByRole('button', {
    name: 'Approve reviewed payroll',
    exact: true
  }).click();
  await page.getByRole('button', {
    name: 'Post payroll',
    exact: true
  }).click();
  await expect(page.getByRole('link', {
    name: 'Open posted journal',
    exact: true
  })).toBeVisible();
  const runId = Number(new URL(page.url()).searchParams.get('run')),
    before = await request(page, '/payroll/' + runId, undefined, 'GET');
  const replay = await request(page, '/payroll/' + runId + '/post', {
    fingerprint: before.run.fingerprint
  });
  expect(replay.journal_entry_id).toBe(before.run.journal_entry_id);
  await page.getByRole('button', {
    name: 'Pay employee',
    exact: true
  }).click();
  let pay = page.getByRole('dialog');
  await pay.getByLabel('Payment account', {
    exact: true
  }).selectOption(String(bank.id));
  await pay.getByRole('button', {
    name: 'Record payment',
    exact: true
  }).click();
  await expect(page.getByRole('dialog')).toHaveCount(0);
  await page.getByRole('button', {
    name: 'Payslip',
    exact: true
  }).click();
  await expect(page.getByRole('dialog')).toContainText('€734.00');
  await page.getByLabel('Payslip language', {
    exact: true
  }).selectOption('sq');
  await expect(page.getByRole('dialog')).toContainText('Paga neto');
  await page.getByRole('dialog').getByRole('button', {
    name: 'Close dialog',
    exact: true
  }).click();
  for (let i = 0; i < 3; i++) {
    await page.getByRole('button', {
      name: 'Settle liability',
      exact: true
    }).first().click();
    pay = page.getByRole('dialog');
    await pay.getByLabel('Payment account', {
      exact: true
    }).selectOption(String(bank.id));
    await pay.getByRole('button', {
      name: 'Record payment',
      exact: true
    }).click();
    await expect(page.getByRole('dialog')).toHaveCount(0);
  }
  const final = await request(page, '/payroll/' + runId, undefined, 'GET');
  expect(final.obligations.reconciled).toBe(true);
  expect(final.obligations.rows.every(r => r.remaining === '0.00' && r.difference === '0.00')).toBe(true);
  await page.getByRole('button', {
    name: 'Close payroll',
    exact: true
  }).click();
  await expect(page.locator('.wf-panel').filter({
    has: page.getByRole('heading', {
      name: 'October 2026 · PAY-' + runId,
      exact: true
    })
  }).locator('.wf-status').first()).toHaveText('closed');
  await page.screenshot({
    path: path.resolve('../output/dc4-payroll-browser.png'),
    fullPage: true
  });
  expect(errors).toEqual([]);
});
