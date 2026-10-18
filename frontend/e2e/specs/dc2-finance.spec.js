import { test, expect } from "@playwright/test";
import path from "node:path";
import { execFileSync } from "node:child_process";

test.describe.configure({ timeout: 180_000 });

test.beforeAll(() => {
  execFileSync("php", ["tests/Support/dc2-browser-fixture.php"], {
    cwd: path.resolve("../backend"),
    env: {
      ...process.env,
      APP_ENV: "e2e",
      DB_CONNECTION: "sqlite",
      DB_DATABASE: path.resolve("../backend/database/e2e.sqlite"),
      DB_URL: "",
      CACHE_STORE: "array",
    },
    windowsHide: true,
  });
});
async function login(page) {
  await page.goto("/login");
  await page.getByLabel("Email address").fill("dc2-finance@e2e.example");
  await page.getByLabel("Password", { exact: true }).fill("dc2-fixture-secret");
  await page.getByRole("button", { name: "Enter workspace" }).click();
  await expect(page).toHaveURL(/\/dashboard$/);
}

async function request(page, route, body, method = "POST") {
  const result = await page.evaluate(
    async ({ route, body, method }) => {
      const response = await fetch(`/api${route}`, {
        method,
        headers: {
          Authorization: `Bearer ${localStorage.getItem("api_token")}`,
          Accept: "application/json",
          "Content-Type": "application/json",
        },
        ...(body === undefined ? {} : { body: JSON.stringify(body) }),
      });
      return { status: response.status, data: await response.json() };
    },
    { route, body, method },
  );
  expect(result.status, JSON.stringify(result.data)).toBeLessThan(300);
  return result.data;
}

test("DC2 accountant workspace posts, traces, matches a bank statement and closes a month", async ({
  page,
}) => {
  test.setTimeout(180_000);
  const errors = [];
  page.on("pageerror", (e) => errors.push(e.message));
  await login(page);
  await request(page, "/accounting/initialize");
  const expense = await request(page, "/accounting/accounts", {
    code: "DC2-6010",
    name: "DC2 test expense",
    type: "expense",
    normal_balance: "debit",
    is_posting: true,
    is_active: true,
    statement_group: "operating_expense",
  });
  const equity = await request(page, "/accounting/accounts", {
    code: "DC2-3010",
    name: "DC2 test equity",
    type: "equity",
    normal_balance: "credit",
    is_posting: true,
    is_active: true,
  });
  await page.goto("/accounting?tab=journal");
  await expect(
    page.getByRole("heading", { name: "Finance Control Center" }),
  ).toBeVisible();
  await page
    .getByRole("button", { name: "New manual draft", exact: true })
    .click();
  let dialog = page.getByRole("dialog", {
    name: "Prepare manual journal",
    exact: true,
  });
  await dialog.getByLabel("Posting date", { exact: true }).fill("2026-09-15");
  await dialog
    .getByLabel("Description", { exact: true })
    .fill("DC2 browser adjustment");
  await dialog.getByLabel("Reference", { exact: true }).fill("DC2-UI-JOURNAL");
  await dialog
    .getByLabel("Account", { exact: true })
    .nth(0)
    .selectOption(String(expense.id));
  await dialog
    .getByLabel("Account", { exact: true })
    .nth(1)
    .selectOption(String(equity.id));
  await dialog.getByLabel("Debit", { exact: true }).nth(0).fill("125");
  await dialog.getByLabel("Credit", { exact: true }).nth(1).fill("125");
  await dialog.getByRole("button", { name: "Save draft", exact: true }).click();
  await expect(dialog).not.toBeVisible();
  await page
    .getByRole("button", { name: "Review posting", exact: true })
    .click();
  dialog = page.getByRole("dialog", {
    name: "Review and post manual journal",
    exact: true,
  });
  await dialog
    .getByRole("button", { name: "Post balanced journal", exact: true })
    .click();
  await expect(dialog).not.toBeVisible();
  await expect(
    page.getByRole("button", {
      name: "Reverse & prepare correction",
      exact: true,
    }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Statements", exact: true }).click();
  await expect(
    page.getByText("Complete trial balance balanced", { exact: true }),
  ).toBeVisible();
  const download = page.waitForEvent("download");
  await page.getByRole("button", { name: "Export CSV", exact: true }).click();
  expect((await download).suggestedFilename()).toContain("aims-trial");
  await page.getByRole("button", { name: "Cash & bank", exact: true }).click();
  await page.getByRole("button", { name: "New account", exact: true }).click();
  dialog = page.getByRole("dialog", {
    name: "Add cash / bank account",
    exact: true,
  });
  await dialog.getByLabel("Name", { exact: true }).fill("DC2 Browser Bank");
  await dialog
    .getByRole("button", { name: "Create zero-balance account", exact: true })
    .click();
  await expect(dialog).not.toBeVisible();
  const bank = (
    await request(page, "/finance/accounts", undefined, "GET")
  ).find((a) => a.name === "DC2 Browser Bank");
  const tx = await request(page, `/finance/accounts/${bank.id}/transactions`, {
    type: "inflow",
    amount: 75,
    transaction_date: "2026-09-15",
    reference_number: "DC2-DEPOSIT",
    description: "DC2 browser deposit",
    counter_accounting_account_id: equity.id,
    idempotency_key: crypto.randomUUID(),
  });
  await page
    .getByRole("button", { name: "Stage bank CSV", exact: true })
    .click();
  dialog = page.getByRole("dialog", {
    name: "Stage a bank statement CSV",
    exact: true,
  });
  await dialog
    .getByLabel("Bank account", { exact: true })
    .selectOption(String(bank.id));
  await dialog
    .getByLabel("CSV file · up to 10 MB / 10,000 movements", { exact: true })
    .setInputFiles({
      name: "dc2-browser.csv",
      mimeType: "text/csv",
      buffer: Buffer.from(
        "Date,Amount,Reference,Balance\n2026-09-15,75.00,DC2-DEPOSIT,75.00\n",
      ),
    });
  await dialog
    .getByLabel("Reference column", { exact: true })
    .fill("Reference");
  await dialog
    .getByLabel("Running balance column", { exact: true })
    .fill("Balance");
  await dialog
    .getByRole("button", { name: "Validate and stage statement", exact: true })
    .click();
  await expect(dialog).not.toBeVisible();
  await page
    .getByRole("button", { name: "Review matches", exact: true })
    .click();
  dialog = page.getByRole("dialog", {
    name: "Review bank match evidence",
    exact: true,
  });
  await expect(
    dialog.getByText("Reference matches", { exact: false }),
  ).toBeVisible();
  await dialog.locator(".fc-match-candidate input").first().check();
  await dialog
    .getByRole("button", { name: "Confirm explicit match", exact: true })
    .click();
  await expect(dialog).not.toBeVisible();
  await page
    .getByRole("button", { name: "Complete reconciliation", exact: true })
    .click();
  await expect(
    page.getByRole("button", { name: "Reopen for review", exact: true }),
  ).toBeVisible();
  const stored = await request(
    page,
    `/finance/accounts/${bank.id}/transactions`,
    undefined,
    "GET",
  );
  expect(stored.data.map((t) => t.id)).toEqual([tx.id]);
  await page.getByRole("button", { name: "Month close", exact: true }).click();
  await page
    .getByRole("button", { name: "Create period", exact: true })
    .click();
  dialog = page.getByRole("dialog", {
    name: "Create accounting period",
    exact: true,
  });
  await dialog
    .getByLabel("Name", { exact: true })
    .fill("DC2 September browser close");
  await dialog.getByLabel("Starts", { exact: true }).fill("2026-09-01");
  await dialog.getByLabel("Ends", { exact: true }).fill("2026-09-30");
  await dialog
    .getByRole("button", { name: "Create open period", exact: true })
    .click();
  await expect(dialog).not.toBeVisible();
  await expect(
    page.getByRole("button", { name: "Review final close", exact: true }),
  ).toBeEnabled();
  await page
    .getByRole("button", { name: "Review final close", exact: true })
    .click();
  dialog = page.getByRole("dialog", {
    name: "Final accounting period close",
    exact: true,
  });
  await dialog
    .getByLabel("Reason", { exact: true })
    .fill("Reviewed September balances and completed bank evidence.");
  await dialog
    .getByRole("button", { name: "Confirm reviewed action", exact: true })
    .click();
  await expect(dialog).not.toBeVisible();
  await expect(
    page.getByRole("button", { name: "Reopen with reason", exact: true }),
  ).toBeVisible();
  await page.goto("/accounting");
  await expect(
    page.getByRole("heading", { name: "Working capital", exact: true }),
  ).toBeVisible();
  await page.screenshot({
    path: path.resolve("../output/dc2-finance-desktop.png"),
    fullPage: true,
  });
  expect(errors).toEqual([]);
});

test("DC2 AR and AP workbenches preserve settlement and reversal history", async ({
  page,
}) => {
  test.setTimeout(180_000);
  const errors = [];
  page.on("pageerror", (e) => errors.push(e.message));
  await login(page);
  const cash = await request(page, "/finance/accounts", {
    name: "DC2 Settlement Cash",
    type: "cashbox",
    currency: "EUR",
    opening_balance: 10000,
    opening_date: "2026-10-01",
    is_active: true,
  });
  const customer = await request(page, "/customers", {
    name: "DC2 UI Customer",
  });
  await request(page, `/customers/${customer.id}/debts`, {
    amount: 3700,
    transaction_date: "2026-10-02",
    due_date: "2026-10-10",
    reference_number: "DC2-AR-3700",
    idempotency_key: crypto.randomUUID(),
  });
  await request(page, `/customers/${customer.id}/payments`, {
    amount: 3400,
    transaction_date: "2026-10-03",
    payment_method: "cash",
    financial_account_id: cash.id,
    reference_number: "DC2-AR-PAYMENT",
    idempotency_key: crypto.randomUUID(),
  });
  await page.goto(`/accounting?tab=ar&customer=${customer.id}`);
  await expect(
    page.getByRole("heading", {
      name: "Customer statement & payment allocation",
      exact: true,
    }),
  ).toBeVisible();
  await expect(
    page.getByText("Closing receivable", { exact: false }),
  ).toContainText("300.00");
  await page
    .getByRole("button", { name: "Payment allocations", exact: true })
    .click();
  await page.getByRole("button", { name: "Allocate", exact: true }).click();
  let dialog = page.getByRole("dialog", {
    name: "Allocate customer payment",
    exact: true,
  });
  await dialog.getByLabel(/DC2-AR-3700/).fill("3400");
  await dialog
    .getByLabel("Reason / remittance evidence", { exact: true })
    .fill("Customer remittance identifies this sale.");
  await dialog
    .getByRole("button", { name: "Confirm allocation", exact: true })
    .click();
  await expect(dialog).not.toBeVisible();
  await expect(
    page.getByRole("button", { name: "Release", exact: true }),
  ).toBeVisible();
  const customerPosition = await request(
    page,
    `/customers/${customer.id}`,
    undefined,
    "GET",
  );
  expect(Number(customerPosition.current_debt)).toBe(300);
  const supplier = await request(page, "/suppliers", {
    name: "DC2 UI Services",
  });
  await page.goto("/accounting?tab=ap");
  await page
    .getByRole("button", { name: "New supplier bill", exact: true })
    .click();
  dialog = page.getByRole("dialog", {
    name: "New supplier invoice / vendor bill",
    exact: true,
  });
  await dialog
    .getByLabel("Selected party", { exact: true })
    .selectOption(String(supplier.id));
  await dialog
    .getByLabel("Vendor name on document", { exact: true })
    .fill("DC2 UI Services");
  await dialog
    .getByLabel("Invoice number", { exact: true })
    .fill("DC2-UI-BILL-25000");
  await dialog.getByLabel("Invoice date", { exact: true }).fill("2026-10-04");
  await dialog.getByLabel("Received date", { exact: true }).fill("2026-10-04");
  await dialog
    .getByLabel("Business purpose", { exact: true })
    .fill("Professional service for the synthetic accounting journey.");
  await dialog
    .getByLabel("Description", { exact: true })
    .fill("Reviewed professional services");
  await dialog.getByLabel("Invoice unit price", { exact: true }).fill("25000");
  await dialog
    .getByRole("button", { name: "Save supplier draft", exact: true })
    .click();
  await expect(dialog).not.toBeVisible();
  await page
    .getByRole("button", { name: "Review approval & posting", exact: true })
    .click();
  dialog = page.getByRole("dialog", {
    name: "Approve and post supplier invoice",
    exact: true,
  });
  await dialog
    .getByLabel("Reason", { exact: true })
    .fill("Reviewed legitimate non-PO professional service invoice.");
  await dialog
    .getByRole("button", { name: "Confirm reviewed action", exact: true })
    .click();
  await expect(dialog).not.toBeVisible();
  await page
    .getByRole("button", { name: "Record partial / full payment", exact: true })
    .click();
  dialog = page.getByRole("dialog", {
    name: "Record supplier payment",
    exact: true,
  });
  await dialog.getByLabel("Payment amount", { exact: true }).fill("6000");
  await dialog.getByLabel("Method", { exact: true }).selectOption("cash");
  await dialog
    .getByLabel("Payment account", { exact: true })
    .selectOption(String(cash.id));
  await dialog
    .getByLabel("Bank / payment reference", { exact: true })
    .fill("DC2-SUPPLIER-PARTIAL");
  await dialog
    .getByRole("button", { name: "Confirm recorded payment", exact: true })
    .click();
  await expect(dialog).not.toBeVisible();
  const bills = await request(page, "/supplier-invoices", undefined, "GET");
  const bill = bills.data.find(
    (b) => b.document_number === "DC2-UI-BILL-25000",
  );
  expect(Number(bill.remaining_amount)).toBe(19000);
  await expect(
    page.getByRole("heading", { name: "Linked journals", exact: true }),
  ).toBeVisible();
  await page
    .getByRole("button", { name: "Reverse payment", exact: true })
    .click();
  dialog = page.getByRole("dialog", {
    name: "Reverse supplier payment",
    exact: true,
  });
  await dialog
    .getByLabel("Reason", { exact: true })
    .fill("Correct a synthetic payment while preserving its audit history.");
  await dialog
    .getByRole("button", { name: "Confirm reviewed action", exact: true })
    .click();
  await expect(dialog).not.toBeVisible();
  const corrected = await request(
    page,
    `/supplier-invoices/${bill.id}`,
    undefined,
    "GET",
  );
  expect(Number(corrected.remaining_amount)).toBe(25000);
  expect(corrected.payments[0].status).toBe("reversed");
  const before = await request(page, "/accounting/journals", undefined, "GET");
  const answer = await request(page, "/intelligence-assistant/ask", {
    question: "sa borxh kane klientet",
  });
  expect(answer.read_only).toBe(true);
  expect(answer.text).toContain("300.00 EUR");
  const refusal = await request(page, "/intelligence-assistant/ask", {
    question: "post journal",
  });
  expect(refusal.tools).toEqual([]);
  const after = await request(page, "/accounting/journals", undefined, "GET");
  expect(after.total).toBe(before.total);
  await page.goto(`/accounting?tab=ap&supplier=${supplier.id}`);
  await page
    .getByRole("button", { name: "Payment workbench", exact: true })
    .click();
  await page
    .getByRole("button", { name: "Supplier statement", exact: true })
    .click();
  await expect(
    page.getByRole("heading", { name: "Supplier statement", exact: true }),
  ).toBeVisible();
  expect(errors).toEqual([]);
});

test("DC2 finance navigation stays usable on a narrow Albanian workspace", async ({
  page,
}) => {
  await login(page);
  await request(
    page,
    "/settings/preferences",
    { language: "sq", theme: "dark" },
    "PUT",
  );
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto("/accounting");
  await expect(
    page.getByRole("heading", {
      name: "Qendra e kontrollit financiar",
      exact: true,
    }),
  ).toBeVisible();
  await expect(
    page.getByRole("button", { name: "Mbyllja mujore", exact: true }),
  ).toBeAttached();
  await expect(
    page.getByRole("heading", { name: "Kapitali qarkullues", exact: true }),
  ).toBeVisible();
  const overflow = await page
    .locator("main.fc-page")
    .evaluate((el) => el.getBoundingClientRect().right > window.innerWidth + 1);
  expect(overflow).toBe(false);
  await page.screenshot({
    path: path.resolve("../output/dc2-finance-mobile-sq.png"),
    fullPage: true,
  });
});
