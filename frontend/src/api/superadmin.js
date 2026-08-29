import { apiRequest } from "./client";

export const getSystemHealth = () => apiRequest("/superadmin/health");
export const getSuperadminDashboard = () => apiRequest("/superadmin/dashboard");
export const getCompanies = () => apiRequest("/superadmin/companies");
export const createCompany = (payload) =>
  apiRequest("/superadmin/companies", {
    method: "POST",
    body: JSON.stringify(payload),
  });
export const getSuperadminUsers = () => apiRequest("/superadmin/users");
export const createSuperadminUser = (payload) =>
  apiRequest("/superadmin/users", {
    method: "POST",
    body: JSON.stringify(payload),
  });
export const updateSuperadminUser = (id, payload) =>
  apiRequest(`/superadmin/users/${id}`, {
    method: "PUT",
    body: JSON.stringify(payload),
  });
export const resetSuperadminUserPassword = (id, payload) =>
  apiRequest(`/superadmin/users/${id}/reset-password`, {
    method: "POST",
    body: JSON.stringify(payload),
  });
export const deleteSuperadminUser = (id) =>
  apiRequest(`/superadmin/users/${id}`, {
    method: "DELETE",
  });
