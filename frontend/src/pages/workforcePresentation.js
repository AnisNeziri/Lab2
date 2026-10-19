export const money = value => value == null ? '—' : new Intl.NumberFormat('en-IE', {
  style: 'currency',
  currency: 'EUR'
}).format(Number(value));
export const payrollMoneyKeys = ['gross', 'employee_pension', 'employer_pension', 'tax', 'deductions', 'advance_repayment', 'net', 'employer_cost'];
export const payrollLabels = {
  gross: ['Gross', 'Bruto'],
  employee_pension: ['Employee pension', 'Pensioni i punonjësit'],
  employer_pension: ['Employer pension', 'Pensioni i punëdhënësit'],
  taxable: ['Taxable salary', 'Paga e tatueshme'],
  tax: ['Withholding tax', 'Tatimi në paga'],
  deductions: ['Other deductions', 'Zbritje të tjera'],
  advance_repayment: ['Advance repayment', 'Kthimi i paradhënies'],
  net: ['Net pay', 'Paga neto'],
  employer_cost: ['Employer cost', 'Kostoja e punëdhënësit']
};
export function paymentRemaining(line, payments) {
  return Math.max(0, Number(line.net) - payments.filter(p => p.kind === 'salary' && p.payroll_line_id === line.id).reduce((sum, p) => sum + Number(p.amount), 0)).toFixed(2);
}
export const periodDates = month => ({
  starts_on: month + '-01',
  ends_on: month + '-' + new Date(Number(month.slice(0, 4)), Number(month.slice(5, 7)), 0).getDate()
});
