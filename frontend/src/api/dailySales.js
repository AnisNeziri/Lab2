import { apiRequest } from "./client";

export function getDailySales(params = {}) {
  const search = new URLSearchParams();
  if (params.date) search.set("date", params.date);
  if (params.status) search.set("status", params.status);
  const query = search.toString();
  return apiRequest(
    `/daily-sales${query ? `?${query}` : ""}`,
    {},
    "Failed to load daily sales",
  );
}

export function getDailySalesSummary(date) {
  const search = date ? `?date=${date}` : "";
  return apiRequest(
    `/daily-sales/summary${search}`,
    {},
    "Failed to load daily sales summary",
  );
}

export function getDailySalesDayNotes(date) {
  return apiRequest(
    `/daily-sales/day-notes?date=${encodeURIComponent(date)}`,
    {},
    "Failed to load daily sales notes",
  );
}

export function updateDailySalesDayNotes(date, notes) {
  return apiRequest(
    "/daily-sales/day-notes",
    {
      method: "PUT",
      body: JSON.stringify({ date, notes }),
    },
    "Failed to save daily sales notes",
  );
}

export function getDailySale(id) {
  return apiRequest(
    `/daily-sales/${id}`,
    {},
    "Failed to load daily sales sheet",
  );
}

export function createDailySale(payload) {
  return apiRequest(
    "/daily-sales",
    {
      method: "POST",
      body: JSON.stringify(payload),
    },
    "Failed to create daily sales sheet",
  );
}

export function updateDailySale(id, payload) {
  return apiRequest(
    `/daily-sales/${id}`,
    {
      method: "PUT",
      body: JSON.stringify(payload),
    },
    "Failed to update daily sales sheet",
  );
}

export function finalizeDailySale(id) {
  return apiRequest(
    `/daily-sales/${id}/finalize`,
    {
      method: "POST",
    },
    "Failed to finalize daily sales sheet",
  );
}

export function finalizeDailySalesDay(date) {
  return apiRequest(
    "/daily-sales/finalize-day",
    {
      method: "POST",
      body: JSON.stringify({ date }),
    },
    "Failed to close daily sales day",
  );
}

export function deleteDailySale(id) {
  return apiRequest(
    `/daily-sales/${id}`,
    {
      method: "DELETE",
    },
    "Failed to delete daily sales sheet",
  );
}

export async function downloadDailySalePdf(id, locale = "en") {
  const data = await apiRequest(
    `/daily-sales/${id}/pdf?locale=${locale}`,
    {},
    "Failed to generate PDF",
  );
  const link = document.createElement("a");
  link.href = `data:application/pdf;base64,${data.pdf}`;
  link.download = data.filename;
  link.click();
}

export async function downloadDailySalesDayPdf(date, locale = "en") {
  const query = new URLSearchParams({ date, locale });
  const data = await apiRequest(
    `/daily-sales/day-pdf?${query}`,
    {},
    "Failed to generate daily sales PDF",
  );
  const link = document.createElement("a");
  link.href = `data:application/pdf;base64,${data.pdf}`;
  link.download = data.filename;
  link.click();
}
