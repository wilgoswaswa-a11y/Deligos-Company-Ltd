ALTER TABLE `lipana_payment_requests`
  ADD COLUMN `mpesa_code` varchar(100) DEFAULT NULL AFTER `checkout_request_id`,
  ADD COLUMN `customer_name` varchar(255) DEFAULT NULL AFTER `mpesa_code`,
  ADD COLUMN `customer_phone` varchar(20) DEFAULT NULL AFTER `customer_name`;
