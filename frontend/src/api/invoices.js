import {
  apiRequest,
  authenticatedFetch,
  buildApiUrl,
  parseApiResponse,
} from "./client";

export function getInvoiceProfile() {
  return apiRequest(
    "/invoice-profile",
    {},
    "Could not load the invoice company profile.",
  );
}

export function updateInvoiceProfile(payload) {
  return apiRequest(
    "/invoice-profile",
    {
      method: "PUT",
      body: JSON.stringify(payload),
    },
    "Could not save the invoice company profile.",
  );
}

export function getInvoices(filters = {}) {
  return apiRequest(
    buildApiUrl("/invoices", {
      search: filters.search,
      status: filters.status,
      payment_status: filters.payment_status,
      date_from: filters.date_from,
      date_to: filters.date_to,
      page: filters.page,
      per_page: filters.per_page,
    }),
    {},
    "Could not load invoices.",
  );
}

export function getInvoice(id) {
  return apiRequest(`/invoices/${id}`, {}, "Could not load the invoice.");
}

export function createInvoice(payload) {
  return apiRequest(
    "/invoices",
    {
      method: "POST",
      body: JSON.stringify(payload),
    },
    "Could not create the invoice.",
  );
}

export function updateInvoice(id, payload) {
  return apiRequest(
    `/invoices/${id}`,
    {
      method: "PUT",
      body: JSON.stringify(payload),
    },
    "Could not update the invoice.",
  );
}

export function issueInvoice(id) {
  return apiRequest(
    `/invoices/${id}/issue`,
    { method: "POST" },
    "Could not issue the invoice.",
  );
}

export function voidInvoice(id, reason) {
  return apiRequest(
    `/invoices/${id}/void`,
    {
      method: "POST",
      body: JSON.stringify({ reason }),
    },
    "Could not void the invoice.",
  );
}

export function createInvoiceCreditNote(id, reason) {
  return apiRequest(
    `/invoices/${id}/credit-note`,
    {
      method: "POST",
      body: JSON.stringify({ reason }),
    },
    "Could not create the credit note.",
  );
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

function triggerDownload(href, filename, revoke = false) {
  const link = document.createElement("a");
  link.href = href;
  link.download = filename;
  link.style.display = "none";
  document.body.appendChild(link);
  link.click();
  link.remove();
  if (revoke) window.setTimeout(() => URL.revokeObjectURL(href), 1000);
}

export async function downloadInvoicePdf(id, invoiceNumber, locale = "en") {
  const response = await authenticatedFetch(
    buildApiUrl(`/invoices/${id}/download/pdf`, { locale }),
    { headers: { Accept: "application/pdf, application/json" } },
  );

  if (!response.ok) {
    await parseApiResponse(response, "Could not download the invoice PDF.");
  }

  const fallback = `invoice-${invoiceNumber || id}.pdf`;
  const contentType = response.headers.get("content-type") || "";
  if (contentType.includes("application/json")) {
    const data = await response.json();
    if (!data?.pdf) throw new Error(data?.message || "The PDF response was empty.");
    triggerDownload(
      `data:application/pdf;base64,${data.pdf}`,
      data.filename || fallback,
    );
    return;
  }

  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  triggerDownload(url, filenameFromResponse(response, fallback), true);
}

export async function downloadInvoiceExcel(id, invoiceNumber, locale = "en") {
  const response = await authenticatedFetch(
    buildApiUrl(`/invoices/${id}/excel`, { locale }),
    {
      headers: {
        Accept:
          "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet, application/json",
      },
    },
  );

  if (!response.ok) {
    await parseApiResponse(response, "Could not download the invoice Excel file.");
  }

  const fallback = `invoice-${invoiceNumber || id}.xlsx`;
  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  triggerDownload(url, filenameFromResponse(response, fallback), true);
}
