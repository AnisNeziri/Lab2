import { apiRequest } from "./client";

const query = (params = {}) => {
  const search = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    if (value !== "" && value !== null && value !== undefined) search.set(key, value);
  });
  return search.toString();
};

export const getControlTower = (params = {}) =>
  apiRequest(`/control-tower?${query(params)}`, {}, "Could not load the Supply Chain Control Tower");
export const getControlTowerShipment = (id) =>
  apiRequest(`/control-tower/${id}`, {}, "Could not load the import timeline");
export const saveControlTowerMilestone = (id, payload) =>
  apiRequest(`/control-tower/${id}/milestone`, { method: "PUT", body: JSON.stringify(payload) }, "Could not save the milestone");
export const getControlTowerAttention = (params = {}) =>
  apiRequest(`/control-tower/attention?${query(params)}`, {}, "Could not load operational exceptions");
export const resolveControlTowerException = (id) =>
  apiRequest(`/control-tower/exceptions/${id}/resolve`, { method: "POST" }, "Could not resolve the exception");
export const getIntegrationHealth = () =>
  apiRequest("/integrations/health", {}, "Could not load integration health");
export const getContainerProfiles = () =>
  apiRequest("/control-tower/container-profiles", {}, "Could not load container profiles");
export const planContainer = (payload) =>
  apiRequest("/control-tower/container-plan", { method: "POST", body: JSON.stringify(payload) }, "Could not calculate the container plan");
