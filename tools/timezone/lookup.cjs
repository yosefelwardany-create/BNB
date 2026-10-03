// Coordinates stay on this server. No geocoding service or API key is involved.
const { find } = require('geo-tz/all')
const [latitude, longitude] = process.argv.slice(2).map(Number)
if (!Number.isFinite(latitude) || !Number.isFinite(longitude) ||
    Math.abs(latitude) > 90 || Math.abs(longitude) > 180) {
  process.exitCode = 1
} else {
  process.stdout.write(JSON.stringify(find(latitude, longitude)))
}
