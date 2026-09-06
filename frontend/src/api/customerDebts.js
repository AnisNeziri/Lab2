import { apiRequest, authenticatedFetch, buildApiUrl } from "./client";

async function customerCreditRequest(path, options, fallbackMessage) {
  const response = await authenticatedFetch(buildApiUrl(path), options);
  const contentType = response.headers.get("content-type") || "";
  const payload = contentType.includes("application/json")
    ? await response.json()
    : null;

  if (!response.ok) {
    const error = new Error(payload?.message || fallbackMessage);
    error.code = payload?.code;
    error.errors = payload?.errors;
    error.creditControl = payload?.credit_control;
    error.payload = payload;
    throw error;
  }

  return payload;
}

export const getCustomerDebts = (params = {}) =>
  apiRequest(buildApiUrl("/customers", params));
export const getDebtSummary = () => apiRequest("/customers/debts/summary");
export const getCustomerCreditReport = (params = {}) =>
  apiRequest(buildApiUrl("/customers/credit-report", params));
export const getCustomerDebt = (id) => apiRequest(`/customers/${id}`);
export const createCustomer = (payload) =>
  apiRequest("/customers", { method: "POST", body: JSON.stringify(payload) });
export const updateCustomer = (id, payload) =>
  apiRequest(`/customers/${id}`, {
    method: "PUT",
    body: JSON.stringify(payload),
  });
export const deleteCustomerDebtSheet = (id) =>
  apiRequest(`/customers/${id}`, { method: "DELETE" });
export const createCustomerDebtEntry = (id, payload) =>
  customerCreditRequest(`/customers/${id}/debts`, {
    method: "POST",
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
    },
    body: JSON.stringify(payload),
  }, "Could not add the debt entry.");
export const requestCustomerCreditOverride = (id, payload) =>
  apiRequest(`/customers/${id}/credit-overrides`, {
    method: "POST",
    body: JSON.stringify(payload),
  });
export const getCustomerCreditOverrideStatus = (customerId, approvalId) =>
  apiRequest(`/customers/${customerId}/credit-overrides/${approvalId}`);
export const recordCustomerDebtPayment = (id, payload) =>
  apiRequest(`/customers/${id}/payments`, {
    method: "POST",
    body: JSON.stringify(payload),
  });
export const reverseDebtTransaction = (id, reason) =>
  apiRequest(`/debt-transactions/${id}/reverse`, {
    method: "POST",
    body: JSON.stringify({ reason }),
  });
export const updateDebtTransaction = (id, payload) =>
  apiRequest(`/debt-transactions/${id}`, {
    method: "PUT",
    body: JSON.stringify(payload),
  });
export async function downloadDebtStatement(id) {
  const response = await authenticatedFetch(
    buildApiUrl(`/customers/${id}/statement`),
  );
  if (!response.ok) throw new Error("Could not export statement.");
  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = `debt-statement-${id}.csv`;
  link.click();
  URL.revokeObjectURL(url);
}
