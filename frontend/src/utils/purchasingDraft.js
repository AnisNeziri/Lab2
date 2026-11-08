const key = user => user?.company_id && user?.id ? `aims.purchase-draft.${user.company_id}.${user.id}` : null

export function readPurchasingDraft(user) {
  const storageKey = key(user)
  if (!storageKey) return null
  try { return JSON.parse(sessionStorage.getItem(storageKey)) } catch { return null }
}

export function storePurchasingDraft(user, draft) {
  const storageKey = key(user)
  if (!storageKey) return
  try {
    if (draft) sessionStorage.setItem(storageKey, JSON.stringify(draft))
    else sessionStorage.removeItem(storageKey)
  } catch { /* Purchasing still works when browser storage is unavailable. */ }
}
