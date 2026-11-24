import { useAuthStore } from '../store/authStore'

export function useCompanyTimezone() {
  return useAuthStore(state => state.user?.business_timezone || 'UTC')
}
