// Selectors need every available option, not just the first API page. Keep
// requests sequential so loading large catalogues does not flood the server.
export async function collectPages(fetchPage, filters = {}) {
  const items = []
  let page = 1
  let lastPage = 1
  do {
    const response = await fetchPage({ per_page: 50, ...filters, page })
    items.push(...(Array.isArray(response) ? response : response.data || []))
    lastPage = Number(response.last_page ?? response.meta?.last_page ?? 1)
    page += 1
  } while (page <= lastPage)
  return items
}
