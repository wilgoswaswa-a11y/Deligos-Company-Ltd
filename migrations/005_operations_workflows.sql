-- Operations workflows: itemised returns, receipt delivery history, suppliers and purchase orders.

CREATE TABLE IF NOT EXISTS refund_items (
  id INT NOT NULL AUTO_INCREMENT,
  refund_id INT NOT NULL,
  sale_item_id INT NOT NULL,
  product_id INT NOT NULL,
  qty INT NOT NULL,
  refund_amount DECIMAL(10,2) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_refund_items_refund (refund_id),
  KEY idx_refund_items_sale_item (sale_item_id),
  CONSTRAINT fk_refund_items_refund FOREIGN KEY (refund_id) REFERENCES refunds(id) ON DELETE CASCADE,
  CONSTRAINT fk_refund_items_sale_item FOREIGN KEY (sale_item_id) REFERENCES sale_items(id),
  CONSTRAINT fk_refund_items_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS receipt_deliveries (
  id INT NOT NULL AUTO_INCREMENT,
  receipt_id INT NOT NULL,
  delivery_type ENUM('reprint','email') NOT NULL,
  recipient_email VARCHAR(100) DEFAULT NULL,
  status ENUM('sent','failed','printed') NOT NULL,
  delivered_by INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_receipt_delivery_receipt (receipt_id),
  KEY idx_receipt_delivery_created (created_at),
  CONSTRAINT fk_receipt_delivery_receipt FOREIGN KEY (receipt_id) REFERENCES receipts(id) ON DELETE CASCADE,
  CONSTRAINT fk_receipt_delivery_user FOREIGN KEY (delivered_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS suppliers (
  id INT NOT NULL AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  contact_name VARCHAR(100) DEFAULT NULL,
  phone VARCHAR(30) DEFAULT NULL,
  email VARCHAR(100) DEFAULT NULL,
  address TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), UNIQUE KEY uq_supplier_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS purchase_orders (
  id INT NOT NULL AUTO_INCREMENT,
  po_no VARCHAR(32) NOT NULL,
  supplier_id INT DEFAULT NULL,
  created_by INT NOT NULL,
  status ENUM('draft','ordered','received','cancelled') NOT NULL DEFAULT 'draft',
  total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  ordered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  received_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_purchase_order_no (po_no),
  KEY idx_purchase_order_supplier (supplier_id),
  CONSTRAINT fk_purchase_order_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
  CONSTRAINT fk_purchase_order_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS purchase_order_items (
  id INT NOT NULL AUTO_INCREMENT,
  purchase_order_id INT NOT NULL,
  product_id INT NOT NULL,
  qty INT NOT NULL,
  unit_cost DECIMAL(10,2) NOT NULL,
  PRIMARY KEY (id), KEY idx_purchase_order_item_order (purchase_order_id),
  CONSTRAINT fk_purchase_order_item_order FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_purchase_order_item_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
