'use strict';

/**
 * Application error types. Centralizes HTTP status + error codes so every
 * API/service layer returns consistent errors.
 */
class AppError extends Error {
  constructor(status, code, message, details) {
    super(message);
    this.name = this.constructor.name;
    this.status = status;
    this.code = code;
    this.details = details || null;
    this.expose = status < 500;
  }
}

function badRequest(message, details, code = 'BAD_REQUEST') {
  return new AppError(400, code, message, details);
}
function unauthorized(message = 'Authentication required', code = 'UNAUTHORIZED') {
  return new AppError(401, code, message);
}
function forbidden(message = 'You do not have permission to perform this action', code = 'FORBIDDEN') {
  return new AppError(403, code, message);
}
function notFound(message = 'Record not found', code = 'NOT_FOUND') {
  return new AppError(404, code, message);
}
function conflict(message, code = 'CONFLICT', details) {
  return new AppError(409, code, message, details);
}
function validation(message, details) {
  return new AppError(422, 'VALIDATION_ERROR', message, details);
}

module.exports = { AppError, badRequest, unauthorized, forbidden, notFound, conflict, validation };
