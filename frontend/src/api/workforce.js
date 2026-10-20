import { apiRequest, buildApiUrl, authenticatedFetch, parseApiResponse } from './client';
export const workforce = (path = '', data, method = 'GET') => apiRequest('/workforce' + path, {
  method,
  ...(data === undefined ? {} : {
    body: JSON.stringify(data)
  })
});
export const payroll = (path = '', data, method = 'GET') => apiRequest('/payroll' + path, {
  method,
  ...(data === undefined ? {} : {
    body: JSON.stringify(data)
  })
});
export const query = (path, values) => buildApiUrl(path, values);
export async function downloadPayroll(id, type) {
  const response = await authenticatedFetch(buildApiUrl(`/payroll/${id}/export`, {
    type
  }));
  if (!response.ok) await parseApiResponse(response, 'Payroll export failed');
  const url = URL.createObjectURL(await response.blob()),
    a = document.createElement('a');
  a.href = url;
  a.download = `payroll-${id}-${type}-preparation.csv`;
  a.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
