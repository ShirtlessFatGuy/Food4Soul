-- ============================================================
-- Food4Soul Analytics Portal
-- Database schema. Field names follow the ERD / data dictionary
-- in Chapter 2 of the capstone paper exactly.
-- Requires MySQL 8+ or MariaDB 10.2+ (uses ROW_NUMBER()).
-- Import via phpMyAdmin, or: mysql -u root -p < food4soul_schema.sql
-- ============================================================

CREATE DATABASE IF NOT EXISTS food4soul CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE food4soul;

-- ---------- SUPPLIERS ----------
CREATE TABLE suppliers (
  Supplier_ID      VARCHAR(10)  PRIMARY KEY,
  Supplier_Name    VARCHAR(100) NOT NULL,
  Contact_Person   VARCHAR(100),
  Contact_Address  VARCHAR(255),
  Phone_Number     VARCHAR(20),
  Email_Address    VARCHAR(100)
) ENGINE=InnoDB;

-- ---------- USERS ----------
CREATE TABLE users (
  User_ID        VARCHAR(10)  PRIMARY KEY,
  Full_Name      VARCHAR(100) NOT NULL,
  Email_Address  VARCHAR(100) NOT NULL UNIQUE,
  Password       VARCHAR(255) NOT NULL,          -- bcrypt hash (REQ-007)
  Role           ENUM('Admin','Supplier','Reseller') NOT NULL,
  Supplier_ID    VARCHAR(10)  NULL,               -- set only when Role = 'Supplier'
  Created_At     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_supplier
    FOREIGN KEY (Supplier_ID) REFERENCES suppliers(Supplier_ID) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------- PRODUCTS ----------
CREATE TABLE products (
  Product_ID              VARCHAR(10)  PRIMARY KEY,
  Product_Name            VARCHAR(100) NOT NULL,
  Description             TEXT,
  Category                VARCHAR(50),
  Unit_Price              DECIMAL(10,2) NOT NULL,
  Current_Stock_Quantity  INT NOT NULL DEFAULT 0,
  Supplier_ID             VARCHAR(10),
  CONSTRAINT fk_products_supplier
    FOREIGN KEY (Supplier_ID) REFERENCES suppliers(Supplier_ID) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------- INVENTORY (reorder tracking, per ERD) ----------
CREATE TABLE inventory (
  Inventory_ID       VARCHAR(10) PRIMARY KEY,
  Product_ID         VARCHAR(10) NOT NULL,
  Supplier_ID        VARCHAR(10),
  Reorder_Level      INT NOT NULL DEFAULT 10,
  Last_Updated_Date  DATE,
  CONSTRAINT fk_inventory_product
    FOREIGN KEY (Product_ID) REFERENCES products(Product_ID) ON DELETE CASCADE,
  CONSTRAINT fk_inventory_supplier
    FOREIGN KEY (Supplier_ID) REFERENCES suppliers(Supplier_ID) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------- ORDERS (admin -> supplier restock orders) ----------
CREATE TABLE orders (
  Order_ID                VARCHAR(10) PRIMARY KEY,
  Order_Date              DATE NOT NULL,
  Expected_Delivery_Date  DATE,
  Status                  ENUM('Pending','Processing','Delivered','Cancelled') DEFAULT 'Pending',
  Supplier_ID             VARCHAR(10),
  CONSTRAINT fk_orders_supplier
    FOREIGN KEY (Supplier_ID) REFERENCES suppliers(Supplier_ID) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------- ORDER DETAILS ----------
CREATE TABLE order_details (
  Order_Detail_ID  VARCHAR(10) PRIMARY KEY,
  Order_ID         VARCHAR(10) NOT NULL,
  Product_ID       VARCHAR(10) NOT NULL,
  Quantity         INT NOT NULL,
  CONSTRAINT fk_od_order   FOREIGN KEY (Order_ID)   REFERENCES orders(Order_ID)     ON DELETE CASCADE,
  CONSTRAINT fk_od_product FOREIGN KEY (Product_ID) REFERENCES products(Product_ID) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------- SALES (reseller purchases) ----------
CREATE TABLE sales (
  Sale_ID         VARCHAR(10) PRIMARY KEY,
  Sale_Date       DATE NOT NULL,
  User_ID         VARCHAR(10) NOT NULL,
  Product_ID      VARCHAR(10) NOT NULL,
  Quantity_Sold   INT NOT NULL,
  Unit_Price      DECIMAL(10,2) NOT NULL,
  Total_Amount    DECIMAL(10,2) NOT NULL,
  Payment_Method  ENUM('Cash','QR') DEFAULT 'Cash',
  CONSTRAINT fk_sales_user    FOREIGN KEY (User_ID)    REFERENCES users(User_ID)       ON DELETE CASCADE,
  CONSTRAINT fk_sales_product FOREIGN KEY (Product_ID) REFERENCES products(Product_ID) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------- ALERTS ----------
CREATE TABLE alerts (
  Alert_ID        VARCHAR(10) PRIMARY KEY,
  Product_ID      VARCHAR(10),
  Alert_Type      VARCHAR(50),
  Alert_Message   VARCHAR(255),
  Date_Generated  DATE,
  Status          ENUM('Read','Unread') DEFAULT 'Unread',
  CONSTRAINT fk_alerts_product FOREIGN KEY (Product_ID) REFERENCES products(Product_ID) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- SEED DATA
-- ============================================================

INSERT INTO suppliers (Supplier_ID, Supplier_Name, Contact_Person, Contact_Address, Phone_Number, Email_Address) VALUES
('S000001','Whisk Inc.','Juan Luna','Block 0 Lot 99, Example Street, Barangay 9, Manila','0917-234-5678','contact@whisk.ph'),
('S000002','Ilocos Fresh Co.','Rosa Bautista','Vigan City, Ilocos Sur','0917-555-2211','sales@ilocosfresh.ph'),
('S000003','Manila Bay Seafoods','Ana Reyes','Navotas Fish Port, Metro Manila','0917-888-0099','orders@mbseafoods.ph');

-- The Password values below are placeholders, NOT valid bcrypt hashes.
-- Run `php database/generate_demo_users.php` once after import to set real hashes
-- for admin123 / supplier123 / reseller123 (see README, Step 3).
INSERT INTO users (User_ID, Full_Name, Email_Address, Password, Role, Supplier_ID) VALUES
('F4S000001','Maria Santos','admin@food4soul.com','PLACEHOLDER','Admin', NULL),
('F4S000002','Whisk Inc.','supplier@food4soul.com','PLACEHOLDER','Supplier','S000001'),
('F4S000003','Juan Dela Cruz','reseller@food4soul.com','PLACEHOLDER','Reseller', NULL);

INSERT INTO products (Product_ID, Product_Name, Description, Category, Unit_Price, Current_Stock_Quantity, Supplier_ID) VALUES
('P000001','Vigan Longganisa','A Filipino pork sausage originating from Vigan City, Ilocos Sur.','Meat',250.00,34,'S000001'),
('P000002','Bagnet (1kg pack)','Crispy deep-fried pork belly.','Meat',320.00,58,'S000001'),
('P000003','Kesong Puti','Soft white cheese made from carabao milk.','Dairy',180.00,12,'S000002'),
('P000004','Bangus (Deboned)','Deboned milkfish, ready to cook.','Seafood',210.00,9,'S000003'),
('P000005','Tinapa Flakes','Smoked fish flakes.','Seafood',145.00,41,'S000003'),
('P000006','Patis (1L)','Filipino fish sauce.','Pantry',95.00,63,'S000002'),
('P000007','Calamansi (kg)','Philippine lime.','Produce',70.00,5,'S000002'),
('P000008','Sago''t Gulaman Mix','Instant drink mix.','Beverage',60.00,77,'S000001'),
('P000009','Dried Pusit','Dried squid.','Seafood',390.00,14,'S000003'),
('P000010','Sukang Iloko (1L)','Iloco sugarcane vinegar.','Pantry',85.00,48,'S000002');

INSERT INTO inventory (Inventory_ID, Product_ID, Supplier_ID, Reorder_Level, Last_Updated_Date) VALUES
('I000001','P000001','S000001',40,CURDATE()),
('I000002','P000002','S000001',25,CURDATE()),
('I000003','P000003','S000002',20,CURDATE()),
('I000004','P000004','S000003',15,CURDATE()),
('I000005','P000005','S000003',20,CURDATE()),
('I000006','P000006','S000002',30,CURDATE()),
('I000007','P000007','S000002',25,CURDATE()),
('I000008','P000008','S000001',20,CURDATE()),
('I000009','P000009','S000003',15,CURDATE()),
('I000010','P000010','S000002',20,CURDATE());

INSERT INTO orders (Order_ID, Order_Date, Expected_Delivery_Date, Status, Supplier_ID) VALUES
('O000001', CURDATE() - INTERVAL 6 DAY, CURDATE() + INTERVAL 1 DAY, 'Pending',    'S000001'),
('O000002', CURDATE() - INTERVAL 5 DAY, CURDATE() - INTERVAL 1 DAY, 'Processing', 'S000003'),
('O000003', CURDATE() - INTERVAL 9 DAY, CURDATE() - INTERVAL 3 DAY, 'Delivered',  'S000002');

INSERT INTO order_details (Order_Detail_ID, Order_ID, Product_ID, Quantity) VALUES
('OD000001','O000001','P000001',60),
('OD000002','O000001','P000002',30),
('OD000003','O000002','P000004',40),
('OD000004','O000002','P000009',20),
('OD000005','O000003','P000007',50);

-- Sample sales over the last 14 days so the dashboard charts have data on first run
INSERT INTO sales (Sale_ID, Sale_Date, User_ID, Product_ID, Quantity_Sold, Unit_Price, Total_Amount, Payment_Method) VALUES
('S000001', CURDATE() - INTERVAL 13 DAY, 'F4S000003','P000001', 4,250.00,1000.00,'Cash'),
('S000002', CURDATE() - INTERVAL 12 DAY, 'F4S000003','P000002', 2,320.00, 640.00,'QR'),
('S000003', CURDATE() - INTERVAL 11 DAY, 'F4S000003','P000006', 6, 95.00, 570.00,'Cash'),
('S000004', CURDATE() - INTERVAL 10 DAY, 'F4S000003','P000005', 3,145.00, 435.00,'QR'),
('S000005', CURDATE() - INTERVAL 9 DAY,  'F4S000003','P000001', 5,250.00,1250.00,'Cash'),
('S000006', CURDATE() - INTERVAL 8 DAY,  'F4S000003','P000009', 2,390.00, 780.00,'QR'),
('S000007', CURDATE() - INTERVAL 7 DAY,  'F4S000003','P000008', 8, 60.00, 480.00,'Cash'),
('S000008', CURDATE() - INTERVAL 6 DAY,  'F4S000003','P000002', 3,320.00, 960.00,'Cash'),
('S000009', CURDATE() - INTERVAL 5 DAY,  'F4S000003','P000004', 4,210.00, 840.00,'QR'),
('S000010', CURDATE() - INTERVAL 4 DAY,  'F4S000003','P000001', 6,250.00,1500.00,'Cash'),
('S000011', CURDATE() - INTERVAL 3 DAY,  'F4S000003','P000010', 5, 85.00, 425.00,'QR'),
('S000012', CURDATE() - INTERVAL 2 DAY,  'F4S000003','P000003', 4,180.00, 720.00,'Cash'),
('S000013', CURDATE() - INTERVAL 1 DAY,  'F4S000003','P000002', 5,320.00,1600.00,'QR'),
('S000014', CURDATE(),                   'F4S000003','P000001', 3,250.00, 750.00,'Cash');

-- Initial low-stock alerts for products already at/below their reorder level.
-- After this, alerts are created by includes/functions.php::update_stock() (REQ-004).
INSERT INTO alerts (Alert_ID, Product_ID, Alert_Type, Alert_Message, Date_Generated, Status)
SELECT CONCAT('A', LPAD(ROW_NUMBER() OVER (ORDER BY p.Product_ID), 6, '0')),
       p.Product_ID, 'Low Stock',
       CONCAT(p.Product_Name, ' is at ', p.Current_Stock_Quantity, ' units (reorder level: ', i.Reorder_Level, ').'),
       CURDATE(), 'Unread'
FROM products p JOIN inventory i ON i.Product_ID = p.Product_ID
WHERE p.Current_Stock_Quantity <= i.Reorder_Level;
