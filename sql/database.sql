-- Create database
CREATE DATABASE IF NOT EXISTS minerals_depot;
USE minerals_depot;

-- Users table
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    role ENUM('admin', 'manager', 'storekeeper') DEFAULT 'storekeeper',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Suppliers table
CREATE TABLE suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_code VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(100) NOT NULL,
    contact_person VARCHAR(100),
    phone VARCHAR(20),
    email VARCHAR(100),
    license_number VARCHAR(100),
    address TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Mineral types
CREATE TABLE mineral_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) UNIQUE NOT NULL,
    unit VARCHAR(20) DEFAULT 'kg',
    description TEXT
);

-- Batches (Lot tracking)
CREATE TABLE batches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    batch_id VARCHAR(50) UNIQUE NOT NULL,
    mineral_type_id INT,
    supplier_id INT,
    quantity DECIMAL(12,3) NOT NULL,
    quality_grade VARCHAR(20),
    origin_location VARCHAR(200),
    received_date DATE NOT NULL,
    expiry_date DATE,
    certificate_number VARCHAR(100),
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (mineral_type_id) REFERENCES mineral_types(id),
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Inventory (current stock per mineral type)
CREATE TABLE inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    mineral_type_id INT UNIQUE,
    current_stock DECIMAL(12,3) DEFAULT 0,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (mineral_type_id) REFERENCES mineral_types(id)
);

-- Transactions table
CREATE TABLE transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_code VARCHAR(50) UNIQUE NOT NULL,
    transaction_type ENUM('IN', 'OUT') NOT NULL,
    batch_id INT,
    mineral_type_id INT,
    quantity DECIMAL(12,3) NOT NULL,
    transaction_date DATE NOT NULL,
    price_per_unit DECIMAL(15,2),
    total_amount DECIMAL(15,2),
    reference_number VARCHAR(100),
    recipient_company VARCHAR(200),
    driver_name VARCHAR(100),
    vehicle_number VARCHAR(50),
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (batch_id) REFERENCES batches(id),
    FOREIGN KEY (mineral_type_id) REFERENCES mineral_types(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Mineral price settings (global pricing by quality grade)
CREATE TABLE mineral_price_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    mineral_type_id INT NOT NULL,
    quality_grade VARCHAR(20) NOT NULL,
    purchase_price DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    selling_price DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (mineral_type_id) REFERENCES mineral_types(id) ON DELETE CASCADE,
    UNIQUE KEY unique_mineral_grade (mineral_type_id, quality_grade)
);

-- Supplier loans / repayments
CREATE TABLE supplier_loans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT NOT NULL,
    batch_id INT NULL,
    type ENUM('loan','repayment') NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    FOREIGN KEY (batch_id) REFERENCES batches(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Audit log
CREATE TABLE audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(100),
    table_name VARCHAR(50),
    record_id INT,
    details TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Insert sample mineral types
INSERT INTO mineral_types (name, unit) VALUES 
('Gold', 'kg'),
('Coltan', 'kg'),
('Cassiterite', 'kg'),
('Copper', 'kg'),
('Cobalt', 'kg');

-- Insert sample supplier
INSERT INTO suppliers (supplier_code, name, contact_person, phone, license_number) VALUES 
('SUP001', 'Eastern Mining Co.', 'John Doe', '+1234567890', 'MIN-2024-001');

-- Insert sample user (password: admin123)
INSERT INTO users (username, password, full_name, role) VALUES 
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrator', 'admin');

-- Trigger to update inventory after transaction
DELIMITER $$
CREATE TRIGGER update_inventory_after_transaction
AFTER INSERT ON transactions
FOR EACH ROW
BEGIN
    IF NEW.transaction_type = 'IN' THEN
        INSERT INTO inventory (mineral_type_id, current_stock)
        VALUES (NEW.mineral_type_id, NEW.quantity)
        ON DUPLICATE KEY UPDATE 
        current_stock = current_stock + NEW.quantity;
    ELSEIF NEW.transaction_type = 'OUT' THEN
        INSERT INTO inventory (mineral_type_id, current_stock)
        VALUES (NEW.mineral_type_id, -NEW.quantity)
        ON DUPLICATE KEY UPDATE 
        current_stock = current_stock - NEW.quantity;
    END IF;
END$$
DELIMITER ;