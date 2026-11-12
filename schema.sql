CREATE TABLE IF NOT EXISTS barangays (
    id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(50)  NOT NULL UNIQUE,
    email         VARCHAR(120) NOT NULL UNIQUE,
    full_name     VARCHAR(120) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('dilg','barangay') NOT NULL,
    barangay_id   INT UNSIGNED NULL,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    last_login    DATETIME NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_users_barangay FOREIGN KEY (barangay_id) REFERENCES barangays(id)
) ENGINE=InnoDB;

INSERT IGNORE INTO barangays (name) VALUES
 ('San Andres'), ('San Isidro'), ('San Juan'), ('San Roque'),
 ('Santa Rosa'), ('Santo Domingo'), ('Santo Niño');