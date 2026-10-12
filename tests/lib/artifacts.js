/**
 * Where test runs put the files they produce.
 *
 * Deliberately NOT inside the plugin: browser profiles hold session cookies, the site backup
 * holds store settings, and result files hold order ids and customer emails. The plugin lives
 * in the web root, so anything written there is one misconfigured server away from being
 * downloadable. Keeping it in the system temp directory removes the question entirely.
 *
 * Override with "artifactsDir" in config.json if you want them somewhere else.
 */
const fs = require('fs');
const path = require('path');
const config = require('../config.js');

function artifactsDir(...parts) {
  const dir = path.join(config.artifactsBase, ...parts);
  fs.mkdirSync(dir, { recursive: true, mode: 0o700 });
  return dir;
}

/** Path for a result file, e.g. artifactPath('results-block.json'). */
function artifactPath(name) {
  return path.join(artifactsDir(), name);
}

/** Path for a throwaway Chrome profile, e.g. profilePath('block'). */
function profilePath(name) {
  return artifactsDir('chrome', name);
}

module.exports = { artifactsDir, artifactPath, profilePath };
