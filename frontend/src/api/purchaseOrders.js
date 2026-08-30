import { apiRequest, authenticatedFetch, buildApiUrl } from "./client";

export const getPurchaseOrders = (params = {}) =>
  apiRequest(buildApiUrl("/purchase-orders", params));
export const getPurchaseOrder = (id) => apiRequest(`/purchase-orders/${id}`);
export const createPurchaseOrder = (payload) =>
  apiRequest("/purchase-orders", {
    method: "POST",
    body: JSON.stringify(payload),
  });
export const updatePurchaseOrder = (id, payload) =>
  apiRequest(`/purchase-orders/${id}`, {
    method: "PUT",
    body: JSON.stringify(payload),
  });
export const recordPurchaseOrderPayment = (id, payload) =>
  apiRequest(`/purchase-orders/${id}/payments`, {
    method: "POST",
    body: JSON.stringify(payload),
  });
export const reversePurchaseOrderPayment = (id, reason) =>
  apiRequest(`/purchase-order-payments/${id}/reverse`, {
    method: "POST",
    body: JSON.stringify({ reason }),
  });
export const receivePurchaseOrder = (id, payload) =>
  apiRequest(`/purchase-orders/${id}/receive`, {
    method: "POST",
    body: JSON.stringify(payload),
  });
export const changePurchaseOrderStatus = (id, payload) =>
  apiRequest(`/purchase-orders/${id}/status`, {
    method: "PATCH",
    body: JSON.stringify(payload),
  });
export const cancelPurchaseOrder = (id, reason) =>
  apiRequest(`/purchase-orders/${id}/cancel`, {
    method: "POST",
    body: JSON.stringify({ reason }),
  });
export const deletePurchaseOrder = (id) =>
  apiRequest(`/purchase-orders/${id}`, { method: "DELETE" });

export async function downloadPurchaseOrderStatement(id) {
  const response = await authenticatedFetch(
    buildApiUrl(`/purchase-orders/${id}/statement`),
  );
  if (!response.ok) throw new Error("Could not export purchase order.");
  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = `purchase-order-${id}.csv`;
  link.click();
  URL.revokeObjectURL(url);
}
