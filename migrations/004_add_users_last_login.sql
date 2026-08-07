-- Migration: add the login timestamp used by the sign-in flow.
-- Run with `php migrate.php` during deployment, not from a page request.

ALTER TABLE `users`
  ADD COLUMN `last_login` DATETIME NULL AFTER `email`;
