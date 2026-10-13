--
-- @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
-- @license     GNU General Public License version 3 or later; see LICENSE.txt
-- SPDX-License-Identifier: GPL-3.0-or-later
--
-- Failed attempts on the confirmation page for privacy requests, per e-mail address and per
-- client IP address. Only HMAC hashes (site secret) are stored, never the address itself.
--
CREATE TABLE IF NOT EXISTS "#__privacy_j2commerce_attempts" (
  "id" serial NOT NULL,
  "scope" varchar(16) NOT NULL,
  "subject_hash" char(64) NOT NULL,
  "failures" integer DEFAULT 0 NOT NULL,
  "window_start" timestamp without time zone NOT NULL,
  PRIMARY KEY ("id"),
  CONSTRAINT "#__privacy_j2commerce_attempts_idx_scope_subject" UNIQUE ("scope", "subject_hash")
);
CREATE INDEX IF NOT EXISTS "#__privacy_j2commerce_attempts_idx_window_start" ON "#__privacy_j2commerce_attempts" ("window_start");
