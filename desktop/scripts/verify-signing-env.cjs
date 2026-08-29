if (! process.env.CSC_LINK || ! process.env.CSC_KEY_PASSWORD) {
  console.error('A trusted Windows signing certificate is required. Set CSC_LINK and CSC_KEY_PASSWORD before building the commercial installer.')
  process.exit(1)
}

console.log('Windows installer signing credentials are configured.')
