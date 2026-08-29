import { apiRequest } from './client'

export function verifyEmail(payload) {
  return apiRequest('/verify-email', {
    method: 'POST',
    body: JSON.stringify(payload),
  }, 'Email verification failed.')
}

export function resendVerificationEmail(email) {
  return apiRequest('/verify-email/resend', {
    method: 'POST',
    body: JSON.stringify({ email }),
  }, 'Could not resend verification email.')
}

export function forgotPassword(email) {
  return apiRequest('/forgot-password', {
    method: 'POST',
    body: JSON.stringify({ email }),
  }, 'Could not request password reset.')
}

export function resetPassword(payload) {
  return apiRequest('/reset-password', {
    method: 'POST',
    body: JSON.stringify(payload),
  }, 'Could not reset password.')
}
