import {
  apiRequest,
  buildApiUrl,
  authenticatedFetch,
  parseApiResponse,
} from "./client";
const body = (method, data) => ({ method, body: JSON.stringify(data) });
export const taxConfiguration = () => apiRequest("/tax/configuration");
export const availableTaxCodes = () => apiRequest("/tax/available-codes");
export const initializeTax = () =>
  apiRequest("/tax/configuration/initialize", { method: "POST" });
export const saveTaxCode = (data) =>
  apiRequest("/tax/codes", body("POST", data));
export const retireTaxCode = (id) =>
  apiRequest("/tax/codes/" + id + "/retire", { method: "POST" });
export const assignProductTax = (id, default_tax_code_id) =>
  apiRequest("/tax/products/" + id, body("PUT", { default_tax_code_id }));
export const vatPeriod = (p) => apiRequest(buildApiUrl("/tax/vat-period", p));
export const vatDocuments = (p) =>
  apiRequest(buildApiUrl("/tax/vat-documents", p));
export const fiscalSummary = () => apiRequest("/fiscalization");
export const fiscalInvoices = (p) =>
  apiRequest(buildApiUrl("/fiscalization/invoices", p));
export const fiscalMappings = () => apiRequest("/fiscalization/mappings");
export const saveFiscalMapping = (p) =>
  apiRequest("/fiscalization/mappings", body("PUT", p));
export const saveFiscalConfig = (p) =>
  apiRequest("/fiscalization/configuration", body("PUT", p));
export const fiscalReceipt = (id) =>
  apiRequest("/fiscalization/receipts/" + id);
export const prepareFiscal = (id) =>
  apiRequest("/fiscalization/invoices/" + id + "/prepare", { method: "POST" });
export const submitFiscal = (id) =>
  apiRequest(
    "/fiscalization/receipts/" + id + "/submit",
    body("POST", { confirm_test_submission: true }),
  );
export const reviewFiscal = (id, reason) =>
  apiRequest(
    "/fiscalization/receipts/" + id + "/review",
    body("POST", { reason }),
  );
export async function exportVat(period) {
  const r = await authenticatedFetch(
    buildApiUrl("/tax/vat-export", { period }),
  );
  if (!r.ok) return parseApiResponse(r);
  const blob = await r.blob();
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = "VAT-preview-" + period + ".csv";
  a.click();
  URL.revokeObjectURL(url);
}
