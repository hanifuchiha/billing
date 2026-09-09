-- Kolom rincian pembayaran yang digunakan callback Tripay.
-- Kompatibel dengan MySQL lama dan aman dijalankan ulang.

SET @schema_name := DATABASE();

SET @ddl := IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='transaksi' AND COLUMN_NAME='fee_merchant'),
    'SELECT 1',
    'ALTER TABLE `transaksi` ADD COLUMN `fee_merchant` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `PAY_DETAIL`'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='transaksi' AND COLUMN_NAME='fee_customer'),
    'SELECT 1',
    'ALTER TABLE `transaksi` ADD COLUMN `fee_customer` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `fee_merchant`'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='transaksi' AND COLUMN_NAME='payment_method'),
    'SELECT 1',
    'ALTER TABLE `transaksi` ADD COLUMN `payment_method` VARCHAR(50) NULL AFTER `fee_customer`'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='transaksi' AND COLUMN_NAME='harga_gross'),
    'SELECT 1',
    'ALTER TABLE `transaksi` ADD COLUMN `harga_gross` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `payment_method`'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
