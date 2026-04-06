CREATE TABLE IF NOT EXISTS suppliers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL UNIQUE,
  contact VARCHAR(255) NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS purchase_orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_number VARCHAR(50) NOT NULL UNIQUE,
  supplier_id INT NULL,
  supplier_name VARCHAR(255) NOT NULL,
  requested_by VARCHAR(100) NOT NULL,
  total_items INT NOT NULL DEFAULT 0,
  total_units INT NOT NULL DEFAULT 0,
  notes TEXT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'ordered',
  follow_up_date DATE NULL,
  notification_status VARCHAR(30) NOT NULL DEFAULT 'pending',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_po_created_at (created_at),
  INDEX idx_po_supplier (supplier_id),
  CONSTRAINT fk_po_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
);

CREATE TABLE IF NOT EXISTS purchase_order_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  purchase_order_id INT NOT NULL,
  stock_id INT NOT NULL,
  item_name VARCHAR(255) NOT NULL,
  current_quantity INT NOT NULL,
  order_quantity INT NOT NULL,
  line_status VARCHAR(30) NOT NULL DEFAULT 'ordered',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_poi_order (purchase_order_id),
  INDEX idx_poi_stock (stock_id),
  CONSTRAINT fk_poi_order FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_poi_stock FOREIGN KEY (stock_id) REFERENCES stock(id)
);

CREATE TABLE IF NOT EXISTS order_followups (
  id INT AUTO_INCREMENT PRIMARY KEY,
  purchase_order_id INT NOT NULL,
  supplier_id INT NULL,
  supplier_name VARCHAR(255) NOT NULL,
  scheduled_for DATE NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'pending',
  notes TEXT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_followup_date (scheduled_for),
  INDEX idx_followup_order (purchase_order_id),
  CONSTRAINT fk_followup_order FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_followup_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
);

CREATE TABLE IF NOT EXISTS stock (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  category VARCHAR(100) NULL,
  batch_number VARCHAR(100) NULL,
  quantity INT NOT NULL DEFAULT 0,
  unit_price DECIMAL(10,2) NULL,
  supplier_id INT NULL,
  expiry_date DATE NULL,
  notes TEXT NULL,
  other_details TEXT NULL,
  image_path VARCHAR(255) NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_by VARCHAR(100) NULL,
  INDEX idx_stock_name (name),
  INDEX idx_stock_qty (quantity),
  CONSTRAINT fk_stock_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
);

CREATE TABLE IF NOT EXISTS usage_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  stock_id INT NOT NULL,
  used_quantity INT NOT NULL,
  used_by VARCHAR(255) NOT NULL,
  note VARCHAR(255) NULL,
  used_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_usage_stock (stock_id),
  INDEX idx_usage_date (used_at),
  CONSTRAINT fk_usage_stock FOREIGN KEY (stock_id) REFERENCES stock(id)
);

CREATE TABLE IF NOT EXISTS users (
  user_id INT AUTO_INCREMENT PRIMARY KEY,
  staff_id VARCHAR(50) NULL,
  username VARCHAR(100) NOT NULL UNIQUE,
  email VARCHAR(150) NOT NULL UNIQUE,
  phone VARCHAR(50) NULL,
  role VARCHAR(50) NOT NULL DEFAULT 'staff',
  password VARCHAR(255) NOT NULL,
  google_id VARCHAR(100) NULL UNIQUE,
  auth_provider VARCHAR(50) NOT NULL DEFAULT 'local',
  reset_token VARCHAR(128) NULL,
  token_expiry DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  staff_scope VARCHAR(50) GENERATED ALWAYS AS (CASE WHEN role IN ('admin','staff') THEN staff_id ELSE NULL END) STORED,
  UNIQUE KEY uq_staff_scope (staff_scope)
);
