'use strict';

const config = require('./config');
const db = require('./db/database');
const migrations = require('./db/migrations');
const seed = require('./db/seed');
const { createApp } = require('./app');

function bootstrap() {
  // Migrate + seed idempotently on startup (safe for existing databases).
  db.migrate(migrations);
  if (db.get('SELECT COUNT(*) c FROM users').c === 0) {
    seed.seedAll();
  }
  // Refresh contractor document expiry statuses on startup.
  try {
    require('./services/contractorService').refreshExpiryStatuses();
  } catch (_) { /* non-fatal */ }

  const app = createApp();
  const server = app.listen(config.port, config.host, () => {
    // eslint-disable-next-line no-console
    console.log(`[${config.systemShortName}] listening on http://${config.host}:${config.port}`);
    // eslint-disable-next-line no-console
    console.log(`[${config.systemShortName}] environment: ${config.env}`);
  });
  return { app, server };
}

if (require.main === module) {
  bootstrap();
}

module.exports = { bootstrap };
