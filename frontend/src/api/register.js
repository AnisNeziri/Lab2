import { apiRequest } from './client'

export async function register({
  name,
  email,
  password,
  password_confirmation,
  company_name,
  company_address,
}) {
  return apiRequest('/register', {
    method: 'POST',
    body: JSON.stringify({
      name,
      email,
      password,
      password_confirmation,
      company_name,
      company_address,
    }),
  }, 'Failed to register')
}
