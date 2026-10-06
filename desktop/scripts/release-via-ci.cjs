// Publishing must use the workflow that tests and verifies the signed artifacts.
// A local build is still available through dist:win or dist:win:signed.
console.error('Direct publishing is disabled. Run the "AIMS signed desktop release" GitHub Actions workflow after committing your release version. It runs the required tests, verifies signatures, and publishes only verified artifacts.')
process.exitCode = 1
