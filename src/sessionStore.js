'use strict';

const session = require('express-session');
const Store = session.Store;
const db = require('./db/database');
const { json } = require('./db/database');

/**
 * SQLite-backed session store for express-session (survives restarts,
 * no memory leak). Schema created lazily on the main database.
 */
class SQLiteSessionStore extends Store {
  constructor() {
    super();
    this._ready = false;
  }
  _ensure() {
    if (this._ready) return;
    db.getDb().exec(
      `CREATE TABLE IF NOT EXISTS sessions (
         sid TEXT PRIMARY KEY,
         sess TEXT NOT NULL,
         expires INTEGER NOT NULL
       );`
    );
    this._ready = true;
  }
  get(sid, cb) {
    try {
      this._ensure();
      const row = db.get('SELECT sess, expires FROM sessions WHERE sid = ?', [sid]);
      if (!row) return cb(null, null);
      if (row.expires && row.expires < Date.now()) {
        db.run('DELETE FROM sessions WHERE sid = ?', [sid]);
        return cb(null, null);
      }
      cb(null, JSON.parse(row.sess));
    } catch (err) {
      cb(err);
    }
  }
  set(sid, sess, cb) {
    try {
      this._ensure();
      const expires = sess.cookie && sess.cookie.expires ? new Date(sess.cookie.expires).getTime() : Date.now() + 86400000;
      db.run(
        'INSERT INTO sessions (sid, sess, expires) VALUES (?,?,?) ON CONFLICT(sid) DO UPDATE SET sess=excluded.sess, expires=excluded.expires',
        [sid, json(sess), expires]
      );
      cb(null);
    } catch (err) {
      cb(err);
    }
  }
  destroy(sid, cb) {
    try {
      this._ensure();
      db.run('DELETE FROM sessions WHERE sid = ?', [sid]);
      cb(null);
    } catch (err) {
      cb(err);
    }
  }
  touch(sid, sess, cb) {
    try {
      this._ensure();
      const expires = sess.cookie && sess.cookie.expires ? new Date(sess.cookie.expires).getTime() : Date.now() + 86400000;
      db.run('UPDATE sessions SET expires = ? WHERE sid = ?', [expires, sid]);
      cb(null);
    } catch (err) {
      cb(err);
    }
  }
}

module.exports = SQLiteSessionStore;
