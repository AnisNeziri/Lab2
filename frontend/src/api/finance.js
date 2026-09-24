import {
  apiRequest,
  authenticatedFetch,
  buildApiUrl,
  parseApiResponse,
} from "./client";

function query(path, filters = {}) {
  return buildApiUrl(path, Object.fromEntries(
    Object.entries(filters).filter(([, value]) => value !== "" && value != null),
  ));
}

export function getFinanceOverview(filters = {}) {
  return apiRequest(query("/finance/overview", filters), {}, "Could not load the finance overview.");
}

export function getExpense(id) {
  return apiRequest(`/finance/expenses/${id}`, {}, "Could not load the expense.");
}

export function getExpenses(filters = {}) {
  return apiRequest(query("/finance/expenses", filters), {}, "Could not load expenses.");
}

export function createExpense(payload) {
  return apiRequest("/finance/expenses", {
    method: "POST",
    body: payload instanceof FormData ? payload : JSON.stringify(payload),
  }, "Could not create the expense.");
}

export function updateExpense(id, payload) {
  if (payload instanceof FormData) payload.set("_method", "PUT");
  return apiRequest(`/finance/expenses/${id}`, {
    method: payload instanceof FormData ? "POST" : "PUT",
    body: payload instanceof FormData ? payload : JSON.stringify(payload),
  }, "Could not update the expense.");
}

export function deleteExpense(id) {
  return apiRequest(`/finance/expenses/${id}`, { method: "DELETE" }, "Could not delete the expense.");
}

export function postExpense(id) {
  return apiRequest(`/finance/expenses/${id}/post`, { method: "POST" }, "Could not post the expense.");
}

export function reverseExpense(id, reason) {
  return apiRequest(`/finance/expenses/${id}/reverse`, {
    method: "POST",
    body: JSON.stringify({ reason }),
  }, "Could not reverse the expense.");
}

export function recordExpensePayment(id, payload) {
  return apiRequest(`/finance/expenses/${id}/payments`, {
    method: "POST",
    body: JSON.stringify(payload),
  }, "Could not record the expense payment.");
}

export function reverseExpensePayment(id, reason) {
  return apiRequest(`/finance/expense-payments/${id}/reverse`, {
    method: "POST",
    body: JSON.stringify({ reason }),
  }, "Could not reverse the expense payment.");
}

export function uploadExpenseProof(id, file) {
  const body = new FormData();
  body.append("proof", file);
  return apiRequest(`/finance/expenses/${id}/attachment`, {
    method: "POST",
    body,
  }, "Could not upload the supporting document.");
}

export function deleteExpenseProof(id) {
  return apiRequest(`/finance/expenses/${id}/attachment`, { method: "DELETE" }, "Could not remove the supporting document.");
}

export function getCashFlow(filters = {}) {
  return apiRequest(query("/finance/cash-flow", filters), {}, "Could not load cash flow.");
}

export function getVatBooks(filters = {}) {
  return apiRequest(query("/finance/vat-books", filters), {}, "Could not load VAT books.");
}

export function getReceivablesAging(filters = {}) {
  return apiRequest(query("/finance/receivables-aging", filters), {}, "Could not load receivables aging.");
}

function filenameFromResponse(response, fallback) {
  const disposition = response.headers.get("content-disposition") || "";
  const utfName = disposition.match(/filename\*=UTF-8''([^;]+)/i)?.[1];
  const basicName = disposition.match(/filename="?([^";]+)"?/i)?.[1];
  try {
    return decodeURIComponent(utfName || basicName || fallback);
  } catch {
    return basicName || fallback;
  }
}

function saveBlob(response, blob, fallback) {
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = filenameFromResponse(response, fallback);
  link.style.display = "none";
  document.body.appendChild(link);
  link.click();
  link.remove();
  window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}

export async function downloadExpenseProof(expense) {
  const response = await authenticatedFetch(buildApiUrl(`/finance/expenses/${expense.id}/attachment`), {
    headers: { Accept: "application/pdf,image/png,image/jpeg,application/octet-stream,application/json" },
  });
  if (!response.ok) await parseApiResponse(response, "Could not download the supporting document.");
  saveBlob(response, await response.blob(), expense.proof_filename || `expense-${expense.id}-proof`);
}

export async function downloadVatBooks(filters = {}) {
  const response = await authenticatedFetch(query("/finance/vat-books/export", { ...filters, format: "xlsx" }), {
    headers: { Accept: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/json" },
  });
  if (!response.ok) await parseApiResponse(response, "Could not export the VAT books.");
  saveBlob(response, await response.blob(), "vat-books.xlsx");
}
