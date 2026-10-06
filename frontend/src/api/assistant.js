import { apiRequest } from './client'
import { recordCapability } from '../components/assistantPresentation'

export const askIntelligence = body => apiRequest('/intelligence-assistant/ask', {method:'POST',body:JSON.stringify(body)})
export const assistantStatus = () => apiRequest('/intelligence-assistant/status')
export const assistantFeedback = body => apiRequest('/intelligence-assistant/feedback', {method:'POST',body:JSON.stringify(body)})
export const confirmAssistantDraft = body => apiRequest('/intelligence-assistant/confirm', {method:'POST',body:JSON.stringify(body)})
export const assistantUsage = () => apiRequest('/intelligence-assistant/usage')

// Reuse the server's tenant-scoped, permission-checked read-only tools.
// No arbitrary SQL, write endpoints or external AI service is exposed here.
export async function readAssistantRecord(record, mode = 'record') {
  const capability = recordCapability(record.group, mode)
  if (!capability) return null
  const response = await apiRequest(`/capabilities/${capability.name}/execute`, {
    method: 'POST', body: JSON.stringify({ input: { [capability.key]: Number(record.id) } }),
  })
  return response.data
}
