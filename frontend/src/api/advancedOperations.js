import { apiRequest, buildApiUrl } from "./client";

const json = (method, payload) => ({ method, body: JSON.stringify(payload) });

export const getBinInventory = (filters = {}) => apiRequest(buildApiUrl("/inventory/locator", filters));
export const getInventoryProduct = (id, filters = {}) => apiRequest(buildApiUrl(`/inventory/products/${id}`, filters));
export const getExpiringInventory = (filters = {}) => apiRequest(buildApiUrl("/inventory/expiring", filters));
export const moveBinStock = (payload) => apiRequest("/inventory/bin-transfer", json("POST", payload));

export const getInventoryCounts = (filters = {}) => apiRequest(buildApiUrl("/inventory-counts", filters));
export const getInventoryCount = (id) => apiRequest(`/inventory-counts/${id}`);
export const createInventoryCount = (payload) => apiRequest("/inventory-counts", json("POST", payload));
export const recordInventoryCount = (id, payload) => apiRequest(`/inventory-counts/${id}/record`, json("POST", payload));
export const requestInventoryRecount = (id, payload) => apiRequest(`/inventory-counts/${id}/recount`, json("POST", payload));
export const submitInventoryCount = (id) => apiRequest(`/inventory-counts/${id}/submit`, { method: "POST" });
export const approveInventoryCount = (id, reason) => apiRequest(`/inventory-counts/${id}/approve`, json("POST", { reason }));
export const cancelInventoryCount = (id, reason) => apiRequest(`/inventory-counts/${id}/cancel`, json("POST", { reason }));

export const getReplenishment = (filters = {}) => apiRequest(buildApiUrl("/replenishment", filters));
export const createReplenishmentDrafts = (payload) => apiRequest("/replenishment/draft-purchase-orders", json("POST", payload));

export const getProductSuppliers = (filters = {}) => apiRequest(buildApiUrl("/product-suppliers", filters));
export const createProductSupplier = (payload) => apiRequest("/product-suppliers", json("POST", payload));
export const updateProductSupplier = (id, payload) => apiRequest(`/product-suppliers/${id}`, json("PUT", payload));
export const deactivateProductSupplier = (id) => apiRequest(`/product-suppliers/${id}`, { method: "DELETE" });
export const getProductSupplierHistory = (id) => apiRequest(`/product-suppliers/${id}/price-history`);
export const getSupplierPerformance = (id) => apiRequest(`/suppliers/${id}/performance`);

export const getLandedCosts = (filters = {}) => apiRequest(buildApiUrl("/landed-costs", filters));
export const getLandedCost = (id) => apiRequest(`/landed-costs/${id}`);
export const createLandedCost = (payload) => apiRequest("/landed-costs", json("POST", payload));
export const postLandedCost = (id) => apiRequest(`/landed-costs/${id}/post`, { method: "POST" });
export const reverseLandedCost = (id, reason) => apiRequest(`/landed-costs/${id}/reverse`, json("POST", { reason }));
export const deleteLandedCost = (id) => apiRequest(`/landed-costs/${id}`, { method: "DELETE" });

export const getInventoryReturns = (filters = {}) => apiRequest(buildApiUrl("/inventory-returns", filters));
export const getInventoryReturnSources = (filters) => apiRequest(buildApiUrl("/inventory-return-sources", filters));
export const getInventoryReturn = (id) => apiRequest(`/inventory-returns/${id}`);
export const createInventoryReturn = (payload) => apiRequest("/inventory-returns", json("POST", payload));
export const updateInventoryReturn = (id, payload) => apiRequest(`/inventory-returns/${id}`, json("PUT", payload));
export const deleteInventoryReturn = (id) => apiRequest(`/inventory-returns/${id}`, { method: "DELETE" });
export const transitionInventoryReturn = (id, action, payload = {}) => apiRequest(`/inventory-returns/${id}/${action}`, json("POST", payload));

export const getSupplierInvoices = (filters = {}) => apiRequest(buildApiUrl("/supplier-invoices", filters));
export const getSupplierInvoice = (id) => apiRequest(`/supplier-invoices/${id}`);
export const createSupplierInvoice = (payload) => apiRequest("/supplier-invoices", json("POST", payload));
export const updateSupplierInvoice = (id, payload) => apiRequest(`/supplier-invoices/${id}`, json("PUT", payload));
export const recalculateSupplierInvoice = (id) => apiRequest(`/supplier-invoices/${id}/recalculate`, { method: "POST" });
export const approveSupplierInvoice = (id, reason = null) => apiRequest(`/supplier-invoices/${id}/approve`, json("POST", { reason }));
export const allocateSupplierInvoicePayment = (id, payload) => apiRequest(`/supplier-invoices/${id}/allocate-payment`, json("POST", payload));

export const getFinancialAccounts = () => apiRequest("/finance/accounts");
export const getFinancialPostingAccounts = () => apiRequest("/finance/posting-accounts");
export const createFinancialAccount = (payload) => apiRequest("/finance/accounts", json("POST", payload));
export const updateFinancialAccount = (id, payload) => apiRequest(`/finance/accounts/${id}`, json("PUT", payload));
export const getFinancialTransactions = (id, filters = {}) => apiRequest(buildApiUrl(`/finance/accounts/${id}/transactions`, filters));
export const postFinancialTransaction = (id, payload) => apiRequest(`/finance/accounts/${id}/transactions`, json("POST", payload));
export const transferFinancialAccounts = (payload) => apiRequest("/finance/account-transfers", json("POST", payload));
export const reverseFinancialTransaction = (id, reason) => apiRequest(`/finance/account-transactions/${id}/reverse`, json("POST", { reason }));

export const getBankStatements = () => apiRequest("/finance/bank-statements");
export const getBankStatement = (id) => apiRequest(`/finance/bank-statements/${id}`);
export const importBankStatement = (formData) => apiRequest("/finance/bank-statements/import", { method: "POST", body: formData });
export const getBankMatchSuggestions = (id) => apiRequest(`/finance/bank-statement-rows/${id}/suggestions`);
export const reconcileBankRow = (id, transactionId) => apiRequest(`/finance/bank-statement-rows/${id}/reconcile`, json("POST", { transaction_id: transactionId }));
export const ignoreBankRow = (id, reason) => apiRequest(`/finance/bank-statement-rows/${id}/ignore`, json("POST", { reason }));
export const unmatchBankRow = (id, reason) => apiRequest(`/finance/bank-statement-rows/${id}/unmatch`, json("POST", { reason }));
