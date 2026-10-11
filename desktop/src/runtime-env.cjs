function sanitizeInheritedEnvironment(source) {
  return Object.fromEntries(Object.entries(source).filter(([key]) =>
    !/^(DB_|MYSQL_)/i.test(key) &&
    !/^(APP_KEY|JWT_SECRET|AIMS_DESKTOP_RECOVERY_PASSWORD|REDIS_PASSWORD|AISSTREAM_API_KEY|VESSELAPI_API_KEY|REVERB_APP_SECRET|STRIPE_SECRET|MAIL_PASSWORD|AIMS_DOCUMENT_ROOT|AIMS_BACKUP_ROOT|AIMS_BACKUP_PASSPHRASE_FILE|AIMS_BACKUP_SCHEDULE|APP_CONFIG_CACHE)$/i.test(key)
  ))
}

module.exports = { sanitizeInheritedEnvironment }
