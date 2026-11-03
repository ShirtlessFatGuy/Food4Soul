-- Run this once if you already imported the original schema (adds QR checkout).
USE food4soul;

-- ---------- PAYMENTS (QR checkout) ----------
-- A reseller choosing "QR" creates a Pending payment holding a snapshot of the cart.
-- The sale is only recorded (and stock deducted) once the payment is confirmed Paid.
CREATE TABLE payments (
  Payment_ID    VARCHAR(10)   PRIMARY KEY,
  Reference_No  VARCHAR(20)   NOT NULL UNIQUE,
  User_ID       VARCHAR(10)   NOT NULL,
  Amount        DECIMAL(10,2) NOT NULL,
  Method        VARCHAR(20)   NOT NULL DEFAULT 'QR',
  Status        ENUM('Pending','Paid','Expired','Cancelled','Failed') NOT NULL DEFAULT 'Pending',
  Cart_Snapshot TEXT          NOT NULL,           -- JSON {Product_ID: qty}
  QR_Payload    TEXT          NULL,               -- text encoded in the QR image
  Provider_Ref  VARCHAR(100)  NULL,               -- gateway transaction id (live mode)
  Created_At    DATETIME      NOT NULL,
  Expires_At    DATETIME      NOT NULL,
  Paid_At       DATETIME      NULL,
  CONSTRAINT fk_payments_user FOREIGN KEY (User_ID) REFERENCES users(User_ID) ON DELETE CASCADE
) ENGINE=InnoDB;
