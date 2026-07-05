CREATE TABLE IF NOT EXISTS subscription (
    id INT PRIMARY KEY AUTO_INCREMENT,
    client_name VARCHAR(255) NOT NULL,
    client_email VARCHAR(255) DEFAULT NULL,
    client_phone VARCHAR(100) DEFAULT NULL,
    plan_name VARCHAR(100) DEFAULT 'Monthly',
    amount DECIMAL(12,2) DEFAULT 0.00,
    start_date DATE NOT NULL,
    expiry_date DATE NOT NULL,
    grace_days TINYINT UNSIGNED DEFAULT 3,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS subscription_payments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    payment_date DATE NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    period_from DATE NOT NULL,
    period_to DATE NOT NULL,
    payment_method ENUM('cash','bank','momo') DEFAULT 'cash',
    reference VARCHAR(255) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    recorded_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


ALTER TABLE `users` MODIFY COLUMN `role`
  ENUM('admin','manager','storekeeper','superadmin') NOT NULL DEFAULT 'manager';

INSERT IGNORE INTO `users` ( `username`, `password`, `full_name`, `role`)
VALUES ('superadmin', '$2y$10$.jJafyBL/kRUv1eQAomQQ.w5sLK2y.GZ4gsPDHfH2GqzAFPC.KsSW', 'Super Admin', 'superadmin', 'active');


