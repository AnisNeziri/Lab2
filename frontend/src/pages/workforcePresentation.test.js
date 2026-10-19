import test from 'node:test';
import assert from 'node:assert/strict';
import { money, paymentRemaining, periodDates } from './workforcePresentation.js';
import { canOpenPage } from '../config/pageAccess.js';
test('workforce routes require separate permissions; ordinary managers cannot navigate to payroll', () => {
  assert.equal(canOpenPage('payroll', ['finance.view', 'accounting.reports.view']), false);
  assert.equal(canOpenPage('workforce', ['workforce.self']), false);
  assert.equal(canOpenPage('my-workforce', ['workforce.self']), true);
  assert.equal(canOpenPage('payroll', ['payroll.view']), true);
});
test('partial settlement display and fiscal-year month boundaries remain explicit', () => {
  assert.equal(paymentRemaining({
    id: 4,
    net: '734'
  }, [{
    kind: 'salary',
    payroll_line_id: 4,
    amount: '300'
  }, {
    kind: 'tax',
    payroll_line_id: null,
    amount: '66'
  }, {
    kind: 'salary',
    payroll_line_id: 7,
    amount: '100'
  }]), '434.00');
  assert.deepEqual(periodDates('2026-12'), {
    starts_on: '2026-12-01',
    ends_on: '2026-12-31'
  });
  assert.deepEqual(periodDates('2028-02'), {
    starts_on: '2028-02-01',
    ends_on: '2028-02-29'
  });
  assert.match(money('-66'), /66\.00/);
});
