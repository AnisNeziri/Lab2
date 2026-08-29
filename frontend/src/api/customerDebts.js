import { apiRequest, authenticatedFetch, buildApiUrl } from "./client";
export const getCustomerDebts = (params = {}) =>
  apiRequest(buildApiUrl("/customers", params));
export const getDebtSummary = () => apiRequest("/customers/debts/summary");
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
  apiRequest(`/customers/${id}/debts`, {
    method: "POST",
    body: JSON.stringify(payload),
  });
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
